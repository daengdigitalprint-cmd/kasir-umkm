<?php

namespace Database\Seeders;

use App\Actions\StockRequestWorkflow;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductSaleConsumption;
use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Models\StockRequest;
use App\Models\StockTransfer;
use App\Models\Store;
use App\Models\SubscriptionPlan;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\UpsellRule;
use App\Models\User;
use App\Support\InventoryCosting;
use App\Support\StoreLocationType;
use App\Support\TransferStockOperations;
use App\Support\UpsellSuggestions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Akun demo lokal: usaha F&B dengan 1 Central Kitchen + 2 outlet (skenario klien perintis).
 * Semua fitur stok & Central Kitchen menyala dan berisi data dummy.
 *
 *   php artisan db:seed --class=DemoCentralKitchenSeeder
 *
 * Aman diulang: data demo lama (grup "demo-resto-nusantara") dihapus dulu.
 * Hanya untuk lingkungan local/testing. Kata sandi semua akun: password
 */
class DemoCentralKitchenSeeder extends Seeder
{
    public const ORG_SLUG = 'demo-resto-nusantara';

    public const PASSWORD = 'password';

    /** Diisi oleh perintah demo:central-kitchen; kosong = kata sandi lokal 'password'. */
    public ?string $password = null;

    /** Hanya perintah demo:central-kitchen --production yang boleh menyalakan ini. */
    public bool $allowProduction = false;

    private array $users = [];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing']) && ! $this->allowProduction) {
            $this->command?->error('Seeder demo hanya untuk local. Di server pakai: php artisan demo:central-kitchen --production');

