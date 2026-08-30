<?php

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('riwayat stok memfilter rentang tanggal', function () {
    $store = Store::query()->create([
        'name' => 'Toko Test',
        'slug' => 'toko-test-'.uniqid(),
        'is_active' => true,
    ]);

    $user = User::factory()->create([
        'store_id' => $store->id,
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $product = Product::query()->create([
        'store_id' => $store->id,
        'name' => 'Item A',
        'price' => 1000,
        'stock_quantity' => 10,
    ]);

    $old = StockMovement::query()->create([
        'store_id' => $store->id,
        'product_id' => $product->id,
        'user_id' => $user->id,
        'quantity_change' => -1,
        'type' => StockMovement::TYPE_SALE,
        'balance_after' => 9,
    ]);
    DB::table('stock_movements')->where('id', $old->id)->update([
        'created_at' => '2026-01-01 10:00:00',
        'updated_at' => '2026-01-01 10:00:00',
    ]);

    $recent = StockMovement::query()->create([
        'store_id' => $store->id,
        'product_id' => $product->id,
        'user_id' => $user->id,
        'quantity_change' => -1,
        'type' => StockMovement::TYPE_SALE,
        'balance_after' => 8,
    ]);
    DB::table('stock_movements')->where('id', $recent->id)->update([
        'created_at' => '2026-04-01 12:00:00',
        'updated_at' => '2026-04-01 12:00:00',
    ]);

    $this->actingAs($user)
        ->get(route('stock-movements.index', [
            'date_from' => '2026-03-01',
            'date_to' => '2026-04-30',
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('StockMovements/Index')
            ->has('movements.data', 1)
            ->where('movements.data.0.id', $recent->id)
        );
});

test('rentang tanggal tidak valid ditolak', function () {
    $store = Store::query()->create([
        'name' => 'Toko Test 2',
        'slug' => 'toko-test-2-'.uniqid(),
        'is_active' => true,
    ]);

    $user = User::factory()->create([
        'store_id' => $store->id,
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('stock-movements.index', [
            'date_from' => '2026-04-10',
            'date_to' => '2026-04-01',
        ]))
        ->assertSessionHasErrors('date_to');
});
