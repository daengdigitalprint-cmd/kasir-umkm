# Rencana Implementasi Fitur KPI (Key Performance Indicators)

## 📋 Overview

Fitur KPI dirancang untuk memberikan insight performa bisnis secara komprehensif kepada admin dan owner outlet. Sistem akan melacak metrik penjualan, produk, profitabilitas, pelanggan, dan operasional dengan target otomatis berbasis growth percentage.

**Tipe KPI:** Komprehensif (Penjualan, Produk, Profitabilitas, Pelanggan, Operasional)  
**Level Tracking:** Per Outlet  
**Target System:** Otomatis (Growth %)  
**UI:** Dashboard terpisah di `/kpi`

---

## 🎯 Objektif

1. Memberikan visibilitas real-time terhadap performa outlet
2. Membantu owner/admin mengidentifikasi tren dan masalah lebih cepat
3. Membandingkan performa antar outlet (untuk multi-outlet)
4. Memberikan insight untuk decision making berbasis data
5. Tracking pencapaian target tanpa input manual berulang

---

## 📊 KPI Metrics yang Akan Ditampilkan

### 1. Penjualan & Revenue (Primary)
- **Omset Bulanan:** Total revenue bulan berjalan vs target
- **Jumlah Transaksi:** Count transaksi vs target
- **Average Transaction Value (ATV):** Revenue / transaksi
- **Growth Rate:** Persentase pertumbuhan vs bulan lalu
- **Trend Harian:** Chart omset per hari dalam bulan berjalan

### 2. Produk & Inventory
- **Top 10 Produk Terlaris:** Berdasarkan quantity dan revenue
- **Slow Moving Products:** Produk dengan < 5 transaksi per bulan
- **Inventory Turnover Rate:** Rasio penjualan terhadap stok rata-rata
- **Stok Menipis:** Produk di bawah `min_stock_level`
- **Produk Mendekati Expired:** (Jika batch tracking aktif) produk expired < 7 hari

### 3. Profitabilitas
- **Gross Profit Margin:** ((Price - Cost) / Price) × 100%
- **Net Profit:** Revenue - Operational Expenses
- **Profit per Transaction:** Net profit / jumlah transaksi
- **Contribution Margin per Product:** Profit margin produk terlaris

### 4. Pelanggan
- **Pelanggan Aktif:** Customer dengan transaksi bulan ini
- **Pelanggan Baru:** First-time customer bulan ini
- **Repeat Customer Rate:** % customer yang transaksi > 1x
- **Customer Retention Rate:** % customer bulan lalu yang kembali

### 5. Operasional
- **Produktivitas Kasir:** Rata-rata transaksi per user per hari
- **Alert Stok Menipis:** Count produk < min stock
- **Expense Ratio:** Operational expenses / revenue (%)
- **Transaction per Hour:** Peak hours analysis

---

## 🏗️ Arsitektur Database

### Tabel Baru

#### 1. `kpi_targets`
Target KPI per outlet per periode dengan auto-growth calculation.

```sql
CREATE TABLE kpi_targets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    store_id BIGINT UNSIGNED NOT NULL,
    period VARCHAR(7) NOT NULL, -- Format: YYYY-MM
    growth_percentage DECIMAL(5, 2) DEFAULT 10.00, -- Default 10%
    
    -- Target metrics (null = auto-calculate)
    target_revenue DECIMAL(15, 2) NULL,
    target_transactions INT NULL,
    target_margin DECIMAL(5, 2) NULL,
    target_customers INT NULL,
    
    -- Baseline data untuk perhitungan auto-target
    baseline_data JSON NULL, -- {revenue: 40000000, transactions: 500, ...}
    
    auto_generated BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_store_period (store_id, period),
    FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE,
    INDEX idx_period (period)
);
```

#### 2. `kpi_snapshots`
Snapshot harian untuk performa historis (menghindari query berat real-time).

