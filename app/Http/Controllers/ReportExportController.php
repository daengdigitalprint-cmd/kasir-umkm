<?php

namespace App\Http\Controllers;

use App\Exports\GenericReportExport;
use App\Models\CashShift;
use App\Models\ChartOfAccount;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentLine;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Accounting\ChartOfAccountsBootstrap;
use App\Support\StorePlanLimits;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Titik masuk tunggal untuk ekspor semua laporan ke PDF / Excel.
 *
 * Tiap laporan punya builder yang mengembalikan "payload" seragam:
 *   ['title','storeName','meta'=>[],'sections'=>[],'generatedAt'=>..]
 * Payload dirender oleh reports/generic.blade.php (PDF) & GenericReportExport (Excel).
 */
class ReportExportController extends Controller
{
    /** Laporan yang butuh paket lanjutan (advanced retail). */
    private const ADVANCED_REPORTS = ['selisih-stok', 'kartu-stok', 'shift-kasir'];

    public function export(Request $request, string $report, string $format)
    {
        $user = auth()->user();
        $store = $user->store;

        if (! $store) {
            return redirect()->route('stores.create')
                ->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        if (in_array($report, self::ADVANCED_REPORTS, true)
            && ! StorePlanLimits::allowsAdvancedRetailFeatures($store)) {
            return redirect()->route('reports.index')
                ->with('warning', 'Paket Anda belum mencakup laporan ini.');
        }

        $builder = $this->builderFor($report);
        if ($builder === null) {
            abort(404, 'Laporan tidak dikenali.');
        }

        $payload = $this->{$builder}($user, $store, $request);
        $payload['generatedAt'] = Carbon::now()->translatedFormat('d F Y H:i');
        $payload['storeName'] = $store->name;

        $slug = $report.'-'.now()->format('Ymd-His');

        if ($format === 'excel') {
            return Excel::download(
                new GenericReportExport($payload),
                $slug.'.xlsx',
                ExcelWriter::XLSX,
                [
                    'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'Cache-Control' => 'no-cache, no-store, must-revalidate',
                ],
            );
        }

        if ($format === 'pdf') {
            $pdf = Pdf::loadView('reports.generic', $payload);
            $pdf->setPaper('A4', $this->orientationFor($report));
            $pdf->setOptions(['defaultFont' => 'Arial', 'isRemoteEnabled' => true]);

            return response($pdf->output(), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="'.$slug.'.pdf"',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
            ]);
        }

        abort(404, 'Format tidak didukung.');
    }

    private function builderFor(string $report): ?string
    {
        return [
            'ringkasan' => 'buildRingkasan',
            'penjualan' => 'buildPenjualan',
            'pembelian' => 'buildPembelian',
            'stok' => 'buildStok',
            'selisih-stok' => 'buildSelisihStok',
            'kartu-stok' => 'buildKartuStok',
            'produksi' => 'buildProduksi',
            'shift-kasir' => 'buildShiftKasir',
            'neraca' => 'buildNeraca',
            'neraca-saldo' => 'buildNeracaSaldo',
            'laba-rugi' => 'buildLabaRugi',
            'ppn' => 'buildPpn',
            'calk' => 'buildCaLK',
            'beban' => 'buildBeban',
            'utang-usaha' => 'buildUtangUsaha',
            'cash-flow' => 'buildCashFlow',
            'absensi' => 'buildAbsensi',
        ][$report] ?? null;
    }

    private function orientationFor(string $report): string
    {
        return in_array($report, ['penjualan', 'pembelian', 'kartu-stok', 'shift-kasir', 'neraca-saldo'], true)
            ? 'landscape'
            : 'portrait';
    }

    /* =========================================================
     |  Helper umum
     * ========================================================= */

    private function resolveStoreIds(User $user, Request $request): array
    {
        $store = $user->store;
        if (! $store) {
            return [];
        }
        if ($user->isOwner()) {
            $storeId = $request->integer('store_id');
            if ($storeId) {
                $owned = $user->stores()->pluck('stores.id')->toArray();

                return in_array($storeId, $owned) ? [$storeId] : [$store->id];
            }

            // Default: gunakan store yang sedang aktif (sama seperti role lain)
            return [$store->id];
        }

        return [$store->id];
    }

    private function period(Request $request, string $default = '30d'): array
    {
        $start = $request->get('start_date')
            ?? $request->get('date_from')
            ?? ($default === 'month'
                ? Carbon::now()->startOfMonth()->format('Y-m-d')
                : Carbon::now()->subDays(30)->format('Y-m-d'));
        $end = $request->get('end_date')
            ?? $request->get('date_to')
            ?? Carbon::now()->format('Y-m-d');

        return [$start, $end];
    }

    private function rp($value): string
    {
        return 'Rp '.number_format((float) $value, 0, ',', '.');
    }

    private function qty($value): string
    {
        $n = (float) $value;

        return $n == (int) $n
            ? (string) (int) $n
            : rtrim(rtrim(number_format($n, 4, ',', '.'), '0'), ',');
    }

    private function periodMeta(string $start, string $end): array
    {
        return [[
            'label' => 'Periode',
            'value' => Carbon::parse($start)->translatedFormat('d M Y').' - '.Carbon::parse($end)->translatedFormat('d M Y'),
        ]];
    }

    /* =========================================================
     |  1. Ringkasan
     * ========================================================= */
    private function buildRingkasan(User $user, Store $store, Request $request): array
    {
        $storeIds = $this->resolveStoreIds($user, $request);
        [$start, $end] = $this->period($request);

        $completed = Transaction::whereIn('store_id', $storeIds)
            ->whereBetween('created_at', [$start, $end.' 23:59:59'])
            ->where('status', 'completed');

        $totalSales = (float) (clone $completed)->sum('total_amount');
        $totalTx = (int) (clone $completed)->count();
        $aov = $totalTx > 0 ? $totalSales / $totalTx : 0;

        $itemsSold = (float) DB::table('transaction_items')
            ->join('transactions', 'transaction_items.transaction_id', '=', 'transactions.id')
            ->whereIn('transactions.store_id', $storeIds)
            ->whereBetween('transactions.created_at', [$start, $end.' 23:59:59'])
            ->where('transactions.status', 'completed')
            ->sum('transaction_items.quantity');

        $payment = DB::table('transactions')
            ->whereIn('store_id', $storeIds)
            ->whereBetween('created_at', [$start, $end.' 23:59:59'])
            ->where('status', 'completed')
            ->selectRaw('COALESCE(payment_method, ?) as method, COUNT(*) as cnt, SUM(total_amount) as total', ['cash'])
            ->groupByRaw('COALESCE(payment_method, ?)', ['cash'])
            ->orderByDesc('total')
            ->get();

        $top = DB::table('transaction_items')
            ->join('transactions', 'transaction_items.transaction_id', '=', 'transactions.id')
            ->join('products', 'transaction_items.product_id', '=', 'products.id')
            ->whereIn('transactions.store_id', $storeIds)
            ->whereBetween('transactions.created_at', [$start, $end.' 23:59:59'])
            ->where('transactions.status', 'completed')
            ->selectRaw('products.name, SUM(transaction_items.quantity) as qty, SUM(transaction_items.total_price) as revenue')
            ->groupBy('products.name')
            ->orderByDesc('revenue')
            ->limit(15)
            ->get();

        return [
            'title' => 'Laporan Ringkasan Penjualan',
            'meta' => $this->periodMeta($start, $end),
            'sections' => [
                [
                    'type' => 'keyvalue', 'title' => 'Ringkasan',
                    'rows' => [
                        ['label' => 'Total Omzet', 'value' => $this->rp($totalSales), 'strong' => true],
                        ['label' => 'Jumlah Transaksi', 'value' => number_format($totalTx, 0, ',', '.').' transaksi'],
                        ['label' => 'Rata-rata per Transaksi', 'value' => $this->rp($aov)],
                        ['label' => 'Unit Terjual', 'value' => $this->qty($itemsSold)],
                    ],
                ],
                [
                    'type' => 'table', 'title' => 'Rincian Metode Pembayaran',
                    'headings' => ['Metode', 'Jumlah Transaksi', 'Total'],
                    'aligns' => ['left', 'right', 'right'],
                    'rows' => $payment->map(fn ($p) => [
                        $this->paymentLabel($p->method), number_format($p->cnt, 0, ',', '.'), $this->rp($p->total),
                    ])->all(),
                    'footer' => ['Total', number_format($payment->sum('cnt'), 0, ',', '.'), $this->rp($payment->sum('total'))],
                ],
                [
                    'type' => 'table', 'title' => 'Produk Terlaris (15 teratas)',
                    'headings' => ['No', 'Produk', 'Qty', 'Omzet'],
                    'aligns' => ['center', 'left', 'right', 'right'],
                    'rows' => $top->values()->map(fn ($p, $i) => [
                        $i + 1, $p->name, $this->qty($p->qty), $this->rp($p->revenue),
                    ])->all(),
                ],
            ],
        ];
    }

