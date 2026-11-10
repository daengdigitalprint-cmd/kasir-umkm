<?php

namespace Database\Seeders;

use App\Models\CashShift;
use App\Models\CashTransaction;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\OperationalExpense;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Models\PurchasePayment;
use App\Models\ReceivablePayment;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\SubscriptionPlan;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\TransactionPayment;
use App\Models\User;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\Employee;
use App\Modules\Attendance\Models\Holiday;
use App\Modules\Attendance\Models\OvertimeRequest;
use App\Modules\Attendance\Models\WorkSchedule;
use App\Modules\Attendance\Services\AttendanceService;
use App\Modules\Attendance\Support\AttendanceSettings;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Models\SalaryComponent;
use App\Modules\Payroll\Services\PayrollService;
use App\Support\Accounting\ChartOfAccountsBootstrap;
use App\Support\Accounting\JournalPoster;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Akun demo "Toko Bangunan Sumber Jaya" — data lengkap 75 hari agar semua menu & laporan terisi.
 *
 *   php artisan db:seed --class=TokoBangunanDemoSeeder
 *
 * Login: demo.bangunan@umkm-kasir.test / password (pemilik, role admin)
 *        kasir.bangunan@umkm-kasir.test / password (kasir)
 * Di production, ganti password setelah seeding (lihat DEMO_PASSWORD env opsional).
 *
 * Data ditulis lewat jalur yang sama dengan aplikasi (stok + stock_movements, snapshot HPP,
 * transaction_payments, JournalPoster) sehingga laporan operasional & keuangan konsisten dan
 * neraca seimbang. Idempoten: menjalankan ulang menghapus lalu membuat ulang data toko demo.
 * Deterministik (mt_srand tetap) — hasil sama setiap dijalankan pada tanggal yang sama.
 */
class TokoBangunanDemoSeeder extends Seeder
{
    private const STORE_SLUG = 'demo-toko-bangunan';

    private const DAYS = 75;

    /** Jam operasional toko demo dalam WIB; dikonversi ke zona waktu aplikasi (APP_TIMEZONE) saat disimpan. */
    private const STORE_TZ = 'Asia/Jakarta';

    private Store $store;

    private User $owner;

    private User $cashier;

    /** @var array<string, Product> */
    private array $products = [];

    /** @var array<string, array> definisi katalog per SKU */
    private array $catalog = [];

    /** @var array<string, Supplier> */
    private array $suppliers = [];

    /** @var list<Customer> */
    private array $customers = [];

    /** @var array<string, int> stok berjalan per SKU */
    private array $stock = [];

    /** @var array<int, list<array>> pelunasan piutang terjadwal per indeks hari */
    private array $receivableQueue = [];

    /** @var array<int, list<int>> pembayaran utang supplier terjadwal per indeks hari (purchase id) */
    private array $payableQueue = [];

    private int $txSeq = 0;

    /** @var array<int, Carbon> customer id => tanggal bergabung */
    private array $customerJoined = [];

    /** @var array<string, bool> supplier key => PKP (faktur pajak / PPN masukan) */
    private array $supplierPkp = [];

    /** Jam nyata saat seeder dijalankan (sebelum Carbon::setTestNow dipakai simulasi). */
    private Carbon $realNow;

    private int $purSeq = 0;

    public function run(): void
    {
        mt_srand(20261108);
        $this->realNow = Carbon::now();
        // Paket langganan hanya dibuat bila belum ada — jangan menimpa harga paket di production.
        if (! SubscriptionPlan::query()->whereIn('slug', ['bisnis-tahunan', 'bisnis'])->exists()) {
            $this->call([SubscriptionPlanSeeder::class]);
        }

        $today = Carbon::today();
        $start = $today->copy()->subDays(self::DAYS);

        DB::transaction(function () use ($start) {
            $this->setupStoreAndUsers($start);
            $this->setupCatalog($start);
            $this->setupParties($start);

            // Hari 0: modal awal + stok awal lewat pembelian (bukan set stok langsung).
            Carbon::setTestNow($this->at($start, 7, 30));
            JournalPoster::postCapitalInjection($this->store, 350_000_000, $start->toDateString(), $this->owner->id, 'Setoran modal awal pemilik');
            $this->openingPurchases($start);

            for ($d = 0; $d <= self::DAYS; $d++) {
                $this->simulateDay($start->copy()->addDays($d), $d, $d === self::DAYS);
            }

            Carbon::setTestNow();
            $this->recomputeCustomerStats();

            // Kembalikan batas tanggal mundur ke default setelah seeding.
            $settings = $this->store->settings ?? [];
            $settings['max_backdate_days'] = 7;
            $this->store->update(['settings' => $settings]);
        });

        Carbon::setTestNow();
        DB::transaction(fn () => $this->setupAttendancePayroll());
        $this->report();
    }

    /** Waktu lokal toko (WIB) pada tanggal $day, dikonversi ke UTC untuk disimpan. */
    private function at(Carbon $day, int $h, int $m = 0, int $sec = 0): Carbon
    {
        return Carbon::create($day->year, $day->month, $day->day, $h, $m, $sec, self::STORE_TZ)
            ->setTimezone(config('app.timezone'));
    }

    // ------------------------------------------------------------------ setup

    private function setupStoreAndUsers(Carbon $start): void
    {
        $plan = SubscriptionPlan::where('slug', 'bisnis-tahunan')->first() ?? SubscriptionPlan::where('slug', 'bisnis')->firstOrFail();
        Carbon::setTestNow($start->copy()->subDays(3)->setTime(9, 0));

        $this->owner = User::updateOrCreate(['email' => 'demo.bangunan@umkm-kasir.test'], [
            'name' => 'Hendra Wijaya',
            'password' => 'password',
            'role' => 'admin',
            'business_type' => 'general',
            'phone' => '081234567801',
            'is_active' => true,
            'is_super_admin' => false,
            'email_verified_at' => now(),
        ]);
        $this->cashier = User::updateOrCreate(['email' => 'kasir.bangunan@umkm-kasir.test'], [
            'name' => 'Rina Lestari',
            'password' => 'password',
            'role' => 'cashier',
            'business_type' => 'general',
            'phone' => '081234567802',
            'is_active' => true,
            'is_super_admin' => false,
            'email_verified_at' => now(),
        ]);

        $org = Organization::updateOrCreate(['slug' => 'toko-bangunan-sumber-jaya'], [
            'name' => 'Toko Bangunan Sumber Jaya',
            'owner_user_id' => $this->owner->id,
        ]);

        $existing = Store::where('slug', self::STORE_SLUG)->first();
        if ($existing) {
            $this->purge($existing->id);
        }

        $attrs = [
            'organization_id' => $org->id,
            'name' => 'Toko Bangunan Sumber Jaya',
            'business_type' => 'general',
            'location_type' => 'outlet',
            'is_primary' => true,
            'address' => 'Jl. Raya Bogor No. 128, Cibinong, Kab. Bogor',
            'phone' => '0218765432',
            'email' => 'sumberjaya.bangunan@umkm-kasir.test',
            'currency' => 'IDR',
            'timezone' => self::STORE_TZ,
            'is_active' => true,
            'tax_enabled' => true,
            'tax_rate' => 11,
            'tax_name' => 'PPN',
            'subscription_plan_id' => $plan->id,
            'subscription_start' => $start->copy()->subDays(3),
            'subscription_end' => Carbon::today()->addYear(),
            'subscription_days' => 365,
            'is_subscription_active' => true,
            'pro_trial_used' => true,
            'pro_trial_active' => false,
            'cashier_login_mode' => 'email',
            'settings' => [
                'tax_rate' => 11,
                'receipt_footer' => 'Terima kasih telah berbelanja di Sumber Jaya. Barang yang sudah dibeli tidak dapat ditukar kecuali cacat produksi.',
                'accounting_enabled' => true,
                'receivables_enabled' => true,
                'product_discount_enabled' => true,
                'wholesale_enabled' => true,
                'max_backdate_days' => 400,
                'recipes_enabled' => false,
                'productions_enabled' => false,
            ],
        ];

        $this->store = $existing ? tap($existing)->update($attrs) : Store::create(['slug' => self::STORE_SLUG] + $attrs);
        $this->store->refresh();
        ChartOfAccountsBootstrap::ensureForStore($this->store);

        foreach ([[$this->owner, 'admin'], [$this->cashier, 'cashier']] as [$user, $role]) {
            $user->update(['store_id' => $this->store->id]);
            $user->stores()->syncWithoutDetaching([$this->store->id => ['role' => $role]]);
        }
    }

