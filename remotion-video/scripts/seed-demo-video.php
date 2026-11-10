<?php

/**
 * Menyiapkan data demo yang layak tampil untuk video promosi.
 *
 * Dijalankan lewat: herd php artisan tinker remotion-video/scripts/seed-demo-video.php
 *
 * Semua operasi dibatasi pada toko demo (slug: demo-meli-saturiski). Toko lain
 * di database ini tidak disentuh sama sekali.
 *
 * Alasan skrip ini ada: DemoShowcaseSeeder punya $productSeed yang kosong, jadi
 * outlet demo tidak pernah dapat produk jual. Transaksinya lalu dibangun dari
 * bahan baku berharga Rp 0 sehingga omzetnya negatif dan POS-nya kosong.
 */

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use App\Support\Accounting\JournalPoster;
use Illuminate\Support\Facades\DB;

$store = Store::query()->where('slug', 'demo-meli-saturiski')->first();
if (! $store) {
    echo "Toko demo tidak ditemukan. Jalankan DemoShowcaseSeeder dulu.\n";

    return;
}

$user = User::query()->where('email', 'demo@umkm-kasir.test')->first();
if (! $user) {
    echo "User demo tidak ditemukan.\n";

    return;
}

echo "Toko demo: {$store->name} (id={$store->id})\n";

/*
 * 1. Katalog produk jual.
 * Temanya minuman dingin, menyambung bahan baku yang sudah ada di gudang demo.
 */
$menu = [
    ['Es Teh Manis',        'MENU-001', 'Minuman Dingin', 5000,  1800, 240],
    ['Es Teh Peach',        'MENU-002', 'Minuman Dingin', 8000,  2900, 180],
    ['Es Jeruk Nipis',      'MENU-003', 'Minuman Dingin', 8000,  3000, 165],
    ['Es Kopi Susu',        'MENU-004', 'Kopi',           15000, 5600, 210],
    ['Es Kopi Gula Aren',   'MENU-005', 'Kopi',           18000, 6800, 190],
    ['Kopi Hitam Panas',    'MENU-006', 'Kopi',           10000, 3200, 120],
    ['Es Coklat Premium',   'MENU-007', 'Minuman Dingin', 15000, 5400, 175],
    ['Es Matcha Latte',     'MENU-008', 'Minuman Dingin', 18000, 7100, 140],
    ['Es Taro Creamy',      'MENU-009', 'Minuman Dingin', 16000, 6200, 130],
    ['Es Red Velvet',       'MENU-010', 'Minuman Dingin', 16000, 6400, 125],
    ['Air Mineral 600ml',   'MENU-011', 'Lainnya',        4000,  2200, 300],
    ['Pisang Goreng Keju',  'MENU-012', 'Camilan',        15000, 6000, 90],
    ['Roti Bakar Coklat',   'MENU-013', 'Camilan',        12000, 4800, 85],
    ['Kentang Goreng',      'MENU-014', 'Camilan',        13000, 5200, 80],
];

$products = [];
foreach ($menu as [$name, $sku, $category, $price, $cost, $stock]) {
    $cat = ProductCategory::query()->firstOrCreate(
        ['store_id' => $store->id, 'name' => $category],
        ['sort_order' => 0],
    );

    $products[] = Product::query()->updateOrCreate(
        ['store_id' => $store->id, 'sku' => $sku],
        [
            'name' => $name,
            'barcode' => (string) random_int(1000000000000, 9999999999999),
            'price' => $price,
            'cost_price' => $cost,
            'average_cost' => (string) $cost,
            'stock_quantity' => $stock,
            'min_stock_level' => 20,
            'product_category_id' => $cat->id,
            'category' => $category,
            'brand' => 'Es Nusa',
            'unit' => 'pcs',
            'base_unit' => 'pcs',
            'inventory_type' => 'sellable',
            'tax_rate' => 0,
            'is_active' => true,
            'track_stock' => true,
            'has_variants' => false,
            'is_bundle' => false,
            'allows_decimal' => false,
        ]
    );
}
echo 'Produk jual siap: '.count($products)."\n";

