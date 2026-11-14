<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Events\AiAlertCreated;
use App\Models\AiAlert;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\AI\AiMetrics;
use App\Services\AI\AiPeriod;
use App\Services\AI\AiStockVelocity;
use App\Services\AI\TransactionAnomalyDetector;
use App\Services\Alerts\AlertPushService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class GenerateAiProactiveAlerts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ai:proactive-alerts {--dry-run : Tampilkan ringkasan peringatan tanpa menyimpan}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Deteksi peringatan proaktif harian (stok kritis, anomali transaksi, pelanggan hampir churn, margin turun) lalu kirimkan notifikasi in-app + push email/telegram/whatsapp';

    private const RECIPIENT_ROLES = ['owner', 'admin', 'manager'];

    private const LOW_STOCK_LIMIT = 5;

    private const CHURN_INACTIVE_DAYS = 30;

    private const CHURN_SAMPLE_LIMIT = 5;

    public function __construct(
        private readonly TransactionAnomalyDetector $detector,
        private readonly AlertPushService $pushService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $created = 0;
        $storesWithoutAlerts = 0;

        Store::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->chunkById(50, function ($stores) use ($dryRun, &$created, &$storesWithoutAlerts) {
                foreach ($stores as $store) {
                    $alerts = $this->detectAlerts($store);

                    if ($alerts === []) {
                        $storesWithoutAlerts++;

                        continue;
                    }

                    foreach ($this->recipients($store) as $user) {
                        foreach ($alerts as $alert) {
                            if ($dryRun) {
                                $this->line("[dry-run] {$store->name} → {$user->email} [{$alert['type']}] {$alert['title']}");
                                $created++;

                                continue;
                            }

                            if ($this->storeAlert($user, $store, $alert)) {
                                $created++;
                            }
                        }
                    }
                }
            });

        $suffix = $dryRun ? ' (dry-run, tidak disimpan)' : '';
        $this->info("Peringatan proaktif: {$created} dibuat, {$storesWithoutAlerts} toko tanpa temuan{$suffix}.");

        if (! $dryRun && $created > 0) {
            $this->newLine();
            if ((bool) config('alerts.push.enabled', true)) {
                $channels = [];
                if ((bool) config('alerts.push.mail_enabled', true)) {
                    $channels[] = 'email';
                }
                if ((bool) config('alerts.push.telegram.enabled', false) && config('alerts.push.telegram.bot_token') && config('alerts.push.telegram.chat_ids')) {
                    $channels[] = 'telegram';
                }
                if ((bool) config('alerts.push.whatsapp.enabled', false) && config('alerts.push.whatsapp.webhook_url')) {
                    $channels[] = 'whatsapp';
                }
                $this->line($channels !== [] ? 'Push: '.implode(', ', $channels).'.' : 'Push nonaktif — aktifkan via env ALERT_PUSH_* / TELEGRAM_* / WHATSAPP_WEBHOOK_URL.');
            } else {
                $this->line('Push nonaktif (ALERT_PUSH_ENABLED=false).');
            }
        } elseif ($dryRun) {
            $this->newLine();
            $this->line('Push dilewati pada dry-run.');
        }

        return self::SUCCESS;
    }

    /**
     * Jalankan seluruh detektor deterministik untuk satu toko.
     *
     * @return array<int, array{type: string, title: string, message: string, data: array<string, mixed>}>
     */
    private function detectAlerts(Store $store): array
    {
        $alerts = [];

        foreach ([$this->lowStockAlert($store), $this->anomalyAlert($store), $this->churnAlert($store), $this->marginDropAlert($store)] as $alert) {
            if ($alert !== null) {
                $alerts[] = $alert;
            }
        }

        return $alerts;
    }

    /**
     * @return array{type: string, title: string, message: string, data: array<string, mixed>}|null
     */
    private function lowStockAlert(Store $store): ?array
    {
        // Hanya stok yang benar-benar perlu ditindak: di bawah stok minimum, atau
        // habis padahal masih terjual/terpakai. "Sisa 0 (min 0)" yang tak bergerak dilewati.
        $lowStock = AiStockVelocity::lowStock((int) $store->id, self::LOW_STOCK_LIMIT);
        $products = $lowStock['items'];
        $total = (int) $lowStock['total'];

        if ($products->isEmpty()) {
            return null;
        }

        $lines = $products
            ->map(fn (Product $product): string => sprintf(
                '%s (sisa %s%s, min %s%s)',
                $product->name,
                $this->formatStock((float) $product->stock_quantity),
                $product->unit ? ' '.$product->unit : '',
                $this->formatStock((float) $product->min_stock_level),
                $product->unit ? ' '.$product->unit : '',
            ))
            ->implode('; ');

        return [
            'type' => 'low_stock',
            'title' => 'Stok kritis',
            'message' => "{$total} produk menyentuh batas minimum atau habis: {$lines}"
                .($total > $products->count() ? ', dan '.($total - $products->count()).' lainnya' : '')
                .'. Segera restock sebelum kehabisan.',
            'data' => [
                'count' => $total,
                'items' => $products->map(fn (Product $product): array => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'stock_quantity' => (float) $product->stock_quantity,
                    'min_stock_level' => (float) $product->min_stock_level,
                ])->all(),
            ],
        ];
    }

    /**
     * @return array{type: string, title: string, message: string, data: array<string, mixed>}|null
     */
    private function anomalyAlert(Store $store): ?array
    {
        $from = Carbon::now()->subDay();
        $to = Carbon::now();
        $storeIds = [(int) $store->id];

        $discounts = $this->detector->findDiscountAnomalies($storeIds, $from, $to);
        $voids = $this->detector->findRepeatedVoids($storeIds, $from, $to);
        $differences = $this->detector->findCashDifferences($storeIds, $from, $to);

        if ($discounts === [] && $voids === [] && $differences === []) {
            return null;
        }

        $parts = [];

        foreach (array_slice($discounts, 0, 2) as $row) {
            $parts[] = "diskon {$row['discount_pct']}% pada {$row['transaction_number']} ({$row['cashier']})";
        }
        if (count($discounts) > 2) {
            $parts[] = (count($discounts) - 2).' diskon tidak wajar lainnya';
        }
        foreach ($voids as $row) {
            $parts[] = "void berulang oleh {$row['cashier']} ({$row['void_count']}x)";
        }
        if ($differences !== []) {
            $parts[] = count($differences).' shift kasir dengan selisih hitungan fisik';
        }

        return [
            'type' => 'transaction_anomaly',
            'title' => 'Anomali transaksi 24 jam terakhir',
            'message' => 'Ditemukan: '.implode('; ', $parts).'. Tinjau nota terkait dan pastikan tidak ada kebocoran kas.',
            'data' => [
                'discount_anomalies' => $discounts,
                'void_patterns' => $voids,
                'cash_differences' => $differences,
            ],
        ];
    }

    /**
     * @return array{type: string, title: string, message: string, data: array<string, mixed>}|null
     */
    private function churnAlert(Store $store): ?array
    {
        $cutoff = Carbon::now()->subDays(self::CHURN_INACTIVE_DAYS);

        $baseQuery = Customer::query()
            ->where('store_id', $store->id)
            ->active()
            ->where('total_orders', '>=', 1)
            ->where(function ($query) use ($cutoff) {
                $query->whereNull('last_purchase_at')
                    ->orWhere('last_purchase_at', '<', $cutoff);
            });

        $total = (clone $baseQuery)->count();

        if ($total === 0) {
            return null;
        }

        $sample = $baseQuery
            ->orderBy('last_purchase_at')
            ->limit(self::CHURN_SAMPLE_LIMIT)
            ->get();

        $names = $sample->pluck('name')->implode(', ');
        $extra = $total > self::CHURN_SAMPLE_LIMIT ? ', dan '.($total - self::CHURN_SAMPLE_LIMIT).' lainnya' : '';

        return [
            'type' => 'churn_risk',
            'title' => 'Pelanggan hampir hilang',
            'message' => "{$total} pelanggan tidak belanja selama lebih dari ".self::CHURN_INACTIVE_DAYS." hari: {$names}{$extra}. "
                .'Hubungi dengan penawaran khusus sebelum pindah ke kompetitor.',
            'data' => [
                'count' => $total,
                'inactive_days' => self::CHURN_INACTIVE_DAYS,
                'customer_ids' => $sample->pluck('id')->all(),
            ],
        ];
    }

    /**
     * @return array{type: string, title: string, message: string, data: array<string, mixed>}|null
     */
    private function marginDropAlert(Store $store): ?array
    {
        $windowDays = max(1, (int) config('alerts.margin.window_days', 7));
        $dropPointsThreshold = (float) config('alerts.margin.drop_points_threshold', 5.0);
        $dropPercentThreshold = (float) config('alerts.margin.drop_percent_threshold', 15.0);

        // Dua jendela penuh yang sama panjang, berakhir kemarin (jam toko): hari
        // ini baru berjalan sebagian dan tidak sebanding dengan hari penuh.
        $today = AiPeriod::now($store)->startOfDay();
        $recentStart = Carbon::instance(AiPeriod::toDb($today->copy()->subDays($windowDays)));
        $recentEnd = Carbon::instance(AiPeriod::toDb($today->copy()->subSecond()));
        $prevStart = Carbon::instance(AiPeriod::toDb($today->copy()->subDays($windowDays * 2)));
        $prevEnd = $recentStart->copy()->subSecond();

        $recent = $this->marginForPeriod($store, $recentStart, $recentEnd);
        $prev = $this->marginForPeriod($store, $prevStart, $prevEnd);

        if ($recent === null || $prev === null) {
            return null;
        }

        $dropPoints = $prev['margin_pct'] - $recent['margin_pct'];
        $dropPercent = $prev['margin_pct'] > 0 ? ($dropPoints / $prev['margin_pct']) * 100 : 0;

        $triggered = $dropPoints >= $dropPointsThreshold || $dropPercent >= $dropPercentThreshold;

        if (! $triggered) {
            return null;
        }

        return [
            'type' => 'margin_drop',
            'title' => 'Margin turun',
            'message' => sprintf(
                'Margin kotor %d hari terakhir (s.d. kemarin) %.1f%% vs %.1f%% pada periode sebelumnya — turun %.1f poin (%.1f%%). Periksa diskon dan kenaikan harga modal (tanya asisten: "produk bocor").',
                $windowDays,
                $recent['margin_pct'],
                $prev['margin_pct'],
                $dropPoints,
                $dropPercent,
            ),
            'data' => [
                'window_days' => $windowDays,
                'recent_margin_pct' => round($recent['margin_pct'], 1),
                'previous_margin_pct' => round($prev['margin_pct'], 1),
                'drop_points' => round($dropPoints, 1),
                'drop_percent' => round($dropPercent, 1),
                'recent_revenue' => $recent['revenue'],
                'recent_cogs' => $recent['cogs'],
                'previous_revenue' => $prev['revenue'],
                'previous_cogs' => $prev['cogs'],
            ],
        ];
    }

    /**
     * @return array{revenue: float, cogs: float, margin_pct: float}|null
     */
    private function marginForPeriod(Store $store, Carbon $start, Carbon $end): ?array
    {
        // Definisi sama dengan laporan: pendapatan bersih (tanpa pajak/servis/tip).
        $summary = AiMetrics::summary([(int) $store->id], $start, $end);

        // Margin hanya dibandingkan bila harga modal cukup tercatat di periode
        // itu. Baris tanpa harga modal dulu dihitung bermodal nol, sehingga
        // perubahan kelengkapan data tampak sebagai "margin turun".
        if ($summary['revenue'] <= 0 || ! $summary['margin_reliable'] || $summary['covered_margin_pct'] === null) {
            return null;
        }

        return [
            'revenue' => (float) $summary['revenue'],
            'cogs' => (float) $summary['hpp'],
            'margin_pct' => (float) $summary['covered_margin_pct'],
        ];
    }

    private function storeAlert(User $user, Store $store, array $alert): bool
    {
        $aiAlert = AiAlert::query()->firstOrCreate(
            [
                'user_id' => $user->id,
                'store_id' => $store->id,
                'type' => $alert['type'],
                'alert_date' => Carbon::today(),
            ],
            [
                'title' => $alert['title'],
                'message' => $alert['message'],
                'data' => $alert['data'],
                'is_read' => false,
            ]
        );

        if (! $aiAlert->wasRecentlyCreated) {
            return false;
        }

        try {
            AiAlertCreated::dispatch($aiAlert);
        } catch (\Throwable) {
            // Gagal broadcast WebSocket tidak boleh menghentikan pembuatan alert berikutnya.
        }

        try {
            $this->pushService->push($user, $store, $alert);
        } catch (\Throwable) {
            // Kegagalan push eksternal tidak boleh menghapus alert in-app.
        }

        return true;
    }

    /**
     * Penerima: user dengan role owner/admin/manager (direct store_id maupun via pivot store_user).
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, User>
     */
    private function recipients(Store $store)
    {
        $direct = User::query()
            ->where('store_id', $store->id)
            ->whereIn('role', self::RECIPIENT_ROLES)
            ->where('is_active', true);

        $viaPivot = User::query()
            ->whereHas('stores', fn ($q) => $q->where('stores.id', $store->id)->whereIn('store_user.role', self::RECIPIENT_ROLES));

        return User::query()
            ->where(function ($q) use ($direct, $viaPivot) {
                $q->whereIn('id', $direct->select('id'))
                    ->orWhereIn('id', $viaPivot->select('id'));
            })
            ->where('is_active', true)
            ->orderBy('id')
            ->distinct()
            ->get();
    }

    private function formatStock(float $qty): string
    {
        return $qty == floor($qty) ? (string) (int) $qty : (string) $qty;
    }
}
