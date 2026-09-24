<?php

use App\Models\Product;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use App\Support\StoreLocationType;
use Inertia\Testing\AssertableInertia as Assert;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users without store can visit the dashboard', function () {
    $user = User::factory()->create([
        'role' => 'admin',
    ]);
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertStatus(200);
});

test('authenticated users with store can view peak hours and top products for this month', function () {
    $store = Store::query()->create([
        'name' => 'Test Store',
        'slug' => 'test-store-'.uniqid(),
        'location_type' => StoreLocationType::OUTLET,
        'is_active' => true,
    ]);

    $user = User::factory()->create([
        'store_id' => $store->id,
        'role' => 'admin',
    ]);

    $product = Product::query()->create([
        'store_id' => $store->id,
        'name' => 'Kopi Susu',
        'price' => 15000,
    ]);

    $transaction = Transaction::create([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'transaction_number' => 'TRX-MONTH-1',
        'subtotal' => 30000,
        'total_amount' => 30000,
        'amount_paid' => 30000,
        'paid_amount' => 30000,
        'change_amount' => 0,
        'payment_method' => 'cash',
        'status' => 'completed',
        'created_at' => now()->startOfMonth()->addDays(2)->setHour(14),
    ]);

    TransactionItem::create([
        'transaction_id' => $transaction->id,
        'product_id' => $product->id,
        'product_name' => $product->name,
        'quantity' => 2,
        'unit_price' => 15000,
        'total_price' => 30000,
        'subtotal' => 30000,
    ]);

    $this->actingAs($user);

    $response = $this->get(route('dashboard'));

    $response->assertStatus(200)
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->has('peakHours')
            ->has('topProducts', 1)
            ->where('topProducts.0.name', 'Kopi Susu')
            ->where('topProducts.0.quantity', 2)
        );
});
