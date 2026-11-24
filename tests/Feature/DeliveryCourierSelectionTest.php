<?php

/*
 * F10 — Kurir toko tidak perlu punya akun: cukup namanya. Toko boleh mengisi daftar
 * nama kurir di pengaturan Pengantaran; bila kosong, pengantaran hanya berketerangan
 * "Kurir toko" dan tidak ada daftar karyawan (kasir, dapur, owner) yang ditampilkan.
 */

use App\Models\DeliveryOrder;
use App\Models\Product;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use App\Support\DeliveryFeatures;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
});

/**
 * Toko dengan admin, kasir, dan dapur — tak satu pun dari mereka boleh muncul sebagai kurir.
 *
 * @return array{store: Store, admin: User, cashier: User, kitchen: User}
 */
function dcsShop(array $settings = []): array
{
    $plan = \App\Models\SubscriptionPlan::query()->firstOrCreate(
        ['slug' => 'starter'],
        ['name' => 'Paket Starter', 'price' => 0, 'billing_cycle' => 'monthly', 'is_active' => true],
    );

    $store = Store::query()->create([
        'name' => 'Ayam Geprek Bu Ani',
        'slug' => 'kurir-'.uniqid(),
        'is_active' => true,
        'is_subscription_active' => true,
        'subscription_plan_id' => $plan->id,
        'subscription_end' => now()->addDay(),
        'settings' => array_merge([
            'delivery_enabled' => true,
            'delivery_default_fee' => 8000,
            'plan_unlocked_modules' => ['delivery'],
        ], $settings),
    ]);

    $member = function (string $name, string $role) use ($store): User {
        $user = User::factory()->create(['store_id' => $store->id, 'role' => $role, 'name' => $name, 'email_verified_at' => now()]);
        $user->stores()->attach($store->id, ['role' => $role]);

        return $user;
    };

    return [
        'store' => $store,
        'admin' => $member('Bu Ani', 'admin'),
        'cashier' => $member('Kasir Sari', 'cashier'),
        'kitchen' => $member('Dapur Tono', 'kitchen'),
    ];
}

function dcsDelivery(Store $store, array $attributes = []): DeliveryOrder
{
    $txn = Transaction::query()->create([
        'store_id' => $store->id,
        'status_token' => Str::random(64),
        'status' => 'completed',
        'payment_method' => 'cash',
        'payment_status' => 'paid',
        'subtotal' => 20000,
        'total_amount' => 28000,
        'amount_paid' => 28000,
        'change_amount' => 0,
        'order_type' => 'delivery',
    ]);

    return DeliveryOrder::query()->create(array_merge([
        'store_id' => $store->id,
        'transaction_id' => $txn->id,
        'recipient_name' => 'Pak Budi',
        'address' => 'Jl. Melati No. 5',
        'delivery_fee' => 8000,
        'status' => DeliveryOrder::STATUS_PREPARING,
    ], $attributes));
}

it('tanpa daftar nama kurir, tidak ada karyawan yang ditampilkan sebagai kurir', function () {
    $shop = dcsShop();
    dcsDelivery($shop['store']);

    expect(DeliveryFeatures::courierNames($shop['store']))->toBe([]);

    $this->actingAs($shop['admin'])->get('/deliveries')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Deliveries/Index')
            ->where('couriers', [])
            ->where('orders.data.0.courier', null)
            ->missing('staff'));

    $this->actingAs($shop['admin'])->get('/pos')
        ->assertInertia(fn ($page) => $page->where('delivery.couriers', []));
});

it('menyimpan daftar nama kurir yang rapi dari pengaturan Pengantaran', function () {
    $shop = dcsShop();

    $this->actingAs($shop['admin'])
        ->post('/deliveries/settings', [
            'enabled' => true,
            'default_fee' => 8000,
            'courier_names' => ['  Joko  ', 'Andi   Saputra', 'joko', '', null, 'Rudi'],
        ])
        ->assertSessionHasNoErrors();

    $store = $shop['store']->fresh();
    // Spasi dirapikan, kosong dibuang, duplikat (tanpa membedakan huruf besar/kecil) digabung.
    expect(DeliveryFeatures::courierNames($store))->toBe(['Joko', 'Andi Saputra', 'Rudi']);

    $this->actingAs($shop['admin'])->get('/deliveries')
        ->assertInertia(fn ($page) => $page->where('couriers', ['Joko', 'Andi Saputra', 'Rudi']));
    $this->actingAs($shop['admin'])->get('/pos')
        ->assertInertia(fn ($page) => $page->where('delivery.couriers', ['Joko', 'Andi Saputra', 'Rudi']));
});

it('form lama yang tidak mengirim nama kurir tidak mengubah daftar, dan daftar bisa dikosongkan', function () {
    $shop = dcsShop(['delivery_courier_names' => ['Joko']]);

    $this->actingAs($shop['admin'])->post('/deliveries/settings', ['enabled' => true, 'default_fee' => 8000]);
    expect(DeliveryFeatures::courierNames($shop['store']->fresh()))->toBe(['Joko']);

    $this->actingAs($shop['admin'])->post('/deliveries/settings', ['enabled' => true, 'default_fee' => 8000, 'courier_names' => []]);
    expect(DeliveryFeatures::courierNames($shop['store']->fresh()))->toBe([]);
});

