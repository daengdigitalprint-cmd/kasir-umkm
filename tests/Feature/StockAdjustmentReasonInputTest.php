<?php

use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Penyesuaian stok: jumlah diisi sesuai alasan, alasan per baris, dan pecah kemasan.
 */
function reasonStore(): array
{
    $plan = SubscriptionPlan::query()->firstOrCreate(
        ['slug' => 'bisnis'],
        ['name' => 'Paket GrowUp', 'price' => 99000, 'billing_cycle' => 'monthly', 'is_active' => true]
    );

    $store = Store::query()->create([
        'name' => 'Toko Alasan',
        'slug' => 'alasan-'.uniqid(),
        'is_active' => true,
        'is_subscription_active' => true,
        'subscription_plan_id' => $plan->id,
        'subscription_end' => now()->addMonth(),
    ]);

    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin', 'email_verified_at' => now()]);

    return [$store, $user];
}

function reasonProduct(Store $store, string $name, float $stock, float $cost = 1000, string $unit = 'pcs'): Product
{
    return Product::query()->create([
        'store_id' => $store->id,
        'name' => $name,
        'price' => $cost * 2,
        'cost_price' => $cost,
        'average_cost' => $cost,
        'stock_quantity' => $stock,
        'unit' => $unit,
        'track_stock' => true,
        'unlimited_stock' => false,
        'is_bundle' => false,
        'has_variants' => false,
        'allows_decimal' => false,
        'is_active' => true,
    ]);
}

it('mengurangi stok sebanyak jumlah yang diisi untuk alasan rusak', function () {
    [$store, $user] = reasonStore();
    $p = reasonProduct($store, 'Kurma Ajwa', 100);

    $this->actingAs($user)->post('/stock-adjustments', [
        'reason_code' => 'damage',
        'lines' => [['product_id' => $p->id, 'quantity' => 3]],
    ])->assertSessionHasNoErrors();

    expect((float) $p->fresh()->stock_quantity)->toEqual(97.0);
});

it('menambah stok sebanyak jumlah yang diisi untuk alasan ditemukan', function () {
    [$store, $user] = reasonStore();
    $p = reasonProduct($store, 'Kurma Ajwa', 100);

    $this->actingAs($user)->post('/stock-adjustments', [
        'reason_code' => 'found',
        'lines' => [['product_id' => $p->id, 'quantity' => 5]],
    ])->assertSessionHasNoErrors();

    expect((float) $p->fresh()->stock_quantity)->toEqual(105.0);
});

it('memakai stok sebenarnya untuk alasan koreksi', function () {
    [$store, $user] = reasonStore();
    $p = reasonProduct($store, 'Kurma Ajwa', 100);

    $this->actingAs($user)->post('/stock-adjustments', [
        'reason_code' => 'correction',
        'lines' => [['product_id' => $p->id, 'counted_quantity' => 120]],
    ])->assertSessionHasNoErrors();

    expect((float) $p->fresh()->stock_quantity)->toEqual(120.0);
});

it('menolak pengurangan melebihi stok tercatat', function () {
    [$store, $user] = reasonStore();
    $p = reasonProduct($store, 'Kurma Ajwa', 2);

    $this->actingAs($user)->post('/stock-adjustments', [
        'reason_code' => 'damage',
        'lines' => [['product_id' => $p->id, 'quantity' => 5]],
    ])->assertSessionHasErrors('lines');

    expect((float) $p->fresh()->stock_quantity)->toEqual(2.0);
});

it('menolak bila alasan belum dipilih', function () {
    [$store, $user] = reasonStore();
    $p = reasonProduct($store, 'Kurma Ajwa', 100);

    $this->actingAs($user)->post('/stock-adjustments', [
        'lines' => [['product_id' => $p->id, 'quantity' => 1]],
    ])->assertSessionHasErrors('reason_code');
});

it('memecah baris beralasan berbeda menjadi dokumen per alasan', function () {
    [$store, $user] = reasonStore();
    $a = reasonProduct($store, 'Kurma Ajwa', 100);
    $b = reasonProduct($store, 'Kurma Sukari', 50);

    $this->actingAs($user)->post('/stock-adjustments', [
        'lines' => [
            ['product_id' => $a->id, 'reason_code' => 'damage', 'quantity' => 2],
            ['product_id' => $b->id, 'reason_code' => 'expired', 'quantity' => 1],
        ],
    ])->assertSessionHasNoErrors()->assertRedirect(route('stock-adjustments.index'));

    expect(StockAdjustment::query()->pluck('reason_code')->sort()->values()->all())->toBe(['damage', 'expired'])
        ->and((float) $a->fresh()->stock_quantity)->toEqual(98.0)
        ->and((float) $b->fresh()->stock_quantity)->toEqual(49.0);
});