    private function purge(int $storeId): void
    {
        $txIds = DB::table('transactions')->where('store_id', $storeId)->pluck('id');
        $purIds = DB::table('purchases')->where('store_id', $storeId)->pluck('id');
        $jeIds = DB::table('journal_entries')->where('store_id', $storeId)->pluck('id');

        foreach ($jeIds->chunk(1000) as $c) {
            DB::table('journal_lines')->whereIn('journal_entry_id', $c)->delete();
        }
        DB::table('journal_entries')->where('store_id', $storeId)->delete();
        foreach (['receivable_payments', 'transaction_payments'] as $t) {
            if (Schema::hasTable($t)) {
                DB::table($t)->where('store_id', $storeId)->delete();
            }
        }
        foreach ($txIds->chunk(1000) as $c) {
            DB::table('transaction_items')->whereIn('transaction_id', $c)->delete();
        }
        DB::table('transactions')->where('store_id', $storeId)->delete();
        DB::table('stock_movements')->where('store_id', $storeId)->delete();
        foreach ($purIds->chunk(1000) as $c) {
            if (Schema::hasTable('purchase_payments')) {
                DB::table('purchase_payments')->whereIn('purchase_id', $c)->delete();
            }
            if (Schema::hasTable('purchase_charges')) {
                DB::table('purchase_charges')->whereIn('purchase_id', $c)->delete();
            }
            DB::table('purchase_lines')->whereIn('purchase_id', $c)->delete();
        }
        DB::table('purchases')->where('store_id', $storeId)->delete();
        foreach (['payroll_runs', 'payroll_periods', 'salary_components', 'attendance_photos', 'attendances', 'leave_requests', 'overtime_requests', 'work_schedules', 'attendance_holidays', 'employees'] as $t) {
            if (Schema::hasTable($t)) {
                DB::table($t)->where('store_id', $storeId)->delete();
            }
        }
        foreach (['cash_transactions', 'operational_expenses', 'cash_shifts', 'product_variants', 'products', 'product_categories', 'customers', 'suppliers', 'activity_logs'] as $t) {
            if (Schema::hasTable($t) && Schema::hasColumn($t, 'store_id')) {
                DB::table($t)->where('store_id', $storeId)->delete();
            }
        }
    }

