<?php

use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\Store;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Koreksi stok dari staf (gudang, kasir) selalu menunggu persetujuan peninjau
 * (admin, manajer, pemilik) — meski alur persetujuan toko tidak dinyalakan.
 * Pembuat tetap tidak boleh menyetujui dokumennya sendiri.
 */
function staffReviewStore(): Store
{
    $plan = SubscriptionPlan::query()->firstOrCreate(
        ['slug' => 'bisnis'],
        ['name' => 'Paket GrowUp', 'price' => 99000, 'billing_cycle' => 'monthly', 'is_active' => true]
    );

    return Store::query()->create([
        'name' => 'Toko Tinjau',
        'slug' => 'tinjau-'.uniqid(),
        'is_active' => true,
        'is_subscription_active' => true,
        'subscription_plan_id' => $plan->id,
        'subscription_end' => now()->addMonth(),
    ]);
}

function staffReviewUser(Store $store, string $role): User
{
    $user = User::factory()->create(['store_id' => $store->id, 'role' => $role, 'email_verified_at' => now()]);
    $user->stores()->attach($store->id, ['role' => $role]);

    return $user;
}

function staffReviewProduct(Store $store): Product
{
    return Product::query()->create([
        'store_id' => $store->id,
        'name' => 'Beras',
        'price' => 2000,
        'cost_price' => 1000,
        'average_cost' => 1000,
        'stock_quantity' => 100,
        'unit' => 'pcs',
        'track_stock' => true,
        'unlimited_stock' => false,
        'is_bundle' => false,
        'has_variants' => false,
        'allows_decimal' => false,
        'is_active' => true,
    ]);
}

function staffReviewSubmit($test, User $user, Product $product): StockAdjustment
{
    $test->actingAs($user)->post('/stock-adjustments', [
        'reason_code' => 'damage',
        'lines' => [['product_id' => $product->id, 'quantity' => 3]],
    ])->assertSessionHasNoErrors();

    return StockAdjustment::query()->latest('id')->firstOrFail();
}

dataset('staf koreksi', ['warehouse', 'cashier']);

test('koreksi dari staf menunggu persetujuan dan stok belum berubah', function (string $role) {
    $store = staffReviewStore();
    $p = staffReviewProduct($store);

    $doc = staffReviewSubmit($this, staffReviewUser($store, $role), $p);

    expect($doc->status)->toBe(StockAdjustment::STATUS_PENDING_APPROVAL)
        ->and((float) $p->fresh()->stock_quantity)->toEqual(100.0);
})->with('staf koreksi');

test('staf tidak dapat menyetujui koreksinya sendiri', function () {
    $store = staffReviewStore();
    $p = staffReviewProduct($store);
    $gudang = staffReviewUser($store, 'warehouse');
    $doc = staffReviewSubmit($this, $gudang, $p);

    $this->actingAs($gudang)->post(route('stock-adjustments.approve', $doc))->assertSessionHasErrors('approval');

    expect($doc->fresh()->status)->toBe(StockAdjustment::STATUS_PENDING_APPROVAL)
        ->and((float) $p->fresh()->stock_quantity)->toEqual(100.0);
});

test('admin atau pemilik dapat menyetujui koreksi staf dan stok berubah', function (string $reviewerRole) {
    $store = staffReviewStore();
    $p = staffReviewProduct($store);
    $doc = staffReviewSubmit($this, staffReviewUser($store, 'warehouse'), $p);

    $this->actingAs(staffReviewUser($store, $reviewerRole))
        ->post(route('stock-adjustments.approve', $doc))
        ->assertSessionHasNoErrors();

    expect($doc->fresh()->status)->toBe(StockAdjustment::STATUS_POSTED)
        ->and((float) $p->fresh()->stock_quantity)->toEqual(97.0);
})->with(['admin', 'manager', 'owner']);

test('koreksi oleh admin tetap langsung diposting', function () {
    $store = staffReviewStore();
    $p = staffReviewProduct($store);

    $doc = staffReviewSubmit($this, staffReviewUser($store, 'admin'), $p);

    expect($doc->status)->toBe(StockAdjustment::STATUS_POSTED)
        ->and((float) $p->fresh()->stock_quantity)->toEqual(97.0);
});

test('form koreksi memberi tahu staf bahwa dokumen akan ditinjau', function () {
    $store = staffReviewStore();

    $this->actingAs(staffReviewUser($store, 'warehouse'))->get(route('stock-adjustments.create'))
        ->assertInertia(fn ($page) => $page->where('needsReview', true));
    $this->actingAs(staffReviewUser($store, 'admin'))->get(route('stock-adjustments.create'))
        ->assertInertia(fn ($page) => $page->where('needsReview', false));
});