```sql
CREATE TABLE kpi_snapshots (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    store_id BIGINT UNSIGNED NOT NULL,
    snapshot_date DATE NOT NULL,
    
    -- Metrics (stored as JSON untuk fleksibilitas)
    metrics JSON NOT NULL,
    /* Format JSON:
    {
        "revenue": 1500000,
        "transactions_count": 45,
        "avg_transaction_value": 33333.33,
        "gross_profit": 450000,
        "net_profit": 300000,
        "active_customers": 12,
        "new_customers": 3,
        "top_products": [
            {"product_id": 5, "name": "Kopi Susu", "quantity": 25, "revenue": 375000}
        ],
        "low_stock_count": 8,
        "near_expiry_count": 2
    }
    */
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_store_date (store_id, snapshot_date),
    FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE,
    INDEX idx_snapshot_date (snapshot_date)
);
```

---

## 🔧 Backend Implementation

### 1. Service Layer: `KPIService.php`

**Lokasi:** `app/Services/KPIService.php`

**Methods:**

```php
class KPIService
{
    /**
     * Generate target otomatis untuk bulan berikutnya
     * berdasarkan performa bulan sebelumnya + growth %
     */
    public function generateAutoTarget(Store $store, string $period): KpiTarget;
    
    /**
     * Hitung KPI metrics untuk periode tertentu
     * (digunakan untuk display real-time)
     */
    public function calculateMonthlyKPI(Store $store, string $period): array;
    
    /**
     * Compare current performance dengan target
     * Return: progress percentage dan status (on-track/behind/exceeded)
     */
    public function compareWithTarget(Store $store, string $period): array;
    
    /**
     * Generate daily snapshot (dipanggil oleh scheduled job)
     */
    public function createDailySnapshot(Store $store): KpiSnapshot;
    
    /**
     * Get top performing products
     */
    public function getTopProducts(Store $store, string $period, int $limit = 10): array;
    
    /**
     * Get slow moving products
     */
    public function getSlowMovingProducts(Store $store, string $period): array;
    
    /**
     * Calculate customer metrics (active, new, retention)
     */
    public function calculateCustomerMetrics(Store $store, string $period): array;
    
    /**
     * Get operational alerts (low stock, near expiry, dll)
     */
    public function getOperationalAlerts(Store $store): array;
}
```

**Logic Auto-Target Generation:**

```php
public function generateAutoTarget(Store $store, string $period): KpiTarget
{
    // Parse periode (misal: "2026-07")
    $targetMonth = Carbon::createFromFormat('Y-m', $period);
    $previousMonth = $targetMonth->copy()->subMonth();
    $previousPeriod = $previousMonth->format('Y-m');
    
    // Cari target sebelumnya atau ambil data aktual
    $previousTarget = KpiTarget::where('store_id', $store->id)
        ->where('period', $previousPeriod)
        ->first();
    
    // Hitung baseline dari bulan sebelumnya
    $baselineRevenue = Transaction::where('store_id', $store->id)
        ->whereYear('created_at', $previousMonth->year)
        ->whereMonth('created_at', $previousMonth->month)
        ->sum('total_amount');
    
    $baselineTransactions = Transaction::where('store_id', $store->id)
        ->whereYear('created_at', $previousMonth->year)
        ->whereMonth('created_at', $previousMonth->month)
        ->count();
    
    // Ambil growth % dari target sebelumnya atau default 10%
    $growthPercentage = $previousTarget?->growth_percentage ?? 10.0;
    
    // Hitung target baru
    $targetRevenue = $baselineRevenue * (1 + ($growthPercentage / 100));
    $targetTransactions = $baselineTransactions * (1 + ($growthPercentage / 100));
    
    return KpiTarget::create([
        'store_id' => $store->id,
        'period' => $period,
        'growth_percentage' => $growthPercentage,
        'target_revenue' => $targetRevenue,
        'target_transactions' => (int) $targetTransactions,
        'baseline_data' => [
            'revenue' => $baselineRevenue,
            'transactions' => $baselineTransactions,
        ],
        'auto_generated' => true,
    ]);
}
```

---

### 2. Models

