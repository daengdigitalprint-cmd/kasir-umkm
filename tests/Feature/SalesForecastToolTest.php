<?php

use App\Models\Product;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use App\Tools\Reports\SalesForecastTool;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createForecastStore(): Store
{
    return Store::query()->create([
        'name' => 'Toko Forecast',
        'slug' => 'toko-forecast-'.uniqid(),
        'is_active' => true,
    ]);
}

function createForecastProduct(Store $store, string $name): Product
{
    return Product::query()->create([
        'store_id' => $store->id,
        'name' => $name,
        'price' => 10000,
        'cost_price' => 6000,
        'stock_quantity' => 500,
        'unit' => 'pcs',
        'track_stock' => true,
        'is_active' => true,
    ]);
}

function recordForecastSale(Store $store, User $user, Product $product, int $quantity, \Carbon\Carbon $createdAt): void
{
    $total = $product->price * $quantity;

    $transaction = Transaction::create([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'transaction_number' => 'TRX-FCST-'.uniqid(),
        'subtotal' => $total,
        'total_amount' => $total,
        'amount_paid' => $total,
        'change_amount' => 0,
        'payment_method' => 'cash',
        'status' => 'completed',
    ]);

    $transaction->forceFill(['created_at' => $createdAt])->save();

    TransactionItem::create([
        'transaction_id' => $transaction->id,
        'product_id' => $product->id,
        'product_name' => $product->name,
        'quantity' => $quantity,
        'unit_price' => $product->price,
        'total_price' => $total,
    ]);
}

it('memprediksi penjualan tujuh hari ke depan dari histori penjualan', function () {
    $store = createForecastStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);
    $product = createForecastProduct($store, 'Teh Botol');

    // 2 unit setiap hari selama 30 hari terakhir.
    foreach (range(1, 30) as $offset) {
        recordForecastSale($store, $user, $product, 2, now()->subDays($offset)->setTime(12, 0));
    }

    $result = (new SalesForecastTool)->execute(['forecast_days' => 7], $user);

    expect($result->success)->toBeTrue()
        ->and($result->data['items'])->toHaveCount(7)
        ->and($result->data['total_forecast_qty'])->toBeGreaterThan(7)
        ->and($result->data['total_forecast_qty'])->toBeLessThan(21)
        ->and($result->data['total_forecast_revenue'])->toBeGreaterThan(0)
        ->and($result->data['forecast_days'])->toBe(7)
        ->and($result->data['history_days'])->toBe(90)
        ->and($result->data['method'])->toContain('berbobot')
        ->and($result->message)->toContain('7 hari ke depan')
        ->and($result->message)->toContain('Keyakinan prediksi');
});

it('menghitung prediksi per produk jika nama produk ditentukan', function () {
    $store = createForecastStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);
    $target = createForecastProduct($store, 'Kopi Sachet');
    $other = createForecastProduct($store, 'Es Teh Jumbo');

    foreach (range(1, 20) as $offset) {
        recordForecastSale($store, $user, $target, 5, now()->subDays($offset)->setTime(10, 0));
        recordForecastSale($store, $user, $other, 100, now()->subDays($offset)->setTime(11, 0));
    }

    $result = (new SalesForecastTool)->execute(['product_name' => 'Kopi Sachet'], $user);

    expect($result->success)->toBeTrue()
        ->and($result->data['product_name'])->toBe('Kopi Sachet')
        ->and($result->data['items'])->toHaveCount(7)
        // Prediksi harus dekat 5 unit/hari (produk target), bukan 105 (semua produk).
        ->and($result->data['total_forecast_qty'])->toBeGreaterThan(20)
        ->and($result->data['total_forecast_qty'])->toBeLessThan(50);
});

it('mengembalikan prediksi nol dengan pesan jelas saat belum ada penjualan', function () {
    $store = createForecastStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);
    createForecastProduct($store, 'Produk Baru');

    $result = (new SalesForecastTool)->execute([], $user);

    expect($result->success)->toBeTrue()
        ->and($result->data['total_forecast_qty'])->toBe(0.0)
        ->and($result->data['items'])->toHaveCount(7)
        ->and($result->message)->toContain('Belum ada data penjualan');
});

it('mengabaikan transaksi void dari dasar prediksi', function () {
    $store = createForecastStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);
    $product = createForecastProduct($store, 'Roti Bakar');

    foreach (range(1, 14) as $offset) {
        recordForecastSale($store, $user, $product, 3, now()->subDays($offset)->setTime(12, 0));
    }

    $voided = Transaction::create([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'transaction_number' => 'TRX-FCST-VOID-'.uniqid(),
        'subtotal' => 999999,
        'total_amount' => 999999,
        'amount_paid' => 999999,
        'change_amount' => 0,
        'payment_method' => 'cash',
        'status' => 'completed',
        'is_void' => true,
    ]);
    $voided->forceFill(['created_at' => now()->subDay()->setTime(12, 0)])->save();

    TransactionItem::create([
        'transaction_id' => $voided->id,
        'product_id' => $product->id,
        'product_name' => $product->name,
        'quantity' => 10000,
        'unit_price' => 999999,
        'total_price' => 999999,
    ]);

    $result = (new SalesForecastTool)->execute([], $user);

    expect($result->success)->toBeTrue()
        ->and($result->data['history_total_qty'])->toBe(42.0)
        ->and($result->data['total_forecast_qty'])->toBeLessThan(30);
});

it('mendaftarkan tool forecast_sales pada jalur chat', function () {
    $store = createForecastStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);

    $service = new \App\Services\AI\AiService($user, $store);
    $method = new ReflectionMethod(\App\Http\Controllers\Ai\AiChatController::class, 'registerTools');
    $method->setAccessible(true);
    $method->invoke(app(\App\Http\Controllers\Ai\AiChatController::class), $service, $user);

    expect($service->getToolRegistry()->has('forecast_sales'))->toBeTrue();
});