it('tidak bisa memakai alasan pecah kemasan lewat form penyesuaian', function () {
    [$store, $user] = reasonStore();
    $p = reasonProduct($store, 'Kurma Ajwa', 100);

    $this->actingAs($user)->post('/stock-adjustments', [
        'reason_code' => 'repack',
        'lines' => [['product_id' => $p->id, 'quantity' => 1]],
    ])->assertSessionHasErrors('reason_code');
});

it('memecah kemasan dan memindahkan nilai persediaan ke produk hasil', function () {
    [$store, $user] = reasonStore();
    // 1 kg = Rp72.000 → 72 bungkus × Rp1.000.
    $kg = reasonProduct($store, 'KACANG ARAB 1KG', 10, 72000, 'kg');
    $pcs = reasonProduct($store, 'KACANG ARAB PERPCS (15GRM)', 0, 0, 'pcs');

    $this->actingAs($user)->post('/stock-repacks', [
        'source_product_id' => $kg->id,
        'source_quantity' => 1,
        'output_product_id' => $pcs->id,
        'output_quantity' => 72,
    ])->assertSessionHasNoErrors();

    expect((float) $kg->fresh()->stock_quantity)->toEqual(9.0)
        ->and((float) $pcs->fresh()->stock_quantity)->toEqual(72.0)
        ->and((float) $pcs->fresh()->average_cost)->toEqual(1000.0);

    $doc = StockAdjustment::query()->where('type', StockAdjustment::TYPE_REPACK)->firstOrFail();
    expect($doc->adjustment_number)->toStartWith('RPK/')
        ->and((float) $doc->total_variance_value)->toEqual(0.0)
        ->and($doc->status)->toBe(StockAdjustment::STATUS_POSTED);

    expect(StockMovement::query()->where('type', StockMovement::TYPE_REPACK)->count())->toBe(2);
});

it('menghitung HPP rata-rata produk hasil yang sudah punya stok', function () {
    [$store, $user] = reasonStore();
    $kg = reasonProduct($store, 'KACANG ARAB 1KG', 10, 72000, 'kg');
    // 28 bungkus lama @Rp1.500 + 72 bungkus baru @Rp1.000 = Rp114.000 / 100 = Rp1.140.
    $pcs = reasonProduct($store, 'KACANG ARAB PERPCS (15GRM)', 28, 1500, 'pcs');

    $this->actingAs($user)->post('/stock-repacks', [
        'source_product_id' => $kg->id, 'source_quantity' => 1,
        'output_product_id' => $pcs->id, 'output_quantity' => 72,
    ])->assertSessionHasNoErrors();

    expect((float) $pcs->fresh()->stock_quantity)->toEqual(100.0)
        ->and((float) $pcs->fresh()->average_cost)->toEqual(1140.0);
});

it('menolak pecah kemasan melebihi stok produk asal', function () {
    [$store, $user] = reasonStore();
    $kg = reasonProduct($store, 'KACANG ARAB 1KG', 1, 72000, 'kg');
    $pcs = reasonProduct($store, 'KACANG ARAB PERPCS (15GRM)', 0, 0, 'pcs');

    $this->actingAs($user)->post('/stock-repacks', [
        'source_product_id' => $kg->id, 'source_quantity' => 2,
        'output_product_id' => $pcs->id, 'output_quantity' => 144,
    ])->assertSessionHasErrors('source_quantity');

    expect((float) $kg->fresh()->stock_quantity)->toEqual(1.0)
        ->and((float) $pcs->fresh()->stock_quantity)->toEqual(0.0);
});

it('mengembalikan stok kedua produk saat pecah kemasan dibatalkan', function () {
    [$store, $user] = reasonStore();
    $kg = reasonProduct($store, 'KACANG ARAB 1KG', 10, 72000, 'kg');
    $pcs = reasonProduct($store, 'KACANG ARAB PERPCS (15GRM)', 0, 0, 'pcs');

    $this->actingAs($user)->post('/stock-repacks', [
        'source_product_id' => $kg->id, 'source_quantity' => 1,
        'output_product_id' => $pcs->id, 'output_quantity' => 72,
    ]);
    $doc = StockAdjustment::query()->where('type', StockAdjustment::TYPE_REPACK)->firstOrFail();

    $this->actingAs($user)->post("/stock-adjustments/{$doc->id}/cancel", ['cancellation_reason' => 'salah produk'])
        ->assertSessionHasNoErrors();

    expect((float) $kg->fresh()->stock_quantity)->toEqual(10.0)
        ->and((float) $pcs->fresh()->stock_quantity)->toEqual(0.0);
});