#### `KpiTarget.php`
```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KpiTarget extends Model
{
    protected $fillable = [
        'store_id',
        'period',
        'growth_percentage',
        'target_revenue',
        'target_transactions',
        'target_margin',
        'target_customers',
        'baseline_data',
        'auto_generated',
    ];

    protected $casts = [
        'growth_percentage' => 'decimal:2',
        'target_revenue' => 'decimal:2',
        'target_margin' => 'decimal:2',
        'baseline_data' => 'array',
        'auto_generated' => 'boolean',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}
```

#### `KpiSnapshot.php`
```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KpiSnapshot extends Model
{
    protected $fillable = [
        'store_id',
        'snapshot_date',
        'metrics',
    ];

    protected $casts = [
        'snapshot_date' => 'date',
        'metrics' => 'array',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}
```

---

### 3. Controller: `KPIController.php`

**Lokasi:** `app/Http/Controllers/KPIController.php`

```php
namespace App\Http\Controllers;

use App\Models\Store;
use App\Services\KPIService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class KPIController extends Controller
{
    protected $kpiService;

    public function __construct(KPIService $kpiService)
    {
        $this->kpiService = $kpiService;
    }

    /**
     * Dashboard KPI utama
     */
    public function index(Request $request)
    {
        $user = $request->user();
        
        // Validasi akses (hanya admin & owner)
        if (!$user->canManageStoreSettings() && !$user->canAccessOwnerPanel()) {
            abort(403, 'Akses ditolak. Hanya admin dan owner yang dapat melihat KPI.');
        }

        $validated = $request->validate([
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'period' => ['nullable', 'string', 'regex:/^\d{4}-\d{2}$/'], // Format: YYYY-MM
        ]);

        // Dapatkan store yang bisa diakses
        $accessibleStoreIds = $user->isSuperAdmin()
            ? Store::pluck('id')->all()
            : $user->stores()->pluck('stores.id')->all();

        if (empty($accessibleStoreIds)) {
            abort(403, 'Tidak ada outlet yang dapat diakses.');
        }

        // Default: store aktif user atau store pertama
        $storeId = $validated['store_id'] ?? $user->store_id ?? $accessibleStoreIds[0];
        
        if (!in_array($storeId, $accessibleStoreIds)) {
            abort(403, 'Anda tidak memiliki akses ke outlet ini.');
        }

        $store = Store::findOrFail($storeId);
        $period = $validated['period'] ?? now()->format('Y-m');

        // Hitung KPI
        $kpiData = $this->kpiService->calculateMonthlyKPI($store, $period);
        $comparison = $this->kpiService->compareWithTarget($store, $period);
        $topProducts = $this->kpiService->getTopProducts($store, $period);
        $operationalAlerts = $this->kpiService->getOperationalAlerts($store);

        return Inertia::render('KPI/Dashboard', [
            'store' => $store,
            'period' => $period,
            'kpi' => $kpiData,
            'comparison' => $comparison,
            'topProducts' => $topProducts,
            'alerts' => $operationalAlerts,
        ]);
    }

    /**
     * Settings KPI: atur growth percentage
     */
    public function settings(Request $request)
    {
        $user = $request->user();
        
        if (!$user->canManageStoreSettings()) {
            abort(403);
        }

        $validated = $request->validate([
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'growth_percentage' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        $store = Store::findOrFail($validated['store_id']);
        
        if (!$user->isMemberOfStore($store) && !$user->isSuperAdmin()) {
            abort(403);
        }

        // Update target bulan depan
        $nextMonth = now()->addMonth()->format('Y-m');
        
        $target = \App\Models\KpiTarget::updateOrCreate(
            [
                'store_id' => $store->id,
                'period' => $nextMonth,
            ],
            [
                'growth_percentage' => $validated['growth_percentage'],
                'auto_generated' => false,
            ]
        );

        return back()->with('success', "Target growth untuk {$nextMonth} diperbarui: {$validated['growth_percentage']}%");
    }
}
```

---

### 4. Scheduled Job: Daily Snapshot

**Lokasi:** `app/Console/Commands/GenerateKPISnapshot.php`