it('menolak nama kurir yang terlalu panjang atau terlalu banyak', function () {
    $shop = dcsShop();

    $this->actingAs($shop['admin'])
        ->post('/deliveries/settings', ['enabled' => true, 'default_fee' => 8000, 'courier_names' => [str_repeat('a', 61)]])
        ->assertSessionHasErrors('courier_names.0');

    $this->actingAs($shop['admin'])
        ->post('/deliveries/settings', ['enabled' => true, 'default_fee' => 8000, 'courier_names' => array_map(fn ($i) => "Kurir {$i}", range(1, 31))])
        ->assertSessionHasErrors('courier_names');
});

it('menetapkan dan mengosongkan kurir sebuah pengantaran dengan nama', function () {
    $shop = dcsShop(['delivery_courier_names' => ['Joko']]);
    $delivery = dcsDelivery($shop['store']);

    $this->actingAs($shop['cashier'])
        ->patch("/deliveries/{$delivery->id}/courier", ['courier_name' => '  Joko '])
        ->assertSessionHasNoErrors();
    expect($delivery->fresh()->courier_name)->toBe('Joko')
        ->and($delivery->fresh()->courier_user_id)->toBeNull();

    $this->actingAs($shop['admin'])->get('/deliveries')
        ->assertInertia(fn ($page) => $page->where('orders.data.0.courier', 'Joko'));

    $this->actingAs($shop['cashier'])
        ->patch("/deliveries/{$delivery->id}/courier", ['courier_name' => null])
        ->assertSessionHasNoErrors();
    expect($delivery->fresh()->courier_name)->toBeNull();
});

it('tidak mengubah kurir pengantaran yang sudah selesai atau milik toko lain', function () {
    $shop = dcsShop();
    $other = dcsShop();
    $done = dcsDelivery($shop['store'], ['status' => DeliveryOrder::STATUS_DELIVERED, 'courier_name' => 'Joko']);

    $this->actingAs($shop['admin'])
        ->patch("/deliveries/{$done->id}/courier", ['courier_name' => 'Andi'])
        ->assertSessionHasErrors('courier_name');
    $this->actingAs($other['admin'])
        ->patch("/deliveries/{$done->id}/courier", ['courier_name' => 'Andi'])
        ->assertNotFound();

    expect($done->fresh()->courier_name)->toBe('Joko');
});

it('pengantaran lama yang kurirnya akun pengguna tetap menampilkan namanya', function () {
    $shop = dcsShop();
    $delivery = dcsDelivery($shop['store'], ['courier_user_id' => $shop['cashier']->id]);

    expect($delivery->courierLabel())->toBe('Kasir Sari');

    $this->actingAs($shop['admin'])->get('/deliveries')
        ->assertInertia(fn ($page) => $page->where('orders.data.0.courier', 'Kasir Sari'));

    // Begitu kurirnya diganti, tautan ke akun dilepas.
    $this->actingAs($shop['admin'])->patch("/deliveries/{$delivery->id}/courier", ['courier_name' => 'Joko']);
    expect($delivery->fresh()->courier_user_id)->toBeNull()
        ->and($delivery->fresh()->courierLabel())->toBe('Joko');
});

it('kasir mencatat nama kurir saat membuat pesanan antar, dan ID pengguna dari kasir versi lama tetap dipahami', function () {
    $shop = dcsShop();
    $other = dcsShop();
    $product = Product::query()->create([
        'store_id' => $shop['store']->id, 'name' => 'Geprek', 'price' => 20000,
        'stock_quantity' => 50, 'track_stock' => false, 'unlimited_stock' => true, 'is_active' => true,
    ]);
    $checkout = fn (array $delivery) => $this->actingAs($shop['admin'])->postJson('/pos/process-payment', [
        'items' => [['product_id' => $product->id, 'quantity' => 1, 'modifiers' => []]],
        'payment_method' => 'cash',
        'amount_paid' => 100000,
        'delivery' => array_merge(['address' => 'Jl. Melati No. 5', 'fee' => 8000], $delivery),
    ])->assertOk();
    $last = fn () => DeliveryOrder::query()->where('store_id', $shop['store']->id)->latest('id')->firstOrFail();

    $checkout(['courier_name' => 'Joko']);
    expect($last()->courier_name)->toBe('Joko');

    // Kasir versi lama mengirim ID karyawan → disimpan sebagai namanya.
    $checkout(['courier_user_id' => $shop['cashier']->id]);
    expect($last()->courier_name)->toBe('Kasir Sari')
        ->and($last()->courier_user_id)->toBeNull();

    // ID pengguna toko lain diabaikan, pesanan tetap tersimpan tanpa kurir.
    $checkout(['courier_user_id' => $other['cashier']->id]);
    expect($last()->courier_name)->toBeNull();
});