    private function setupCatalog(Carbon $start): void
    {
        Carbon::setTestNow($start->copy()->subDays(2)->setTime(10, 0));

        // kategori => [[sku, nama, satuan, merek, modal, jual, grosir, min stok, bobot laku, qty min, qty max, supplier], ...]
        $data = [
            'Semen & Mortar' => [
                ['SMN-TR50', 'Semen Tiga Roda 50 kg', 'sak', 'Tiga Roda', 61500, 69000, 66500, 120, 16, 2, 20, 'semen'],
                ['SMN-GR40', 'Semen Gresik 40 kg', 'sak', 'Gresik', 50500, 57000, 55000, 80, 9, 2, 15, 'semen'],
                ['SMN-PTH40', 'Semen Putih Tiga Roda 40 kg', 'sak', 'Tiga Roda', 98000, 112000, 107000, 15, 2, 1, 3, 'semen'],
                ['MRT-MU380', 'Mortar MU-380 Perekat Bata Ringan 40 kg', 'sak', 'MU', 92000, 105000, 101000, 25, 4, 2, 10, 'semen'],
                ['MRT-NAT', 'Semen Nat Keramik AM 1 kg', 'pcs', 'AM', 9500, 13000, 12000, 40, 5, 2, 8, 'keramik'],
            ],
            'Besi & Baja' => [
                ['BSI-P08', 'Besi Beton Polos 8 mm SNI (12 m)', 'batang', 'Master Steel', 39000, 46000, 44000, 150, 10, 10, 60, 'besi'],
                ['BSI-P10', 'Besi Beton Polos 10 mm SNI (12 m)', 'batang', 'Master Steel', 61000, 71000, 68000, 100, 8, 8, 40, 'besi'],
                ['BSI-U12', 'Besi Ulir 12 mm SNI (12 m)', 'batang', 'Master Steel', 92000, 106000, 102000, 60, 4, 6, 30, 'besi'],
                ['BSI-H44', 'Besi Hollow Galvanis 4x4 cm (6 m)', 'batang', 'Alderon', 54000, 65000, 62000, 40, 4, 4, 20, 'besi'],
                ['BSI-WM8', 'Wiremesh M8 2,1 x 5,4 m', 'lembar', 'Union', 385000, 435000, 420000, 10, 2, 1, 6, 'besi'],
                ['BSI-BND', 'Kawat Bendrat 1 kg', 'kg', 'Lion', 21000, 27000, 25500, 30, 5, 1, 6, 'besi'],
            ],
            'Cat & Pelapis' => [
                ['CAT-AVT5', 'Cat Tembok Avitex 5 kg Putih', 'kaleng', 'Avian', 98000, 118000, 112000, 30, 7, 1, 6, 'cat'],
                ['CAT-DLX5', 'Dulux Catylac Interior 5 kg', 'kaleng', 'Dulux', 128000, 152000, 146000, 20, 5, 1, 4, 'cat'],
                ['CAT-NVL5', 'Nippon Vinilex 5 kg', 'kaleng', 'Nippon Paint', 112000, 134000, 128000, 20, 4, 1, 4, 'cat'],
                ['CAT-AVK1', 'Cat Kayu & Besi Avian 1 kg', 'kaleng', 'Avian', 62000, 76000, 72000, 24, 4, 1, 3, 'cat'],
                ['CAT-NLX', 'No Drop Pelapis Anti Bocor 4 kg', 'kaleng', 'No Drop', 165000, 192000, 185000, 12, 3, 1, 2, 'cat'],
                ['CAT-THN', 'Thinner A Super 1 liter', 'kaleng', 'Ultra', 19000, 26000, 24000, 30, 4, 1, 4, 'cat'],
                ['CAT-KS3', 'Kuas Cat 3 inci', 'pcs', 'Eterna', 9000, 14000, 13000, 30, 4, 1, 4, 'perkakas'],
                ['CAT-ROL', 'Roll Cat 9 inci + Gagang', 'pcs', 'Ace Oldfields', 24000, 35000, 32000, 15, 3, 1, 2, 'perkakas'],
            ],
            'Pipa & Sanitasi' => [
                ['PPA-AW12', 'Pipa PVC Rucika AW 1/2 inci (4 m)', 'batang', 'Rucika', 27000, 35000, 33000, 60, 6, 2, 15, 'pipa'],
                ['PPA-AW34', 'Pipa PVC Rucika AW 3/4 inci (4 m)', 'batang', 'Rucika', 36000, 46000, 43500, 40, 4, 2, 10, 'pipa'],
                ['PPA-D3', 'Pipa PVC Wavin D 3 inci (4 m)', 'batang', 'Wavin', 84000, 102000, 97000, 20, 3, 1, 6, 'pipa'],
                ['PPA-ELB', 'Elbow PVC 1/2 inci', 'pcs', 'Rucika', 2500, 4500, 4000, 100, 5, 4, 20, 'pipa'],
                ['PPA-LEM', 'Lem Pipa Isarplas 100 g', 'pcs', 'Isarplas', 11000, 16000, 15000, 30, 4, 1, 4, 'pipa'],
                ['SNT-KRN', 'Kran Air Tembok Onda 1/2 inci', 'pcs', 'Onda', 32000, 45000, 42000, 20, 3, 1, 4, 'pipa'],
                ['SNT-KLS', 'Kloset Jongkok Toto CE6', 'unit', 'Toto', 265000, 315000, 300000, 5, 1, 1, 2, 'pipa'],
            ],
            'Keramik & Lantai' => [
                ['KRM-RM40', 'Keramik Roman 40x40 Putih (dus)', 'dus', 'Roman', 62000, 74000, 71000, 40, 5, 2, 20, 'keramik'],
                ['KRM-PL30', 'Keramik Platinum 30x30 Motif (dus)', 'dus', 'Platinum', 48000, 58000, 55500, 30, 3, 2, 15, 'keramik'],
                ['KRM-GR60', 'Granit Indogress 60x60 Polished (dus)', 'dus', 'Indogress', 182000, 215000, 206000, 15, 2, 2, 10, 'keramik'],
            ],
            'Kayu & Triplek' => [
                ['KYU-TP9', 'Triplek 9 mm 122x244', 'lembar', 'Sengon', 98000, 118000, 113000, 20, 3, 1, 8, 'kayu'],
                ['KYU-MP12', 'Multiplek 12 mm 122x244', 'lembar', 'Meranti', 165000, 192000, 185000, 12, 2, 1, 5, 'kayu'],
                ['KYU-KS46', 'Kayu Kaso 4x6 cm Meranti (4 m)', 'batang', 'Meranti', 34000, 42000, 40000, 50, 4, 4, 25, 'kayu'],
            ],
            'Atap & Plafon' => [
                ['ATP-GYP9', 'Gypsum Board Jayaboard 9 mm', 'lembar', 'Jayaboard', 62000, 75000, 72000, 20, 3, 2, 12, 'kayu'],
                ['ATP-RNG', 'Rangka Hollow Plafon 2x4 cm (4 m)', 'batang', 'Alderon', 21000, 27000, 25500, 60, 4, 6, 30, 'besi'],
                ['ATP-SNG', 'Seng Gelombang 0,20 x 180 cm', 'lembar', 'Kirin', 43000, 54000, 51500, 30, 3, 2, 15, 'besi'],
            ],
            'Listrik' => [
                ['LST-NYM', 'Kabel NYM 2x1,5 mm Eterna (50 m)', 'roll', 'Eterna', 285000, 335000, 322000, 8, 2, 1, 3, 'listrik'],
                ['LST-LED', 'Lampu LED Philips 10 W', 'pcs', 'Philips', 28000, 37000, 35000, 40, 5, 1, 6, 'listrik'],
                ['LST-SKL', 'Saklar Tunggal Broco', 'pcs', 'Broco', 12500, 19000, 17500, 30, 3, 1, 5, 'listrik'],
                ['LST-STK', 'Stop Kontak Broco', 'pcs', 'Broco', 14500, 22000, 20500, 30, 3, 1, 5, 'listrik'],
                ['LST-MCB', 'MCB Schneider 6 A', 'pcs', 'Schneider', 58000, 74000, 70000, 10, 2, 1, 3, 'listrik'],
            ],
            'Paku & Baut' => [
                ['PKU-K5', 'Paku Kayu 5 cm', 'kg', 'Garuda', 17500, 23000, 21500, 40, 6, 1, 8, 'besi'],
                ['PKU-BT', 'Paku Beton 5 cm (kotak)', 'pcs', 'Garuda', 19000, 26000, 24500, 20, 3, 1, 4, 'besi'],
                ['PKU-BAJ', 'Baut Baja Ringan (isi 100)', 'pcs', 'Taso', 28000, 38000, 35500, 15, 3, 1, 5, 'besi'],
            ],
            'Alat & Perkakas' => [
                ['ALT-MTR5', 'Meteran Tukang 5 m', 'pcs', 'Krisbow', 22000, 32000, 30000, 12, 2, 1, 2, 'perkakas'],
                ['ALT-PLU', 'Palu Kambing Gagang Fiber', 'pcs', 'Tekiro', 42000, 58000, 55000, 8, 1, 1, 1, 'perkakas'],
                ['ALT-EMB', 'Ember Cor Hitam', 'pcs', 'Lion Star', 8500, 13000, 12000, 30, 4, 1, 6, 'perkakas'],
                ['ALT-SKP', 'Sekop Pasir Gagang Kayu', 'pcs', 'Tiga Mata', 48000, 65000, 62000, 8, 1, 1, 2, 'perkakas'],
                ['ALT-SRG', 'Sarung Tangan Kain Bintik (lusin)', 'pcs', 'Proyek', 21000, 30000, 28000, 15, 3, 1, 3, 'perkakas'],
            ],
        ];

        $sort = 1;
        foreach ($data as $catName => $rows) {
            $cat = ProductCategory::create(['store_id' => $this->store->id, 'name' => $catName, 'sort_order' => $sort++]);
            foreach ($rows as [$sku, $name, $unit, $brand, $cost, $price, $whole, $min, $weight, $qmin, $qmax, $sup]) {
                $this->catalog[$sku] = compact('sku', 'name', 'unit', 'cost', 'price', 'whole', 'min', 'weight', 'qmin', 'qmax', 'sup');
                $this->products[$sku] = Product::create([
                    'store_id' => $this->store->id,
                    'product_category_id' => $cat->id,
                    'category' => $catName,
                    'name' => $name,
                    'sku' => $sku,
                    'barcode' => '899'.str_pad((string) (crc32($sku) % 10_000_000_000), 10, '0', STR_PAD_LEFT),
                    'brand' => $brand,
                    'unit' => $unit,
                    'base_unit' => 'pcs',
                    'price' => $price,
                    'wholesale_price' => $whole,
                    'cost_price' => $cost,
                    'average_cost' => $cost,
                    'stock_quantity' => 0,
                    'min_stock_level' => $min,
                    'inventory_type' => 'sellable',
                    'is_active' => true,
                    'track_stock' => true,
                    'unlimited_stock' => false,
                    'allow_negative_stock' => false,
                    'allows_decimal' => false,
                    'has_variants' => false,
                    'is_bundle' => false,
                ]);
                $this->stock[$sku] = 0;
            }
        }

        // Kategori bawaan (Umum/Lainnya) yang kosong dihapus agar daftar kategori rapi.
        ProductCategory::where('store_id', $this->store->id)
            ->whereNotIn('name', array_keys($data))
            ->whereDoesntHave('products')
            ->delete();
    }

