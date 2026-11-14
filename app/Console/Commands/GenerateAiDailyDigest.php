<?php

namespace App\Console\Commands;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\Product;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use App\Services\AI\AiMetrics;
use App\Services\AI\AiPeriod;
use App\Services\AI\AiStockVelocity;
use App\Services\Alerts\AlertPushService;
use App\Services\KPIService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class GenerateAiDailyDigest extends Command
{
    protected $signature = 'ai:daily-digest {--dry-run : Tampilkan ringkasan tanpa menyimpan pesan}';

    protected $description = 'Buat digest proaktif AI (rekap KPI kemarin, stok menipis, dan prediksi segera habis) di percakapan AI setiap pemilik/admin toko.';

    private const RECIPIENT_ROLES = ['owner', 'admin', 'manager'];

    private const DIGEST_TITLE = 'Digest Harian';

    private const LOW_STOCK_LIMIT = 5;

    private const URGENT_STOCK_DAYS = 7;

    private const URGENT_STOCK_LIMIT = 5;

    private const SALES_WINDOW_DAYS = 14;

    /** Bagian stok dari digest terakhir yang disusun (null bila ada penjualan). */
    private ?string $stockSection = null;

    private const MONTHS_ID = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];

    public function __construct(
        private KPIService $kpiService,
        private readonly AlertPushService $pushService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $created = 0;
        $skipped = 0;

        Store::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->chunkById(50, function ($stores) use ($dry, &$created, &$skipped) {
                foreach ($stores as $store) {
                    // "Kemarin" menurut jam toko, bukan jam server.
                    $date = Carbon::instance(AiPeriod::now($store)->subDay()->startOfDay());
                    $digest = $this->buildDigest($store, $date);

                    if ($digest === null) {
                        $skipped++;

                        continue;
                    }

                    foreach ($this->recipients($store) as $user) {
                        if ($dry) {
                            $this->line("[dry-run] {$store->name} → {$user->email}");
                            $created++;

                            continue;
                        }

                        if ($this->storeDigest($user, $store, $digest, $date)) {
                            $created++;
                        }
                    }
                }
            });

        $this->info(($dry ? 'Selesai (dry-run): ' : 'Selesai: ').$created.' digest, '.$skipped.' toko dilewati (tanpa aktivitas).');

        return self::SUCCESS;
    }

    /**
     * Susun teks digest untuk satu toko. Mengembalikan null jika tidak ada
     * data berguna (tanpa penjualan kemarin dan tanpa stok menipis).
     */
    private function buildDigest(Store $store, Carbon $date): ?string
    {
        // Batas hari menurut jam toko, dikonversi ke zona aplikasi untuk query.
        $start = AiPeriod::toDb($date->copy()->startOfDay());
        $end = AiPeriod::toDb($date->copy()->endOfDay());

        // Pendapatan bersih (subtotal − diskon − retur), sama dengan laporan & chat AI.
        $summary = AiMetrics::summary([$store->id], $start, $end);
        $revenue = (float) $summary['revenue'];
        $transactions = (int) $summary['transactions'];

        $activeCustomers = (int) Transaction::query()
            ->where('store_id', $store->id)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$start, $end])
            ->distinct()
            ->count('customer_id');

        $lowStockResult = AiStockVelocity::lowStock((int) $store->id, self::LOW_STOCK_LIMIT);
        $lowStock = $lowStockResult['items'];

        $urgentRestock = $this->urgentRestockItems($store, $end);

        if ($revenue === 0.0 && $transactions === 0 && $lowStock->isEmpty() && $urgentRestock->isEmpty()) {
            return null;
        }

        $alerts = $this->kpiService->getOperationalAlerts($store);
        $humanDate = $date->format('d').' '.self::MONTHS_ID[(int) $date->format('m')].' '.$date->format('Y');

        $lines = [
            '📊 Digest Harian '.$date->format('Y-m-d'),
            '',
            '📌 Ringkasan penjualan '.$humanDate.':',
            '• Pendapatan: Rp '.$this->formatMoney($revenue),
            '• Transaksi: '.$transactions,
            '• Rata-rata transaksi: Rp '.$this->formatMoney($transactions > 0 ? $revenue / $transactions : 0),
            '• Pelanggan aktif: '.$activeCustomers,
        ];

        if ($transactions > 0 && $summary['margin_reliable'] && $summary['gross_margin_pct'] !== null) {
            $lines[] = '• Laba kotor: Rp '.$this->formatMoney((float) $summary['gross_profit']).' (margin '.AiMetrics::number($summary['gross_margin_pct']).'%)';
        }

        // Bagian stok dikumpulkan terpisah agar digest yang isinya sama persis
        // dengan kemarin tidak dikirim berulang ke toko tanpa penjualan.
        $salesLineCount = count($lines);

        if ($transactions > 0) {
            $topProducts = TransactionItem::query()
                ->join('transactions', 'transaction_items.transaction_id', '=', 'transactions.id')
                ->where('transactions.store_id', $store->id)
                ->where('transactions.status', 'completed')
                ->whereBetween('transactions.created_at', [$start, $end])
                ->selectRaw('transaction_items.product_name, SUM(transaction_items.quantity) as total_qty, SUM(transaction_items.total_price) as total_revenue')
                ->groupBy('transaction_items.product_name')
                ->orderByDesc('total_qty')
                ->limit(3)
                ->get();

            if ($topProducts->isNotEmpty()) {
                $lines[] = '';
                $lines[] = '🏆 Top produk:';
                foreach ($topProducts as $product) {
                    $lines[] = '• '.$product->product_name.' — '.$this->formatStock((float) $product->total_qty).' unit (Rp '.$this->formatMoney((float) $product->total_revenue).')';
                }
            }
        }

        if ($lowStock->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '⚠️ Stok menipis / habis:';
            foreach ($lowStock as $product) {
                $lines[] = '• '.$product->name.' — sisa '.$this->formatStock((float) $product->stock_quantity)
                    .((float) $product->min_stock_level > 0
                        ? ' (min '.$this->formatStock((float) $product->min_stock_level).')'
                        : ' (habis, masih terjual/terpakai 30 hari terakhir)');
            }

            $totalLow = (int) $lowStockResult['total'];
            if ($totalLow > $lowStock->count()) {
                $lines[] = '• …dan '.($totalLow - $lowStock->count()).' produk lainnya.';
            }
        }

        $slowMoving = (int) $alerts['slow_moving_count'];
        if ($slowMoving > 0) {
            $lines[] = '';
            $lines[] = '🐌 Slow moving: '.$slowMoving.' produk tidak terjual ≥5 unit dalam 30 hari terakhir.';
        }

        if ($urgentRestock->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '🚨 Perkiraan segera habis (laju penjualan '.self::SALES_WINDOW_DAYS.' hari):';
            foreach ($urgentRestock as $item) {
                $eta = $item['days_left'] <= 0 ? 'hari ini' : '±'.$item['days_left'].' hari lagi';
                $lines[] = '• '.$item['product']->name.' — sisa '.$this->formatStock((float) $item['product']->stock_quantity).' '.$item['product']->unit.' (±'.$this->formatStock($item['avg_per_day']).' unit/hari), perkiraan habis '.$eta.'. Siapkan restock.';
            }
        }

        if ($transactions === 0) {
            $this->stockSection = implode("\n", array_slice($lines, $salesLineCount));
        } else {
            $this->stockSection = null;
        }

        return implode("\n", $lines);
    }

    /**
     * Simpan digest ke percakapan AI pemilik toko. Mengembalikan false jika
     * digest untuk tanggal tersebut sudah pernah dibuat (idempoten).
     */
    private function storeDigest(User $user, Store $store, string $digest, Carbon $date): bool
    {
        $conversation = AiConversation::query()->firstOrCreate([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'title' => self::DIGEST_TITLE,
        ]);

        $alreadySent = $conversation->messages()
            ->where('role', 'assistant')
            ->where('content', 'like', '%Digest Harian '.$date->format('Y-m-d').'%')
            ->exists();

        if ($alreadySent) {
            return false;
        }

        // Tanpa penjualan kemarin dan kondisi stok sama persis dengan digest
        // terakhir: tidak ada kabar baru, jangan ulangi pesan yang sama tiap hari.
        if ($this->stockSection !== null && $this->stockSection !== '') {
            $last = $conversation->messages()->where('role', 'assistant')->reorder()->latest('id')->value('content');

            if (is_string($last) && str_contains($last, $this->stockSection)) {
                return false;
            }
        }

        AiMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => $digest,
        ]);

        try {
            $this->pushService->push($user, $store, [
                'type' => 'daily_digest',
                'title' => 'Digest Harian '.$date->format('Y-m-d'),
                'message' => mb_substr($digest, 0, 3500),
                'data' => ['date' => $date->toDateString()],
            ]);
        } catch (\Throwable) {
            // Kegagalan push eksternal tidak boleh membatalkan digest in-app.
        }

        return true;
    }

    /**
     * Pengguna yang boleh menerima digest: owner/admin/manajer toko tersebut.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function recipients(Store $store)
    {
        $direct = User::query()
            ->where('store_id', $store->id)
            ->whereIn('role', self::RECIPIENT_ROLES)
            ->get();

        $memberIds = $store->members()
            ->wherePivotIn('role', self::RECIPIENT_ROLES)
            ->pluck('users.id');

        $members = User::query()->whereIn('id', $memberIds)->get();

        return $direct->merge($members)->unique('id')->values();
    }

    /**
     * Peringatan dini (alert proaktif): produk yang stoknya belum menyentuh
     * batas minimum tetapi diperkirakan habis dalam beberapa hari ke depan
     * berdasarkan laju penjualan 14 hari terakhir.
     *
     * @return \Illuminate\Support\Collection<int, array{product: Product, avg_per_day: float, days_left: int}>
     */
    private function urgentRestockItems(Store $store, Carbon $end)
    {
        // Termasuk bahan baku/kemasan yang terpakai lewat resep, bukan hanya penjualan langsung.
        $soldPerProduct = AiStockVelocity::usage(
            [(int) $store->id],
            $end->copy()->subDays(self::SALES_WINDOW_DAYS)->addSecond(),
            $end
        )->filter(fn (float $used) => $used > 0);

        if ($soldPerProduct->isEmpty()) {
            return collect();
        }

        return AiStockVelocity::trackedProducts([(int) $store->id])
            ->where('stock_quantity', '>', 0)
            ->whereColumn('stock_quantity', '>', 'min_stock_level')
            ->whereIn('id', $soldPerProduct->keys())
            ->get()
            ->map(function (Product $product) use ($soldPerProduct) {
                $avgPerDay = (float) $soldPerProduct[$product->id] / AiStockVelocity::activeDays($product, self::SALES_WINDOW_DAYS);

                return [
                    'product' => $product,
                    'avg_per_day' => $avgPerDay,
                    'days_left' => $avgPerDay > 0 ? (int) floor((float) $product->stock_quantity / $avgPerDay) : PHP_INT_MAX,
                ];
            })
            ->filter(fn (array $item) => $item['days_left'] <= self::URGENT_STOCK_DAYS)
            ->sortBy('days_left')
            ->take(self::URGENT_STOCK_LIMIT)
            ->values();
    }

    private function formatMoney(float $value): string
    {
        return number_format($value, 0, ',', '.');
    }

    private function formatStock(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }
}