    /* =========================================================
     |  2. Penjualan (termasuk dimensi teknisi untuk barbershop)
     * ========================================================= */
    private function buildPenjualan(User $user, Store $store, Request $request): array
    {
        $storeIds = $this->resolveStoreIds($user, $request);
        [$start, $end] = $this->period($request);

        $barbershopEnabled = \App\Support\BarbershopFeatures::enabled($store);
        $barberUserId = $barbershopEnabled ? ($request->integer('barber_user_id') ?: null) : null;
        if ($barberUserId && $barbershopEnabled) {
            $isValid = \App\Models\User::barbersForStore($store)->pluck('id')->contains($barberUserId);
            if (! $isValid) {
                $barberUserId = null;
            }
        }

        $txQuery = Transaction::whereIn('store_id', $storeIds)
            ->whereBetween('created_at', [$start, $end.' 23:59:59'])
            ->where('status', 'completed')
            ->when($barberUserId, fn ($q) => $q->where('barber_user_id', $barberUserId));

        $tx = (clone $txQuery)
            ->with(['customer:id,name', 'store:id,name', 'barber:id,name'])
            ->latest()
            ->get();

        $hpp = DB::table('transaction_items')
            ->join('transactions', 'transaction_items.transaction_id', '=', 'transactions.id')
            ->whereIn('transactions.store_id', $storeIds)
            ->whereBetween('transactions.created_at', [$start, $end.' 23:59:59'])
            ->where('transactions.status', 'completed')
            ->when($barberUserId, fn ($q) => $q->where('transactions.barber_user_id', $barberUserId))
            ->selectRaw('COALESCE(SUM(total_price),0) rev, COALESCE(SUM(total_cost_snapshot),0) cost, COALESCE(SUM(gross_profit_snapshot),0) gp')
            ->first();

        $meta = $this->periodMeta($start, $end);
        $barberBreakdownSection = null;
        if ($barbershopEnabled) {
            if ($barberUserId) {
                $barberName = $tx->first()?->barber?->name ?? (User::find($barberUserId)?->name) ?? 'Teknisi #'.$barberUserId;
                $meta[] = ['label' => 'Teknisi', 'value' => $barberName];
            }

            // Ringkasan per teknisi selalu untuk seluruh periode (tidak ikut filter barber) — untuk rekonsiliasi.
            $barberBase = Transaction::whereIn('store_id', $storeIds)
                ->whereBetween('created_at', [$start, $end.' 23:59:59'])
                ->where('status', 'completed')
                ->whereNotNull('barber_user_id');

            $barberRows = $barberBase
                ->selectRaw('barber_user_id, COUNT(*) as tx_count, COALESCE(SUM(total_amount), 0) as gross, COALESCE(SUM(barber_commission_amount), 0) as commission')
                ->groupBy('barber_user_id')
                ->with('barber:id,name')
                ->get();

            $mapped = $barberRows->map(function ($row) {
                $gross = (float) $row->gross;

                return [
                    'barber_id' => (int) $row->barber_user_id,
                    'barber_name' => $row->barber?->name ?? 'Teknisi (nonaktif)',
                    'transaction_count' => (int) $row->tx_count,
                    'gross_revenue' => round($gross, 2),
                    'commission_amount' => round((float) $row->commission, 2),
                    'average_per_visit' => (int) $row->tx_count > 0 ? round($gross / (int) $row->tx_count, 2) : 0,
                ];
            })->sortByDesc('gross_revenue')->values();

            if ($mapped->isNotEmpty()) {
                $barberBreakdownSection = [
                    'type' => 'table', 'title' => 'Ringkasan per Teknisi',
                    'headings' => ['Teknisi', 'Transaksi', 'Omzet Kotor', 'Komisi', 'Rata-rata/kunjungan'],
                    'aligns' => ['left', 'right', 'right', 'right', 'right'],
                    'rows' => $mapped->map(fn ($r) => [
                        $r['barber_name'], number_format($r['transaction_count'], 0, ',', '.'), $this->rp($r['gross_revenue']), $this->rp($r['commission_amount']), $this->rp($r['average_per_visit']),
                    ])->all(),
                    'footer' => ['Total', number_format($mapped->sum('transaction_count'), 0, ',', '.'), $this->rp($mapped->sum('gross_revenue')), $this->rp($mapped->sum('commission_amount')), ''],
                ];
            }
        }

        $txHeadings = ['No', 'Nomor', 'Tanggal', 'Pelanggan', 'Metode', 'Total'];
        $txAligns = ['center', 'left', 'left', 'left', 'left', 'right'];
        if ($barbershopEnabled) {
            array_splice($txHeadings, 4, 0, ['Teknisi']);
            array_splice($txAligns, 4, 0, ['left']);
        }

        $txRows = $tx->values()->map(function ($t, $i) use ($barbershopEnabled) {
            $base = [
                $i + 1,
                $t->transaction_number,
                Carbon::parse($t->created_at)->format('d/m/Y H:i'),
                $t->customer?->name ?? 'Pelanggan umum',
            ];
            if ($barbershopEnabled) {
                $base[] = $t->barber?->name ?? '—';
            }
            $base[] = $this->paymentLabel($t->payment_method ?? 'cash');
            $base[] = $this->rp($t->total_amount);

            return $base;
        })->all();

        $txFooter = $barbershopEnabled
            ? ['', '', '', '', '', 'Total', $this->rp($tx->sum('total_amount'))]
            : ['', '', '', '', 'Total', $this->rp($tx->sum('total_amount'))];

        return [
            'title' => 'Laporan Penjualan',
            'meta' => $meta,
            'sections' => array_values(array_filter([
                [
                    'type' => 'keyvalue', 'title' => 'Ringkasan Laba Kotor',
                    'rows' => [
                        ['label' => 'Total Penjualan (item)', 'value' => $this->rp($hpp->rev)],
                        ['label' => 'Total HPP', 'value' => $this->rp($hpp->cost)],
                        ['label' => 'Laba Kotor', 'value' => $this->rp($hpp->gp), 'strong' => true],
                    ],
                ],
                $barberBreakdownSection,
                [
                    'type' => 'table', 'title' => 'Daftar Transaksi',
                    'headings' => $txHeadings,
                    'aligns' => $txAligns,
                    'rows' => $txRows,
                    'footer' => $txFooter,
                ],
            ])),
        ];
    }

