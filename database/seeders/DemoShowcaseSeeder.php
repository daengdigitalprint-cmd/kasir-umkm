<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use App\Models\Voucher;
use App\Support\Accounting\ChartOfAccountsBootstrap;
use App\Support\Accounting\JournalPoster;
use App\Support\ProductQuantity;
use App\Support\StoreDefaultProductCategories;
use App\Support\StoreLocationType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoShowcaseSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $superAdmin = User::query()->where('email', 'superadmin@umkm-kasir.com')->first();
            $starterPlan = SubscriptionPlan::query()->where('slug', 'starter')->first();
            $growUpPlan = SubscriptionPlan::query()->where('slug', 'bisnis')->first();
            $promoYearlyPlan = SubscriptionPlan::query()->where('slug', 'promo-onboarding-tahunan')->first();

            $store = Store::query()->updateOrCreate(
                ['slug' => 'demo-meli-saturiski'],
                [
                    'name' => 'Meli Saturiski',
                    'business_type' => 'retail',
                    'description' => 'Toko demo untuk kebutuhan screenshot UI.',
                    'address' => 'Jl. Citra Sudiang Blok X4 No 21B RT.003/RW.12, Pai Kec. Biringkanaya',
                    'phone' => '0812-0000-1122',
                    'email' => 'meli.demo@umkm-kasir.test',
                    'currency' => 'IDR',
                    'timezone' => 'Asia/Jakarta',
                    'is_active' => true,
                    'subscription_start' => now()->subDays(30),
                    'subscription_end' => null,
                    'subscription_days' => 0,
                    'is_subscription_active' => true,
                    'subscription_plan_id' => $starterPlan?->id,
                    'subscription_notes' => 'Demo: Starter aktif untuk showcase.',
                ]
            );

            StoreDefaultProductCategories::ensureForStore($store);
            ChartOfAccountsBootstrap::ensureForStore($store);
            $this->purgeDemoStoreTransactionsAndJournals($store);

            // Buat Gudang Pusat dalam organisasi yang sama
            $warehouse = Store::query()->updateOrCreate(
                ['slug' => 'demo-gudang-pusat-es-nusa'],
                [
                    'name' => 'Gudang Pusat Es Nusa',
                    'organization_id' => $store->organization_id,
                    'business_type' => 'retail',
                    'description' => 'Gudang pusat untuk bahan baku dan kemasan.',
                    'address' => 'Jl. Citra Sudiang Blok X4 No 21B RT.003/RW.12, Pai Kec. Biringkanaya',
                    'phone' => '0812-0000-1122',
                    'email' => 'gudang.demo@umkm-kasir.test',
                    'currency' => 'IDR',
                    'timezone' => 'Asia/Jakarta',
                    'is_active' => true,
                    'subscription_start' => now()->subDays(30),
                    'subscription_end' => null,
                    'subscription_days' => 0,
                    'is_subscription_active' => true,
                    'subscription_plan_id' => $starterPlan?->id,
                    'subscription_notes' => 'Demo: Gudang pusat untuk bahan baku.',
                    'location_type' => StoreLocationType::CENTRAL_WAREHOUSE,
                ]
            );

            StoreDefaultProductCategories::ensureForStore($warehouse);
            ChartOfAccountsBootstrap::ensureForStore($warehouse);

            $user = User::query()->updateOrCreate(
                ['email' => 'demo@umkm-kasir.test'],
                [
                    'name' => 'Meli Saturiski',
                    'password' => 'password',
                    'business_type' => 'retail',
                    'store_id' => $store->id,
                    'role' => 'admin',
                    'is_active' => true,
                    'is_super_admin' => false,
                    'email_verified_at' => now(),
                ]
            );

            $user->stores()->syncWithoutDetaching([
                $store->id => ['role' => 'admin'],
                $warehouse->id => ['role' => 'admin'],
            ]);

            $customers = [
                ['name' => 'Andi Pratama', 'phone' => '081290001001'],
                ['name' => 'Siti Rahma', 'phone' => '081290001002'],
                ['name' => 'Budi Santoso', 'phone' => '081290001003'],
                ['name' => 'Lina Marlina', 'phone' => '081290001004'],
                ['name' => 'Rina Wati', 'phone' => '081290001005'],
            ];

            foreach ($customers as $c) {
                Customer::query()->updateOrCreate(
                    ['store_id' => $store->id, 'phone' => $c['phone']],
                    [
                        'name' => $c['name'],
                        'is_active' => true,
                        'total_spent' => 0,
                        'total_orders' => 0,
                    ]
                );
            }

            $productSeed = [
                // Data sembako lama sudah dihapus — produk jual outlet sudah ada dari seed sebelumnya.
            ];

            foreach ($productSeed as $p) {
                $variants = $p['variants'] ?? null;
                $hasVariants = is_array($variants) && count($variants) > 0;
                $row = $p;
                unset($row['variants']);

                if ($hasVariants) {
                    $row['has_variants'] = true;
                    $row['is_bundle'] = false;
                    $row['allows_decimal'] = $row['allows_decimal'] ?? false;
                    $row['barcode'] = $row['barcode'] ?? null;
                } else {
                    $row['has_variants'] = false;
                    $row['barcode'] = (string) random_int(1000000000000, 9999999999999);
                }

                $cat = ProductCategory::query()->firstOrCreate(
                    ['store_id' => $store->id, 'name' => $row['category']],
                    ['sort_order' => 0],
                );

                $minStock = $row['min_stock_level'] ?? 10;

                $product = Product::query()->updateOrCreate(
                    ['store_id' => $store->id, 'sku' => $row['sku']],
                    [
                        'name' => $row['name'],
                        'barcode' => $row['barcode'],
                        'price' => $row['price'],
                        'cost_price' => $row['cost_price'],
                        'stock_quantity' => $row['stock_quantity'],
                        'min_stock_level' => $minStock,
                        'product_category_id' => $cat->id,
                        'category' => $row['category'],
                        'brand' => 'Demo Brand',
                        'unit' => $row['unit'],
                        'tax_rate' => 11,
                        'is_active' => true,
                        'track_stock' => true,
                        'has_variants' => $row['has_variants'],
                        'is_bundle' => $row['is_bundle'] ?? false,
                        'allows_decimal' => $row['allows_decimal'] ?? false,
                    ]
                );

                if ($hasVariants) {
                    ProductVariant::query()->where('product_id', $product->id)->delete();
                    $sumStock = 0.0;
                    foreach ($variants as $i => $vDef) {
                        ProductVariant::query()->create([
                            'store_id' => $store->id,
                            'product_id' => $product->id,
                            'name' => $vDef['name'],
                            'sku' => $vDef['sku'] ?? null,
                            'barcode' => $vDef['barcode'] ?? null,
                            'price' => $vDef['price'] ?? null,
                            'cost_price' => $vDef['cost_price'] ?? null,
                            'stock_quantity' => (string) ($vDef['stock_quantity'] ?? 0),
                            'min_stock_level' => (string) ($vDef['min_stock_level'] ?? 0),
                            'is_active' => true,
                            'sort_order' => (int) ($vDef['sort_order'] ?? $i),
                        ]);
                        $sumStock += (float) ($vDef['stock_quantity'] ?? 0);
                    }
                    $product->update([
                        'stock_quantity' => $sumStock,
                        'price' => (float) ($variants[0]['price'] ?? $product->price),
                    ]);
                }
            }

            // Bahan baku & kemasan untuk coffeeshop
            $rawMaterials = [
                ['name' => 'Cup Kecil',              'sku' => 'DM-RM-001', 'inventory_type' => 'packaging',   'unit' => 'pcs',    'cost_price' => 650,    'min_stock_level' => 200,   'category' => 'Kemasan'],
                ['name' => 'Cup Seler',              'sku' => 'DM-RM-002', 'inventory_type' => 'packaging',   'unit' => 'pcs',    'cost_price' => 75,     'min_stock_level' => 1000,  'category' => 'Kemasan'],
                ['name' => 'Sedotan',                'sku' => 'DM-RM-003', 'inventory_type' => 'packaging',   'unit' => 'pcs',    'cost_price' => 75,     'min_stock_level' => 250,   'category' => 'Kemasan'],
                ['name' => 'Cup Besar',              'sku' => 'DM-RM-004', 'inventory_type' => 'packaging',   'unit' => 'pcs',    'cost_price' => 780,    'min_stock_level' => 200,   'category' => 'Kemasan'],
                ['name' => 'Bubuk Coklat Premium',   'sku' => 'DM-RM-005', 'inventory_type' => 'raw_material', 'unit' => 'bungkus', 'cost_price' => 2500,   'min_stock_level' => 10,    'category' => 'Bahan Baku'],
                ['name' => 'Bubuk Peach',            'sku' => 'DM-RM-006', 'inventory_type' => 'raw_material', 'unit' => 'bungkus', 'cost_price' => 2100,   'min_stock_level' => 10,    'category' => 'Bahan Baku'],
                ['name' => 'Bubuk Lecy',             'sku' => 'DM-RM-007', 'inventory_type' => 'raw_material', 'unit' => 'bungkus', 'cost_price' => 2100,   'min_stock_level' => 10,    'category' => 'Bahan Baku'],
                ['name' => 'Bubuk Lemon',            'sku' => 'DM-RM-008', 'inventory_type' => 'raw_material', 'unit' => 'bungkus', 'cost_price' => 2100,   'min_stock_level' => 10,    'category' => 'Bahan Baku'],
                ['name' => 'Bubuk Teh Jahe Jeruk',   'sku' => 'DM-RM-009', 'inventory_type' => 'raw_material', 'unit' => 'bungkus', 'cost_price' => 2500,   'min_stock_level' => 10,    'category' => 'Bahan Baku'],
                ['name' => 'Bubuk Teh Sereh Wangi',  'sku' => 'DM-RM-010', 'inventory_type' => 'raw_material', 'unit' => 'bungkus', 'cost_price' => 2500,   'min_stock_level' => 10,    'category' => 'Bahan Baku'],
                ['name' => 'Bubuk Melon',            'sku' => 'DM-RM-011', 'inventory_type' => 'raw_material', 'unit' => 'bungkus', 'cost_price' => 2500,   'min_stock_level' => 5,     'category' => 'Bahan Baku'],
                ['name' => 'Bubuk Honey Lime',       'sku' => 'DM-RM-012', 'inventory_type' => 'raw_material', 'unit' => 'bungkus', 'cost_price' => 2500,   'min_stock_level' => 5,     'category' => 'Bahan Baku'],
                ['name' => 'Bubuk Green Apel',       'sku' => 'DM-RM-013', 'inventory_type' => 'raw_material', 'unit' => 'bungkus', 'cost_price' => 2500,   'min_stock_level' => 5,     'category' => 'Bahan Baku'],
                ['name' => 'Bubuk Semangka',         'sku' => 'DM-RM-014', 'inventory_type' => 'raw_material', 'unit' => 'bungkus', 'cost_price' => 2500,   'min_stock_level' => 5,     'category' => 'Bahan Baku'],
                ['name' => 'Bubuk Taro',             'sku' => 'DM-RM-015', 'inventory_type' => 'raw_material', 'unit' => 'bungkus', 'cost_price' => 2500,   'min_stock_level' => 10,    'category' => 'Bahan Baku'],
                ['name' => 'Bubuk Greentea',         'sku' => 'DM-RM-016', 'inventory_type' => 'raw_material', 'unit' => 'bungkus', 'cost_price' => 2500,   'min_stock_level' => 5,     'category' => 'Bahan Baku'],
                ['name' => 'Bubuk Red Velvet',       'sku' => 'DM-RM-017', 'inventory_type' => 'raw_material', 'unit' => 'bungkus', 'cost_price' => 2500,   'min_stock_level' => 5,     'category' => 'Bahan Baku'],
                ['name' => 'Bubuk Caffelatte',       'sku' => 'DM-RM-018', 'inventory_type' => 'raw_material', 'unit' => 'bungkus', 'cost_price' => 2500,   'min_stock_level' => 5,     'category' => 'Bahan Baku'],
                ['name' => 'Bubuk Mango',            'sku' => 'DM-RM-019', 'inventory_type' => 'raw_material', 'unit' => 'bungkus', 'cost_price' => 2500,   'min_stock_level' => 10,    'category' => 'Bahan Baku'],
                ['name' => 'Bubuk Vanila',           'sku' => 'DM-RM-020', 'inventory_type' => 'raw_material', 'unit' => 'bungkus', 'cost_price' => 2500,   'min_stock_level' => 5,     'category' => 'Bahan Baku'],
                ['name' => 'Bubuk Strawbery',        'sku' => 'DM-RM-021', 'inventory_type' => 'raw_material', 'unit' => 'bungkus', 'cost_price' => 2500,   'min_stock_level' => 5,     'category' => 'Bahan Baku'],
                ['name' => 'Bubuk Doger',            'sku' => 'DM-RM-022', 'inventory_type' => 'raw_material', 'unit' => 'bungkus', 'cost_price' => 2500,   'min_stock_level' => 10,    'category' => 'Bahan Baku'],
                ['name' => 'Bubuk Strawbery Squash', 'sku' => 'DM-RM-023', 'inventory_type' => 'raw_material', 'unit' => 'bungkus', 'cost_price' => 2500,   'min_stock_level' => 5,     'category' => 'Bahan Baku'],
                ['name' => 'Bubuk Blackcurrant Squash', 'sku' => 'DM-RM-024', 'inventory_type' => 'raw_material', 'unit' => 'bungkus', 'cost_price' => 2500, 'min_stock_level' => 5,     'category' => 'Bahan Baku'],
                ['name' => 'Bubuk Roselatte',        'sku' => 'DM-RM-025', 'inventory_type' => 'raw_material', 'unit' => 'bungkus', 'cost_price' => 2500,   'min_stock_level' => 5,     'category' => 'Bahan Baku'],
                ['name' => 'Bubuk Cotton Candy',     'sku' => 'DM-RM-026', 'inventory_type' => 'raw_material', 'unit' => 'bungkus', 'cost_price' => 2500,   'min_stock_level' => 5,     'category' => 'Bahan Baku'],
                ['name' => 'Bubuk Rock Melon',       'sku' => 'DM-RM-027', 'inventory_type' => 'raw_material', 'unit' => 'bungkus', 'cost_price' => 2500,   'min_stock_level' => 5,     'category' => 'Bahan Baku'],
                ['name' => 'Bubuk Kacang Hijau',     'sku' => 'DM-RM-028', 'inventory_type' => 'raw_material', 'unit' => 'bungkus', 'cost_price' => 2500,   'min_stock_level' => 5,     'category' => 'Bahan Baku'],
                ['name' => 'Cream Macciato',         'sku' => 'DM-RM-029', 'inventory_type' => 'raw_material', 'unit' => 'gram',    'cost_price' => 125,    'min_stock_level' => 100,   'category' => 'Bahan Baku'],
                ['name' => 'Tahu Bulat Mentah',      'sku' => 'DM-RM-030', 'inventory_type' => 'raw_material', 'unit' => 'pcs',     'cost_price' => 500,    'min_stock_level' => 50,    'category' => 'Cemilan'],
                ['name' => 'Soda 125 ml',            'sku' => 'DM-RM-031', 'inventory_type' => 'raw_material', 'unit' => 'ml',      'cost_price' => 11,     'min_stock_level' => 1000,  'category' => 'Bahan Baku'],
                ['name' => 'Plastik 1 Cup',          'sku' => 'DM-RM-032', 'inventory_type' => 'packaging',   'unit' => 'pcs',     'cost_price' => 70,     'min_stock_level' => 350,   'category' => 'Kemasan'],
                ['name' => 'Plastik 2 Cup',          'sku' => 'DM-RM-033', 'inventory_type' => 'packaging',   'unit' => 'pcs',     'cost_price' => 100,    'min_stock_level' => 350,   'category' => 'Kemasan'],
                ['name' => 'Yakult',                 'sku' => 'DM-RM-034', 'inventory_type' => 'raw_material', 'unit' => 'botol',   'cost_price' => 2000,   'min_stock_level' => 5,     'category' => 'Bahan Baku'],
                ['name' => 'Oreo',                   'sku' => 'DM-RM-035', 'inventory_type' => 'raw_material', 'unit' => 'pcs',     'cost_price' => 225,    'min_stock_level' => 20,    'category' => 'Bahan Baku'],
                ['name' => 'Susu UHT 1000 ml',       'sku' => 'DM-RM-036', 'inventory_type' => 'raw_material', 'unit' => 'ml',      'cost_price' => 20000,  'min_stock_level' => 200,   'category' => 'Bahan Baku'],
                ['name' => 'SKM',                    'sku' => 'DM-RM-037', 'inventory_type' => 'raw_material', 'unit' => 'gram',    'cost_price' => 26,     'min_stock_level' => 500,   'category' => 'Bahan Baku'],
                ['name' => 'Gula Pasir',             'sku' => 'DM-RM-038', 'inventory_type' => 'raw_material', 'unit' => 'gram',    'cost_price' => 17.5,   'min_stock_level' => 2000,  'category' => 'Bahan Baku'],
                ['name' => 'Daun Teh 120 gr + Air + Es', 'sku' => 'DM-RM-039', 'inventory_type' => 'raw_material', 'unit' => 'gram', 'cost_price' => 525, 'min_stock_level' => 360, 'category' => 'Bahan Baku'],
                ['name' => 'Bubuk Milo',             'sku' => 'DM-RM-040', 'inventory_type' => 'raw_material', 'unit' => 'bungkus', 'cost_price' => 3500,   'min_stock_level' => 2,     'category' => 'Bahan Baku'],
                ['name' => 'Sirup Markisa',          'sku' => 'DM-RM-041', 'inventory_type' => 'raw_material', 'unit' => 'ml',      'cost_price' => 90,     'min_stock_level' => 150,   'category' => 'Bahan Baku'],
                ['name' => 'Bubuk Chocolatos',       'sku' => 'DM-RM-042', 'inventory_type' => 'raw_material', 'unit' => 'bungkus', 'cost_price' => 2000,   'min_stock_level' => 2,     'category' => 'Bahan Baku'],
                ['name' => 'Bubuk Top Kopi Gula Aren', 'sku' => 'DM-RM-043', 'inventory_type' => 'raw_material', 'unit' => 'bungkus', 'cost_price' => 1750, 'min_stock_level' => 2,   'category' => 'Bahan Baku'],
                ['name' => 'Bubuk Good Day Cool In', 'sku' => 'DM-RM-044', 'inventory_type' => 'raw_material', 'unit' => 'bungkus', 'cost_price' => 1500,   'min_stock_level' => 2,     'category' => 'Bahan Baku'],
                ['name' => 'Bubuk Good Day Moccacino', 'sku' => 'DM-RM-045', 'inventory_type' => 'raw_material', 'unit' => 'bungkus', 'cost_price' => 1500, 'min_stock_level' => 2,   'category' => 'Bahan Baku'],
                ['name' => 'Bubuk Beng Beng',        'sku' => 'DM-RM-046', 'inventory_type' => 'raw_material', 'unit' => 'bungkus', 'cost_price' => 2150,   'min_stock_level' => 2,     'category' => 'Bahan Baku'],
                ['name' => 'Bubuk Good Day Freeze Mocafrio', 'sku' => 'DM-RM-047', 'inventory_type' => 'raw_material', 'unit' => 'bungkus', 'cost_price' => 2300, 'min_stock_level' => 2, 'category' => 'Bahan Baku'],
                ['name' => 'Bubuk ThaiTea',          'sku' => 'DM-RM-048', 'inventory_type' => 'raw_material', 'unit' => 'bungkus', 'cost_price' => 3500,   'min_stock_level' => 0,     'category' => 'Bahan Baku'],
                ['name' => 'Bubuk Alvocado',         'sku' => 'DM-RM-049', 'inventory_type' => 'raw_material', 'unit' => 'pcs',     'cost_price' => 2000,   'min_stock_level' => 5,     'category' => 'Bahan Baku'],
            ];

            $unitMap = [
                'bungkus' => 'pcs',
                'botol' => 'pcs',
                'gram' => 'gram',
                'ml' => 'ml',
                'pcs' => 'pcs',
            ];

            // Bersihkan produk bahan baku/kemasan lama dari outlet (jika ada dari seed sebelumnya)
            Product::query()
                ->where('store_id', $store->id)
                ->whereIn('inventory_type', ['raw_material', 'packaging'])
                ->delete();

            foreach ($rawMaterials as $rm) {
                $cat = ProductCategory::query()->firstOrCreate(
                    ['store_id' => $warehouse->id, 'name' => $rm['category']],
                    ['sort_order' => 0],
                );

                Product::query()->updateOrCreate(
                    ['store_id' => $warehouse->id, 'sku' => $rm['sku']],
                    [
                        'name' => $rm['name'],
                        'price' => 0,
                        'cost_price' => $rm['cost_price'],
                        'average_cost' => (string) $rm['cost_price'],
                        'stock_quantity' => $rm['min_stock_level'],
                        'min_stock_level' => $rm['min_stock_level'],
                        'product_category_id' => $cat->id,
                        'category' => $rm['category'],
                        'unit' => $rm['unit'],
                        'base_unit' => $unitMap[$rm['unit']] ?? 'pcs',
                        'inventory_type' => $rm['inventory_type'],
                        'tax_rate' => 0,
                        'is_active' => true,
                        'track_stock' => true,
                        'has_variants' => false,
                        'is_bundle' => false,
                        'allows_decimal' => in_array($rm['unit'], ['gram', 'ml']),
                    ]
                );
            }

            // Salin produk bahan baku & kemasan dari Gudang Pusat ke outlet
            $store->copyProductsFrom($warehouse);

            $products = Product::query()
                ->where('store_id', $store->id)
                ->where('has_variants', false)
                ->orderBy('id')
                ->get();

            if ($products->count() < 3) {
                return;
            }

            $customerIds = Customer::query()->where('store_id', $store->id)->pluck('id')->all();
            // Sebarkan data lebih panjang agar grafik dashboard/report terlihat penuh.
            $baseDate = now()->subDays(150);
            $transactionCount = 72; // sekitar 5 bulan data, rata-rata 2-3 hari sekali

            for ($i = 0; $i < $transactionCount; $i++) {
                $p1 = $products[$i % $products->count()];
                $p2 = $products[($i + 1) % $products->count()];
                $q1 = ($i % 3) + 1;
                $q2 = (($i + 1) % 2) + 1;

                $line1 = (float) $p1->price * $q1;
                $line2 = (float) $p2->price * $q2;
                $subtotal = $line1 + $line2;
                $discount = $i % 4 === 0 ? 5000 : 0;
                $tax = round(($subtotal - $discount) * 0.11, 2);
                $total = $subtotal - $discount + $tax;

                $dayOffset = (int) floor($i * 2.1); // tidak terlalu rapat, rentang memanjang
                $createdAt = $baseDate->copy()->addDays($dayOffset)->setTime(8 + ($i % 11), 10 + (($i * 7) % 45), 0);
                $trxNo = sprintf('DMO-%s-%04d', now()->format('Ymd'), $i + 1);

                $trx = Transaction::query()->create([
                    'store_id' => $store->id,
                    'customer_id' => $customerIds[$i % max(count($customerIds), 1)] ?? null,
                    'user_id' => $user->id,
                    'transaction_number' => $trxNo,
                    'status' => 'completed',
                    'payment_method' => $i % 2 === 0 ? 'cash' : 'ewallet',
                    'subtotal' => $subtotal,
                    'tax_amount' => $tax,
                    'discount_amount' => $discount,
                    'service_charge_percent' => 0,
                    'service_charge_amount' => 0,
                    'total_amount' => $total,
                    'amount_paid' => $total,
                    'change_amount' => 0,
                    'notes' => 'Demo seeder: transaksi untuk screenshot.',
                    'completed_at' => $createdAt,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ]);

                TransactionItem::query()->create([
                    'transaction_id' => $trx->id,
                    'product_id' => $p1->id,
                    'product_name' => $p1->name,
                    'product_sku' => $p1->sku,
                    'quantity' => $q1,
                    'unit_price' => $p1->price,
                    'total_price' => $line1,
                    'discount_amount' => 0,
                    'tax_amount' => round($line1 * 0.11, 2),
                ]);

                TransactionItem::query()->create([
                    'transaction_id' => $trx->id,
                    'product_id' => $p2->id,
                    'product_name' => $p2->name,
                    'product_sku' => $p2->sku,
                    'quantity' => $q2,
                    'unit_price' => $p2->price,
                    'total_price' => $line2,
                    'discount_amount' => 0,
                    'tax_amount' => round($line2 * 0.11, 2),
                ]);
            }

            Transaction::query()
                ->where('store_id', $store->id)
                ->where('notes', 'like', 'Demo seeder:%')
                ->orderBy('id')
                ->chunkById(50, function ($chunk) use ($store): void {
                    foreach ($chunk as $trx) {
                        $trx->load(['items.product', 'items.productVariant']);
                        JournalPoster::postSaleTransaction($store, $trx);
                    }
                });

            $demoSupplier = Supplier::query()->updateOrCreate(
                ['store_id' => $store->id, 'name' => 'Supplier Demo (PPN)'],
                ['phone' => '081200009999']
            );

            $pPurchase = $products[0];
            $qtyPur = ProductQuantity::normalizeLineQuantity($pPurchase, 4);
            $unitCostDemo = 5000.0;
            $dppDemo = round($unitCostDemo * (float) $qtyPur, 2);
            $ppnDemo = round($dppDemo * 0.11, 2);
            $totalDemo = round($dppDemo + $ppnDemo, 2);

            $purchaseDemo = Purchase::query()->create([
                'store_id' => $store->id,
                'supplier_id' => $demoSupplier->id,
                'user_id' => $user->id,
                'purchased_at' => now()->subDays(5),
                'notes' => 'Demo seeder: pembelian contoh untuk PPN Masukan.',
                'dpp_amount' => $dppDemo,
                'ppn_amount' => $ppnDemo,
                'total_amount' => $totalDemo,
                'supplier_tax_invoice_no' => 'FK-DEMO-001',
            ]);

            PurchaseLine::query()->create([
                'purchase_id' => $purchaseDemo->id,
                'product_id' => $pPurchase->id,
                'quantity' => $qtyPur,
                'unit_cost' => $unitCostDemo,
            ]);

            $pPurchase->increment('stock_quantity', $qtyPur);
            $pPurchase->refresh();

            StockMovement::query()->create([
                'store_id' => $store->id,
                'product_id' => $pPurchase->id,
                'user_id' => $user->id,
                'quantity_change' => $qtyPur,
                'type' => StockMovement::TYPE_PURCHASE,
                'reference_type' => Purchase::class,
                'reference_id' => $purchaseDemo->id,
                'balance_after' => (string) $pPurchase->stock_quantity,
                'notes' => $purchaseDemo->purchase_number,
            ]);

            JournalPoster::postPurchase($store, $purchaseDemo->fresh(['lines']));

            // Rehitung ringkasan pelanggan dari transaksi yang ada.
            $customerStats = Transaction::query()
                ->selectRaw('customer_id, COUNT(*) as orders_count, SUM(total_amount) as spent_total')
                ->where('store_id', $store->id)
                ->whereNotNull('customer_id')
                ->groupBy('customer_id')
                ->get()
                ->keyBy('customer_id');

            Customer::query()
                ->where('store_id', $store->id)
                ->get()
                ->each(function (Customer $customer) use ($customerStats): void {
                    $stat = $customerStats->get($customer->id);
                    $customer->total_orders = (int) ($stat->orders_count ?? 0);
                    $customer->total_spent = (float) ($stat->spent_total ?? 0);
                    $customer->save();
                });

            $voucher = Voucher::query()->updateOrCreate(
                ['code' => 'DEMO99000'],
                [
                    'name' => 'Voucher Demo 99rb',
                    'description' => 'Voucher demo untuk screenshot checkout/subscription.',
                    'days' => 30,
                    'price' => 99000,
                    'usage_limit' => 200,
                    'used_count' => 0,
                    'is_active' => true,
                    'valid_from' => now()->subDays(7),
                    'valid_until' => now()->addMonths(2),
                ]
            );

            SubscriptionPayment::query()->updateOrCreate(
                ['invoice_number' => 'INV-DEMO-CONFIRMED-001'],
                [
                    'store_id' => $store->id,
                    'user_id' => $user->id,
                    'subscription_plan_id' => $growUpPlan?->id,
                    'voucher_id' => null,
                    'amount' => 99000,
                    'duration_days' => 30,
                    'payment_method' => 'bank_transfer',
                    'status' => 'confirmed',
                    'notes' => 'Demo seeder: pembayaran terkonfirmasi.',
                    'admin_notes' => 'Valid (demo)',
                    'confirmed_at' => now()->subDays(4),
                    'confirmed_by' => $superAdmin?->id,
                ]
            );

            SubscriptionPayment::query()->updateOrCreate(
                ['invoice_number' => 'INV-DEMO-PENDING-001'],
                [
                    'store_id' => $store->id,
                    'user_id' => $user->id,
                    'subscription_plan_id' => $promoYearlyPlan?->id,
                    'voucher_id' => $voucher->id,
                    'amount' => 792000,
                    'duration_days' => 365,
                    'payment_method' => 'qris',
                    'status' => 'pending',
                    'notes' => 'Demo seeder: menunggu konfirmasi admin.',
                    'admin_notes' => null,
                    'confirmed_at' => null,
                    'confirmed_by' => null,
                ]
            );
        });

        $this->command?->info('DemoShowcaseSeeder selesai: akun demo + data transaksi siap untuk screenshot.');
        $this->command?->line('Login demo user: demo@umkm-kasir.test / password');
    }

    private function purgeDemoStoreTransactionsAndJournals(Store $store): void
    {
        $jeIds = DB::table('journal_entries')->where('store_id', $store->id)->pluck('id');
        if ($jeIds->isNotEmpty()) {
            DB::table('journal_lines')->whereIn('journal_entry_id', $jeIds)->delete();
        }
        DB::table('journal_entries')->where('store_id', $store->id)->delete();

        StockMovement::query()
            ->where('store_id', $store->id)
            ->whereIn('reference_type', [Transaction::class, Purchase::class])
            ->delete();

        Transaction::query()->where('store_id', $store->id)->delete();

        $pids = DB::table('purchases')->where('store_id', $store->id)->pluck('id');
        if ($pids->isNotEmpty()) {
            DB::table('purchase_lines')->whereIn('purchase_id', $pids)->delete();
        }
        DB::table('purchases')->where('store_id', $store->id)->delete();
    }
}
