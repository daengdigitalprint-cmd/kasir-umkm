<?php

/*
 * F10 — Pesanan Antar mandiri: ongkir masuk total (di luar pajak) dan jurnal tetap
 * seimbang; data pengantaran tercatat; status Disiapkan → Diantar → Selesai.
 */

use App\Models\AccountingPeriod;
use App\Models\DeliveryOrder;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
});

function deliveryShop(bool $enabled = true, bool $accounting = false, array $extra = []): array
{
    // Paket starter: kasir tidak butuh shift terbuka; modul dibuka khusus untuk toko uji.
    $plan = \App\Models\SubscriptionPlan::query()->firstOrCreate(
        ['slug' => 'starter'],
        ['name' => 'Paket Starter', 'price' => 0, 'billing_cycle' => 'monthly', 'is_active' => true],
    );

    $store = Store::query()->create([
        'name' => 'Ayam Geprek Bu Ani',
        'slug' => 'geprek-'.uniqid(),
        'is_active' => true,
        'is_subscription_active' => true,
        'subscription_plan_id' => $plan->id,
        'subscription_end' => now()->addDay(),
        'tax_enabled' => true,
        'tax_rate' => 10,
        'settings' => array_merge([
            'delivery_enabled' => $enabled,
            'delivery_default_fee' => 8000,
            'accounting_enabled' => $accounting,
            'plan_unlocked_modules' => ['delivery', 'accounting'],
        ], $extra),
    ]);

    $admin = User::factory()->create(['store_id' => $store->id, 'role' => 'admin', 'email_verified_at' => now()]);
    $admin->stores()->attach($store->id, ['role' => 'admin']);
    $courier = User::factory()->create(['store_id' => $store->id, 'role' => 'cashier', 'name' => 'Kurir Joko', 'email_verified_at' => now()]);
    $courier->stores()->attach($store->id, ['role' => 'cashier']);

    $product = Product::query()->create([
        'store_id' => $store->id,
        'name' => 'Geprek',
        'price' => 20000,
        'cost_price' => 9000,
        'average_cost' => 9000,
        'stock_quantity' => 50,
        'track_stock' => true,
        'is_active' => true,
    ]);

    return compact('store', 'admin', 'courier', 'product');
}

function deliveryCheckout($test, array $shop, array $delivery, int $amountPaid = 100000)
{
    return $test->actingAs($shop['admin'])->postJson('/pos/process-payment', [
        'items' => [['product_id' => $shop['product']->id, 'quantity' => 2, 'modifiers' => []]],
        'payment_method' => 'cash',
        'amount_paid' => $amountPaid,
        'delivery' => $delivery,
    ]);
}

it('ongkir masuk total di luar pajak dan data pengantaran tercatat', function () {
    $shop = deliveryShop();

    deliveryCheckout($this, $shop, [
        'recipient_name' => 'Pak Rudi',
        'phone' => '0812 1111 2222',
        'address' => 'Jl. Mawar 5, RT 02',
        'fee' => 10000,
        'courier_name' => 'Joko',
        'notes' => 'Pagar hijau',
    ])->assertOk()->assertJsonPath('success', true);

    $transaction = Transaction::query()->sole();
    $delivery = DeliveryOrder::query()->sole();

    // 2 × 20.000 = 40.000; pajak 10% = 4.000 (ongkir tidak kena pajak); ongkir 10.000.
    expect((float) $transaction->tax_amount)->toBe(4000.0)
        ->and((float) $transaction->delivery_fee)->toBe(10000.0)
        ->and((float) $transaction->total_amount)->toBe(54000.0)
        ->and($transaction->order_type)->toBe('delivery')
        ->and($delivery->transaction_id)->toBe($transaction->id)
        ->and($delivery->status)->toBe(DeliveryOrder::STATUS_PREPARING)
        ->and($delivery->recipient_name)->toBe('Pak Rudi')
        // Kurir cukup nama: tidak ditautkan ke akun pengguna.
        ->and($delivery->courier_name)->toBe('Joko')
        ->and($delivery->courier_user_id)->toBeNull()
        ->and((float) $delivery->delivery_fee)->toBe(10000.0);
});

it('jurnal penjualan tetap seimbang dengan ongkir', function () {
    $shop = deliveryShop(accounting: true);
    AccountingPeriod::query()->create([
        'store_id' => $shop['store']->id,
        'name' => 'Periode uji',
        'start_date' => now()->startOfMonth()->toDateString(),
        'end_date' => now()->endOfMonth()->toDateString(),
        'status' => AccountingPeriod::STATUS_OPEN,
    ]);

    deliveryCheckout($this, $shop, ['address' => 'Jl. Melati 1', 'fee' => 7000])->assertOk();

    $entry = JournalEntry::query()->where('reference_type', Transaction::class)->sole();
    $debit = (float) $entry->lines()->sum('debit');

    expect($debit)->toEqual((float) $entry->lines()->sum('credit'))
        ->and($debit)->toBeGreaterThanOrEqual(51000.0); // kas 51.000 (40.000 + pajak 4.000 + ongkir 7.000) + HPP
});