            return;
        }

        // Jangan coba menyiarkan event realtime saat mengisi data.
        config(['broadcasting.default' => 'null']);
        mt_srand(2026);

        $this->purge();

        DB::transaction(function () {
            $plan = SubscriptionPlan::query()->where('slug', 'bisnis')->firstOrFail();

            $owner = $this->user('demo.owner@kasirumkm.test', 'Hendra (Owner)', 'owner');
            $org = Organization::query()->create([
                'name' => 'Demo Resto Nusantara',
                'slug' => self::ORG_SLUG,
                'owner_user_id' => $owner->id,
                'central_kitchen_until' => now()->addYear(),
            ]);

            $ck = $this->store($org, $plan, 'Demo Central Kitchen', StoreLocationType::CENTRAL_WAREHOUSE, 'Jl. Perintis Kemerdekaan Km 10, Makassar');
            $outletA = $this->store($org, $plan, 'Demo Outlet Panakkukang', StoreLocationType::OUTLET, 'Jl. Boulevard Panakkukang No. 8, Makassar', [
                'upsell_enabled' => true,
                'upsell_auto_enabled' => true,
                'delivery_enabled' => true,
                'delivery_default_fee' => 8000,
                // Order otomatis ke CK setiap jam 21.00 (bisa dicoba lewat tombol "Buat sekarang").
                'auto_stock_request' => ['enabled' => true, 'time' => '21:00'],
            ]);
            $outletB = $this->store($org, $plan, 'Demo Outlet Sudiang', StoreLocationType::OUTLET, 'Jl. Citra Sudiang Blok X4 No. 21, Makassar', [
                'upsell_enabled' => true,
                'delivery_enabled' => true,
                'delivery_default_fee' => 10000,
            ]);

            // Owner melihat semua lokasi; tiap lokasi punya admin sendiri.
            $owner->update(['store_id' => $outletA->id]);
            foreach ([$ck, $outletA, $outletB] as $store) {
                $owner->stores()->syncWithoutDetaching([$store->id => ['role' => 'owner']]);
            }
            $ckAdmin = $this->user('demo.gudang@kasirumkm.test', 'Rahmat (Kepala CK)', 'admin', $ck);
            $adminA = $this->user('demo.outlet1@kasirumkm.test', 'Sari (Admin Panakkukang)', 'admin', $outletA);
            $adminB = $this->user('demo.outlet2@kasirumkm.test', 'Dewi (Admin Sudiang)', 'admin', $outletB);
            $kasirA = $this->user('demo.kasir1@kasirumkm.test', 'Joko (Kasir & Kurir)', 'cashier', $outletA);

            // ── Barang & stok ──
            $ckItems = $this->materials($ck, central: true);
            $aItems = $this->materials($outletA, central: false, lowStock: true);
            $bItems = $this->materials($outletB, central: false, lowStock: false);
            $menuA = $this->menu($outletA, $aItems);
            $menuB = $this->menu($outletB, $bItems);

            $this->purchaseUnits($ckItems);
            $this->purchases($ck, $ckAdmin, $ckItems);

            // ── Penjualan, pelanggan, saran menu, pengantaran ──
            $customersA = $this->customers($outletA);
            $this->sales($outletA, $kasirA, $menuA, $customersA, 160);
            $this->sales($outletB, $adminB, $menuB, $this->customers($outletB, prefix: 'B'), 90);
            $this->upsellRules($outletA, $menuA);
            $this->upsellRules($outletB, $menuB);
            $this->deliveries($outletA, $kasirA, $menuA);
            UpsellSuggestions::forget($outletA->id);
            UpsellSuggestions::forget($outletB->id);

            // ── Permintaan Stok & Transfer ──
            $this->stockFlow($ck, $ckAdmin, $outletA, $adminA, $outletB, $adminB, $aItems, $bItems);
        });

        $this->summary();
    }

    // ───────────────────────── Toko & akun ─────────────────────────

    private function store(Organization $org, SubscriptionPlan $plan, string $name, string $type, string $address, array $extra = []): Store
    {
        return Store::query()->create([
            'organization_id' => $org->id,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'business_type' => 'fnb',
            'location_type' => $type,
            'address' => $address,
            'phone' => '0811-4000-'.random_int(1000, 9999),
            'currency' => 'IDR',
            'timezone' => 'Asia/Makassar',
            'is_active' => true,
            'tax_enabled' => $type === StoreLocationType::OUTLET,
            'tax_rate' => 10,
            'tax_name' => 'PB1',
            'subscription_plan_id' => $plan->id,
            'subscription_start' => now()->subMonth(),
            'subscription_end' => now()->addYear(),
            'is_subscription_active' => true,
            'subscription_notes' => 'Akun demo Central Kitchen',
            'settings' => array_merge([
                'ui_mode' => 'full',
                'warehouse_enabled' => true,
                'recipes_enabled' => true,
                'productions_enabled' => $type === StoreLocationType::CENTRAL_WAREHOUSE,
                'receivables_enabled' => true,
                'ai_chat_enabled' => false,
                'accounting_enabled' => false,
                'receipt_footer' => 'Terima kasih, sampai jumpa lagi!',
            ], $extra),
        ]);
    }

    private function user(string $email, string $name, string $role, ?Store $store = null): User
    {
        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($this->password ?? self::PASSWORD),
            'role' => $role,
            'store_id' => $store?->id,
            'email_verified_at' => now(),
        ]);
        if ($store) {
            $user->stores()->attach($store->id, ['role' => $role]);
        }
        $this->users[] = [$email, $name, $store?->name ?? 'Semua lokasi'];

        return $user;
    }

    /** Hapus demo lama supaya seeder bisa diulang. */
    private function purge(): void
    {
        $org = Organization::query()->where('slug', self::ORG_SLUG)->first();
        $emails = ['demo.owner', 'demo.gudang', 'demo.outlet1', 'demo.outlet2', 'demo.kasir1'];

        DB::transaction(function () use ($org, $emails) {
            if ($org) {
                $storeIds = Store::query()->where('organization_id', $org->id)->pluck('id');
                $transferIds = StockTransfer::query()->whereIn('from_store_id', $storeIds)->orWhereIn('to_store_id', $storeIds)->pluck('id');
                StockRequest::query()->whereIn('from_store_id', $storeIds)->delete();
                DB::table('stock_transfer_lines')->whereIn('stock_transfer_id', $transferIds)->delete();
                StockTransfer::query()->whereIn('id', $transferIds)->delete();
                foreach (['delivery_orders', 'upsell_rules', 'stock_movements', 'product_sale_consumptions'] as $table) {
                    DB::table($table)->whereIn('store_id', $storeIds)->delete();
                }
                $trxIds = Transaction::query()->whereIn('store_id', $storeIds)->pluck('id');
                DB::table('transaction_items')->whereIn('transaction_id', $trxIds)->delete();
                DB::table('transaction_payments')->whereIn('transaction_id', $trxIds)->delete();
                Transaction::query()->whereIn('id', $trxIds)->delete();
                $purchaseIds = Purchase::query()->whereIn('store_id', $storeIds)->pluck('id');
                PurchaseLine::query()->whereIn('purchase_id', $purchaseIds)->delete();
                Purchase::query()->whereIn('id', $purchaseIds)->delete();
                DB::table('product_units')->whereIn('product_id', Product::query()->whereIn('store_id', $storeIds)->select('id'))->delete();
                Product::query()->whereIn('store_id', $storeIds)->delete();
                Customer::query()->whereIn('store_id', $storeIds)->delete();
                Supplier::query()->whereIn('store_id', $storeIds)->delete();
                DB::table('store_user')->whereIn('store_id', $storeIds)->delete();
                Store::query()->whereIn('id', $storeIds)->delete();
                $org->delete();
            }

            foreach ($emails as $prefix) {
                User::query()->where('email', $prefix.'@kasirumkm.test')->delete();
            }
        });
    }

    // ───────────────────────── Barang ─────────────────────────

    /**
     * Bahan & kemasan dengan SKU yang sama di semua lokasi (supaya Transfer/Permintaan Stok cocok).
     * CK menyimpan stok besar; outlet A sengaja ada yang menipis/habis.
     *
     * @return array<string, Product>
     */
    private function materials(Store $store, bool $central, bool $lowStock = false): array
    {
        // sku => [nama, jenis, satuan dasar, desimal, HPP/satuan, stok CK, stok outlet normal, stok outlet menipis, stok minimum outlet]
        $catalog = [
            'BHN-AYAM' => ['Ayam Fillet', 'raw_material', 'gram', true, 42, 60000, 9000, 1800, 5000],
            'BHN-BERAS' => ['Beras Premium', 'raw_material', 'gram', true, 14, 150000, 20000, 0, 8000],
            'BHN-MINYAK' => ['Minyak Goreng', 'raw_material', 'ml', true, 18, 72000, 8000, 2500, 3000],
            'BHN-SAMBAL' => ['Sambal Bawang (CK)', 'raw_material', 'gram', true, 55, 25000, 3000, 600, 1500],
            'BHN-TEH' => ['Teh Celup', 'raw_material', 'pcs', false, 350, 1200, 150, 40, 100],
            'BHN-GULA' => ['Gula Pasir', 'raw_material', 'gram', true, 16, 50000, 6000, 900, 2000],
            'BHN-JERUK' => ['Jeruk Peras', 'raw_material', 'pcs', false, 1500, 600, 60, 8, 30],
            'BHN-KERUPUK' => ['Kerupuk Udang', 'raw_material', 'pcs', false, 600, 1500, 200, 150, 100],
            'KMS-CUP' => ['Cup Plastik 16oz', 'packaging', 'pcs', false, 450, 5000, 400, 70, 150],
            'KMS-BOX' => ['Box Makan Kertas', 'packaging', 'pcs', false, 1200, 3000, 250, 0, 100],
        ];

        $category = ProductCategory::query()->firstOrCreate(
            ['store_id' => $store->id, 'name' => 'Bahan & Kemasan'],
            ['sort_order' => 90],
        );

        $items = [];
        foreach ($catalog as $sku => [$name, $type, $unit, $decimal, $cost, $ckStock, $normal, $low, $min]) {
            $stock = $central ? $ckStock : ($lowStock ? $low : $normal);
            $items[$sku] = Product::query()->create([
                'store_id' => $store->id,
                'product_category_id' => $category->id,
                'name' => $name,
                'sku' => $sku,
                'price' => 0,
                'cost_price' => $cost,
                'average_cost' => $cost,
                'stock_quantity' => $stock,
                'min_stock_level' => $central ? $min * 3 : $min,
                'unit' => $unit,
                'base_unit' => $unit,
                'allows_decimal' => $decimal,
                'inventory_type' => $type,
                'track_stock' => true,
                'is_active' => true,
            ]);
        }

        return $items;
    }

    /**
     * Menu jual outlet, stoknya dihitung dari bahan (konsumsi per porsi = BOM).
     *
     * @param  array<string, Product>  $materials
     * @return array<string, Product>
     */
    private function menu(Store $store, array $materials): array
    {
        // kode => [nama, harga, [sku bahan => jumlah per porsi]]
        $menu = [
            'MN-GEPREK' => ['Nasi Ayam Geprek', 25000, ['BHN-AYAM' => 150, 'BHN-BERAS' => 150, 'BHN-MINYAK' => 40, 'BHN-SAMBAL' => 30, 'KMS-BOX' => 1]],
            'MN-BAKAR' => ['Ayam Bakar Madu', 28000, ['BHN-AYAM' => 180, 'BHN-BERAS' => 150, 'BHN-SAMBAL' => 20, 'KMS-BOX' => 1]],
            'MN-NASI' => ['Nasi Putih', 6000, ['BHN-BERAS' => 150]],
            'MN-ESTEH' => ['Es Teh Manis', 6000, ['BHN-TEH' => 1, 'BHN-GULA' => 25, 'KMS-CUP' => 1]],
            'MN-JERUK' => ['Es Jeruk', 9000, ['BHN-JERUK' => 2, 'BHN-GULA' => 20, 'KMS-CUP' => 1]],
            'MN-KERUPUK' => ['Kerupuk', 3000, ['BHN-KERUPUK' => 2]],
        ];

        $category = ProductCategory::query()->firstOrCreate(
            ['store_id' => $store->id, 'name' => 'Menu'],
            ['sort_order' => 1],
        );

        $products = [];
        foreach ($menu as $sku => [$name, $price, $bom]) {
            $cost = 0;
            foreach ($bom as $component => $qty) {
                $cost += $qty * (float) $materials[$component]->cost_price;
            }

            $product = Product::query()->create([
                'store_id' => $store->id,
                'product_category_id' => $category->id,
                'name' => $name,
                'sku' => $sku,
                'price' => $price,
                'cost_price' => round($cost, 2),
                'average_cost' => round($cost, 2),
                'stock_quantity' => 0,
                'min_stock_level' => 0,
                'unit' => 'porsi',
                'base_unit' => 'pcs',
                'inventory_type' => 'sellable',
                'track_stock' => false,
                'is_active' => true,
            ]);

            $order = 0;
            foreach ($bom as $component => $qty) {
                ProductSaleConsumption::query()->create([
                    'store_id' => $store->id,
                    'product_id' => $product->id,
                    'product_variant_id' => null,
                    'component_product_id' => $materials[$component]->id,
                    'quantity' => $qty,
                    'base_unit' => $materials[$component]->baseUnit(),
                    'sort_order' => $order++,
                ]);
            }
            $products[$sku] = $product;
        }

        return $products;
    }

    /** Satuan beli di CK: supplier menjual per karung/jerigen/karton (F3). */
    private function purchaseUnits(array $ck): void
    {
        $units = [
            'BHN-BERAS' => [['karung', 25000, true]],
            'BHN-MINYAK' => [['jerigen', 18000, true]],
            'BHN-TEH' => [['dus', 100, true]],
            'BHN-GULA' => [['bal', 50000, true]],
            'KMS-CUP' => [['karton', 1000, true], ['pack', 50, false]],
            'KMS-BOX' => [['karton', 500, true]],
        ];
        foreach ($units as $sku => $rows) {
            foreach ($rows as [$name, $factor, $default]) {
                $ck[$sku]->units()->create(['name' => $name, 'factor' => $factor, 'is_purchase_default' => $default]);
            }
        }
    }

    /** Riwayat pembelian CK (supaya Daftar Belanja menunjukkan supplier terakhir). */
    private function purchases(Store $ck, User $user, array $items): void
    {
        $suppliers = [
            'CV Unggas Segar Sulsel' => ['BHN-AYAM'],
            'Toko Grosir Makmur' => ['BHN-BERAS', 'BHN-MINYAK', 'BHN-GULA', 'BHN-TEH'],
            'UD Kemasan Jaya' => ['KMS-CUP', 'KMS-BOX'],
            'Pasar Terong (Pak Udin)' => ['BHN-JERUK', 'BHN-KERUPUK', 'BHN-SAMBAL'],
        ];

        foreach ($suppliers as $name => $skus) {
            $supplier = Supplier::query()->create(['store_id' => $ck->id, 'name' => $name, 'phone' => '0812-'.random_int(1000, 9999).'-'.random_int(1000, 9999)]);
            $purchase = Purchase::query()->create([
                'store_id' => $ck->id,
                'supplier_id' => $supplier->id,
                'user_id' => $user->id,
                'purchase_number' => 'PB-DEMO-'.Str::upper(Str::random(5)),
                'purchased_at' => now()->subDays(random_int(3, 12)),
                'notes' => 'Data demo',
            ]);
            foreach ($skus as $sku) {
                $p = $items[$sku];
                $qty = (float) $p->stock_quantity / 2;
                PurchaseLine::query()->create([
                    'purchase_id' => $purchase->id,
                    'product_id' => $p->id,
                    'product_name_snapshot' => $p->name,
                    'sku_snapshot' => $p->sku,
                    'quantity' => $qty,
                    'unit_cost' => $p->cost_price,
                    'input_unit' => $p->baseUnit(),
                    'quantity_input' => $qty,
                    'base_unit' => $p->baseUnit(),
                    'qty_base' => $qty,
                    'unit_cost_input' => $p->cost_price,
                    'unit_cost_base' => $p->cost_price,
                    'subtotal' => round($qty * (float) $p->cost_price, 2),
                ]);
            }
        }
    }

    // ───────────────────────── Penjualan ─────────────────────────

    /** @return array<string, Customer> */
    private function customers(Store $store, string $prefix = 'A'): array
    {
        $names = [
            'Pak Budi' => '081241110001',   // setia
            'Bu Rina' => '081241110002',    // setia
            'Mas Andi' => '081241110003',   // baru
            'Kak Nisa' => '081241110004',   // baru
            'Pak Tono' => '081241110005',   // lama tidak datang
            'Bu Lia' => '081241110006',     // lama tidak datang
            'Om Fajar' => '081241110007',   // biasa
        ];
        $result = [];
        foreach ($names as $name => $phone) {
            $result[$name] = Customer::query()->create([
                'store_id' => $store->id,
                'name' => $prefix === 'A' ? $name : "{$name} ({$prefix})",
                'phone' => $phone,
                'is_active' => true,
            ]);
        }

        return $result;
    }

    /**
     * Transaksi 60 hari terakhir. Geprek sering dipesan bersama Es Teh & Kerupuk
     * (bahan "sering dibeli bersama"); pola kunjungan pelanggan membentuk segmen.
     */
    private function sales(Store $store, User $cashier, array $menu, array $customers, int $count): void
    {
        $visitPlan = [
            'Pak Budi' => [1, 3, 6, 9, 13, 18, 24, 40],
            'Bu Rina' => [2, 5, 11, 20, 27],
            'Mas Andi' => [4, 12],
            'Kak Nisa' => [8],
            'Pak Tono' => [36, 44, 52, 58],
            'Bu Lia' => [33, 41, 55],
            'Om Fajar' => [15, 47],
        ];
        foreach ($visitPlan as $name => $days) {
            foreach ($days as $day) {
                $this->sale($store, $cashier, $this->basket($menu), $day, $customers[$name] ?? null);
            }
        }

        for ($i = 0; $i < $count; $i++) {
            $this->sale($store, $cashier, $this->basket($menu), random_int(0, 59));
        }
    }

    /** @return list<array{0: Product, 1: int}> */
    private function basket(array $menu): array
    {
        $main = mt_rand(1, 100) <= 65 ? $menu['MN-GEPREK'] : $menu['MN-BAKAR'];
        $items = [[$main, mt_rand(1, 2)]];
        if (mt_rand(1, 100) <= 70) {
            $items[] = [$menu['MN-ESTEH'], mt_rand(1, 2)];
        } elseif (mt_rand(1, 100) <= 50) {
            $items[] = [$menu['MN-JERUK'], 1];
        }
        if ($main->sku === 'MN-GEPREK' && mt_rand(1, 100) <= 45) {
            $items[] = [$menu['MN-KERUPUK'], 1];
        }
        if (mt_rand(1, 100) <= 20) {
            $items[] = [$menu['MN-NASI'], 1];
        }

        return $items;
    }

    private function sale(Store $store, User $cashier, array $items, int $daysAgo, ?Customer $customer = null, float $deliveryFee = 0): Transaction
    {
        $subtotal = 0;
        foreach ($items as [$product, $qty]) {
            $subtotal += (float) $product->price * $qty;
        }
        $tax = round($subtotal * 0.10, 2);
        $total = $subtotal + $tax + $deliveryFee;
        $paid = ceil($total / 5000) * 5000;
        $when = Carbon::now()->subDays($daysAgo)->setTime(mt_rand(10, 20), mt_rand(0, 59));

        $transaction = Transaction::query()->create([
            'store_id' => $store->id,
            'customer_id' => $customer?->id,
            'user_id' => $cashier->id,
            'transaction_number' => 'TRX-DEMO-'.$when->format('ymd').'-'.Str::upper(Str::random(5)),
            'status_token' => Str::random(64),
            'status' => 'completed',
            'payment_method' => mt_rand(1, 100) <= 60 ? 'cash' : 'qris',
            'payment_status' => 'paid',
            'order_type' => $deliveryFee > 0 ? 'delivery' : (mt_rand(1, 100) <= 70 ? 'dine_in' : 'takeaway'),
            'sales_channel' => 'walk_in',
            'subtotal' => $subtotal,
            'tax_amount' => $tax,
            'discount_amount' => 0,
            'service_charge_amount' => 0,
            'tip_amount' => 0,
            'delivery_fee' => $deliveryFee,
            'total_amount' => $total,
            'amount_paid' => $paid,
            'change_amount' => $paid - $total,
            'completed_at' => $when,
        ]);
        $transaction->forceFill(['created_at' => $when, 'updated_at' => $when])->saveQuietly();

        foreach ($items as [$product, $qty]) {
            TransactionItem::query()->create([
                'transaction_id' => $transaction->id,
                'product_id' => $product->id,
                'product_name' => $product->name,
                'product_sku' => $product->sku,
                'quantity' => $qty,
                'unit_price' => $product->price,
                'total_price' => (float) $product->price * $qty,
                'unit_cost_snapshot' => $product->cost_price,
                'total_cost_snapshot' => (float) $product->cost_price * $qty,
                'gross_profit_snapshot' => ((float) $product->price - (float) $product->cost_price) * $qty,
            ]);
        }

        if ($customer) {
            $customer->forceFill([
                'total_spent' => (float) $customer->total_spent + $total,
                'total_orders' => (int) $customer->total_orders + 1,
                'last_purchase_at' => max($customer->last_purchase_at ?? $when, $when),
                'last_purchase_amount' => $total,
            ])->saveQuietly();
        }

        return $transaction;
    }

    private function upsellRules(Store $store, array $menu): void
    {
        foreach (['MN-ESTEH', 'MN-KERUPUK'] as $i => $sku) {
            UpsellRule::query()->create([
                'store_id' => $store->id,
                'trigger_product_id' => $menu['MN-GEPREK']->id,
                'suggested_product_id' => $menu[$sku]->id,
                'sort' => $i,
            ]);
        }
        UpsellRule::query()->create([
            'store_id' => $store->id,
            'trigger_product_id' => $menu['MN-BAKAR']->id,
            'suggested_product_id' => $menu['MN-JERUK']->id,
        ]);
    }

    /** Pesanan antar (F10) dalam berbagai status. */
    private function deliveries(Store $store, User $courier, array $menu): void
    {
        $orders = [
            ['Ibu Wati', '081355500011', 'Perumahan Bukit Baruga Blok C3 No. 12', DeliveryOrder::STATUS_PREPARING, null, 0],
            ['Pak Herman', '081355500012', 'Jl. Pengayoman Ruko Mirah No. 5 (lantai 2)', DeliveryOrder::STATUS_ON_THE_WAY, $courier->id, 0],
            ['Kantor PT Sinar Timur', '081355500013', 'Menara Bosowa lt. 9, Jl. Jend. Sudirman', DeliveryOrder::STATUS_PREPARING, null, 0],
            ['Kak Rara', '081355500014', 'Jl. Toddopuli Raya No. 41', DeliveryOrder::STATUS_DELIVERED, $courier->id, 1],
        ];
        foreach ($orders as [$name, $phone, $address, $status, $courierId, $daysAgo]) {
            $transaction = $this->sale($store, $courier, [[$menu['MN-GEPREK'], 2], [$menu['MN-ESTEH'], 2]], $daysAgo, null, 8000);
            DeliveryOrder::query()->create([
                'store_id' => $store->id,
                'transaction_id' => $transaction->id,
                'recipient_name' => $name,
                'phone' => $phone,
                'address' => $address,
                'delivery_fee' => 8000,
                'courier_user_id' => $courierId,
                'status' => $status,
                'notes' => $status === DeliveryOrder::STATUS_PREPARING ? 'Sambal dipisah' : null,
                'dispatched_at' => in_array($status, [DeliveryOrder::STATUS_ON_THE_WAY, DeliveryOrder::STATUS_DELIVERED], true) ? now()->subMinutes(25) : null,
                'delivered_at' => $status === DeliveryOrder::STATUS_DELIVERED ? now()->subDay()->addMinutes(40) : null,
            ]);
        }
    }

    // ───────────────────────── Permintaan Stok ─────────────────────────

    private function stockFlow(Store $ck, User $ckAdmin, Store $outletA, User $adminA, Store $outletB, User $adminB, array $aItems, array $bItems): void
    {
        $workflow = app(StockRequestWorkflow::class);

        // 1) Selesai: Outlet B meminta, CK menyetujui (dengan revisi), Outlet B menerima — sebagian (selisih).
        $done = $workflow->create($adminB, $outletB, $ck, [
            ['product_id' => $bItems['BHN-AYAM']->id, 'quantity' => 8000],
            ['product_id' => $bItems['KMS-CUP']->id, 'quantity' => 300],
        ], 'Stok untuk akhir pekan');
        $done->forceFill(['created_at' => now()->subDays(3)])->saveQuietly();
        $lines = $done->lines()->get()->keyBy('requester_product_id');
        $done = $workflow->approve($done, $ckAdmin, [
            $lines[$bItems['BHN-AYAM']->id]->id => 6000, // gudang hanya kirim 6 kg
            $lines[$bItems['KMS-CUP']->id]->id => 300,
        ]);
        $this->receive($done->transfer()->first(), $adminB, $outletB, ['KMS-CUP' => [290, '10 cup penyok saat diantar']]);

        // 2) Dikirim, menunggu diterima Outlet A.
        $sent = $workflow->create($adminA, $outletA, $ck, [
            ['product_id' => $aItems['BHN-SAMBAL']->id, 'quantity' => 2500],
            ['product_id' => $aItems['BHN-MINYAK']->id, 'quantity' => 6000],
        ], 'Sambal hampir habis');
        $workflow->approve($sent, $ckAdmin, []);

        // 3) Menunggu persetujuan CK (muncul sebagai badge di menu gudang).
        $workflow->create($adminA, $outletA, $ck, [
            ['product_id' => $aItems['BHN-AYAM']->id, 'quantity' => 8000],
            ['product_id' => $aItems['BHN-BERAS']->id, 'quantity' => 15000],
            ['product_id' => $aItems['KMS-BOX']->id, 'quantity' => 200],
            ['product_id' => $aItems['BHN-JERUK']->id, 'quantity' => 50],
        ], 'Stok habis untuk makan siang besok');

        // 4) Ditolak.
        $rejected = $workflow->create($adminB, $outletB, $ck, [
            ['product_id' => $bItems['BHN-KERUPUK']->id, 'quantity' => 500],
        ]);
        $workflow->reject($rejected, $ckAdmin, 'Kerupuk sedang kosong dari supplier, kirim minggu depan.');
    }

    /**
     * Terima transfer seperti tombol "Terima" (F5): stok tujuan bertambah sesuai yang tiba.
     *
     * @param  array<string, array{0: float, 1: string}>  $short  sku => [jumlah diterima, alasan]
     */
    private function receive(StockTransfer $transfer, User $user, Store $destination, array $short = []): void
    {
        foreach ($transfer->lines()->with('toProduct')->get() as $line) {
            $product = $line->toProduct;
            $qty = (string) $line->quantity;
            $notes = null;
            if (isset($short[$product->sku])) {
                [$received, $reason] = $short[$product->sku];
                $line->update(['quantity_received' => $received, 'discrepancy_reason' => $reason]);
                $notes = "{$transfer->transfer_number} · diterima {$received} dari {$qty}: {$reason}";
                $qty = (string) $received;
            }
            $line->setRelation('transfer', $transfer);
            TransferStockOperations::addAtDestination($destination, $user, $product, $qty, $line, $notes);
            $product->refresh();
            $product->update([
                'average_cost' => InventoryCosting::weightedAverage(
                    bcsub((string) $product->stock_quantity, $qty, 4),
                    InventoryCosting::currentAverageCost($product),
                    $qty,
                    (string) $line->unit_cost_snapshot,
                ),
            ]);
        }

        $transfer->update(['status' => StockTransfer::STATUS_RECEIVED, 'received_at' => now()->subDays(2), 'received_by' => $user->id]);
        StockRequestWorkflow::syncFromTransfer($transfer);
    }

    private function summary(): void
    {
        if (! $this->command) {
            return;
        }

        $this->command->info('Akun demo Central Kitchen siap (kata sandi semua: '.($this->password ?? self::PASSWORD).')');
        $this->command->table(['Email', 'Nama', 'Lokasi'], $this->users);
    }
}