```php
namespace App\Console\Commands;

use App\Models\Store;
use App\Services\KPIService;
use Illuminate\Console\Command;

class GenerateKPISnapshot extends Command
{
    protected $signature = 'kpi:snapshot';
    protected $description = 'Generate daily KPI snapshot for all active stores';

    protected $kpiService;

    public function __construct(KPIService $kpiService)
    {
        parent::__construct();
        $this->kpiService = $kpiService;
    }

    public function handle()
    {
        $stores = Store::where('is_active', true)->get();
        $date = now()->format('Y-m-d');

        $this->info("Generating KPI snapshots for {$date}...");

        foreach ($stores as $store) {
            try {
                $this->kpiService->createDailySnapshot($store);
                $this->info("✓ {$store->name}");
            } catch (\Exception $e) {
                $this->error("✗ {$store->name}: {$e->getMessage()}");
            }
        }

        $this->info('Snapshot generation completed!');
    }
}
```

**Register di `app/Console/Kernel.php`:**
```php
protected function schedule(Schedule $schedule)
{
    // Generate KPI snapshot setiap hari jam 00:30
    $schedule->command('kpi:snapshot')->dailyAt('00:30');
}
```

---

## 🎨 Frontend Implementation

### 1. Route

**Tambahkan di `routes/web.php`:**
```php
// KPI Dashboard (admin & owner only)
Route::get('/kpi', [App\Http\Controllers\KPIController::class, 'index'])
    ->name('kpi.index')
    ->middleware(['store.active']);

Route::post('/kpi/settings', [App\Http\Controllers\KPIController::class, 'settings'])
    ->name('kpi.settings')
    ->middleware(['store.active', 'role.store_admin']);
```

---

### 2. Menu Sidebar

**Update `resources/js/components/MainMenu.vue`:**
```javascript
{ 
    name: 'KPI Dashboard', 
    href: '/kpi', 
    icon: BarChart3, 
    menuKey: 'kpi', 
    section: 'laporan_keuangan',
    storeAdminOnly: true // Hanya admin & owner
},
```

---

### 3. Vue Component: `KPI/Dashboard.vue`

**Lokasi:** `resources/js/Pages/KPI/Dashboard.vue`

**Struktur UI:**

