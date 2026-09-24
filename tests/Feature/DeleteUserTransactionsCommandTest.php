<?php

use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use App\Support\StoreLocationType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('dry run tidak menghapus transaksi user', function () {
    $store = Store::query()->create([
        'name' => 'Target Store',
        'slug' => 'target-store',
        'location_type' => StoreLocationType::OUTLET,
        'is_active' => true,
        'is_subscription_active' => true,
    ]);
    $user = User::factory()->create([
        'email' => 'target@example.com',
        'store_id' => $store->id,
    ]);
    Transaction::query()->create([
        'store_id' => $user->store_id,
        'user_id' => $user->id,
        'status' => 'completed',
        'payment_method' => 'cash',
        'subtotal' => 10000,
        'total_amount' => 10000,
        'amount_paid' => 10000,
        'change_amount' => 0,
        'created_at' => '2026-08-15 10:00:00',
        'updated_at' => '2026-08-15 10:00:00',
    ]);

    $this->artisan('app:delete-user-transactions', [
        'email' => $user->email,
        '--from' => '2026-08-01',
        '--to' => '2026-08-31',
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('Tidak ada data yang dihapus')
        ->assertSuccessful();

    expect(Transaction::query()->where('user_id', $user->id)->count())->toBe(1);
});

test('command gagal jika user tidak ditemukan', function () {
    $this->artisan('app:delete-user-transactions', [
        'email' => 'tidak-ada@example.com',
        '--from' => '2026-08-01',
        '--to' => '2026-08-31',
    ])
        ->expectsOutputToContain('tidak ditemukan')
        ->assertFailed();
});