/*
 * 2. Bahan baku jangan semuanya tampil "stok rendah" — stoknya dinaikkan
 *    jadi 3x batas minimum supaya halaman stok terlihat sehat.
 */
$bumped = Product::query()
    ->where('store_id', $store->id)
    ->whereIn('inventory_type', ['raw_material', 'packaging'])
    ->update(['stock_quantity' => DB::raw('GREATEST(min_stock_level * 3, 30)')]);
echo "Stok bahan baku dinaikkan: {$bumped}\n";

/*
 * 3. Hapus transaksi demo lama (hanya milik toko demo ini).
 */
$oldIds = Transaction::query()
    ->where('store_id', $store->id)
    ->where(function ($q) {
        $q->where('notes', 'like', 'Demo seeder:%')
            ->orWhere('notes', 'like', 'Demo video:%');
    })
    ->pluck('id');

if ($oldIds->isNotEmpty()) {
    $jeIds = DB::table('journal_entries')
        ->where('store_id', $store->id)
        ->whereIn('reference_id', $oldIds)
        ->pluck('id');
    if ($jeIds->isNotEmpty()) {
        DB::table('journal_lines')->whereIn('journal_entry_id', $jeIds)->delete();
        DB::table('journal_entries')->whereIn('id', $jeIds)->delete();
    }
    DB::table('transaction_items')->whereIn('transaction_id', $oldIds)->delete();
    Transaction::query()->whereIn('id', $oldIds)->delete();
}
echo 'Transaksi demo lama dihapus: '.$oldIds->count()."\n";

/*
 * 4. Transaksi baru: 90 hari ke belakang, menanjak ke arah hari ini supaya
 *    grafik dashboard naik dan persentase pertumbuhannya positif.
 */
$customerIds = Customer::query()->where('store_id', $store->id)->pluck('id')->all();
$productCount = count($products);
$seq = 0;
$created = 0;