```vue
<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { BarChart3, TrendingUp, TrendingDown, AlertTriangle, Package } from 'lucide-vue-next';

interface Props {
    store: any;
    period: string;
    kpi: {
        revenue: number;
        transactions: number;
        avg_transaction_value: number;
        growth_rate: number;
        daily_trend: Array<{ date: string; revenue: number }>;
    };
    comparison: {
        revenue_progress: number; // 0-100
        transactions_progress: number;
        status: 'on-track' | 'behind' | 'exceeded';
    };
    topProducts: Array<any>;
    alerts: {
        low_stock_count: number;
        near_expiry_count: number;
        slow_moving_count: number;
    };
}

const props = defineProps<Props>();

const formatCurrency = (value: number) => {
    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        minimumFractionDigits: 0,
    }).format(value);
};

const getStatusColor = (status: string) => {
    if (status === 'exceeded') return 'text-green-600';
    if (status === 'on-track') return 'text-blue-600';
    return 'text-red-600';
};
</script>

<template>
    <Head title="KPI Dashboard" />

    <AdminLayout title="KPI Dashboard">
        <div class="mx-auto space-y-6 pb-8" style="max-width: 90rem">
            <!-- Header & Filter -->
            <div class="flex justify-between items-center">
                <div>
                    <h1 class="text-2xl font-black text-slate-900">KPI Dashboard</h1>
                    <p class="text-sm text-slate-600 mt-1">{{ store.name }} - {{ period }}</p>
                </div>
                <div class="flex gap-3">
                    <!-- Filter Outlet & Periode -->
                </div>
            </div>

            <!-- Target Progress Cards -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <!-- Omset Card -->
                <Card class="rounded-2xl shadow-sm">
                    <CardContent class="p-6">
                        <div class="flex justify-between items-start">
                            <div>
                                <p class="text-sm text-slate-600">Omset Bulan Ini</p>
                                <p class="text-2xl font-bold mt-2">{{ formatCurrency(kpi.revenue) }}</p>
                                <div class="mt-2">
                                    <div class="w-full bg-slate-200 rounded-full h-2">
                                        <div 
                                            class="bg-blue-600 h-2 rounded-full"
                                            :style="{ width: comparison.revenue_progress + '%' }"
                                        ></div>
                                    </div>
                                    <p class="text-xs text-slate-500 mt-1">
                                        {{ comparison.revenue_progress }}% dari target
                                    </p>
                                </div>
                            </div>
                            <TrendingUp class="h-6 w-6 text-green-600" />
                        </div>
                    </CardContent>
                </Card>

                <!-- Transaksi Card -->
                <!-- Customer Card -->
                <!-- Margin Card -->
            </div>

            <!-- Chart Trend -->
            <Card class="rounded-2xl shadow-sm">
                <CardHeader>
                    <CardTitle>Trend Omset Harian</CardTitle>
                </CardHeader>
                <CardContent>
                    <!-- Line chart menggunakan Chart.js atau Recharts -->
                </CardContent>
            </Card>

            <!-- Top Products & Alerts -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Top Products -->
                <Card class="rounded-2xl shadow-sm">
                    <CardHeader>
                        <CardTitle>Top 10 Produk Terlaris</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="space-y-3">
                            <div v-for="(product, idx) in topProducts" :key="product.id" 
                                 class="flex justify-between items-center">
                                <div class="flex items-center gap-3">
                                    <span class="font-bold text-slate-400">#{{ idx + 1 }}</span>
                                    <div>
                                        <p class="font-medium">{{ product.name }}</p>
                                        <p class="text-sm text-slate-500">{{ product.quantity }} terjual</p>
                                    </div>
                                </div>
                                <p class="font-semibold">{{ formatCurrency(product.revenue) }}</p>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <!-- Operational Alerts -->
                <Card class="rounded-2xl shadow-sm">
                    <CardHeader>
                        <CardTitle>Perlu Perhatian</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="space-y-3">
                            <div v-if="alerts.low_stock_count > 0" 
                                 class="flex items-center gap-3 p-3 bg-orange-50 rounded-lg">
                                <AlertTriangle class="h-5 w-5 text-orange-600" />
                                <p class="text-sm">
                                    <span class="font-semibold">{{ alerts.low_stock_count }}</span> 
                                    produk stok menipis
                                </p>
                            </div>
                            
                            <div v-if="alerts.near_expiry_count > 0" 
                                 class="flex items-center gap-3 p-3 bg-red-50 rounded-lg">
                                <AlertTriangle class="h-5 w-5 text-red-600" />
                                <p class="text-sm">
                                    <span class="font-semibold">{{ alerts.near_expiry_count }}</span> 
                                    produk expired dalam 7 hari
                                </p>
                            </div>

                            <div v-if="alerts.slow_moving_count > 0" 
                                 class="flex items-center gap-3 p-3 bg-slate-50 rounded-lg">
                                <Package class="h-5 w-5 text-slate-600" />
                                <p class="text-sm">
                                    <span class="font-semibold">{{ alerts.slow_moving_count }}</span> 
                                    produk slow moving
                                </p>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </div>
    </AdminLayout>
</template>
```

---

## 📈 Target Auto-Growth Logic

### Cara Kerja

1. **Awal Bulan Baru (via Scheduled Job atau Manual Trigger):**
   ```
   Bulan sekarang: Juni 2026
   Sistem cek: apakah ada KpiTarget untuk Juni 2026?
   Jika belum → generate otomatis dari data Mei 2026
   ```

2. **Perhitungan Target:**
   ```
   Data Mei 2026:
   - Omset: Rp 40.000.000
   - Transaksi: 450

   Growth % default: 10%

   Target Juni 2026:
   - Omset: Rp 40.000.000 × 1.10 = Rp 44.000.000
   - Transaksi: 450 × 1.10 = 495
   ```

3. **Override Growth % (Optional):**
   ```
   Admin bisa ubah growth % menjadi 15% untuk bulan promo
   Target Juni akan recalculated:
   - Omset: Rp 40.000.000 × 1.15 = Rp 46.000.000
   - Transaksi: 450 × 1.15 = 517
   ```

