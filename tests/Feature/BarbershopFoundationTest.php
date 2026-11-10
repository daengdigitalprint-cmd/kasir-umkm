<?php

use App\Models\Product;
use App\Models\Queue;
use App\Models\RoleMenuPermission;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\RoleMenuPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeFoundationBarbershop(): Store
{
    return Store::query()->create([
        'name' => 'Barber Fondasi',
        'slug' => 'barber-fondasi-'.uniqid(),
        'business_type' => 'barbershop',
        'is_active' => true,
        'is_subscription_active' => true,
    ]);
}

test('produk layanan dapat menyimpan durasi layanan dalam menit', function () {
    $store = makeFoundationBarbershop();

    $product = Product::query()->create([
        'store_id' => $store->id,
        'name' => 'Potong Rambut',
        'sku' => 'SVC-'.uniqid(),
        'price' => 50000,
        'track_stock' => false,
        'service_duration_minutes' => 30,
    ]);

    expect($product->service_duration_minutes)->toBe(30);

    $this->assertDatabaseHas('products', [
        'id' => $product->id,
        'service_duration_minutes' => 30,
    ]);
});

test('produk non-layanan tetap boleh tanpa durasi', function () {
    $store = makeFoundationBarbershop();

    $product = Product::query()->create([
        'store_id' => $store->id,
        'name' => 'Pomade',
        'sku' => 'PRD-'.uniqid(),
        'price' => 60000,
    ]);

    expect($product->service_duration_minutes)->toBeNull();
});

test('transaksi dapat menugaskan teknisi lewat barber_user_id', function () {
    $store = makeFoundationBarbershop();
    $cashier = User::factory()->create(['store_id' => $store->id, 'role' => 'cashier']);
    $barber = User::factory()->create(['store_id' => $store->id, 'role' => 'barber']);

    $transaction = Transaction::query()->create([
        'store_id' => $store->id,
        'user_id' => $cashier->id,
        'barber_user_id' => $barber->id,
        'subtotal' => 50000,
        'total_amount' => 50000,
        'amount_paid' => 50000,
    ]);

    $this->assertDatabaseHas('transactions', [
        'id' => $transaction->id,
        'barber_user_id' => $barber->id,
    ]);

    expect($transaction->barber()->first()?->id)->toBe($barber->id)
        ->and($transaction->user()->first()?->id)->toBe($cashier->id);
});

test('transaksi tanpa teknisi tetap valid (jenis usaha lain)', function () {
    $store = makeFoundationBarbershop();
    $cashier = User::factory()->create(['store_id' => $store->id, 'role' => 'cashier']);

    $transaction = Transaction::query()->create([
        'store_id' => $store->id,
        'user_id' => $cashier->id,
        'subtotal' => 10000,
        'total_amount' => 10000,
        'amount_paid' => 10000,
    ]);

    expect($transaction->barber_user_id)->toBeNull();
});

test('entri antrian dapat menugaskan teknisi', function () {
    $store = makeFoundationBarbershop();
    $barber = User::factory()->create(['store_id' => $store->id, 'role' => 'barber']);
    $queue = Queue::query()->create([
        'store_id' => $store->id,
        'name' => 'Antrian Barber',
        'code' => 'Q'.uniqid(),
        'is_active' => true,
    ]);

    $entry = $queue->entries()->create([
        'store_id' => $store->id,
        'number' => 1,
        'status' => 'waiting',
        'barber_user_id' => $barber->id,
    ]);

    $this->assertDatabaseHas('queue_entries', [
        'id' => $entry->id,
        'barber_user_id' => $barber->id,
    ]);

    expect($entry->barber()->first()?->id)->toBe($barber->id);
});

test('user dengan role barber dapat dibuat dan ditambahkan ke toko', function () {
    $store = makeFoundationBarbershop();

    $barber = User::factory()->create([
        'store_id' => $store->id,
        'role' => 'barber',
    ]);
    $store->members()->attach($barber->id, ['role' => 'barber']);

    expect($barber->isBarber())->toBeTrue()
        ->and($store->members()->wherePivot('role', 'barber')->count())->toBe(1);
});

test('barbersForStore menemukan teknisi via pivot maupun role global', function () {
    $store = makeFoundationBarbershop();

    $viaPivot = User::factory()->create(['store_id' => $store->id, 'role' => 'cashier']);
    $store->members()->attach($viaPivot->id, ['role' => 'barber']);

    $viaGlobal = User::factory()->create(['store_id' => $store->id, 'role' => 'barber']);
    $store->members()->attach($viaGlobal->id, ['role' => 'barber']);

    $nonBarber = User::factory()->create(['store_id' => $store->id, 'role' => 'cashier']);
    $store->members()->attach($nonBarber->id, ['role' => 'cashier']);

    $barbers = User::barbersForStore($store);

    expect($barbers->pluck('id')->all())
        ->toContain($viaPivot->id, $viaGlobal->id)
        ->not->toContain($nonBarber->id);
});

test('seeder memberi daftar menu default untuk role barber', function () {
    (new RoleMenuPermissionSeeder)->run();

    $menus = RoleMenuPermission::allowedMenus('barber');

    expect($menus)->toContain('dashboard', 'queues', 'pos_transactions', 'attendance', 'settings')
        ->and($menus)->not->toContain('reports', 'payroll', 'products');
});
