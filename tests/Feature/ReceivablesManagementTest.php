<?php

use App\Models\Customer;
use App\Models\ReceivablePayment;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use App\Support\StoreLocationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function receivableStore(): Store
{
    return Store::query()->create([
        'name' => 'Toko Piutang',
        'slug' => 'toko-piutang-'.uniqid(),
        'location_type' => StoreLocationType::OUTLET,
        'business_type' => 'retail',
        'is_active' => true,
        'is_subscription_active' => true,
        'settings' => ['receivables_enabled' => true],
    ]);
}

function receivableAdmin(Store $store): User
{
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin', 'email_verified_at' => now()]);
    $user->stores()->attach($store->id, ['role' => 'admin']);

    return $user;
}

function receivableBill(Store $store, User $user, Customer $customer, float $total, ?string $dueAt, float $paid = 0): Transaction
{
    $id = DB::table('transactions')->insertGetId([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'customer_id' => $customer->id,
        'transaction_number' => 'TXN-'.uniqid(),
        'status' => 'completed',
        'payment_method' => 'credit',
        'payment_status' => $paid > 0 ? 'partial' : 'unpaid',
        'subtotal' => $total,
        'discount_amount' => 0,
        'tax_amount' => 0,
        'total_amount' => $total,
        'amount_paid' => $paid,
        'change_amount' => 0,
        'debt_due_at' => $dueAt,
        'created_at' => now()->subDays(20),
        'updated_at' => now()->subDays(20),
    ]);

    return Transaction::query()->findOrFail($id);
}

test('pembayaran per pelanggan dialokasikan ke bon dengan tempo paling awal', function () {
    $store = receivableStore();
    $admin = receivableAdmin($store);
    $customer = Customer::query()->create(['store_id' => $store->id, 'name' => 'Bu Ani', 'phone' => '0812']);

    $later = receivableBill($store, $admin, $customer, 100000, now()->addDays(10)->toDateString());
    $earliest = receivableBill($store, $admin, $customer, 50000, now()->subDays(3)->toDateString());

    $this->actingAs($admin)
        ->post("/receivables/customers/{$customer->id}/payments", [
            'amount' => 80000,
            'payment_method' => 'cash',
            'received_at' => now()->toDateString(),
        ])
        ->assertSessionHas('success');

    expect($earliest->fresh()->payment_status)->toBe('paid')
        ->and((float) $later->fresh()->amount_paid)->toBe(30000.0)
        ->and($later->fresh()->payment_status)->toBe('partial')
        ->and(ReceivablePayment::query()->where('customer_id', $customer->id)->sum('amount'))->toEqual(80000);
});

test('pembayaran piutang dengan e-wallet diterima', function () {
    $store = receivableStore();
    $admin = receivableAdmin($store);
    $customer = Customer::query()->create(['store_id' => $store->id, 'name' => 'Pak Budi']);
    $bill = receivableBill($store, $admin, $customer, 20000, null);

    $this->actingAs($admin)
        ->post('/receivable-payments', [
            'transaction_id' => $bill->id,
            'amount' => 20000,
            'payment_method' => 'ewallet',
            'received_at' => now()->toDateString(),
        ])
        ->assertSessionHasNoErrors();

    expect($bill->fresh()->payment_status)->toBe('paid');
});

test('ringkasan dan filter lewat tempo pada daftar piutang', function () {
    $store = receivableStore();
    $admin = receivableAdmin($store);
    $late = Customer::query()->create(['store_id' => $store->id, 'name' => 'Telat']);
    $ok = Customer::query()->create(['store_id' => $store->id, 'name' => 'Aman']);
    receivableBill($store, $admin, $late, 40000, now()->subDays(5)->toDateString());
    receivableBill($store, $admin, $ok, 60000, now()->addDays(3)->toDateString());

    $this->actingAs($admin);

    $this->get('/receivables')->assertInertia(fn ($page) => $page
        ->where('summary.outstanding_total', 100000)
        ->where('summary.customer_count', 2)
        ->where('summary.overdue_total', 40000)
        ->where('summary.overdue_customers', 1)
        ->where('summary.due_soon_total', 60000)
        ->where('customers.data.0.customer.name', 'Telat'));

    $this->get('/receivables?status=overdue')->assertInertia(fn ($page) => $page
        ->has('customers.data', 1)
        ->where('customers.data.0.customer.name', 'Telat'));

    $this->get('/receivables?status=due_soon')->assertInertia(fn ($page) => $page
        ->has('customers.data', 1)
        ->where('customers.data.0.customer.name', 'Aman'));

    $this->get('/receivables?sort=amount')->assertInertia(fn ($page) => $page
        ->where('customers.data.0.customer.name', 'Aman'));
});