4. **Baseline Fallback:**
   ```
   Jika outlet baru (tidak ada data bulan lalu):
   - Admin input manual baseline di Settings
   - Atau gunakan default target industri
   ```

---

## 🚀 Fase Rollout

### **Phase 1: MVP (Minggu 1-2)**
**Tujuan:** Bangun fondasi & KPI paling penting

- ✅ Database migration (`kpi_targets`, `kpi_snapshots`)
- ✅ Model `KpiTarget` dan `KpiSnapshot`
- ✅ `KPIService` dengan method dasar:
  - `generateAutoTarget()`
  - `calculateMonthlyKPI()` untuk revenue & transaksi
  - `compareWithTarget()`
- ✅ `KPIController::index()` dengan data revenue & transaksi saja
- ✅ Scheduled job `kpi:snapshot` untuk daily snapshot
- ✅ Frontend: Dashboard sederhana dengan 4 card (Omset, Transaksi, ATV, Growth)
- ✅ Route `/kpi` dan menu sidebar

**Deliverable:** Admin/owner bisa lihat progress omset & transaksi real-time

---

### **Phase 2: KPI Produk & Profitabilitas (Minggu 3)**
**Tujuan:** Tambah insight produk terlaris & margin

- ✅ Extend `KPIService`:
  - `getTopProducts()`
  - `getSlowMovingProducts()`
  - Calculate gross profit margin
- ✅ Frontend: Tambah section "Top Products" & "Profit Margin"
- ✅ Chart: Line chart untuk trend omset harian

**Deliverable:** Owner tahu produk mana yang paling menguntungkan

---

### **Phase 3: KPI Pelanggan & Operasional (Minggu 4)**
**Tujuan:** Lengkapi KPI komprehensif

- ✅ Customer metrics:
  - `calculateCustomerMetrics()` (active, new, retention)
- ✅ Operational alerts:
  - `getOperationalAlerts()` (low stock, near expiry)
- ✅ Frontend: Section "Operational Alerts" & customer stats
- ✅ Setting page: Atur growth % custom per outlet

**Deliverable:** Dashboard KPI lengkap & production-ready

---

### **Phase 4: Enhancement (Future)**
**Opsional setelah feedback user:**

- Export KPI ke Excel/PDF
- Notifikasi push/email saat target tercapai/tertinggal
- KPI comparison antar outlet (untuk owner multi-outlet)
- Drill-down: klik metric → detail breakdown
- KPI per user/kasir (produktivitas individual)
- Weekly/quarterly view (selain monthly)

---

## ⚡ Pertimbangan Performa

### Optimisasi Query

1. **Gunakan Snapshot Harian:**
   - Hindari query berat real-time ke `transactions` untuk data historis
   - Daily snapshot di-generate jam 00:30 (off-peak)
   - Dashboard load data dari `kpi_snapshots` (jauh lebih cepat)

2. **Index Database:**
   ```sql
   -- Existing index yang diperlukan
   INDEX(store_id, created_at) ON transactions
   INDEX(store_id, quantity) ON products
   INDEX(store_id) ON customers
   
   -- New index
   INDEX(store_id, snapshot_date) ON kpi_snapshots
   INDEX(store_id, period) ON kpi_targets
   ```

3. **Caching (Optional dengan Redis):**
   ```php
   // Cache KPI untuk 1 jam
   Cache::remember("kpi.{$storeId}.{$period}", 3600, function() {
       return $this->kpiService->calculateMonthlyKPI($store, $period);
   });
   ```

4. **Lazy Loading:**
   - Dashboard utama load metric summary dulu
   - Chart & detail di-load on-demand via AJAX/lazy component

---

## 🔐 Security & Access Control

1. **Hak Akses:**
   - Hanya admin & owner bisa akses `/kpi`
   - Validation di controller: `canManageStoreSettings()` atau `canAccessOwnerPanel()`
   - Super admin bisa lihat KPI semua outlet

2. **Data Isolation:**
   - User hanya bisa lihat KPI outlet yang ia kelola
   - Filter `whereIn('store_id', $accessibleStoreIds)`