it('tanpa data antar, checkout berjalan seperti biasa', function () {
    $shop = deliveryShop();

    $this->actingAs($shop['admin'])->postJson('/pos/process-payment', [
        'items' => [['product_id' => $shop['product']->id, 'quantity' => 2, 'modifiers' => []]],
        'payment_method' => 'cash',
        'amount_paid' => 50000,
    ])->assertOk();

    expect((float) Transaction::query()->sole()->delivery_fee)->toBe(0.0)
        ->and((float) Transaction::query()->sole()->total_amount)->toBe(44000.0)
        ->and(DeliveryOrder::query()->count())->toBe(0);
});

it('menolak data antar saat modul mati atau alamat kosong', function (bool $enabled, array $delivery, string $errorKey) {
    $shop = deliveryShop($enabled);

    deliveryCheckout($this, $shop, $delivery)->assertStatus(422)->assertJsonValidationErrors($errorKey);

    expect(Transaction::query()->count())->toBe(0);
})->with([
    'modul mati' => [false, ['address' => 'Jl. A', 'fee' => 5000], 'delivery'],
    'alamat kosong' => [true, ['fee' => 5000, 'phone' => '0812'], 'delivery.address'],
]);

it('status mengikuti alur Disiapkan → Diantar → Selesai', function () {
    $shop = deliveryShop();
    deliveryCheckout($this, $shop, ['address' => 'Jl. Kenanga 3', 'fee' => 5000])->assertOk();
    $delivery = DeliveryOrder::query()->sole();

    // Langsung Selesai dari Disiapkan tidak boleh.
    $this->actingAs($shop['courier'])->patch("/deliveries/{$delivery->id}/status", ['status' => 'delivered'])
        ->assertSessionHasErrors('status');

    $this->actingAs($shop['courier'])->patch("/deliveries/{$delivery->id}/status", ['status' => 'on_the_way'])
        ->assertSessionHasNoErrors();
    $delivery->refresh();
    expect($delivery->status)->toBe('on_the_way')
        ->and($delivery->dispatched_at)->not->toBeNull()
        // Yang menekan "Antar" belum tentu kurirnya, jadi kurir tidak diisi otomatis.
        ->and($delivery->courier_user_id)->toBeNull()
        ->and($delivery->courier_name)->toBeNull();

    $this->actingAs($shop['courier'])->patch("/deliveries/{$delivery->id}/status", ['status' => 'delivered'])
        ->assertSessionHasNoErrors();
    expect($delivery->fresh()->status)->toBe('delivered')
        ->and($delivery->fresh()->delivered_at)->not->toBeNull();

    // Yang sudah Selesai tidak bisa dibatalkan.
    $this->actingAs($shop['admin'])->patch("/deliveries/{$delivery->id}/status", ['status' => 'cancelled'])
        ->assertSessionHasErrors('status');
});

it('daftar pengantaran: pesanan aktif dan isolasi toko', function () {
    $shop = deliveryShop();
    deliveryCheckout($this, $shop, ['address' => 'Jl. A', 'fee' => 5000, 'courier_name' => 'Joko'])->assertOk();
    deliveryCheckout($this, $shop, ['address' => 'Jl. B', 'fee' => 5000])->assertOk();

    $this->actingAs($shop['admin'])->get('/deliveries')->assertOk()->assertInertia(
        fn ($page) => $page->component('Deliveries/Index')->has('orders.data', 2)->where('counts.active', 2)
    );

    $other = deliveryShop();
    $foreign = DeliveryOrder::query()->first();
    $this->actingAs($other['admin'])->patch("/deliveries/{$foreign->id}/status", ['status' => 'on_the_way'])->assertNotFound();
    expect($foreign->fresh()->status)->toBe('preparing');
});

it('admin menyalakan modul dan mengatur ongkir bawaan; data kasir ikut', function () {
    $shop = deliveryShop(enabled: false);

    $this->actingAs($shop['admin'])->post('/deliveries/settings', ['enabled' => true, 'default_fee' => 12000])
        ->assertSessionHasNoErrors();

    $this->actingAs($shop['admin'])->get('/pos')->assertOk()->assertInertia(
        fn ($page) => $page->where('delivery.enabled', true)
            ->where('delivery.default_fee', 12000)
            // Daftar kurir kini berisi nama yang diisi toko, bukan akun karyawan.
            ->where('delivery.couriers', [])
    );

    // Kasir tidak bisa mengubah pengaturan.
    $this->actingAs($shop['courier'])->post('/deliveries/settings', ['enabled' => false, 'default_fee' => 0])->assertForbidden();
});
