<?php

use App\Models\StockAdjustment;
use App\Models\Store;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\StockAdjustmentApproval;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeApprovalStore(array $settings = []): array
{
    $plan = SubscriptionPlan::query()->firstOrCreate(
        ['slug' => 'bisnis'],
        ['name' => 'Paket GrowUp', 'price' => 99000, 'billing_cycle' => 'monthly', 'is_active' => true]
    );

    $store = Store::query()->create([
        'name' => 'Toko Approval',
        'slug' => 'approval-'.uniqid(),
        'is_active' => true,
        'is_subscription_active' => true,
        'subscription_plan_id' => $plan->id,
        'subscription_end' => now()->addMonth(),
        'settings' => $settings,
    ]);

    $user = User::factory()->create([
        'store_id' => $store->id,
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    return [$store, $user];
}

function makeApprovalDoc(Store $store, User $createdBy, string $totalValue = '0.00'): StockAdjustment
{
    return StockAdjustment::create([
        'store_id' => $store->id,
        'type' => StockAdjustment::TYPE_ADJUSTMENT,
        'reason_code' => StockAdjustment::REASON_DAMAGE,
        'status' => StockAdjustment::STATUS_DRAFT,
        'total_variance_value' => $totalValue,
        'created_by' => $createdBy->id,
    ]);
}

test('persetujuan nonaktif secara default, dokumen tidak memerlukan approval', function () {
    [$store, $user] = makeApprovalStore();

    expect(StockAdjustmentApproval::enabled($store))->toBeFalse();

    $doc = makeApprovalDoc($store, $user, '999999.00');
    expect(StockAdjustmentApproval::requiresApproval($store, $doc))->toBeFalse();
});

test('ambang nol berarti seluruh dokumen wajib disetujui', function () {
    [$store, $user] = makeApprovalStore(['stock_adjustment' => [
        'approval_enabled' => true,
        'approval_threshold' => 0,
    ]]);

    expect(StockAdjustmentApproval::enabled($store))->toBeTrue();

    $doc = makeApprovalDoc($store, $user, '100.00');
    expect(StockAdjustmentApproval::requiresApproval($store, $doc))->toBeTrue();
});

test('dokumen di bawah ambang lolos tanpa persetujuan', function () {
    [$store, $user] = makeApprovalStore(['stock_adjustment' => [
        'approval_enabled' => true,
        'approval_threshold' => 500000,
    ]]);

    $doc = makeApprovalDoc($store, $user, '400000.00');
    expect(StockAdjustmentApproval::requiresApproval($store, $doc))->toBeFalse();
});

test('dokumen di atas ambang memerlukan persetujuan', function () {
    [$store, $user] = makeApprovalStore(['stock_adjustment' => [
        'approval_enabled' => true,
        'approval_threshold' => 500000,
    ]]);

    $doc = makeApprovalDoc($store, $user, '600000.00');
    expect(StockAdjustmentApproval::requiresApproval($store, $doc))->toBeTrue();
});

test('pemisahan wewenang mencegah pembuat menyetujui dokumennya sendiri', function () {
    [$store] = makeApprovalStore(['stock_adjustment' => [
        'segregation_of_duties' => true,
    ]]);

    $creator = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);
    $doc = makeApprovalDoc($store, $creator, '100000.00');

    expect(fn () => StockAdjustmentApproval::assertCanApprove($store, $doc, $creator))
        ->toThrow(RuntimeException::class);
});

test('bila pemisahan wewenang nonaktif, pembuat boleh menyetujui', function () {
    [$store] = makeApprovalStore(['stock_adjustment' => [
        'segregation_of_duties' => false,
    ]]);

    $creator = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);
    $doc = makeApprovalDoc($store, $creator);

    // Tidak throw.
    StockAdjustmentApproval::assertCanApprove($store, $doc, $creator);
    expect(true)->toBeTrue();
});

test('kasir tidak dapat menyetujui dokumen', function () {
    [$store] = makeApprovalStore();

    $creator = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);
    $cashier = User::factory()->create(['store_id' => $store->id, 'role' => 'cashier']);
    $doc = makeApprovalDoc($store, $creator);

    expect(fn () => StockAdjustmentApproval::assertCanApprove($store, $doc, $cashier))
        ->toThrow(RuntimeException::class);
});

test('threshold dan segregation tersimpan dan terbaca di pengaturan toko', function () {
    [$store] = makeApprovalStore();

    StockAdjustmentApproval::update($store, [
        'approval_enabled' => true,
        'approval_threshold' => 250000,
        'segregation_of_duties' => false,
    ]);

    $store->refresh();

    expect(StockAdjustmentApproval::enabled($store))->toBeTrue();
    expect(StockAdjustmentApproval::threshold($store))->toBe(250000.0);
    expect(StockAdjustmentApproval::segregationOfDuties($store))->toBeFalse();
});