3. **Rate Limiting:**
   - Throttle endpoint KPI untuk hindari abuse

---

## 📝 Testing Plan

### Unit Test
- `KPIService::generateAutoTarget()` → pastikan perhitungan growth benar
- `KPIService::calculateMonthlyKPI()` → validasi aggregation revenue/transaksi
- `KPIService::compareWithTarget()` → cek logic progress percentage

### Integration Test
- `KPIController::index()` → akses control & response data
- Scheduled job `kpi:snapshot` → pastikan snapshot ter-generate
- Auto-target generation saat awal bulan

### Manual Testing Checklist
- [ ] Admin bisa buka `/kpi` dan lihat dashboard
- [ ] Owner multi-outlet bisa switch outlet & lihat KPI masing-masing
- [ ] Chart trend harian tampil dengan benar
- [ ] Top products list sesuai data transaksi
- [ ] Alert low stock muncul jika ada produk < min_stock
- [ ] Target auto-generate dengan benar setiap bulan baru
- [ ] Override growth % di settings berhasil update target

---

## 📚 Documentation

### User Guide (untuk Admin/Owner)
1. **Cara Akses KPI Dashboard**
2. **Membaca Metrik KPI:** penjelasan tiap metric
3. **Mengatur Target Growth %:** cara override di Settings
4. **Interpretasi Alert:** apa yang harus dilakukan jika ada alert

### Developer Guide
1. **Cara menambah metric baru** ke KPI
2. **Cara customize snapshot generation**
3. **API documentation** untuk endpoint KPI (jika perlu)

---

## 🎯 Success Metrics

Fitur KPI dianggap sukses jika:

1. **Adoption Rate:** 70%+ admin/owner aktif menggunakan dashboard KPI dalam 1 bulan
2. **User Feedback:** Rating kepuasan ≥ 4/5
3. **Performance:** Dashboard load < 2 detik
4. **Data Accuracy:** 0% discrepancy antara KPI vs laporan existing
5. **Business Impact:** Owner membuat minimal 1 keputusan bisnis per bulan berdasarkan insight KPI

---

## 📞 Support & Maintenance

1. **Monitoring:**
   - Log error di `kpi:snapshot` job
   - Alert jika snapshot generation gagal > 3 hari berturut-turut

2. **Backup:**
   - `kpi_snapshots` di-backup harian (data historis penting)

3. **Data Retention:**
   - Snapshot disimpan 12 bulan
   - Data > 12 bulan di-archive atau dihapus (configurable)

---

## ✅ Checklist Implementasi

### Backend
- [ ] Migration: `kpi_targets`, `kpi_snapshots`
- [ ] Model: `KpiTarget`, `KpiSnapshot`
- [ ] Service: `KPIService` dengan semua methods
- [ ] Controller: `KPIController`
- [ ] Scheduled Job: `GenerateKPISnapshot`
- [ ] Route: `/kpi`, `/kpi/settings`
- [ ] Seeder: RoleMenuPermission untuk menu `kpi`

### Frontend
- [ ] Component: `KPI/Dashboard.vue`
- [ ] Component: `KPI/Settings.vue` (optional)
- [ ] Menu: Update `MainMenu.vue`
- [ ] Chart library: Install & setup
- [ ] Responsive design untuk mobile

### Testing
- [ ] Unit test: `KPIService`
- [ ] Feature test: `KPIController`
- [ ] Manual testing: Semua user flow

### Documentation
- [ ] User guide
- [ ] Developer API docs
- [ ] Update README.md

---

## 🚦 Go-Live Checklist

Sebelum production:

- [ ] Semua test passed
- [ ] Scheduled job registered & tested
- [ ] Performance test dengan data real
- [ ] User guide selesai
- [ ] Rollback plan siap (jika ada issue)
- [ ] Monitoring & alert setup
- [ ] Stakeholder approval

---

**Dokumen ini bersifat living document dan akan diupdate seiring perkembangan implementasi.**

**Last Updated:** 2026-08-14  
**Version:** 1.0  
**Author:** Development Team