    private function setupParties(Carbon $start): void
    {
        Carbon::setTestNow($start->copy()->subDays(2)->setTime(11, 0));
        $sup = [
            'semen' => ['CV Sinar Semen Nusantara', '0218821001', 'Jl. Industri Raya No. 12, Bekasi', true],
            'besi' => ['PT Baja Prima Distribusi', '0218821002', 'Kawasan Pergudangan Cakung Blok C-7, Jakarta Timur', true],
            'cat' => ['UD Warna Indah', '0218821003', 'Jl. Pemuda No. 45, Depok', false],
            'pipa' => ['CV Tirta Pipa Mandiri', '0218821004', 'Jl. Raya Parung No. 88, Bogor', false],
            'keramik' => ['PT Keramindo Jaya', '0218821005', 'Jl. Raya Narogong Km 9, Bekasi', true],
            'kayu' => ['UD Kayu Lestari', '0218821006', 'Jl. Sawangan No. 21, Depok', false],
            'listrik' => ['CV Terang Elektrik', '0218821007', 'Glodok Jaya Lt. 2 No. 14, Jakarta Barat', false],
            'perkakas' => ['Toko Grosir Perkakas Maju', '0218821008', 'Jl. Pasar Anyar No. 3, Bogor', false],
        ];
        foreach ($sup as $key => [$name, $phone, $address, $pkp]) {
            $this->suppliers[$key] = Supplier::create([
                'store_id' => $this->store->id,
                'name' => $name,
                'phone' => $phone,
                'address' => $address,
                'notes' => $pkp ? 'PKP — faktur pajak tersedia' : null,
            ]);
            $this->supplierPkp[$key] = $pkp;
        }

        // [nama, telepon, tipe, alamat] — grosir = kontraktor/tukang langganan (harga grosir, boleh bon).
        $cust = [
            ['CV Karya Bangun Persada', '081311110001', 'grosir', 'Jl. Tegar Beriman No. 5, Cibinong'],
            ['PT Griya Asri Developer', '081311110002', 'grosir', 'Ruko Sentul City Blok A-12'],
            ['Mandor Agus Salim', '081311110003', 'grosir', 'Kp. Pabuaran RT 03/04, Cibinong'],
            ['Pak Slamet (Tukang)', '081311110004', 'grosir', 'Perum Bumi Cibinong Endah C3/9'],
            ['CV Rumah Idaman Konstruksi', '081311110005', 'grosir', 'Jl. Mayor Oking No. 77, Citeureup'],
            ['Bapak Darmawan Renovasi', '081311110006', 'grosir', 'Jl. Raya Jakarta-Bogor Km 43'],
            ['Ibu Sari Wulandari', '081311110007', 'ecer', 'Perum Villa Nusa Indah 2 Blok K/15'],
            ['Bapak Joko Susilo', '081311110008', 'ecer', 'Kp. Cikaret RT 01/02'],
            ['Ibu Ratna Dewi', '081311110009', 'ecer', 'Griya Cibinong Indah B7/20'],
            ['Bapak Fajar Nugroho', '081311110010', 'ecer', 'Jl. Pemda No. 32, Cibinong'],
            ['Bapak Hasan Basri', '081311110011', 'ecer', 'Perum Puri Nirwana 3 Blok G/4'],
            ['Ibu Melati Putri', '081311110012', 'ecer', 'Kp. Sukahati RT 05/01'],
            ['Bapak Teguh Prasetyo', '081311110013', 'ecer', 'Perum Graha Cibinong Blok D/11'],
            ['Bapak Andi Firmansyah', '081311110014', 'ecer', 'Jl. Kebon Kopi No. 9'],
            ['Ibu Nurhayati', '081311110015', 'ecer', 'Perum Cimandala Permai C/2'],
            ['Bapak Rudi Hartono', '081311110016', 'ecer', 'Kp. Sasak Panjang RT 02/06'],
            ['Bapak Yusuf Maulana', '081311110017', 'ecer', 'Perum Pesona Cibinong A/8'],
            ['Ibu Lestari Handayani', '081311110018', 'ecer', 'Jl. Raya Tapos No. 61'],
            ['Bapak Eko Wahyudi', '081311110019', 'ecer', 'Perum Bukit Golf Blok F/3'],
            ['Bapak Iwan Setiawan', '081311110020', 'ecer', 'Kp. Kedung Halang RT 04/02'],
        ];
        foreach ($cust as $i => [$name, $phone, $type, $address]) {
            // 12 pelanggan sudah ada sejak awal; sisanya bergabung bertahap (laporan pelanggan baru terisi).
            $joined = $i < 12 ? $start->copy()->subDays(10 + $i) : $start->copy()->addDays((int) (($i - 11) * self::DAYS / 9));
            Carbon::setTestNow($this->at($joined, 9 + $i % 7, 15));
            $c = Customer::create([
                'store_id' => $this->store->id,
                'name' => $name,
                'phone' => $phone,
                'customer_type' => $type,
                'address' => $address,
                'is_active' => true,
                'total_spent' => 0,
                'total_orders' => 0,
            ]);
            $this->customerJoined[$c->id] = $joined->copy();
            $this->customers[] = $c;
        }
    }

    // ------------------------------------------------------------------ pembelian

    private function openingPurchases(Carbon $start): void
    {
        $bySup = [];
        foreach ($this->catalog as $sku => $p) {
            // stok awal ≈ kebutuhan 25–35 hari
            $bySup[$p['sup']][$sku] = max($p['min'] * 2, $this->dailyDemand($sku) * (25 + mt_rand(0, 10)));
        }
        $i = 0;
        foreach ($bySup as $supKey => $lines) {
            $credit = in_array($supKey, ['besi', 'keramik', 'semen'], true);
            $this->createPurchase($supKey, $lines, $this->at($start, 8, 10 + $i * 6), 0, $credit ? 'credit' : 'cash', 'Stok awal pembukaan toko');
            $i++;
        }
    }