    /* =========================================================
     |  3. Pembelian
     * ========================================================= */
    private function buildPembelian(User $user, Store $store, Request $request): array
    {
        $storeIds = $this->resolveStoreIds($user, $request);
        [$start, $end] = $this->period($request);
        $rangeStart = Carbon::parse($start)->startOfDay();
        $rangeEnd = Carbon::parse($end)->endOfDay();

        $purchases = \App\Models\Purchase::whereIn('store_id', $storeIds)
            ->with(['supplier:id,name'])
            ->whereBetween('purchased_at', [$rangeStart, $rangeEnd])
            ->orderByDesc('purchased_at')
            ->get();

        $sum = \App\Models\Purchase::whereIn('store_id', $storeIds)
            ->whereBetween('purchased_at', [$rangeStart, $rangeEnd])
            ->selectRaw('COALESCE(SUM(dpp_amount),0) dpp, COALESCE(SUM(charges_amount),0) charges, COALESCE(SUM(ppn_amount),0) ppn, COALESCE(SUM(total_amount),0) total')
            ->first();

        return [
            'title' => 'Laporan Pembelian',
            'meta' => $this->periodMeta($start, $end),
            'sections' => [
                [
                    'type' => 'keyvalue', 'title' => 'Ringkasan',
                    'rows' => [
                        ['label' => 'Jumlah Pembelian', 'value' => number_format($purchases->count(), 0, ',', '.').' dokumen'],
                        ['label' => 'Total DPP', 'value' => $this->rp($sum->dpp)],
                        ['label' => 'Total Biaya', 'value' => $this->rp($sum->charges)],
                        ['label' => 'Total PPN Masukan', 'value' => $this->rp($sum->ppn)],
                        ['label' => 'Total Nilai Pembelian', 'value' => $this->rp($sum->total), 'strong' => true],
                    ],
                ],
                [
                    'type' => 'table', 'title' => 'Daftar Pembelian',
                    'headings' => ['No', 'Nomor', 'Tanggal', 'Supplier', 'DPP', 'PPN', 'Total'],
                    'aligns' => ['center', 'left', 'left', 'left', 'right', 'right', 'right'],
                    'rows' => $purchases->values()->map(fn ($p, $i) => [
                        $i + 1,
                        $p->purchase_number,
                        optional($p->purchased_at)->format('d/m/Y') ?? '',
                        $p->supplier?->name ?? 'Tanpa supplier',
                        $this->rp($p->dpp_amount),
                        $this->rp($p->ppn_amount),
                        $this->rp($p->total_amount),
                    ])->all(),
                    'footer' => ['', '', '', 'Total', $this->rp($sum->dpp), $this->rp($sum->ppn), $this->rp($sum->total)],
                ],
            ],
        ];
    }

