<?php

use App\Models\StockAdjustment;
use App\Models\StockAdjustmentLine;
use App\Models\Store;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\StorePlanLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Test Tahap 1 — Fondasi data koreksi stok.
 */
function makeAdjustmentStore(string $planSlug = 'bisnis'): array
{
    $plan = SubscriptionPlan::query()->firstOrCreate(
        ['slug' => $planSlug],
        [
            'name' => 'Paket '.$planSlug,
            'price' => $planSlug === 'starter' ? 0 : 99000,
            'billing_cycle' => 'monthly',
            'is_active' => true,
        ]
    );

    $store = Store::query()->create([
        'name' => 'Toko Koreksi Stok',
        'slug' => 'koreksi-stok-'.uniqid(),
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

    return [$store, $user, $plan];
}

it('memberi nomor dokumen berurutan per toko', function () {
    [$store] = makeAdjustmentStore();

    $a = StockAdjustment::create(['store_id' => $store->id, 'reason_code' => StockAdjustment::REASON_DAMAGE]);
    $b = StockAdjustment::create(['store_id' => $store->id, 'reason_code' => StockAdjustment::REASON_LOST]);

    $prefix = 'ADJ/'.now()->format('Y').'/'.now()->format('m').'/';

    expect($a->adjustment_number)->toBe($prefix.'0001');
    expect($b->adjustment_number)->toBe($prefix.'0002');
});

it('memakai awalan OPN untuk dokumen opname', function () {
    [$store] = makeAdjustmentStore();

    $doc = StockAdjustment::create([
        'store_id' => $store->id,
        'type' => StockAdjustment::TYPE_OPNAME,
        'reason_code' => StockAdjustment::REASON_OPNAME,
    ]);

    expect($doc->adjustment_number)->toStartWith('OPN/');
    expect($doc->adjustment_number)->toEndWith('/0001');
});

it('memisahkan urutan nomor antar toko', function () {
    [$storeA] = makeAdjustmentStore();
    [$storeB] = makeAdjustmentStore();

    $a = StockAdjustment::create(['store_id' => $storeA->id, 'reason_code' => StockAdjustment::REASON_DAMAGE]);
    $b = StockAdjustment::create(['store_id' => $storeB->id, 'reason_code' => StockAdjustment::REASON_DAMAGE]);

    expect($a->adjustment_number)->toEndWith('/0001');
    expect($b->adjustment_number)->toEndWith('/0001');
    expect($a->store_id)->not->toBe($b->store_id);
});

it('menetapkan status draft dan tipe adjustment secara bawaan', function () {
    [$store] = makeAdjustmentStore();

    $doc = StockAdjustment::create(['store_id' => $store->id, 'reason_code' => StockAdjustment::REASON_CORRECTION]);

    expect($doc->status)->toBe(StockAdjustment::STATUS_DRAFT);
    expect($doc->type)->toBe(StockAdjustment::TYPE_ADJUSTMENT);
    expect($doc->isDraft())->toBeTrue();
    expect($doc->isFinal())->toBeFalse();
});

it('mengizinkan transisi draft ke posted dan menolak posting ganda', function () {
    [$store] = makeAdjustmentStore();

    $doc = StockAdjustment::create(['store_id' => $store->id, 'reason_code' => StockAdjustment::REASON_DAMAGE]);
    $doc->assertCanTransitionTo(StockAdjustment::STATUS_POSTED);

    $doc->update(['status' => StockAdjustment::STATUS_POSTED]);

    expect($doc->isPosted())->toBeTrue();
    expect($doc->isFinal())->toBeTrue();
    expect($doc->allowedTransitions())->toBe([]);

    expect(fn () => $doc->assertCanTransitionTo(StockAdjustment::STATUS_POSTED))
        ->toThrow(RuntimeException::class);
});

it('menyimpan baris dengan snapshot stok sistem dan selisih', function () {
    [$store] = makeAdjustmentStore();

    $doc = StockAdjustment::create(['store_id' => $store->id, 'reason_code' => StockAdjustment::REASON_LOST]);

    $product = App\Models\Product::query()->create([
        'store_id' => $store->id,
        'name' => 'Beras 5kg',
        'price' => 70000,
        'cost_price' => 60000,
        'average_cost' => 60000,
        'stock_quantity' => 50,
        'track_stock' => true,
        'is_active' => true,
    ]);

    $line = StockAdjustmentLine::create([
        'stock_adjustment_id' => $doc->id,
        'product_id' => $product->id,
        'system_quantity' => '50.0000',
        'counted_quantity' => '48.0000',
        'variance_quantity' => '-2.0000',
        'unit_cost' => '60000.000000',
        'variance_value' => '-120000.00',
    ]);

    expect($doc->lines()->count())->toBe(1);
    expect((string) $line->variance_quantity)->toBe('-2.0000');
    expect($line->product->name)->toBe('Beras 5kg');
});

it('menghapus baris saat dokumen dihapus', function () {
    [$store] = makeAdjustmentStore();

    $doc = StockAdjustment::create(['store_id' => $store->id, 'reason_code' => StockAdjustment::REASON_DAMAGE]);
    $product = App\Models\Product::query()->create([
        'store_id' => $store->id,
        'name' => 'Gula 1kg',
        'price' => 15000,
        'stock_quantity' => 10,
        'track_stock' => true,
        'is_active' => true,
    ]);

    StockAdjustmentLine::create([
        'stock_adjustment_id' => $doc->id,
        'product_id' => $product->id,
        'system_quantity' => '10.0000',
        'counted_quantity' => '9.0000',
        'variance_quantity' => '-1.0000',
    ]);

    $doc->delete();

    expect(StockAdjustmentLine::query()->count())->toBe(0);
});

it('menutup modul koreksi stok bagi paket starter', function () {
    [$starterStore] = makeAdjustmentStore('starter');
    [$growUpStore] = makeAdjustmentStore('bisnis');

    expect(StorePlanLimits::allowsAdvancedRetailFeatures($starterStore))->toBeFalse();
    expect(StorePlanLimits::allowsAdvancedRetailFeatures($growUpStore))->toBeTrue();
});

it('menyediakan label alasan dan status dalam bahasa indonesia', function () {
    expect(StockAdjustment::reasonLabel(StockAdjustment::REASON_DAMAGE))->toBe('Rusak atau pecah');
    expect(StockAdjustment::reasonLabel(StockAdjustment::REASON_OPNAME))->toBe('Hasil stock opname');
    expect(StockAdjustment::statusLabel(StockAdjustment::STATUS_POSTED))->toBe('Diposting');
    expect(StockAdjustment::statusLabel(StockAdjustment::STATUS_PENDING_APPROVAL))->toBe('Menunggu persetujuan');
});
