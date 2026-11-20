<?php

use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use App\Support\StoreLocationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function customerListStore(): array
{
    $store = Store::query()->create([
        'name' => 'Toko Pelanggan',
        'slug' => 'pelanggan-'.uniqid(),
        'location_type' => StoreLocationType::OUTLET,
        'is_active' => true,
        'is_subscription_active' => true,
    ]);
    $admin = User::factory()->create(['store_id' => $store->id, 'role' => 'admin', 'email_verified_at' => now()]);
    $admin->stores()->attach($store->id, ['role' => 'admin']);

    return [$store, $admin];
}

function customerListBill(Store $store, User $user, Customer $c, float $total, float $paid, string $status): void
{
    DB::table('transactions')->insert([
        'store_id' => $store->id, 'user_id' => $user->id, 'customer_id' => $c->id,
        'transaction_number' => 'TXN-'.uniqid(), 'status' => 'completed', 'payment_method' => 'credit',
        'payment_status' => $status, 'subtotal' => $total, 'discount_amount' => 0, 'tax_amount' => 0,
        'total_amount' => $total, 'amount_paid' => $paid, 'change_amount' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

test('daftar pelanggan menampilkan total, utang, filter, dan pencarian dari server', function () {
    [$store, $admin] = customerListStore();
    $ani = Customer::query()->create(['store_id' => $store->id, 'name' => 'Bu Ani', 'phone' => '0811', 'total_spent' => 150000, 'last_purchase_at' => now()->subDays(2)]);
    $budi = Customer::query()->create(['store_id' => $store->id, 'name' => 'Pak Budi', 'phone' => '0822', 'total_spent' => 90000, 'last_purchase_at' => now()->subDays(45)]);
    Customer::query()->create(['store_id' => $store->id, 'name' => 'Tanpa Email']);

    customerListBill($store, $admin, $ani, 100000, 40000, 'partial');
    customerListBill($store, $admin, $budi, 90000, 90000, 'paid');

    $this->actingAs($admin);

    $this->get('/customers')->assertInertia(fn ($page) => $page
        ->where('customers.total', 3)
        ->where('counts.all', 3)
        ->where('counts.debt', 1)
        ->where('counts.inactive', 1)
        ->where('customers.data.0.name', 'Bu Ani')
        ->where('customers.data.0.total_spent', 150000)
        ->where('customers.data.0.outstanding_debt', 60000)
        ->where('customers.data.0.visits', 1));

    $this->get('/customers?filter=debt')->assertInertia(fn ($page) => $page
        ->has('customers.data', 1)->where('customers.data.0.name', 'Bu Ani'));

    $this->get('/customers?filter=inactive')->assertInertia(fn ($page) => $page
        ->has('customers.data', 1)->where('customers.data.0.name', 'Pak Budi'));

    $this->get('/customers?search=0822')->assertInertia(fn ($page) => $page
        ->has('customers.data', 1)->where('customers.data.0.name', 'Pak Budi'));

    $this->get('/customers?sort=spent')->assertInertia(fn ($page) => $page
        ->where('customers.data.0.name', 'Bu Ani')->where('customers.data.1.name', 'Pak Budi'));
});

test('pelanggan tanpa transaksi bisa dihapus, yang punya transaksi tidak', function () {
    [$store, $admin] = customerListStore();
    $kosong = Customer::query()->create(['store_id' => $store->id, 'name' => 'Baru']);
    $lama = Customer::query()->create(['store_id' => $store->id, 'name' => 'Lama']);
    customerListBill($store, $admin, $lama, 10000, 10000, 'paid');

    $this->actingAs($admin)->delete("/customers/{$kosong->id}")->assertSessionHas('success');
    $this->actingAs($admin)->delete("/customers/{$lama->id}")->assertSessionHas('error');

    expect(Customer::query()->find($kosong->id))->toBeNull()
        ->and(Customer::query()->find($lama->id))->not->toBeNull();
});