    /** @param array<string,int> $lines sku => qty */
    private function createPurchase(string $supKey, array $lines, Carbon $at, int $dayIdx, string $mode, ?string $note = null): Purchase
    {
        Carbon::setTestNow($at);
        $supplier = $this->suppliers[$supKey];
        $pkp = $this->supplierPkp[$supKey];

        $purchase = Purchase::create([
            'store_id' => $this->store->id,
            'supplier_id' => $supplier->id,
            'user_id' => $this->owner->id,
            'purchase_number' => sprintf('PUR-%s-%03d', $at->format('Ymd'), ++$this->purSeq),
            'purchased_at' => $at->copy(),
            'notes' => $note,
            'is_paid' => false,
            'payment_method' => $mode === 'credit' ? 'transfer' : $mode,
            'amount_paid' => 0,
            'due_date' => $mode === 'credit' ? $at->copy()->addDays(30)->toDateString() : null,
            'dpp_amount' => 0,
            'charges_amount' => 0,
            'ppn_amount' => 0,
            'total_amount' => 0,
            'supplier_tax_invoice_no' => $pkp ? '010.000-'.$at->format('y').'.'.str_pad((string) (10_000_000 + $this->purSeq * 7919 % 9_000_000), 8, '0', STR_PAD_LEFT) : null,
        ]);

        $dpp = 0;
        foreach ($lines as $sku => $qty) {
            $p = $this->catalog[$sku];
            $product = $this->products[$sku];
            $subtotal = $qty * $p['cost'];
            $dpp += $subtotal;
            PurchaseLine::create([
                'purchase_id' => $purchase->id,
                'product_id' => $product->id,
                'product_name_snapshot' => $p['name'],
                'sku_snapshot' => $sku,
                'quantity' => $qty,
                'unit_cost' => $p['cost'],
                'input_unit' => $p['unit'],
                'quantity_input' => $qty,
                'base_unit' => 'pcs',
                'qty_base' => $qty,
                'unit_cost_input' => $p['cost'],
                'unit_cost_base' => $p['cost'],
                'subtotal' => $subtotal,
            ]);
            $this->stock[$sku] += $qty;
            $product->stock_quantity = $this->stock[$sku];
            $product->save();
            StockMovement::create([
                'store_id' => $this->store->id,
                'product_id' => $product->id,
                'user_id' => $this->owner->id,
                'quantity_change' => $qty,
                'type' => StockMovement::TYPE_PURCHASE,
                'reference_type' => Purchase::class,
                'reference_id' => $purchase->id,
                'balance_after' => $this->stock[$sku],
                'notes' => 'Pembelian '.$purchase->purchase_number,
            ]);
        }

        $charges = $dpp > 3_000_000 ? (mt_rand(0, 1) ? 250_000 : 400_000) : 0;
        if ($charges > 0 && Schema::hasTable('purchase_charges')) {
            DB::table('purchase_charges')->insert([
                'purchase_id' => $purchase->id, 'store_id' => $this->store->id, 'name' => 'Ongkos kirim truk',
                'amount' => $charges, 'notes' => null, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $ppn = $pkp ? round($dpp * 0.11) : 0;
        $total = $dpp + $charges + $ppn;
        $paid = $mode === 'credit' ? 0 : $total;

        $purchase->update([
            'dpp_amount' => $dpp,
            'charges_amount' => $charges,
            'ppn_amount' => $ppn,
            'total_amount' => $total,
            'amount_paid' => $paid,
            'is_paid' => $paid >= $total,
        ]);
        JournalPoster::postPurchase($this->store, $purchase->fresh());

        if ($mode === 'credit') {
            // Dilunasi menjelang jatuh tempo (20–34 hari); yang belum waktunya tetap jadi utang usaha.
            $payDay = $dayIdx + 20 + mt_rand(0, 14);
            $this->payableQueue[$payDay][] = $purchase->id;
        }

        return $purchase;
    }

    private function payPurchase(int $purchaseId, Carbon $at, bool $partial): void
    {
        Carbon::setTestNow($at);
        $purchase = Purchase::find($purchaseId);
        $remaining = round((float) $purchase->total_amount - (float) $purchase->amount_paid, 2);
        if ($remaining <= 0) {
            return;
        }
        $amount = $partial ? round($remaining * 0.5, -3) : $remaining;
        $method = 'transfer';
        $newPaid = round((float) $purchase->amount_paid + $amount, 2);
        $purchase->update(['amount_paid' => $newPaid, 'is_paid' => $newPaid >= (float) $purchase->total_amount, 'payment_method' => $method]);
        $payment = PurchasePayment::create([
            'purchase_id' => $purchase->id, 'user_id' => $this->owner->id, 'amount' => $amount,
            'payment_method' => $method, 'payment_date' => $at->toDateString(),
        ]);
        // Jurnal sama persis seperti PurchasePaymentController: Debit Utang Usaha, Kredit Bank.
        $bank = ChartOfAccountsBootstrap::account($this->store, ChartOfAccountsBootstrap::CODE_BANK);
        $utang = ChartOfAccountsBootstrap::account($this->store, ChartOfAccountsBootstrap::CODE_UTANG_USAHA);
        $entry = JournalEntry::create([
            'store_id' => $this->store->id, 'entry_date' => $at->toDateString(), 'reference_type' => PurchasePayment::class,
            'reference_id' => $payment->id, 'description' => 'Pelunasan pembelian '.$purchase->purchase_number, 'user_id' => $this->owner->id,
        ]);
        JournalLine::create(['journal_entry_id' => $entry->id, 'chart_of_account_id' => $utang->id, 'debit' => $amount, 'credit' => 0, 'memo' => 'Pelunasan cicilan pembelian '.$purchase->purchase_number]);
        JournalLine::create(['journal_entry_id' => $entry->id, 'chart_of_account_id' => $bank->id, 'debit' => 0, 'credit' => $amount, 'memo' => 'Pembayaran utang via Bank']);
    }

    // ------------------------------------------------------------------ simulasi harian

    private function simulateDay(Carbon $day, int $d, bool $isToday): void
    {
        $dow = $day->dayOfWeekIso; // 1=Senin … 7=Minggu
        $progress = $d / self::DAYS;
        $user = ($d % 3 === 1 && ! $isToday) ? $this->owner : ($isToday ? $this->owner : $this->cashier);

        // Pagi: pembayaran utang supplier & restock barang yang menipis.
        foreach ($this->payableQueue[$d] ?? [] as $pid) {
            // Satu pembayaran dicicil (separuh) agar laporan utang punya contoh pembayaran sebagian.
            $this->payPurchase($pid, $this->at($day, 8, 5), $pid % 4 === 0);
        }
        if ($d > 0) {
            $this->restock($day, $d);
        }

        // Shift kasir.
        Carbon::setTestNow($this->at($day, 7, 55));
        $shift = CashShift::create([
            'store_id' => $this->store->id, 'user_id' => $user->id,
            'opened_at' => $this->at($day, 7, 55), 'opening_cash' => 500_000,
        ]);

        // Jumlah transaksi: Senin–Sabtu ramai, Minggu sepi; tren naik pelan sepanjang periode.
        $base = $dow === 7 ? mt_rand(5, 8) : mt_rand(11, 17);
        $count = (int) round($base * (0.85 + 0.35 * $progress));
        $local = $this->realNow->copy()->setTimezone(self::STORE_TZ);
        $nowMinute = $local->hour * 60 + $local->minute;
        $endMinute = $isToday ? min(16 * 60 + 30, max(9 * 60, $nowMinute - 10)) : 16 * 60 + 30;
        if ($isToday) {
            $count = max(3, (int) round($count * max(0.25, ($endMinute - 8 * 60) / (8.5 * 60))));
        }
        $minutes = [];
        for ($i = 0; $i < $count; $i++) {
            $minutes[] = mt_rand(8 * 60 + 5, $endMinute);
        }
        sort($minutes);

        $cashIn = 0;
        $cashOut = 0;
        $cashSales = 0;
        foreach ($minutes as $m) {
            $cashSales += $this->createSale($this->at($day, intdiv($m, 60), $m % 60, mt_rand(0, 59)), $d, $user, $shift);
        }

        // Pelunasan hutang pelanggan yang jatuh pada hari ini.
        foreach ($this->receivableQueue[$d] ?? [] as $job) {
            $this->collectReceivable($job['tx'], $this->at($day, mt_rand(10, 15), mt_rand(0, 59)), $user, $shift);
        }

        // Kas keluar/masuk kecil di laci.
        if (mt_rand(1, 100) <= 35) {
            $amt = [25_000, 35_000, 50_000, 75_000][mt_rand(0, 3)];
            $cashOut += $amt;
            $this->cashTx($shift, $user, $this->at($day, 12, mt_rand(0, 50)), 'out', 'expense', $amt, ['Uang makan kuli angkut', 'Beli air galon & es', 'Parkir & retribusi pasar', 'Bensin motor antar barang'][mt_rand(0, 3)]);
        }
        if (mt_rand(1, 100) <= 8) {
            $amt = [100_000, 150_000][mt_rand(0, 1)];
            $cashIn += $amt;
            $this->cashTx($shift, $user, $this->at($day, 14, mt_rand(0, 50)), 'in', 'non_revenue', $amt, 'Tambahan uang kembalian dari pemilik');
        }

        // Beban operasional bulanan & mingguan (dibayar pemilik, di luar laci kasir).
        $this->expenses($day);

        // Tutup shift (kecuali hari ini: shift pemilik dibiarkan terbuka agar POS bisa dipakai).
        if (! $isToday) {
            $expected = 500_000 + $cashSales + $cashIn - $cashOut;
            $diff = mt_rand(1, 100) <= 85 ? 0 : [-5_000, -2_000, 3_000, -10_000][mt_rand(0, 3)];
            Carbon::setTestNow($this->at($day, 17, 5));
            $shift->update([
                'closed_at' => $this->at($day, 17, 5),
                'expected_cash' => $expected,
                'closing_cash_counted' => $expected + $diff,
                'difference_amount' => $diff,
                'closing_notes' => $diff === 0 ? 'Kas sesuai' : 'Selisih kecil uang receh',
            ]);
        }
    }

    private function restock(Carbon $day, int $d): void
    {
        $lastDays = $d > self::DAYS - 13;
        $bySup = [];
        foreach ($this->catalog as $sku => $p) {
            // Untuk demo: semen Tiga Roda & cat Avitex tidak di-restock 12 hari terakhir → tampil "stok menipis".
            if ($lastDays && in_array($sku, ['SMN-TR50', 'CAT-AVT5'], true)) {
                continue;
            }
            if ($this->stock[$sku] <= $p['min'] * 1.3) {
                $bySup[$p['sup']][$sku] = max($p['min'], $this->dailyDemand($sku) * (18 + mt_rand(0, 8)));
            }
        }
        $i = 0;
        foreach ($bySup as $supKey => $lines) {
            $mode = ['cash', 'transfer', 'credit', 'cash'][($d + $i) % 4];
            $this->createPurchase($supKey, $lines, $this->at($day, 7, 20 + $i * 5), $d, $mode, 'Restock barang menipis');
            $i++;
        }
    }

    private function dailyDemand(string $sku): int
    {
        $p = $this->catalog[$sku];

        return max(1, (int) ceil($p['weight'] * (($p['qmin'] + $p['qmax']) / 2) / 9));
    }

    /** @return int uang tunai yang masuk laci */
    private function createSale(Carbon $at, int $d, User $user, CashShift $shift): int
    {
        Carbon::setTestNow($at);
        $customer = null;
        $r = mt_rand(1, 100);
        if ($r <= 42) {
            $pool = array_values(array_filter($this->customers, fn ($c) => $this->customerJoined[$c->id]->lte($at)));
            if ($pool) {
                // kontraktor lebih sering belanja
                $grosir = array_values(array_filter($pool, fn ($c) => $c->customer_type === 'grosir'));
                $customer = ($grosir && mt_rand(1, 100) <= 55) ? $grosir[mt_rand(0, count($grosir) - 1)] : $pool[mt_rand(0, count($pool) - 1)];
            }
        }
        $isGrosir = $customer?->customer_type === 'grosir';

        // pilih 1–4 barang berbobot laku
        $nItems = $isGrosir ? mt_rand(2, 5) : [1, 1, 2, 2, 3, 4][mt_rand(0, 5)];
        $chosen = [];
        $guard = 0;
        while (count($chosen) < $nItems && $guard++ < 40) {
            $sku = $this->weightedSku();
            if (isset($chosen[$sku])) {
                continue;
            }
            $p = $this->catalog[$sku];
            $qty = mt_rand($p['qmin'], $p['qmax']);
            if ($isGrosir) {
                $qty = (int) ceil($qty * (1.5 + mt_rand(0, 15) / 10));
            }
            $floor = in_array($sku, ['SMN-TR50', 'CAT-AVT5'], true) ? 8 : 0; // sisakan sedikit untuk demo stok menipis
            $qty = min($qty, $this->stock[$sku] - $floor);
            if ($qty <= 0) {
                continue;
            }
            $chosen[$sku] = $qty;
        }
        if (! $chosen) {
            return 0;
        }

        $subtotal = 0;
        $lines = [];
        foreach ($chosen as $sku => $qty) {
            $p = $this->catalog[$sku];
            $unit = $isGrosir ? $p['whole'] : $p['price'];
            $lineTotal = $unit * $qty;
            $subtotal += $lineTotal;
            $lines[] = [$sku, $qty, $unit, $lineTotal];
        }
        $discount = ($subtotal >= 2_000_000 && mt_rand(1, 100) <= 25) ? (int) (floor($subtotal * 0.02 / 1000) * 1000) : 0;
        $afterDiscount = $subtotal - $discount;
        $tax = (int) round($afterDiscount * 0.11);
        $total = $afterDiscount + $tax;

        // metode bayar
        $isCredit = $isGrosir && mt_rand(1, 100) <= 38;
        $method = $isCredit ? 'credit' : (($m = mt_rand(1, 100)) <= 52 ? 'cash' : ($m <= 80 ? 'qris' : 'transfer'));
        $cash = $method === 'cash' ? $total : 0;
        $paid = $isCredit ? 0 : ($method === 'cash' ? (int) (ceil($total / 10_000) * 10_000) : $total);
        $change = $method === 'cash' ? $paid - $total : 0;
        $dueAt = $isCredit ? $at->copy()->addDays([14, 30, 30][mt_rand(0, 2)])->setTime(23, 59) : null;

        $tx = Transaction::create([
            'store_id' => $this->store->id,
            'customer_id' => $customer?->id,
            'user_id' => $user->id,
            'cash_shift_id' => $shift->id,
            'transaction_number' => sprintf('TXN-%s-%04d', $at->format('Ymd'), ++$this->txSeq),
            'status_token' => Str::random(64),
            'status' => 'completed',
            'payment_method' => $method,
            'payment_status' => $isCredit ? 'unpaid' : 'paid',
            'sales_channel' => 'walk_in',
            'subtotal' => $subtotal,
            'tax_amount' => $tax,
            'discount_amount' => $discount,
            'service_charge_amount' => 0,
            'tip_amount' => 0,
            'total_amount' => $total,
            'amount_paid' => $paid,
            'cash_amount' => $cash,
            'change_amount' => $change,
            'completed_at' => $at->copy(),
            'debt_due_at' => $dueAt,
            'notes' => $isCredit ? 'Bon proyek — dibayar sesuai termin' : null,
        ]);

        if (! $isCredit) {
            TransactionPayment::create([
                'transaction_id' => $tx->id, 'store_id' => $this->store->id, 'method' => $method,
                'amount' => $method === 'cash' ? $total : $total, 'reference' => $method === 'cash' ? null : strtoupper(Str::random(10)), 'sort_order' => 0,
            ]);
        }

        foreach ($lines as [$sku, $qty, $unit, $lineTotal]) {
            $p = $this->catalog[$sku];
            $product = $this->products[$sku];
            $cost = $p['cost'] * $qty;
            TransactionItem::create([
                'transaction_id' => $tx->id,
                'product_id' => $product->id,
                'product_name' => $p['name'],
                'product_sku' => $sku,
                'quantity' => $qty,
                'free_quantity' => 0,
                'unit_price' => $unit,
                'total_price' => $lineTotal,
                'discount_amount' => 0,
                'product_snapshot' => ['name' => $p['name'], 'sku' => $sku, 'unit' => $p['unit'], 'price' => $unit],
                'unit_cost_snapshot' => $p['cost'],
                'total_cost_snapshot' => $cost,
                'gross_profit_snapshot' => $lineTotal - $cost,
            ]);
            $this->stock[$sku] -= $qty;
            $product->stock_quantity = $this->stock[$sku];
            $product->save();
            StockMovement::create([
                'store_id' => $this->store->id,
                'product_id' => $product->id,
                'user_id' => $user->id,
                'quantity_change' => -$qty,
                'type' => StockMovement::TYPE_SALE,
                'reference_type' => Transaction::class,
                'reference_id' => $tx->id,
                'balance_after' => $this->stock[$sku],
                'notes' => 'Penjualan '.$tx->transaction_number,
            ]);
        }

        JournalPoster::postSaleTransaction($this->store, $tx->fresh());

        if ($isCredit) {
            // Sebagian besar bon dilunasi (5–35 hari); yang jadwalnya lewat hari ini tetap jadi piutang.
            $payDay = $d + 5 + mt_rand(0, 30);
            if ($payDay <= self::DAYS) {
                $this->receivableQueue[$payDay][] = ['tx' => $tx->id];
            }
        }

        return $cash;
    }

    private function weightedSku(): string
    {
        static $bag = null;
        if ($bag === null) {
            $bag = [];
            foreach ($this->catalog as $sku => $p) {
                for ($i = 0; $i < $p['weight']; $i++) {
                    $bag[] = $sku;
                }
            }
        }

        return $bag[mt_rand(0, count($bag) - 1)];
    }

    private function collectReceivable(int $txId, Carbon $at, User $user, CashShift $shift): void
    {
        Carbon::setTestNow($at);
        $tx = Transaction::find($txId);
        $amount = (float) $tx->total_amount - (float) $tx->amount_paid;
        if ($amount <= 0) {
            return;
        }
        $method = mt_rand(1, 100) <= 70 ? 'transfer' : 'cash';
        $payment = ReceivablePayment::create([
            'transaction_id' => $tx->id,
            'store_id' => $this->store->id,
            'customer_id' => $tx->customer_id,
            'user_id' => $user->id,
            'cash_shift_id' => $method === 'cash' ? $shift->id : null,
            'amount' => $amount,
            'payment_method' => $method,
            'received_at' => $at->copy(),
            'reference_number' => 'RCV-'.$at->format('Ymd').'-'.$tx->id,
            'notes' => 'Pelunasan bon '.$tx->transaction_number,
            'idempotency_key' => (string) Str::uuid(),
            'created_by' => $user->id,
        ]);
        $tx->update(['amount_paid' => (float) $tx->total_amount, 'payment_status' => 'paid']);
        $entry = JournalPoster::postReceivableCollection($this->store, $payment);
        if ($entry) {
            $payment->update(['journal_entry_id' => $entry->id]);
        }
    }

    private function cashTx(CashShift $shift, User $user, Carbon $at, string $type, string $category, int $amount, string $notes): void
    {
        Carbon::setTestNow($at);
        $ct = CashTransaction::create([
            'store_id' => $this->store->id, 'user_id' => $user->id, 'cash_shift_id' => $shift->id,
            'type' => $type, 'category' => $category, 'amount' => $amount, 'party_name' => null, 'notes' => $notes,
        ]);
        JournalPoster::postCashTransaction($this->store, $ct);
    }

    private function expenses(Carbon $day): void
    {
        $items = [];
        if ($day->day === 1) {
            $items[] = ['sewa', 'Sewa ruko & gudang bulan '.$day->translatedFormat('F'), 6_500_000, 'transfer'];
        }
        if ($day->day === 5) {
            $items[] = ['listrik', 'Tagihan listrik PLN', 850_000 + mt_rand(0, 20) * 10_000, 'transfer'];
            $items[] = ['internet', 'Internet IndiHome', 375_000, 'transfer'];
            $items[] = ['air', 'Tagihan air PDAM', 140_000 + mt_rand(0, 6) * 10_000, 'cash'];
        }
        if ($day->day === 28) {
            $items[] = ['gaji', 'Gaji 3 karyawan (kasir & 2 helper gudang)', 10_500_000, 'transfer'];
        }
        if ($day->dayOfWeekIso === 6) {
            $items[] = ['transport', 'Bensin & perawatan pickup antar barang', 280_000 + mt_rand(0, 12) * 10_000, 'cash'];
        }
        foreach ($items as $k => [$cat, $desc, $amount, $method]) {
            $at = $this->at($day, 16, 40 + $k * 3);
            Carbon::setTestNow($at);
            $expense = OperationalExpense::create([
                'store_id' => $this->store->id, 'user_id' => $this->owner->id, 'category' => $cat, 'description' => $desc,
                'amount' => $amount, 'expense_date' => $day->toDateString(), 'payment_method' => $method,
            ]);
            // Jurnal sama persis seperti OperationalExpenseController: Debit Beban Operasional, Kredit Kas/Bank.
            $pay = ChartOfAccountsBootstrap::account($this->store, $method === 'cash' ? ChartOfAccountsBootstrap::CODE_KAS : ChartOfAccountsBootstrap::CODE_BANK);
            $beban = ChartOfAccountsBootstrap::account($this->store, ChartOfAccountsBootstrap::CODE_BEBAN_OPERASIONAL);
            $entry = JournalEntry::create([
                'store_id' => $this->store->id, 'entry_date' => $day->toDateString(), 'reference_type' => OperationalExpense::class,
                'reference_id' => $expense->id, 'description' => 'Beban operasional: '.$desc, 'user_id' => $this->owner->id,
            ]);
            JournalLine::create(['journal_entry_id' => $entry->id, 'chart_of_account_id' => $beban->id, 'debit' => $amount, 'credit' => 0, 'memo' => $cat.': '.$desc]);
            JournalLine::create(['journal_entry_id' => $entry->id, 'chart_of_account_id' => $pay->id, 'debit' => 0, 'credit' => $amount, 'memo' => 'Pembayaran beban via '.($method === 'cash' ? 'Kas' : 'Bank')]);
            $expense->update(['journal_entry_id' => $entry->id]);
        }
    }

    private function recomputeCustomerStats(): void
    {
        foreach ($this->customers as $c) {
            $agg = Transaction::where('customer_id', $c->id)->where('status', 'completed')
                ->selectRaw('COUNT(*) n, COALESCE(SUM(total_amount),0) s, MAX(completed_at) last')->first();
            $last = Transaction::where('customer_id', $c->id)->where('status', 'completed')->orderByDesc('completed_at')->first();
            $c->forceFill([
                'total_orders' => (int) $agg->n,
                'total_spent' => (float) $agg->s,
                'last_purchase_at' => $last?->completed_at,
                'last_purchase_amount' => $last?->total_amount,
            ])->saveQuietly();
        }
    }

    /**
     * Demo modul Absensi + Gaji: add-on aktif, 2 karyawan, jadwal, komponen gaji, absensi bulan lalu
     * (kasir dari shift kasir + absen manual), lembur, hari libur, lalu periode gaji bulan lalu
     * dihitung → disetujui → dibayar (jurnal otomatis) lewat service yang sama dengan aplikasi.
     */
    private function setupAttendancePayroll(): void
    {
        $store = $this->store->fresh();
        $store->update([
            'attendance_payroll_until' => Carbon::today()->addYear(),
            'bpjs_enabled' => true,
            'pph21_enabled' => true,
            'absence_deduction_enabled' => true,
            'late_penalty_method' => 'per_day',
            'late_penalty_per_day' => 10000,
        ]);
        AttendanceSettings::update($store, [
            'enabled' => true,
            'require_selfie' => false,
            'require_geolocation' => false,
            'grace_minutes' => 15,
            'overtime_requires_approval' => true,
            'auto_generate_from_cash_shift' => true,
        ]);
        $store->refresh();

        $from = Carbon::today()->subMonthNoOverflow()->startOfMonth();
        $to = $from->copy()->endOfMonth();
        $joinedJoko = $from->copy()->addDays(15);

        $rina = Employee::create([
            'store_id' => $store->id, 'user_id' => $this->cashier->id, 'name' => 'Rina Lestari', 'position' => 'Kasir',
            'is_active' => true, 'tax_status' => 'TK/0', 'joined_at' => $from->copy()->subYear()->toDateString(),
        ]);
        $joko = Employee::create([
            'store_id' => $store->id, 'name' => 'Joko Susilo', 'position' => 'Staf Gudang',
            'is_active' => true, 'tax_status' => 'K/1', 'joined_at' => $joinedJoko->toDateString(),
        ]);

        foreach ([$rina, $joko] as $e) {
            WorkSchedule::create([
                'store_id' => $store->id, 'employee_id' => $e->id, 'name' => 'Pagi', 'work_days' => [1, 2, 3, 4, 5, 6],
                'shift_start' => '08:00', 'shift_end' => '16:00', 'break_minutes' => 60, 'is_active' => true,
            ]);
        }

        foreach ([
            [$rina, 'Gaji Pokok', 'earning', true, 4_000_000],
            [$rina, 'Tunjangan Transport', 'earning', true, 300_000],
            [$rina, 'Uang Makan', 'earning', false, 500_000],
            [$joko, 'Gaji Pokok', 'earning', true, 3_200_000],
            [$joko, 'Kasbon', 'deduction', true, 200_000],
        ] as $i => [$e, $name, $type, $fixed, $amount]) {
            SalaryComponent::create([
                'store_id' => $store->id, 'employee_id' => $e->id, 'name' => $name, 'type' => $type,
                'is_fixed' => $fixed, 'amount' => $amount, 'sort_order' => $i,
            ]);
        }

        // Hari libur toko: Senin pertama setelah tanggal 20 bulan lalu.
        $holiday = $from->copy()->addDays(20);
        while (! $holiday->isMonday()) {
            $holiday->addDay();
        }
        Holiday::create(['store_id' => $store->id, 'date' => $holiday->toDateString(), 'name' => 'Libur stock opname']);

        $overtimeDay = $from->copy()->addDays(14);
        if ($overtimeDay->isSunday()) {
            $overtimeDay->addDay();
        }
        foreach ([[$overtimeDay, 2], [$holiday, 3]] as [$date, $hours]) {
            OvertimeRequest::create([
                'store_id' => $store->id, 'employee_id' => $rina->id, 'work_date' => $date->toDateString(), 'hours' => $hours,
                'status' => 'approved', 'approved_by' => $this->owner->id, 'approved_at' => now(), 'reason' => 'Bongkar muat semen',
            ]);
        }

        $service = app(AttendanceService::class);
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $service->generateFromCashShifts($store, $d->toImmutable());
        }

        // Joko: hadir tepat waktu sejak tanggal masuk, kecuali 2 hari alpa.
        $jokoAbsent = [$joinedJoko->copy()->addDays(4)->toDateString(), $joinedJoko->copy()->addDays(5)->toDateString()];
        for ($d = $joinedJoko->copy(); $d->lte($to); $d->addDay()) {
            if ($d->isSunday() || $d->isSameDay($holiday) || in_array($d->toDateString(), $jokoAbsent, true)) {
                continue;
            }
            $a = Attendance::create([
                'store_id' => $store->id, 'employee_id' => $joko->id, 'work_date' => $d->toDateString(),
                'clock_in' => $d->toDateString().' 07:55:00', 'clock_out' => $d->toDateString().' 16:05:00',
                'status' => Attendance::STATUS_PRESENT, 'source' => Attendance::SOURCE_MANUAL,
            ]);
            $service->applySchedule($a);
        }

        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $service->markAbsent($store, $d->toImmutable());
        }
        Attendance::where('employee_id', $joko->id)->where('work_date', '<', $joinedJoko->toDateString())->delete();

        // Hari Rina tanpa shift kasir (pemilik yang jaga) dicatat manual: 2 hari telat, 1 hari tetap alpa.
        $gaps = Attendance::where('employee_id', $rina->id)->where('status', Attendance::STATUS_ABSENT)->orderBy('work_date')->get();
        foreach ($gaps->slice(0, max(0, $gaps->count() - 1))->values() as $i => $row) {
            $date = $row->work_date->toDateString();
            $row->forceFill([
                'clock_in' => $date.($i < 2 ? ' 08:25:00' : ' 07:55:00'),
                'clock_out' => $date.' 16:02:00',
                'source' => Attendance::SOURCE_MANUAL,
            ])->save();
            $service->applySchedule($row);
        }

        $period = PayrollPeriod::create([
            'store_id' => $store->id,
            'name' => 'Gaji '.$from->locale('id')->translatedFormat('F Y'),
            'period_start' => $from->toDateString(),
            'period_end' => $to->toDateString(),
            'status' => PayrollPeriod::STATUS_DRAFT,
        ]);
        app(PayrollService::class)->runPeriod($store, $period);
        $period->update(['status' => PayrollPeriod::STATUS_APPROVED, 'approved_by' => $this->owner->id, 'approved_at' => now()]);

        $entry = JournalPoster::postPayroll($store, $period, 'bank', Carbon::today()->toDateString(), $this->owner->id);
        $period->update([
            'status' => PayrollPeriod::STATUS_PAID,
            'paid_by' => $this->owner->id,
            'paid_at' => now(),
            'payment_date' => Carbon::today()->toDateString(),
            'payment_method' => 'bank',
            'journal_entry_id' => $entry?->id,
        ]);

        $this->command?->info(sprintf(
            'Absensi & gaji: %s (%s karyawan, gaji bersih %s, jurnal #%s)',
            $period->name,
            $period->runs()->count(),
            number_format((float) $period->runs()->sum('net_salary'), 0, ',', '.'),
            $entry?->id ?? '-',
        ));
    }

    private function report(): void
    {
        $sid = $this->store->id;
        $bal = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('chart_of_accounts as a', 'a.id', '=', 'l.chart_of_account_id')->where('e.store_id', $sid)
            ->groupBy('a.code', 'a.name')->selectRaw('a.code, a.name, SUM(l.debit) d, SUM(l.credit) c')->orderBy('a.code')->get();
        $this->command?->info('Toko demo: '.$this->store->name.' (id '.$sid.')');
        $this->command?->info(sprintf(
            'Transaksi: %d | Item: %d | Pembelian: %d | Jurnal: %d | Piutang terbuka: %d | Utang supplier terbuka: %d',
            DB::table('transactions')->where('store_id', $sid)->count(),
            DB::table('transaction_items')->whereIn('transaction_id', DB::table('transactions')->where('store_id', $sid)->select('id'))->count(),
            DB::table('purchases')->where('store_id', $sid)->count(),
            DB::table('journal_entries')->where('store_id', $sid)->count(),
            DB::table('transactions')->where('store_id', $sid)->where('payment_status', '!=', 'paid')->count(),
            DB::table('purchases')->where('store_id', $sid)->where('is_paid', false)->count(),
        ));
        $td = 0;
        $tc = 0;
        foreach ($bal as $b) {
            $td += $b->d;
            $tc += $b->c;
            $this->command?->line(sprintf('  %-8s %-34s saldo %16s', $b->code, Str::limit($b->name, 34, ''), number_format($b->d - $b->c, 0, ',', '.')));
        }
        $this->command?->info(sprintf('Total debit %s | kredit %s | selisih %s', number_format($td, 0, ',', '.'), number_format($tc, 0, ',', '.'), number_format($td - $tc, 2, ',', '.')));
        $low = Product::where('store_id', $sid)->whereColumn('stock_quantity', '<=', 'min_stock_level')->where('stock_quantity', '>', 0)->pluck('stock_quantity', 'name');
        $this->command?->info('Stok menipis: '.$low->map(fn ($q, $n) => "$n ($q)")->implode(', '));
    }
}