    /* =========================================================
     |  4. Stok
     * ========================================================= */
    private function buildStok(User $user, Store $store, Request $request): array
    {
        $products = $store->products()
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'unit', 'stock_quantity', 'min_stock_level', 'average_cost', 'track_stock']);

        $stats = $store->products()->selectRaw('
            COUNT(*) total,
            COALESCE(SUM(CASE WHEN track_stock=1 THEN stock_quantity ELSE 0 END),0) qty,
            COALESCE(SUM(CASE WHEN track_stock=1 THEN stock_quantity*average_cost ELSE 0 END),0) value
        ')->first();

        return [
            'title' => 'Laporan Stok',
            'meta' => [['label' => 'Per tanggal', 'value' => Carbon::now()->translatedFormat('d M Y')]],
            'sections' => [
                [
                    'type' => 'keyvalue', 'title' => 'Ringkasan Persediaan',
                    'rows' => [
                        ['label' => 'Total Produk', 'value' => number_format($stats->total, 0, ',', '.')],
                        ['label' => 'Total Kuantitas Stok', 'value' => $this->qty($stats->qty)],
                        ['label' => 'Total Nilai Persediaan (HPP)', 'value' => $this->rp($stats->value), 'strong' => true],
                    ],
                ],
                [
                    'type' => 'table', 'title' => 'Daftar Produk',
                    'headings' => ['No', 'Nama', 'SKU', 'Stok', 'Satuan', 'HPP/unit', 'Nilai'],
                    'aligns' => ['center', 'left', 'left', 'right', 'left', 'right', 'right'],
                    'rows' => $products->values()->map(fn ($p, $i) => [
                        $i + 1,
                        $p->name,
                        $p->sku ?? '-',
                        $p->track_stock ? $this->qty($p->stock_quantity) : '∞',
                        $p->unit ?? '',
                        $this->rp($p->average_cost),
                        $p->track_stock ? $this->rp((float) $p->stock_quantity * (float) $p->average_cost) : '-',
                    ])->all(),
                ],
            ],
        ];
    }

    /* =========================================================
     |  5. Selisih stok
     * ========================================================= */
    private function buildSelisihStok(User $user, Store $store, Request $request): array
    {
        [$start, $end] = $this->period($request, 'month');

        $documents = StockAdjustment::where('store_id', $store->id)
            ->where('status', StockAdjustment::STATUS_POSTED)
            ->whereDate('posted_at', '>=', $start)
            ->whereDate('posted_at', '<=', $end);

        $documentIds = (clone $documents)->pluck('id');

        $byProduct = StockAdjustmentLine::whereIn('stock_adjustment_id', $documentIds)
            ->where('variance_quantity', '!=', 0)
            ->with(['product:id,name,sku,unit'])
            ->selectRaw('product_id, SUM(variance_quantity) qty, SUM(variance_value) value')
            ->groupBy('product_id')
            ->get();

        $loss = $byProduct->where('value', '<', 0)->sum('value');
        $gain = $byProduct->where('value', '>', 0)->sum('value');

        return [
            'title' => 'Laporan Selisih Stok',
            'meta' => $this->periodMeta($start, $end),
            'sections' => [
                [
                    'type' => 'keyvalue', 'title' => 'Ringkasan',
                    'rows' => [
                        ['label' => 'Jumlah Dokumen', 'value' => number_format((clone $documents)->count(), 0, ',', '.')],
                        ['label' => 'Item Terdampak', 'value' => number_format($byProduct->count(), 0, ',', '.')],
                        ['label' => 'Nilai Kerugian', 'value' => $this->rp($loss)],
                        ['label' => 'Nilai Keuntungan', 'value' => $this->rp($gain)],
                        ['label' => 'Selisih Bersih', 'value' => $this->rp($loss + $gain), 'strong' => true],
                    ],
                ],
                [
                    'type' => 'table', 'title' => 'Selisih per Produk',
                    'headings' => ['No', 'Produk', 'SKU', 'Selisih Qty', 'Nilai'],
                    'aligns' => ['center', 'left', 'left', 'right', 'right'],
                    'rows' => $byProduct->values()->map(fn ($r, $i) => [
                        $i + 1,
                        $r->product?->name ?? '—',
                        $r->product?->sku ?? '-',
                        $this->qty($r->qty),
                        $this->rp($r->value),
                    ])->all(),
                ],
            ],
        ];
    }

    /* =========================================================
     |  6. Kartu stok
     * ========================================================= */
    private function buildKartuStok(User $user, Store $store, Request $request): array
    {
        [$start, $end] = $this->period($request);

        $productId = $request->integer('product_id')
            ?: $store->products()->where('track_stock', true)->orderBy('name')->value('id');

        $product = $productId ? $store->products()->find($productId) : null;

        $meta = $this->periodMeta($start, $end);
        if ($product) {
            $meta[] = ['label' => 'Produk', 'value' => $product->name.($product->sku ? ' ('.$product->sku.')' : '')];
        }

        $movements = collect();
        $opening = 0.0;
        if ($product) {
            $prev = StockMovement::where('store_id', $store->id)
                ->where('product_id', $product->id)
                ->whereDate('created_at', '<', $start)
                ->orderByDesc('created_at')->orderByDesc('id')->first();
            $opening = $prev ? (float) $prev->balance_after : 0.0;

            $movements = StockMovement::where('store_id', $store->id)
                ->where('product_id', $product->id)
                ->whereDate('created_at', '>=', $start)
                ->whereDate('created_at', '<=', $end)
                ->orderBy('created_at')->orderBy('id')
                ->get();
        }

        $totalIn = $movements->where('quantity_change', '>', 0)->sum('quantity_change');
        $totalOut = abs($movements->where('quantity_change', '<', 0)->sum('quantity_change'));
        $closing = $movements->isNotEmpty() ? (float) $movements->last()->balance_after : $opening;

        return [
            'title' => 'Kartu Stok',
            'meta' => $meta,
            'sections' => [
                [
                    'type' => 'keyvalue', 'title' => 'Ringkasan',
                    'rows' => [
                        ['label' => 'Saldo Awal', 'value' => $this->qty($opening)],
                        ['label' => 'Total Masuk', 'value' => $this->qty($totalIn)],
                        ['label' => 'Total Keluar', 'value' => $this->qty($totalOut)],
                        ['label' => 'Saldo Akhir', 'value' => $this->qty($closing), 'strong' => true],
                    ],
                ],
                [
                    'type' => 'table', 'title' => 'Mutasi',
                    'headings' => ['Tanggal', 'Jenis', 'Perubahan', 'Saldo', 'Catatan'],
                    'aligns' => ['left', 'left', 'right', 'right', 'left'],
                    'rows' => $movements->values()->map(fn ($m) => [
                        Carbon::parse($m->created_at)->format('d/m/Y H:i'),
                        $this->movementLabel($m->type),
                        $this->qty($m->quantity_change),
                        $this->qty($m->balance_after),
                        $m->notes ?? '',
                    ])->all(),
                ],
            ],
        ];
    }

    /* =========================================================
     |  7. Produksi
     * ========================================================= */
    private function buildProduksi(User $user, Store $store, Request $request): array
    {
        [$start, $end] = $this->period($request);

        $batches = \App\Models\ProductionBatch::where('store_id', $store->id)
            ->with(['product:id,name'])
            ->whereBetween('produced_at', [$start, $end.' 23:59:59'])
            ->orderByDesc('produced_at')
            ->get();

        $sum = \App\Models\ProductionBatch::where('store_id', $store->id)
            ->whereBetween('produced_at', [$start, $end.' 23:59:59'])
            ->where('status', 'completed')
            ->selectRaw('COUNT(*) batches, COALESCE(SUM(output_quantity_initial),0) output, COALESCE(SUM(total_cost),0) cost')
            ->first();

        return [
            'title' => 'Laporan Produksi',
            'meta' => $this->periodMeta($start, $end),
            'sections' => [
                [
                    'type' => 'keyvalue', 'title' => 'Ringkasan',
                    'rows' => [
                        ['label' => 'Total Batch', 'value' => number_format($sum->batches, 0, ',', '.')],
                        ['label' => 'Total Output', 'value' => $this->qty($sum->output)],
                        ['label' => 'Total Biaya Produksi', 'value' => $this->rp($sum->cost), 'strong' => true],
                        ['label' => 'Rata-rata HPP/unit', 'value' => $this->rp($sum->output > 0 ? $sum->cost / $sum->output : 0)],
                    ],
                ],
                [
                    'type' => 'table', 'title' => 'Daftar Batch Produksi',
                    'headings' => ['No', 'Tanggal', 'Produk', 'Output', 'Total Biaya', 'Status'],
                    'aligns' => ['center', 'left', 'left', 'right', 'right', 'left'],
                    'rows' => $batches->values()->map(fn ($b, $i) => [
                        $i + 1,
                        optional($b->produced_at)->format('d/m/Y') ?? '',
                        $b->product?->name ?? '—',
                        $this->qty($b->output_quantity_initial),
                        $this->rp($b->total_cost),
                        ucfirst($b->status),
                    ])->all(),
                ],
            ],
        ];
    }

    /* =========================================================
     |  8. Shift kasir
     * ========================================================= */
    private function buildShiftKasir(User $user, Store $store, Request $request): array
    {
        [$start, $end] = $this->period($request);
        $rangeStart = Carbon::parse($start)->startOfDay();
        $rangeEnd = Carbon::parse($end)->endOfDay();

        $shifts = CashShift::where('store_id', $store->id)
            ->whereNotNull('closed_at')
            ->whereBetween('closed_at', [$rangeStart, $rangeEnd])
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
            ->with(['user:id,name'])
            ->orderByDesc('closed_at')
            ->get();

        $cashByShift = Transaction::whereIn('cash_shift_id', $shifts->pluck('id'))
            ->where('status', 'completed')
            ->selectRaw('cash_shift_id, SUM(cash_amount) total')
            ->groupBy('cash_shift_id')->pluck('total', 'cash_shift_id');

        return [
            'title' => 'Laporan Shift Kasir',
            'meta' => $this->periodMeta($start, $end),
            'sections' => [
                [
                    'type' => 'keyvalue', 'title' => 'Ringkasan',
                    'rows' => [
                        ['label' => 'Shift Tutup', 'value' => number_format($shifts->count(), 0, ',', '.')],
                        ['label' => 'Total Penjualan Tunai', 'value' => $this->rp($cashByShift->sum()), 'strong' => true],
                        ['label' => 'Total Selisih', 'value' => $this->rp($shifts->sum('difference_amount'))],
                    ],
                ],
                [
                    'type' => 'table', 'title' => 'Daftar Shift',
                    'headings' => ['No', 'Shift', 'Kasir', 'Buka', 'Tutup', 'Kas Awal', 'Tunai', 'Selisih'],
                    'aligns' => ['center', 'left', 'left', 'left', 'left', 'right', 'right', 'right'],
                    'rows' => $shifts->values()->map(fn ($s, $i) => [
                        $i + 1,
                        $s->shift_number,
                        $s->user?->name ?? '—',
                        optional($s->opened_at)->format('d/m/Y H:i') ?? '',
                        optional($s->closed_at)->format('d/m/Y H:i') ?? '',
                        $this->rp($s->opening_cash),
                        $this->rp($cashByShift[$s->id] ?? 0),
                        $s->difference_amount !== null ? $this->rp($s->difference_amount) : '-',
                    ])->all(),
                ],
            ],
        ];
    }

    /* =========================================================
     |  9. Neraca
     * ========================================================= */
    private function buildNeraca(User $user, Store $store, Request $request): array
    {
        ChartOfAccountsBootstrap::ensureForStore($store);
        $asOf = $request->get('as_of', Carbon::now()->format('Y-m-d'));

        $rows = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'jl.journal_entry_id', '=', 'je.id')
            ->join('chart_of_accounts as coa', 'jl.chart_of_account_id', '=', 'coa.id')
            ->where('je.store_id', $store->id)
            ->whereDate('je.entry_date', '<=', $asOf)
            ->selectRaw('coa.code, coa.name, coa.type, coa.balance_sheet_group, SUM(jl.debit) dr, SUM(jl.credit) cr')
            ->groupBy('coa.code', 'coa.name', 'coa.type', 'coa.balance_sheet_group')
            ->orderBy('coa.code')->get();

        $assets = [
            ChartOfAccount::GROUP_CURRENT_ASSET => ['label' => 'Aset Lancar', 'rows' => [], 'subtotal' => 0.0],
            ChartOfAccount::GROUP_NON_CURRENT_ASSET => ['label' => 'Aset Tidak Lancar', 'rows' => [], 'subtotal' => 0.0],
        ];
        $liabilities = [
            ChartOfAccount::GROUP_CURRENT_LIABILITY => ['label' => 'Liabilitas Jangka Pendek', 'rows' => [], 'subtotal' => 0.0],
            ChartOfAccount::GROUP_NON_CURRENT_LIABILITY => ['label' => 'Liabilitas Jangka Panjang', 'rows' => [], 'subtotal' => 0.0],
        ];
        $equity = [];
        $ta = $tl = $te = $rev = $cogs = $exp = 0.0;

        foreach ($rows as $r) {
            $dr = (float) $r->dr;
            $cr = (float) $r->cr;
            switch ($r->type) {
                case ChartOfAccount::TYPE_ASSET:
                    $balance = $dr - $cr;
                    $group = $r->balance_sheet_group ?: ChartOfAccount::GROUP_CURRENT_ASSET;
                    $assets[$group] ??= ['label' => $group, 'rows' => [], 'subtotal' => 0.0];
                    $assets[$group]['rows'][] = [$r->code, $r->name, $balance];
                    $assets[$group]['subtotal'] += $balance;
                    $ta += $balance;
                    break;
                case ChartOfAccount::TYPE_LIABILITY:
                    $balance = $cr - $dr;
                    $group = $r->balance_sheet_group ?: ChartOfAccount::GROUP_CURRENT_LIABILITY;
                    $liabilities[$group] ??= ['label' => $group, 'rows' => [], 'subtotal' => 0.0];
                    $liabilities[$group]['rows'][] = [$r->code, $r->name, $balance];
                    $liabilities[$group]['subtotal'] += $balance;
                    $tl += $balance;
                    break;
                case ChartOfAccount::TYPE_EQUITY:
                    $balance = $cr - $dr;
                    $equity[] = [$r->code, $r->name, $balance];
                    $te += $balance;
                    break;
                case ChartOfAccount::TYPE_REVENUE: $rev += $cr - $dr;
                    break;
                case ChartOfAccount::TYPE_COGS: $cogs += $dr - $cr;
                    break;
                case ChartOfAccount::TYPE_EXPENSE: $exp += $dr - $cr;
                    break;
            }
        }

        $laba = $rev - $cogs - $exp;
        $equity[] = ['3.9.99', 'Laba (rugi) berjalan', $laba];
        $te += $laba;
        $sections = [];

        foreach ($assets as $group) {
            $sections[] = [
                'type' => 'table', 'title' => 'ASET — '.$group['label'],
                'headings' => ['Kode', 'Akun', 'Saldo'], 'aligns' => ['left', 'left', 'right'],
                'rows' => array_map(fn ($row) => [$row[0], $row[1], $this->rp($row[2])], $group['rows']),
                'footer' => ['', 'Total '.$group['label'], $this->rp($group['subtotal'])],
            ];
        }
        $sections[] = [
            'type' => 'table', 'title' => 'TOTAL ASET',
            'headings' => ['Kode', 'Akun', 'Saldo'], 'aligns' => ['left', 'left', 'right'],
            'rows' => [], 'footer' => ['', 'Total Aset', $this->rp($ta)],
        ];
        foreach ($liabilities as $group) {
            $sections[] = [
                'type' => 'table', 'title' => 'LIABILITAS — '.$group['label'],
                'headings' => ['Kode', 'Akun', 'Saldo'], 'aligns' => ['left', 'left', 'right'],
                'rows' => array_map(fn ($row) => [$row[0], $row[1], $this->rp($row[2])], $group['rows']),
                'footer' => ['', 'Total '.$group['label'], $this->rp($group['subtotal'])],
            ];
        }
        $sections[] = [
            'type' => 'table', 'title' => 'EKUITAS',
            'headings' => ['Kode', 'Akun', 'Saldo'], 'aligns' => ['left', 'left', 'right'],
            'rows' => array_map(fn ($row) => [$row[0], $row[1], $this->rp($row[2])], $equity),
            'footer' => ['', 'Total Ekuitas', $this->rp($te)],
        ];
        $sections[] = [
            'type' => 'keyvalue', 'title' => 'Keseimbangan',
            'rows' => [
                ['label' => 'Total Aset', 'value' => $this->rp($ta)],
                ['label' => 'Total Liabilitas + Ekuitas', 'value' => $this->rp($tl + $te)],
                ['label' => 'Selisih', 'value' => $this->rp($ta - ($tl + $te)), 'strong' => true],
            ],
        ];

        return [
            'title' => 'Neraca (Laporan Posisi Keuangan)',
            'meta' => [['label' => 'Per tanggal', 'value' => Carbon::parse($asOf)->translatedFormat('d M Y')]],
            'sections' => $sections,
        ];
    }

    /* =========================================================
     |  10. Neraca saldo (Trial Balance)
     * ========================================================= */
    private function buildNeracaSaldo(User $user, Store $store, Request $request): array
    {
        ChartOfAccountsBootstrap::ensureForStore($store);
        [$start, $end] = $this->period($request, 'month');

        $rows = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'jl.journal_entry_id', '=', 'je.id')
            ->join('chart_of_accounts as coa', 'jl.chart_of_account_id', '=', 'coa.id')
            ->where('je.store_id', $store->id)
            ->whereBetween('je.entry_date', [$start, $end])
            ->selectRaw('coa.code, coa.name, SUM(jl.debit) dr, SUM(jl.credit) cr')
            ->groupBy('coa.code', 'coa.name')
            ->orderBy('coa.code')->get();

        return [
            'title' => 'Neraca Saldo (Trial Balance)',
            'meta' => $this->periodMeta($start, $end),
            'sections' => [
                [
                    'type' => 'table', 'title' => 'Mutasi per Akun',
                    'headings' => ['Kode', 'Nama Akun', 'Debit', 'Kredit'],
                    'aligns' => ['left', 'left', 'right', 'right'],
                    'rows' => $rows->map(fn ($r) => [
                        $r->code, $r->name, $this->rp($r->dr), $this->rp($r->cr),
                    ])->all(),
                    'footer' => ['', 'TOTAL', $this->rp($rows->sum('dr')), $this->rp($rows->sum('cr'))],
                ],
            ],
        ];
    }

    /* =========================================================
     |  11. Laba rugi
     * ========================================================= */
    private function buildLabaRugi(User $user, Store $store, Request $request): array
    {
        ChartOfAccountsBootstrap::ensureForStore($store);
        [$start, $end] = $this->period($request, 'month');

        $agg = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'jl.journal_entry_id', '=', 'je.id')
            ->join('chart_of_accounts as coa', 'jl.chart_of_account_id', '=', 'coa.id')
            ->where('je.store_id', $store->id)
            ->whereBetween('je.entry_date', [$start, $end])
            ->selectRaw('coa.type, SUM(jl.debit) dr, SUM(jl.credit) cr')
            ->groupBy('coa.type')->get()->keyBy('type');

        $rev = isset($agg['revenue']) ? (float) $agg['revenue']->cr - (float) $agg['revenue']->dr : 0.0;
        $cogs = isset($agg['cogs']) ? (float) $agg['cogs']->dr - (float) $agg['cogs']->cr : 0.0;
        $exp = isset($agg['expense']) ? (float) $agg['expense']->dr - (float) $agg['expense']->cr : 0.0;
        $gross = $rev - $cogs;
        $net = $gross - $exp;

        return [
            'title' => 'Laporan Laba Rugi',
            'meta' => $this->periodMeta($start, $end),
            'sections' => [
                [
                    'type' => 'keyvalue', 'title' => 'Laba Rugi',
                    'rows' => [
                        ['label' => 'Pendapatan (DPP)', 'value' => $this->rp($rev)],
                        ['label' => 'Harga Pokok Penjualan (HPP)', 'value' => '('.$this->rp($cogs).')'],
                        ['label' => 'Laba Kotor', 'value' => $this->rp($gross), 'strong' => true],
                        ['label' => 'Beban Operasional', 'value' => '('.$this->rp($exp).')'],
                        ['label' => 'Laba (Rugi) Bersih', 'value' => $this->rp($net), 'strong' => true],
                    ],
                ],
            ],
        ];
    }

    /* =========================================================
     |  12. Rekap PPN
     * ========================================================= */
    private function buildPpn(User $user, Store $store, Request $request): array
    {
        ChartOfAccountsBootstrap::ensureForStore($store);
        [$start, $end] = $this->period($request, 'month');

        $ppnAt = function (string $code) use ($store, $start, $end) {
            $r = DB::table('journal_lines as jl')
                ->join('journal_entries as je', 'jl.journal_entry_id', '=', 'je.id')
                ->join('chart_of_accounts as coa', 'jl.chart_of_account_id', '=', 'coa.id')
                ->where('je.store_id', $store->id)
                ->whereBetween('je.entry_date', [$start, $end])
                ->where('coa.code', $code)
                ->selectRaw('COALESCE(SUM(jl.debit),0) dr, COALESCE(SUM(jl.credit),0) cr')->first();

            return [(float) $r->dr, (float) $r->cr];
        };

        [$kelDr, $kelCr] = $ppnAt(ChartOfAccountsBootstrap::CODE_UTANG_PPN_KELUARAN);
        [$masDr, $masCr] = $ppnAt(ChartOfAccountsBootstrap::CODE_PPN_MASUKAN);
        $keluaran = $kelCr - $kelDr;
        $masukan = $masDr - $masCr;

        return [
            'title' => 'Rekap PPN',
            'meta' => $this->periodMeta($start, $end),
            'sections' => [
                [
                    'type' => 'keyvalue', 'title' => 'Posisi PPN',
                    'rows' => [
                        ['label' => 'PPN Keluaran (Penjualan)', 'value' => $this->rp($keluaran)],
                        ['label' => 'PPN Masukan (Pembelian)', 'value' => $this->rp($masukan)],
                        ['label' => ($keluaran - $masukan) >= 0 ? 'PPN Kurang Bayar' : 'PPN Lebih Bayar', 'value' => $this->rp(abs($keluaran - $masukan)), 'strong' => true],
                    ],
                ],
            ],
        ];
    }

    /* =========================================================
     |  13. CaLK (Catatan atas Laporan Keuangan)
     * ========================================================= */
    private function buildCaLK(User $user, Store $store, Request $request): array
    {
        $asOf = $request->get('as_of', Carbon::now()->format('Y-m-d'));
        $productName = DB::table('products')->where('store_id', $store->id)->count();
        $totalProducts = DB::table('products')->where('store_id', $store->id)->sum('stock_quantity');
        $totalValue = DB::table('products')
            ->where('store_id', $store->id)
            ->where('track_stock', true)
            ->sum(DB::raw('stock_quantity * average_cost'));

        // Hitung jumlah jurnal di periode berjalan
        $journalCount = DB::table('journal_entries')
            ->where('store_id', $store->id)
            ->whereYear('entry_date', date('Y'))
            ->count();

        $currencyLabel = $store->currency ?? 'IDR';

        return [
            'title' => 'Catatan atas Laporan Keuangan (CaLK)',
            'meta' => [['label' => 'Per tanggal', 'value' => Carbon::parse($asOf)->translatedFormat('d M Y')]],
            'sections' => [
                [
                    'type' => 'note', 'title' => 'Kebijakan Akuntansi',
                    'text' => 'Laporan keuangan ini disusun berdasarkan Standar Akuntansi Keuangan'
                        .' untuk Entitas Mikro, Kecil, dan Menengah (SAK EMKM).'
                        ."\n".'Metode pencatatan: Double-entry bookkeeping (jurnal debit-kredit).'
                        ."\n".'Metode costing persediaan: Harga Pokok Penjualan berdasarkan biaya perolehan terakhir (average cost).'
                        ."\n".'Aset dilaporkan berdasarkan biaya perolehan (historical cost).'
                        ."\n".'Mata uang pelaporan: '.$currencyLabel,
                ],
                [
                    'type' => 'keyvalue', 'title' => 'Rincian Entitas',
                    'rows' => [
                        ['label' => 'Nama Usaha', 'value' => $store->name],
                        ['label' => 'Jumlah Produk', 'value' => number_format($productName, 0, ',', '.')],
                        ['label' => 'Total Unit Persediaan', 'value' => $this->qty($totalProducts)],
                        ['label' => 'Nilai Persediaan (HPP)', 'value' => $this->rp($totalValue)],
                        ['label' => 'Jumlah Jurnal (Tahun Berjalan)', 'value' => number_format($journalCount, 0, ',', '.')],
                        ['label' => 'Tanggal Penyusunan', 'value' => Carbon::now()->translatedFormat('d F Y')],
                    ],
                ],
                [
                    'type' => 'note', 'title' => 'Catatan Penting',
                    'text' => 'CaLK ini merupakan bagian integral dari laporan keuangan.'
                        ."\n".'Seluruh transaksi penjualan, pembelian, dan penyesuaian stok telah dicatat'
                        .' secara otomatis melalui sistem jurnal double-entry.'
                        ."\n".'Laporan ini bukan pengganti audit independen. Disarankan untuk'
                        .' melakukan evaluasi berkala terhadap catatan akuntansi ini.',
                ],
            ],
        ];
    }

    /* =========================================================
     |  14. Beban Operasional
     * ========================================================= */
    private function buildBeban(User $user, Store $store, Request $request): array
    {
        [$start, $end] = $this->period($request, 'month');

        $expenses = \App\Models\OperationalExpense::where('store_id', $store->id)
            ->where('expense_date', '>=', $start)
            ->where('expense_date', '<=', $end)
            ->with('user:id,name')
            ->orderByDesc('expense_date')
            ->get();

        $total = (float) $expenses->sum('amount');
        $byCategory = $expenses->groupBy('category')->map(fn ($items, $cat) => [
            'category' => \App\Models\OperationalExpense::CATEGORIES[$cat] ?? ucfirst($cat),
            'total' => (float) $items->sum('amount'),
            'count' => $items->count(),
        ])->values();

        $catLabel = function (string $cat) {
            return \App\Models\OperationalExpense::CATEGORIES[$cat] ?? ucfirst($cat);
        };

        return [
            'title' => 'Laporan Biaya Operasional',
            'meta' => $this->periodMeta($start, $end),
            'sections' => [
                [
                    'type' => 'keyvalue', 'title' => 'Ringkasan',
                    'rows' => [
                        ['label' => 'Total Biaya', 'value' => $this->rp($total), 'strong' => true],
                        ['label' => 'Jumlah Transaksi', 'value' => number_format($expenses->count(), 0, ',', '.')],
                        ['label' => 'Rata-rata per Transaksi', 'value' => $this->rp($expenses->count() > 0 ? $total / $expenses->count() : 0)],
                    ],
                ],
                [
                    'type' => 'table', 'title' => 'Rincian per Kategori',
                    'headings' => ['Kategori', 'Jumlah', 'Total'],
                    'aligns' => ['left', 'right', 'right'],
                    'rows' => $byCategory->map(fn ($c) => [
                        $c['category'], number_format($c['count'], 0, ',', '.'), $this->rp($c['total']),
                    ])->all(),
                    'footer' => ['Total', number_format($expenses->count(), 0, ',', '.'), $this->rp($total)],
                ],
                [
                    'type' => 'table', 'title' => 'Daftar Beban',
                    'headings' => ['No', 'Tanggal', 'Kategori', 'Deskripsi', 'Oleh', 'Jumlah'],
                    'aligns' => ['center', 'left', 'left', 'left', 'left', 'right'],
                    'rows' => $expenses->values()->map(fn ($e, $i) => [
                        $i + 1,
                        $e->expense_date instanceof \Carbon\Carbon
                            ? $e->expense_date->format('d/m/Y')
                            : \Carbon\Carbon::parse($e->expense_date)->format('d/m/Y'),
                        $catLabel($e->category),
                        $e->description,
                        $e->user?->name ?? '-',
                        $this->rp($e->amount),
                    ])->all(),
                ],
            ],
        ];
    }

    /* =========================================================
     |  15. Utang Usaha (Outstanding Payables)
     * ========================================================= */
    private function buildUtangUsaha(User $user, Store $store, Request $request): array
    {
        $asOf = $request->get('as_of', Carbon::now()->format('Y-m-d'));

        $purchases = \App\Models\Purchase::where('store_id', $store->id)
            ->where('is_paid', false)
            ->whereNotNull('total_amount')
            ->with('supplier:id,name')
            ->orderBy('due_date')
            ->get();

        $totalOutstanding = 0;
        $overdue = 0;
        $current = 0;

        $rows = $purchases->values()->map(function ($p) use ($asOf, &$totalOutstanding, &$overdue, &$current) {
            $total = (float) $p->total_amount;
            $paid = (float) $p->amount_paid;
            $remaining = max(0, $total - $paid);
            $totalOutstanding += $remaining;

            $due = $p->due_date?->format('Y-m-d');
            $ageDays = $due ? Carbon::parse($due)->diffInDays(Carbon::parse($asOf), false) : null;
            $status = 'Belum jatuh tempo';
            if ($ageDays !== null && $ageDays < 0) {
                $status = 'Jatuh tempo ('.abs($ageDays).' hari lalu)';
                $overdue += $remaining;
            } elseif ($ageDays !== null && $ageDays === 0) {
                $status = 'Jatuh tempo hari ini';
                $current += $remaining;
            } elseif ($due) {
                $current += $remaining;
            }

            return [
                $p->purchase_number,
                $p->supplier?->name ?? '—',
                $p->purchased_at instanceof Carbon ? $p->purchased_at->format('d/m/Y') : '',
                $due ?? '—',
                $this->rp($total),
                $this->rp($paid),
                $this->rp($remaining),
                $status,
            ];
        });

        return [
            'title' => 'Laporan Utang Usaha (Outstanding Payables)',
            'meta' => [['label' => 'Per tanggal', 'value' => Carbon::parse($asOf)->translatedFormat('d M Y')]],
            'sections' => [
                [
                    'type' => 'keyvalue', 'title' => 'Ringkasan',
                    'rows' => [
                        ['label' => 'Total Utang Outstanding', 'value' => $this->rp($totalOutstanding), 'strong' => true],
                        ['label' => 'Jumlah Pembelian Belum Lunas', 'value' => number_format($purchases->count(), 0, ',', '.')],
                        ['label' => 'Nilai Jatuh Tempo', 'value' => $this->rp($overdue)],
                        ['label' => 'Nilai Belum Jatuh Tempo', 'value' => $this->rp($current)],
                    ],
                ],
                [
                    'type' => 'table', 'title' => 'Rincian Utang Usaha',
                    'headings' => ['No', 'Pembelian', 'Supplier', 'Tanggal', 'Jatuh Tempo', 'Total', 'Dibayar', 'Sisa Utang', 'Status'],
                    'aligns' => ['center', 'left', 'left', 'left', 'left', 'right', 'right', 'right', 'left'],
                    'rows' => $rows->values()->all(),
                    'footer' => ['', '', '', '', '', '', '', $this->rp($totalOutstanding), ''],
                ],
            ],
        ];
    }

    /* =========================================================
     |  16. Cash Flow per Shift
     * ========================================================= */
    private function buildCashFlow(User $user, Store $store, Request $request): array
    {
        [$start, $end] = $this->period($request, 'month');

        $shifts = \App\Models\CashShift::where('store_id', $store->id)
            ->whereNotNull('closed_at')
            ->whereDate('closed_at', '>=', $start)
            ->whereDate('closed_at', '<=', $end)
            ->with('user:id,name')
            ->get();

        $shiftIds = $shifts->pluck('id');

        $txByShift = Transaction::whereIn('cash_shift_id', $shiftIds)
            ->where('status', 'completed')
            ->selectRaw('cash_shift_id, payment_method, COUNT(*) as count, SUM(total_amount) as total, SUM(cash_amount) as cash_total')
            ->groupBy('cash_shift_id', 'payment_method')
            ->get()
            ->groupBy('cash_shift_id');

        $cashInByShift = \App\Models\CashTransaction::whereIn('cash_shift_id', $shiftIds)
            ->where('type', 'in')
            ->selectRaw('cash_shift_id, SUM(amount) as total')
            ->groupBy('cash_shift_id')
            ->pluck('total', 'cash_shift_id');

        $cashOutByShift = \App\Models\CashTransaction::whereIn('cash_shift_id', $shiftIds)
            ->where('type', 'out')
            ->selectRaw('cash_shift_id, SUM(amount) as total')
            ->groupBy('cash_shift_id')
            ->pluck('total', 'cash_shift_id');

        $rows = [];
        $totalSales = $totalCash = $totalNonCash = $totalDiff = 0;

        foreach ($shifts as $shift) {
            $openingCash = (float) $shift->opening_cash;
            $salesData = $txByShift[$shift->id] ?? collect();
            $salesCash = (float) $salesData->sum('cash_total');
            $salesNonCash = (float) $salesData->sum('total') - $salesCash;
            $txCount = (int) $salesData->sum('count');
            $cashIn = (float) ($cashInByShift[$shift->id] ?? 0);
            $cashOut = (float) ($cashOutByShift[$shift->id] ?? 0);
            $diff = (float) ($shift->difference_amount ?? 0);

            $totalSales += $salesCash + $salesNonCash;
            $totalCash += $salesCash;
            $totalNonCash += $salesNonCash;
            $totalDiff += $diff;

            $rows[] = [
                $shift->shift_number,
                $shift->user?->name ?? '—',
                $shift->opened_at instanceof Carbon ? $shift->opened_at->format('d/m/Y H:i') : '',
                $shift->closed_at instanceof Carbon ? $shift->closed_at->format('d/m/Y H:i') : '',
                $this->rp($openingCash),
                $this->rp($salesCash),
                $this->rp($salesNonCash),
                $this->rp($salesCash + $salesNonCash),
                $this->rp($cashIn),
                $this->rp($cashOut),
                $this->rp($diff),
            ];
        }

        return [
            'title' => 'Laporan Arus Kas per Shift',
            'meta' => $this->periodMeta($start, $end),
            'sections' => [
                [
                    'type' => 'keyvalue', 'title' => 'Ringkasan',
                    'rows' => [
                        ['label' => 'Total Shift', 'value' => number_format($shifts->count(), 0, ',', '.')],
                        ['label' => 'Total Penjualan Tunai', 'value' => $this->rp($totalCash)],
                        ['label' => 'Total Penjualan Non-Tunai', 'value' => $this->rp($totalNonCash)],
                        ['label' => 'Total Penjualan', 'value' => $this->rp($totalSales), 'strong' => true],
                        ['label' => 'Total Selisih Kas', 'value' => $this->rp($totalDiff)],
                    ],
                ],
                [
                    'type' => 'table', 'title' => 'Rincian per Shift',
                    'headings' => ['Shift', 'Kasir', 'Buka', 'Tutup', 'Kas Awal', 'Penj. Tunai', 'Penj. Non-Tunai', 'Total Penj.', 'Kas Masuk', 'Kas Keluar', 'Selisih'],
                    'aligns' => ['left', 'left', 'left', 'left', 'right', 'right', 'right', 'right', 'right', 'right', 'right'],
                    'rows' => $rows,
                    'footer' => ['', '', '', '', '', $this->rp($totalCash), $this->rp($totalNonCash), $this->rp($totalSales), '', '', $this->rp($totalDiff)],
                ],
            ],
        ];
    }

    /* =========================================================
     |  17. Absensi
     * ========================================================= */
    private function buildAbsensi(User $user, Store $store, Request $request): array
    {
        if (! in_array($user->getRoleForActiveStore(), ['admin', 'manager', 'owner'], true)) {
            abort(403, 'Anda tidak memiliki akses ke menu ini.');
        }

        $from = $request->get('from') ?? now($store->timezone ?: 'UTC')->startOfMonth()->toDateString();
        $to = $request->get('to') ?? now($store->timezone ?: 'UTC')->toDateString();

        $service = app(\App\Modules\Attendance\Services\AttendanceService::class);

        $summary = \App\Modules\Attendance\Models\Employee::query()
            ->where('store_id', $store->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn ($employee) => [
                'name' => $employee->name,
                ...$service->aggregate(
                    $employee,
                    CarbonImmutable::parse($from)->startOfDay(),
                    CarbonImmutable::parse($to)->endOfDay(),
                ),
            ]);

        $records = \App\Modules\Attendance\Models\Attendance::query()
            ->where('store_id', $store->id)
            ->whereBetween('work_date', [$from, $to])
            ->with('employee:id,name')
            ->orderByDesc('work_date')
            ->orderByDesc('clock_in')
            ->get();

        $statusLabel = fn (string $status) => [
            \App\Modules\Attendance\Models\Attendance::STATUS_PRESENT => 'Hadir',
            \App\Modules\Attendance\Models\Attendance::STATUS_LATE => 'Terlambat',
            \App\Modules\Attendance\Models\Attendance::STATUS_ABSENT => 'Absen',
            \App\Modules\Attendance\Models\Attendance::STATUS_LEAVE => 'Cuti',
            \App\Modules\Attendance\Models\Attendance::STATUS_SICK => 'Sakit',
            \App\Modules\Attendance\Models\Attendance::STATUS_HOLIDAY => 'Libur',
            \App\Modules\Attendance\Models\Attendance::STATUS_DAY_OFF => 'Off',
        ][$status] ?? $status;

        $totals = [
            'work_days' => $summary->sum('work_days'),
            'present_days' => $summary->sum('present_days'),
            'late_days' => $summary->sum('late_days'),
            'absent_days' => $summary->sum('absent_days'),
            'leave_days' => $summary->sum('leave_days'),
            'total_hours' => round($summary->sum('total_hours'), 2),
            'total_overtime' => round($summary->sum('total_overtime'), 2),
            'total_late_min' => $summary->sum('total_late_min'),
        ];

        return [
            'title' => 'Laporan Absensi',
            'meta' => $this->periodMeta($from, $to),
            'sections' => [
                [
                    'type' => 'table', 'title' => 'Rekap per Karyawan',
                    'headings' => ['Karyawan', 'Hari', 'Hadir', 'Telat', 'Absen', 'Cuti', 'Jam Kerja', 'Lembur', 'Telat (menit)'],
                    'aligns' => ['left', 'right', 'right', 'right', 'right', 'right', 'right', 'right', 'right'],
                    'rows' => $summary->map(fn ($r) => [
                        $r['name'],
                        $r['work_days'],
                        $r['present_days'],
                        $r['late_days'],
                        $r['absent_days'],
                        $r['leave_days'],
                        $r['total_hours'],
                        $r['total_overtime'],
                        $r['total_late_min'],
                    ])->all(),
                    'footer' => [
                        'Total',
                        $totals['work_days'], $totals['present_days'], $totals['late_days'],
                        $totals['absent_days'], $totals['leave_days'], $totals['total_hours'],
                        $totals['total_overtime'], $totals['total_late_min'],
                    ],
                ],
                [
                    'type' => 'table', 'title' => 'Detail Harian',
                    'headings' => ['Tanggal', 'Karyawan', 'Masuk', 'Pulang', 'Status', 'Telat (menit)', 'Jam Kerja', 'Lembur'],
                    'aligns' => ['left', 'left', 'left', 'left', 'left', 'right', 'right', 'right'],
                    'rows' => $records->map(fn ($r) => [
                        $r->work_date?->format('d/m/Y') ?? '—',
                        $r->employee?->name ?? '—',
                        $r->clock_in?->format('H:i') ?? '—',
                        $r->clock_out?->format('H:i') ?? '—',
                        $statusLabel($r->status),
                        $r->late_minutes,
                        $r->hours_worked ?? '—',
                        $r->overtime_hours ?? '—',
                    ])->all(),
                ],
            ],
        ];
    }

    /* =========================================================
     |  Label helper
     * ========================================================= */
    private function paymentLabel(string $method): string
    {
        return [
            'cash' => 'Tunai', 'card' => 'Kartu', 'ewallet' => 'E-Wallet',
            'transfer' => 'Transfer', 'other' => 'Lainnya',
        ][$method] ?? ucfirst($method);
    }

    private function movementLabel(string $type): string
    {
        return [
            StockMovement::TYPE_SALE => 'Penjualan',
            StockMovement::TYPE_ADJUSTMENT => 'Penyesuaian',
            StockMovement::TYPE_PURCHASE => 'Pembelian',
            StockMovement::TYPE_SUPPLIER_RETURN => 'Retur ke supplier',
            StockMovement::TYPE_RETURN => 'Retur',
            StockMovement::TYPE_DAMAGE => 'Rusak / hilang',
            StockMovement::TYPE_TRANSFER_OUT => 'Transfer keluar',
            StockMovement::TYPE_TRANSFER_IN => 'Transfer masuk',
            StockMovement::TYPE_TRANSFER_REVERSAL => 'Pembatalan transfer',
            StockMovement::TYPE_PRODUCTION_CONSUMPTION => 'Konsumsi produksi',
            StockMovement::TYPE_PRODUCTION_OUTPUT => 'Output produksi',
            StockMovement::TYPE_PRODUCTION_REVERSAL => 'Pembatalan produksi',
            StockMovement::TYPE_OPNAME => 'Stock opname',
            StockMovement::TYPE_ADJUSTMENT_REVERSAL => 'Pembatalan penyesuaian',
        ][$type] ?? $type;
    }
}
