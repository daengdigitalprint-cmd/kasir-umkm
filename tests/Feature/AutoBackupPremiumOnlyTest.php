<?php

use App\Models\BackupHistory;
use App\Models\Store;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake();
});

function autoBackupStore(string $planSlug, int $price): Store
{
    $plan = SubscriptionPlan::query()->firstOrCreate(
        ['slug' => $planSlug],
        [
            'name' => 'Paket '.$planSlug,
            'price' => $price,
            'billing_cycle' => 'monthly',
            'is_active' => true,
        ]
    );

    return Store::query()->create([
        'name' => 'Toko '.$planSlug,
        'slug' => $planSlug.'-'.uniqid(),
        'is_active' => true,
        'is_subscription_active' => true,
        'subscription_plan_id' => $plan->id,
        'subscription_end' => now()->addMonth(),
    ]);
}

it('backup otomatis hanya berjalan untuk toko paket premium', function () {
    $premium = autoBackupStore('bisnis', 99000);
    $free = autoBackupStore('starter', 0);

    $this->artisan('stores:backup')->assertExitCode(0);

    expect(BackupHistory::query()->where('store_id', $premium->id)->where('trigger_type', 'scheduled')->count())->toBe(1)
        ->and(BackupHistory::query()->where('store_id', $free->id)->count())->toBe(0);
});

it('backup otomatis tidak berjalan untuk toko paket gratis walau dipilih lewat --store-id', function () {
    $free = autoBackupStore('starter', 0);

    $this->artisan('stores:backup', ['--store-id' => $free->id])->assertExitCode(0);

    expect(BackupHistory::query()->where('store_id', $free->id)->count())->toBe(0);
});

it('backup otomatis berhenti saat langganan premium sudah habis', function () {
    $expired = autoBackupStore('bisnis', 99000);
    $expired->update(['subscription_end' => now()->subDay()]);

    $this->artisan('stores:backup')->assertExitCode(0);

    expect(BackupHistory::query()->where('store_id', $expired->id)->count())->toBe(0);
});

it('riwayat backup memberi tahu apakah backup otomatis aktif', function () {
    $free = autoBackupStore('starter', 0);
    $premium = autoBackupStore('bisnis', 99000);
    $freeUser = User::factory()->create(['store_id' => $free->id, 'role' => 'admin']);
    $premiumUser = User::factory()->create(['store_id' => $premium->id, 'role' => 'admin']);

    $this->actingAs($freeUser)->getJson('/settings/backup/history')
        ->assertOk()
        ->assertJsonPath('auto_backup_enabled', false);

    $this->actingAs($premiumUser)->getJson('/settings/backup/history')
        ->assertOk()
        ->assertJsonPath('auto_backup_enabled', true);
});

it('backup manual tetap bisa untuk toko paket gratis', function () {
    $free = autoBackupStore('starter', 0);
    $user = User::factory()->create(['store_id' => $free->id, 'role' => 'admin']);

    $this->actingAs($user)->postJson('/settings/backup/create')
        ->assertOk()
        ->assertJsonPath('success', true);

    expect(BackupHistory::query()->where('store_id', $free->id)->where('trigger_type', 'manual')->count())->toBe(1);
});
