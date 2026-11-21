<?php

namespace Database\Seeders;

use App\Models\CashShift;
use App\Models\Customer;
use App\Models\Product;
use App\Models\RoleMenuPermission;
use App\Models\ProductCategory;
use App\Models\Store;
use App\Models\SubscriptionPlan;
use App\Models\Table;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\TransactionPayment;
use App\Models\User;
use App\Support\KitchenOrders;
use App\Support\OptionalFeatures;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Toko demo "Kedai Kopi Senja" untuk screenshot etalase (Google Play, situs, materi promosi).
 * Isinya data dummy lengkap: menu berfoto, stok (ada yang menipis), pelanggan, meja QR,
 * antrean dapur, dan transaksi 30 hari terakhir termasuk hari ini.
 *
 *   php artisan db:seed --class=DemoEtalaseSeeder
 *
 * Login: demo.etalase@kasirumkm.test / password. Aman diulang (data lama dihapus dulu).
 * Foto menu dibaca dari database/seeders/assets/etalase/*.jpg.
 */
class DemoEtalaseSeeder extends Seeder
{
    public const EMAIL = 'demo.etalase@kasirumkm.test';

    public const STORE_SLUG = 'demo-kedai-kopi-senja';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->error('Seeder etalase hanya untuk local.');

            return;
        }

        config(['broadcasting.default' => 'null']);
        mt_srand(1121);

        $this->purge();

        DB::transaction(function () {
            $plan = SubscriptionPlan::query()->where('slug', 'bisnis')->firstOrFail();

            $store = Store::query()->create([
                'name' => 'Kedai Kopi Senja',
                'slug' => self::STORE_SLUG,
                'business_type' => 'fnb',
                'address' => 'Jl. Melati No. 12, Makassar',
                'phone' => '0811-4000-2026',
                'currency' => 'IDR',
                'timezone' => 'Asia/Makassar',
                'is_active' => true,
                'tax_enabled' => true,
                'tax_rate' => 10,
                'tax_name' => 'PB1',
                'subscription_plan_id' => $plan->id,
                'subscription_start' => now()->subMonths(2),
                'subscription_end' => now()->addYear(),
                'is_subscription_active' => true,
                'subscription_notes' => 'Akun demo etalase',
                'settings' => [
                    'ui_mode' => 'full',
                    'kitchen_queue_enabled' => true,
                    'receivables_enabled' => true,
                    'ai_chat_enabled' => false,
                    'accounting_enabled' => false,
                    'receipt_footer' => 'Terima kasih, sampai jumpa lagi!',
                ],
            ]);

            $owner = User::query()->create([
                'name' => 'Rina (Owner)',
                'email' => self::EMAIL,
                'password' => Hash::make('password'),
                'role' => 'owner',
                'store_id' => $store->id,
                'email_verified_at' => now(),
            ]);
            $owner->stores()->attach($store->id, ['role' => 'owner']);

            // Menu Antrian Dapur & Scan Meja tampil seperti toko yang menyalakannya sendiri.
            OptionalFeatures::enable($store, 'kitchen_queue');
            OptionalFeatures::enable($store, 'scan_meja');
            $store->refresh();

            // Pastikan owner demo melihat menu dapur & meja walau izin bawaan lokal belum memuatnya.
            $settings = $store->settings ?? [];
            $settings[RoleMenuPermission::STORE_SETTINGS_KEY]['owner'] = array_values(array_unique([
                ...RoleMenuPermission::defaultMenus('owner'), 'kitchen_queue', 'tables', 'table_orders',
            ]));
            $store->update(['settings' => $settings]);

            CashShift::query()->create([
                'store_id' => $store->id,
                'user_id' => $owner->id,
                'shift_number' => 'SH-'.now()->format('ymd').'-01',
                'opened_at' => now()->startOfDay()->addMinutes(5),
                'opening_cash' => 300000,
            ]);

            $menu = $this->menu($store);
            $customers = $this->customers($store);
            $this->tables($store);
            $this->sales($store, $owner, $menu, $customers);
            $this->kitchen($store, $owner, $menu);
        });

        $this->command?->info('Demo etalase siap: '.self::EMAIL.' / password');
    }

    private function purge(): void
    {
        $store = Store::query()->where('slug', self::STORE_SLUG)->first();
        DB::transaction(function () use ($store) {
            if ($store) {
                $trxIds = Transaction::query()->where('store_id', $store->id)->pluck('id');
                DB::table('queue_entries')->where('store_id', $store->id)->delete();
                DB::table('queues')->where('store_id', $store->id)->delete();
                DB::table('transaction_items')->whereIn('transaction_id', $trxIds)->delete();
                DB::table('transaction_payments')->whereIn('transaction_id', $trxIds)->delete();
                Transaction::query()->whereIn('id', $trxIds)->delete();
                DB::table('stock_movements')->where('store_id', $store->id)->delete();
                CashShift::query()->where('store_id', $store->id)->delete();
                Product::query()->where('store_id', $store->id)->delete();
                ProductCategory::query()->where('store_id', $store->id)->delete();
                Customer::query()->where('store_id', $store->id)->delete();
                Table::query()->where('store_id', $store->id)->delete();
                DB::table('store_user')->where('store_id', $store->id)->delete();
                $store->delete();
            }
            User::query()->where('email', self::EMAIL)->delete();
        });
    }

    /** @return array<string, Product> */
    private function menu(Store $store): array
    {
        // foto => [nama, kategori, harga, HPP, stok, stok minimum]
        $catalog = [
            'es-kopi-susu-aren' => ['Es Kopi Susu Aren', 'Kopi', 22000, 8500, 64, 15],
            'cappuccino' => ['Cappuccino', 'Kopi', 25000, 9000, 48, 15],
            'es-americano' => ['Es Americano', 'Kopi', 18000, 6000, 55, 15],
            'es-matcha-latte' => ['Es Matcha Latte', 'Non Kopi', 26000, 10500, 9, 15],
            'es-coklat' => ['Es Coklat', 'Non Kopi', 22000, 8000, 40, 15],
            'es-red-velvet' => ['Es Red Velvet', 'Non Kopi', 24000, 9500, 36, 15],
            'es-lychee-tea' => ['Es Lychee Tea', 'Non Kopi', 20000, 7000, 42, 15],
            'es-lemon-tea' => ['Es Lemon Tea', 'Non Kopi', 15000, 4500, 58, 15],
            'es-teh-manis' => ['Es Teh Manis', 'Non Kopi', 8000, 2000, 120, 20],
            'nasi-goreng-spesial' => ['Nasi Goreng Spesial', 'Makanan', 28000, 12000, 30, 10],
            'ayam-geprek' => ['Ayam Geprek Sambal', 'Makanan', 25000, 11000, 26, 10],
            'mie-goreng' => ['Mie Goreng Jawa', 'Makanan', 23000, 9000, 4, 10],
            'pisang-goreng-coklat' => ['Pisang Goreng Coklat', 'Camilan', 15000, 5000, 35, 10],
            'kentang-goreng' => ['Kentang Goreng', 'Camilan', 18000, 6500, 44, 10],
            'roti-bakar-coklat-keju' => ['Roti Bakar Coklat Keju', 'Camilan', 20000, 7000, 22, 10],
            'croissant' => ['Croissant Butter', 'Camilan', 19000, 8000, 7, 10],
            'dimsum-ayam' => ['Dimsum Ayam', 'Camilan', 21000, 8500, 28, 10],
            'brownies' => ['Brownies Coklat', 'Camilan', 16000, 6000, 18, 10],
        ];

        $categories = [];
        foreach (['Kopi', 'Non Kopi', 'Makanan', 'Camilan'] as $i => $name) {
            $categories[$name] = ProductCategory::query()->updateOrCreate(['store_id' => $store->id, 'name' => $name], ['sort_order' => $i + 1]);
        }

        $products = [];
        $n = 1;
        foreach ($catalog as $photo => [$name, $category, $price, $cost, $stock, $min]) {
            $path = "products/demo-etalase/{$photo}.jpg";
            $source = database_path("seeders/assets/etalase/{$photo}.jpg");
            if (is_file($source)) {
                Storage::disk('public')->put($path, file_get_contents($source));
            }

            $products[$photo] = Product::query()->create([
                'store_id' => $store->id,
                'product_category_id' => $categories[$category]->id,
                'name' => $name,
                'sku' => sprintf('KS-%03d', $n++),
                'price' => $price,
                'cost_price' => $cost,
                'average_cost' => $cost,
                'stock_quantity' => $stock,
                'min_stock_level' => $min,
                'unit' => 'pcs',
                'base_unit' => 'pcs',
                'inventory_type' => 'sellable',
                'track_stock' => true,
                'is_active' => true,
                'images' => is_file($source) ? [$path] : null,
            ]);
        }

        // Kategori bawaan toko baru yang tidak terpakai di demo ini.
        ProductCategory::query()->where('store_id', $store->id)
            ->whereNotIn('id', collect($categories)->pluck('id'))
            ->delete();

        return $products;
    }

    /** @return list<Customer> */
    private function customers(Store $store): array
    {
        $names = ['Andi Pratama', 'Siti Rahma', 'Budi Santoso', 'Lina Marlina', 'Dimas Saputra', 'Nadia Putri', 'Fajar Hidayat', 'Ayu Lestari'];

        return array_map(fn ($name, $i) => Customer::query()->create([
            'store_id' => $store->id,
            'name' => $name,
            'phone' => '0812900020'.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT),
            'customer_type' => 'regular',
            'is_active' => true,
            'total_spent' => 0,
            'total_orders' => 0,
        ]), $names, array_keys($names));
    }

    private function tables(Store $store): void
    {
        foreach (range(1, 8) as $i) {
            Table::query()->create([
                'store_id' => $store->id,
                'name' => 'Meja '.$i,
                'is_active' => true,
                'capacity' => $i <= 4 ? 2 : 4,
                'location' => $i <= 5 ? 'Dalam' : 'Teras',
            ]);
        }
    }

    /** Transaksi 30 hari terakhir, makin ramai menjelang hari ini; hari ini sudah ramai sampai jam sekarang. */
    private function sales(Store $store, User $cashier, array $menu, array $customers): void
    {
        for ($day = 30; $day >= 1; $day--) {
            $date = Carbon::now()->subDays($day)->startOfDay();
            $count = (int) round((14 + (30 - $day) * 0.35) * ($date->isWeekend() ? 1.4 : 1) + mt_rand(-3, 3));
            for ($i = 0; $i < $count; $i++) {
                $customer = mt_rand(1, 100) <= 30 ? $customers[array_rand($customers)] : null;
                $this->sale($store, $cashier, $this->basket($menu), $date->copy()->setTime(mt_rand(8, 21), mt_rand(0, 59)), $customer);
            }
        }

        // Hari ini: sedikit di atas rata-rata harian, tersebar dari awal hari sampai 15 menit lalu.
        $start = now()->startOfDay();
        $span = max(60, (int) $start->diffInSeconds(now()->subMinutes(15)));
        for ($i = 0; $i < 34; $i++) {
            $customer = mt_rand(1, 100) <= 30 ? $customers[array_rand($customers)] : null;
            $this->sale($store, $cashier, $this->basket($menu), $start->copy()->addSeconds(mt_rand(0, $span)), $customer);
        }
    }

    /** @return list<array{0: Product, 1: int}> */
    private function basket(array $menu): array
    {
        $drinks = ['es-kopi-susu-aren', 'es-kopi-susu-aren', 'es-kopi-susu-aren', 'cappuccino', 'es-americano', 'es-matcha-latte', 'es-coklat', 'es-red-velvet', 'es-lychee-tea', 'es-lemon-tea', 'es-teh-manis', 'es-teh-manis'];
        $foods = ['nasi-goreng-spesial', 'ayam-geprek', 'mie-goreng', 'pisang-goreng-coklat', 'kentang-goreng', 'roti-bakar-coklat-keju', 'croissant', 'dimsum-ayam', 'brownies'];

        $items = [[$menu[$drinks[array_rand($drinks)]], mt_rand(1, 100) <= 30 ? 2 : 1]];
        if (mt_rand(1, 100) <= 45) {
            $second = $drinks[array_rand($drinks)];
            if ($menu[$second]->id !== $items[0][0]->id) {
                $items[] = [$menu[$second], 1];
            }
        }
        if (mt_rand(1, 100) <= 60) {
            $items[] = [$menu[$foods[array_rand($foods)]], 1];
        }

        return $items;
    }

    private function sale(Store $store, User $cashier, array $items, Carbon $when, ?Customer $customer = null, string $status = 'completed'): Transaction
    {
        $subtotal = 0;
        foreach ($items as [$product, $qty]) {
            $subtotal += (float) $product->price * $qty;
        }
        $tax = round($subtotal * 0.10);
        $total = $subtotal + $tax;
        $roll = mt_rand(1, 100);
        $method = $roll <= 45 ? 'cash' : ($roll <= 85 ? 'qris' : 'transfer');
        $paid = $method === 'cash' ? ceil($total / 5000) * 5000 : $total;
        $orderType = mt_rand(1, 100) <= 65 ? 'dine_in' : 'takeaway';

        $transaction = Transaction::query()->create([
            'store_id' => $store->id,
            'customer_id' => $customer?->id,
            'user_id' => $cashier->id,
            'transaction_number' => 'KS-'.$when->format('ymd').'-'.Str::upper(Str::random(5)),
            'status_token' => Str::random(64),
            'status' => $status,
            'payment_method' => $method,
            'payment_status' => 'paid',
            'order_type' => $orderType,
            'table_reference' => $orderType === 'dine_in' ? (string) mt_rand(1, 8) : null,
            'sales_channel' => 'walk_in',
            'subtotal' => $subtotal,
            'tax_amount' => $tax,
            'discount_amount' => 0,
            'service_charge_amount' => 0,
            'tip_amount' => 0,
            'delivery_fee' => 0,
            'total_amount' => $total,
            'amount_paid' => $paid,
            'cash_amount' => $method === 'cash' ? $paid : 0,
            'change_amount' => $paid - $total,
            'completed_at' => $when,
        ]);
        $transaction->forceFill(['created_at' => $when, 'updated_at' => $when])->saveQuietly();

        TransactionPayment::query()->create([
            'transaction_id' => $transaction->id,
            'store_id' => $store->id,
            'method' => $method,
            'amount' => $total,
            'sort_order' => 0,
        ]);

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

    /** Papan dapur: beberapa pesanan berjalan + selesai hari ini. */
    private function kitchen(Store $store, User $cashier, array $menu): void
    {
        $plan = [
            ['completed', 95], ['completed', 80], ['completed', 66], ['completed', 52],
            ['serving', 14], ['serving', 9], ['serving', 6], ['waiting', 4], ['waiting', 2], ['waiting', 1],
        ];
        foreach ($plan as [$status, $minutesAgo]) {
            $when = now()->subMinutes($minutesAgo);
            $trx = $this->sale($store, $cashier, $this->basket($menu), $when);
            $entry = KitchenOrders::enqueue($store, $trx->load('items'), [
                'order_type' => $trx->order_type,
                'table_number' => $trx->table_reference,
            ]);
            if (! $entry) {
                continue;
            }
            $entry->forceFill([
                'status' => $status,
                'created_at' => $when,
                'called_at' => in_array($status, ['serving', 'ready', 'completed'], true) ? $when->copy()->addMinutes(1) : null,
                'ready_at' => in_array($status, ['ready', 'completed'], true) ? $when->copy()->addMinutes(mt_rand(6, 11)) : null,
                'completed_at' => $status === 'completed' ? $when->copy()->addMinutes(mt_rand(12, 15)) : null,
            ])->saveQuietly();
        }
    }
}