for ($daysAgo = 89; $daysAgo >= 0; $daysAgo--) {
    $date = now()->subDays($daysAgo)->startOfDay();

    // Tren naik pelan + variasi harian + akhir pekan lebih ramai.
    $trend = 6 + (int) round(8 * (1 - $daysAgo / 90));
    $weekend = in_array($date->dayOfWeek, [0, 6], true) ? 4 : 0;
    $wobble = (int) round(3 * sin($daysAgo / 2.3));
    $perDay = max(3, $trend + $weekend + $wobble);

    // Hari ini dibuat sedikit lebih ramai dari kemarin.
    if ($daysAgo === 0) {
        $perDay += 3;
    }

    for ($n = 0; $n < $perDay; $n++) {
        $seq++;

        $hour = 8 + (int) floor(($n / max($perDay, 1)) * 13);
        $createdAt = $date->copy()->setTime(min($hour, 21), ($seq * 17) % 60, ($seq * 7) % 60);
        if ($createdAt->isFuture()) {
            $createdAt = now()->subMinutes(($n * 11) % 180);
        }

        $lineCount = 1 + ($seq % 3);
        $lines = [];
        $subtotal = 0.0;

        for ($l = 0; $l < $lineCount; $l++) {
            $p = $products[($seq * 3 + $l * 5) % $productCount];
            $qty = 1 + (($seq + $l) % 3);
            $lineTotal = (float) $p->price * $qty;
            $subtotal += $lineTotal;
            $lines[] = [$p, $qty, $lineTotal];
        }

        $discount = $seq % 7 === 0 ? 2000.0 : 0.0;
        $total = max($subtotal - $discount, 0);

        $trx = Transaction::query()->create([
            'store_id' => $store->id,
            'customer_id' => $customerIds ? $customerIds[$seq % count($customerIds)] : null,
            'user_id' => $user->id,
            'transaction_number' => sprintf('VID-%s-%04d', $createdAt->format('Ymd'), $seq),
            'status' => 'completed',
            'payment_method' => match ($seq % 3) {
                0 => 'cash',
                1 => 'qris',
                default => 'ewallet',
            },
            'subtotal' => $subtotal,
            'tax_amount' => 0,
            'discount_amount' => $discount,
            'service_charge_percent' => 0,
            'service_charge_amount' => 0,
            'total_amount' => $total,
            'amount_paid' => $total,
            'change_amount' => 0,
            'notes' => 'Demo video: transaksi contoh.',
            'completed_at' => $createdAt,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        /*
         * created_at tidak ada di $fillable milik Transaction, jadi Eloquent
         * mengabaikannya saat create() dan menstempel waktu sekarang. Tanggalnya
         * harus ditulis ulang lewat query builder, kalau tidak semua transaksi
         * menumpuk di hari ini dan grafik dashboard jadi satu batang.
         */
        DB::table('transactions')->where('id', $trx->id)->update([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
            'completed_at' => $createdAt,
        ]);

        foreach ($lines as [$p, $qty, $lineTotal]) {
            // Tanpa snapshot HPP, laporan menampilkan laba kotor 0 dan
            // peringatan "baris item belum punya data HPP".
            $unitCost = (float) $p->cost_price;
            $totalCost = $unitCost * $qty;

            TransactionItem::query()->create([
                'transaction_id' => $trx->id,
                'product_id' => $p->id,
                'product_name' => $p->name,
                'product_sku' => $p->sku,
                'quantity' => $qty,
                'unit_price' => $p->price,
                'total_price' => $lineTotal,
                'discount_amount' => 0,
                'tax_amount' => 0,
                'unit_cost_snapshot' => $unitCost,
                'total_cost_snapshot' => $totalCost,
                'gross_profit_snapshot' => $lineTotal - $totalCost,
            ]);
        }

        $created++;
    }
}
echo "Transaksi baru dibuat: {$created}\n";

/*
 * 5. Posting jurnal supaya halaman laporan akuntansi ikut terisi.
 */
$posted = 0;
Transaction::query()
    ->where('store_id', $store->id)
    ->where('notes', 'like', 'Demo video:%')
    ->orderBy('id')
    ->chunkById(50, function ($chunk) use ($store, &$posted): void {
        foreach ($chunk as $trx) {
            $trx->load(['items.product', 'items.productVariant']);
            try {
                JournalPoster::postSaleTransaction($store, $trx);
                $posted++;
            } catch (\Throwable $e) {
                // Jurnal bersifat tambahan untuk tampilan laporan; lewati bila gagal.
            }
        }
    });
echo "Jurnal diposting: {$posted}\n";

/*
 * 6. Rapikan tampilan toko untuk keperluan rekaman.
 *    Deskripsi bawaan seeder menyebut "screenshot UI" dan ada tagihan
 *    berstatus pending yang memunculkan banner peringatan di dashboard.
 *    Keduanya tidak pantas muncul di video promosi.
 */
$store->forceFill([
    'description' => 'Outlet minuman kekinian — es kopi, teh, dan camilan.',
])->save();

$pending = DB::table('subscription_payments')
    ->where('store_id', $store->id)
    ->where('status', 'pending')
    ->update([
        'status' => 'confirmed',
        'admin_notes' => 'Valid (demo)',
        'confirmed_at' => now()->subDays(2),
        'updated_at' => now(),
    ]);
echo "Profil toko dirapikan, tagihan pending ditutup: {$pending}\n";

$todayCount = Transaction::query()->where('store_id', $store->id)->whereDate('created_at', today())->count();
$todaySum = Transaction::query()->where('store_id', $store->id)->whereDate('created_at', today())->sum('total_amount');
echo "Hari ini: {$todayCount} transaksi, omzet Rp ".number_format((float) $todaySum, 0, ',', '.')."\n";
echo "Selesai.\n";
