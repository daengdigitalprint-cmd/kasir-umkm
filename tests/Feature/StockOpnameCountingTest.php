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
 * Perilaku hitung stock opname lewat rute sungguhan:
 * belum dihitung, hitung buta, dan penjualan selama sesi.
 */
function countingStore(): array
{
    $plan = SubscriptionPlan::query()->firstOrCreate(
        ['slug' => 'bisnis'],
        ['name' => 'Paket GrowUp', 'price' => 99000, 'billing_cycle' => 'monthly', 'is_active' => true]
    );

    $store = Store::query()->create([
        'name' => 'Toko Hitung',
        'slug' => 'hitung-'.uniqid(),
        'is_active' => true,
        'is_subscription_active' => true,
        'subscription_plan_id' => $plan->id,
        'subscription_end' => now()->addMonth(),
    ]);

    $user = User::factory()->create([
        'store_id' => $store->id,
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    return [$store, $user];
}

function countingProduct(Store $store, string $name, float $stock): Product
{
    return Product::query()->create([
        'store_id' => $store->id,
        'name' => $name,
        'price' => 15000,
        'cost_price' => 9000,
        'average_cost' => 9000,
        'stock_quantity' => $stock,
        'unit' => 'pcs',
        'track_stock' => true,
        'unlimited_stock' => false,
        'is_bundle' => false,
        'has_variants' => false,
        'allows_decimal' => false,
        'is_active' => true,
    ]);
}

function openCountingSession(User $user, array $payload = []): StockAdjustment
{
    test()->actingAs($user)->post('/stock-opnames', $payload)->assertRedirect();

    return StockAdjustment::query()->where('type', StockAdjustment::TYPE_OPNAME)->latest('id')->firstOrFail();
}

/** @param  array<int, string|null>  $counts  product_id => jumlah fisik (null = kosong) */
function saveCounts(User $user, StockAdjustment $doc, array $counts): void
{
    $lines = $doc->lines()->get()->map(fn ($l) => [
        'id' => $l->id,
        'counted_quantity' => array_key_exists($l->product_id, $counts) ? $counts[$l->product_id] : ($l->counted_quantity === null ? null : (string) $l->counted_quantity),
        'line_notes' => $l->line_notes,
    ])->all();

    test()->actingAs($user)->post("/stock-opnames/{$doc->id}/draft", ['lines' => $lines])->assertRedirect();
}

it('membuka sesi dengan semua baris belum dihitung dan menyimpan pilihan hitung buta', function () {
    [$store, $user] = countingStore();
    countingProduct($store, 'Kurma Ajwa', 60);

    $doc = openCountingSession($user, ['blind_count' => true]);

    expect($doc->blind_count)->toBeTrue();
    expect($doc->lines()->first()->counted_quantity)->toBeNull();
});

it('membiarkan stok produk yang belum dihitung saat posting dengan pilihan abaikan', function () {
    [$store, $user] = countingStore();
    $a = countingProduct($store, 'Kurma Ajwa', 60);
    $b = countingProduct($store, 'Kurma Sukari', 40);

    $doc = openCountingSession($user);
    saveCounts($user, $doc, [$a->id => '55']);

    $this->actingAs($user)->post("/stock-opnames/{$doc->id}/post", ['uncounted_mode' => 'ignore'])->assertSessionHasNoErrors();

    expect((float) $a->fresh()->stock_quantity)->toEqual(55.0)
        ->and((float) $b->fresh()->stock_quantity)->toEqual(40.0)
        ->and($doc->fresh()->status)->toBe(StockAdjustment::STATUS_POSTED);
});

it('mengosongkan stok produk yang belum dihitung saat posting dengan pilihan anggap 0', function () {
    [$store, $user] = countingStore();
    $a = countingProduct($store, 'Kurma Ajwa', 60);
    $b = countingProduct($store, 'Kurma Sukari', 40);

    $doc = openCountingSession($user);
    saveCounts($user, $doc, [$a->id => '55']);

    $this->actingAs($user)->post("/stock-opnames/{$doc->id}/post", ['uncounted_mode' => 'zero'])->assertSessionHasNoErrors();

    expect((float) $a->fresh()->stock_quantity)->toEqual(55.0)
        ->and((float) $b->fresh()->stock_quantity)->toEqual(0.0);
});

it('menolak posting bila belum ada satu pun produk yang dihitung', function () {
    [$store, $user] = countingStore();
    countingProduct($store, 'Kurma Ajwa', 60);

    $doc = openCountingSession($user);

    $this->actingAs($user)->post("/stock-opnames/{$doc->id}/post")->assertSessionHasErrors('lines');
    expect($doc->fresh()->status)->toBe(StockAdjustment::STATUS_DRAFT);
});

it('mengosongkan kembali baris yang isiannya dihapus', function () {
    [$store, $user] = countingStore();
    $a = countingProduct($store, 'Kurma Ajwa', 60);

    $doc = openCountingSession($user);
    saveCounts($user, $doc, [$a->id => '55']);
    saveCounts($user, $doc, [$a->id => null]);

    $line = $doc->lines()->first();
    expect($line->counted_quantity)->toBeNull()
        ->and((float) $line->variance_quantity)->toEqual(0.0);
});

it('tidak menghitung dua kali penjualan yang terjadi setelah sesi dibuka tetapi sebelum barang dihitung', function () {
    [$store, $user] = countingStore();
    $a = countingProduct($store, 'Kurma Ajwa', 60);

    $this->travelTo(now()->subHours(2));
    $doc = openCountingSession($user);
    $this->travelBack();

    // Terjual 5 setelah sesi dibuka (stok sistem 60 → 55), lalu barang dihitung: fisik 55.
    $a->update(['stock_quantity' => 55]);
    StockMovement::query()->create([
        'store_id' => $store->id,
        'product_id' => $a->id,
        'user_id' => $user->id,
        'quantity_change' => -5,
        'type' => 'sale',
        'balance_after' => 55,
    ]);

    saveCounts($user, $doc, [$a->id => '55']);

    $line = $doc->lines()->first();
    expect((float) $line->movement_during_count)->toEqual(-5.0)
        ->and((float) $line->variance_quantity)->toEqual(0.0);

    $this->actingAs($user)->post("/stock-opnames/{$doc->id}/post")->assertSessionHasNoErrors();

    // Tanpa koreksi, stok akan jadi 50 (penjualan terhitung dua kali).
    expect((float) $a->fresh()->stock_quantity)->toEqual(55.0);
});
