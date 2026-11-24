<?php

/*
 * F10 — Pesan Antar mandiri: pelanggan memesan lewat tautan toko (/antar/{slug}),
 * pesanan masuk ke Pengantaran sebagai "Menunggu konfirmasi", kasir menerima/menolak
 * dan mencatat pembayarannya.
 */

use App\Models\CashShift;
use App\Models\DeliveryOrder;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use App\Support\DeliveryFeatures;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
});

/**
 * @return array{0: Store, 1: User, 2: Product}
 */
function dsoShop(bool $selfOrder = true, array $extra = []): array
{
    $plan = \App\Models\SubscriptionPlan::query()->firstOrCreate(
        ['slug' => 'starter'],
        ['name' => 'Paket Starter', 'price' => 0, 'billing_cycle' => 'monthly', 'is_active' => true],
    );

    $store = Store::query()->create([
        'name' => 'Ayam Geprek Bu Ani',
        'slug' => 'geprek-'.uniqid(),
        'business_type' => 'fnb',
        'phone' => '081234567890',
        'is_active' => true,
        'is_subscription_active' => true,
        'subscription_plan_id' => $plan->id,
        'subscription_end' => now()->addDay(),
        'settings' => array_merge([
            'delivery_enabled' => true,
            'delivery_default_fee' => 8000,
            'delivery_self_order_enabled' => $selfOrder,
            'plan_unlocked_modules' => ['delivery'],
        ], $extra),
    ]);

    $admin = User::factory()->create(['store_id' => $store->id, 'role' => 'admin', 'email_verified_at' => now()]);
    $admin->stores()->attach($store->id, ['role' => 'admin']);

    $category = ProductCategory::query()->create(['store_id' => $store->id, 'name' => 'Makanan', 'sort_order' => 1]);
    $product = Product::query()->create([
        'store_id' => $store->id,
        'product_category_id' => $category->id,
        'name' => 'Geprek',
        'price' => 20000,
        'stock_quantity' => 50,
        'track_stock' => false,
        'is_active' => true,
    ]);

    return [$store, $admin, $product];
}

function dsoPayload(Store $store, Product $product, array $overrides = []): array
{
    return array_merge([
        'store_slug' => $store->slug,
        'recipient_name' => 'Pak Budi',
        'phone' => '0812-3456-7890',
        'address' => 'Jl. Melati No. 5, pagar hijau depan masjid',
        'notes' => 'Titip di pos satpam',
        'payment_method' => 'cash',
        'items' => [['product_id' => $product->id, 'quantity' => 2, 'notes' => 'Pedas level 3']],
    ], $overrides);
}

/** Buat pesanan dari tautan dan kembalikan pengantarannya. */
function dsoPlace(Store $store, Product $product, array $overrides = []): DeliveryOrder
{
    test()->postJson('/api/guest/delivery-order', dsoPayload($store, $product, $overrides))->assertOk();

    return DeliveryOrder::query()->where('store_id', $store->id)->latest('id')->firstOrFail();
}

function dsoOpenShift(Store $store, User $user): CashShift
{
    return CashShift::query()->create([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'opening_cash' => 0,
        'opened_at' => now(),
    ]);
}

// ── Halaman tamu ───────────────────────────────────────────────────────────

it('menampilkan menu pesan antar di tautan toko dengan tampilan menu digital', function () {
    [$store, , $product] = dsoShop();

    $this->get("/antar/{$store->slug}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('ScanMeja/Guest/Menu')
            ->where('table', null)
            ->where('delivery.slug', $store->slug)
            ->where('delivery.fee', 8000)
            ->where('categories.0.products.0.id', $product->id)
            // Tunai ditawarkan sebagai bayar di tempat ke kurir.
            ->where('paymentMethods.0.type', 'cash')
            ->where('paymentMethods.0.label', 'Bayar di tempat'));
});

it('menutup tautan bila pemesanan mandiri atau modul antar dimatikan', function (array $settings) {
    [$store, , $product] = dsoShop(true, $settings);

    $this->get("/antar/{$store->slug}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('ScanMeja/Guest/ModuleInactive')->where('delivery', true));

    $this->postJson('/api/guest/delivery-order', dsoPayload($store, $product))->assertStatus(403);
    expect(Transaction::query()->where('store_id', $store->id)->count())->toBe(0);
})->with([
    'saklar tautan mati' => [['delivery_self_order_enabled' => false]],
    'modul antar mati' => [['delivery_enabled' => false]],
]);

it('tidak membuka menu toko yang nonaktif', function () {
    [$store] = dsoShop();
    $store->update(['is_active' => false]);

    $this->get("/antar/{$store->slug}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('ScanMeja/Guest/StoreInactive'));
});

// ── Membuat pesanan ────────────────────────────────────────────────────────

it('membuat pesanan antar yang menunggu konfirmasi dengan total dihitung server', function () {
    [$store, , $product] = dsoShop();

    $response = $this->postJson('/api/guest/delivery-order', dsoPayload($store, $product, [
        // Harga/total kiriman browser tidak dipakai sama sekali.
        'total' => 1,
        'items' => [['product_id' => $product->id, 'quantity' => 2, 'notes' => 'Pedas level 3', 'price' => 1]],
    ]));

    $response->assertOk()->assertJsonPath('success', true)->assertJsonPath('transaction.status', 'pending');

    $txn = Transaction::query()->where('store_id', $store->id)->with(['items', 'deliveryOrder'])->firstOrFail();
    expect($txn->status)->toBe('pending')
        ->and($txn->order_status)->toBe('pending')
        ->and($txn->payment_status)->toBe('unpaid')
        ->and($txn->order_type)->toBe('delivery')
        ->and($txn->user_id)->toBeNull()
        ->and((float) $txn->subtotal)->toBe(40000.0)
        ->and((float) $txn->delivery_fee)->toBe(8000.0)
        ->and((float) $txn->total_amount)->toBe(48000.0)
        ->and($txn->payment_details['source'])->toBe(DeliveryFeatures::SELF_ORDER_SOURCE)
        ->and($txn->items)->toHaveCount(1)
        ->and((float) $txn->items[0]->unit_price)->toBe(20000.0)
        ->and($response->json('transaction.status_token'))->toBe($txn->status_token);

    $delivery = $txn->deliveryOrder;
    expect($delivery->status)->toBe(DeliveryOrder::STATUS_PENDING)
        ->and($delivery->recipient_name)->toBe('Pak Budi')
        ->and($delivery->phone)->toBe('0812-3456-7890')
        ->and($delivery->address)->toBe('Jl. Melati No. 5, pagar hijau depan masjid')
        ->and($delivery->notes)->toBe('Titip di pos satpam')
        ->and((float) $delivery->delivery_fee)->toBe(8000.0)
        ->and($delivery->courier_user_id)->toBeNull();
});

it('mewajibkan nama, nomor HP, dan alamat yang layak', function (array $overrides, string $field) {
    [$store, , $product] = dsoShop();

    $this->postJson('/api/guest/delivery-order', dsoPayload($store, $product, $overrides))
        ->assertStatus(422)
        ->assertJsonValidationErrors($field);

    expect(DeliveryOrder::query()->count())->toBe(0);
})->with([
    'tanpa nama' => [['recipient_name' => ''], 'recipient_name'],
    'tanpa nomor HP' => [['phone' => ''], 'phone'],
    'nomor HP bukan angka' => [['phone' => 'hubungi saya'], 'phone'],
    'tanpa alamat' => [['address' => ''], 'address'],
    'alamat terlalu singkat' => [['address' => 'rumah'], 'address'],
    'keranjang kosong' => [['items' => []], 'items'],
]);

it('menolak menu milik toko lain atau yang sudah nonaktif', function () {
    [$store, , $product] = dsoShop();
    [, , $foreign] = dsoShop();

    $this->postJson('/api/guest/delivery-order', dsoPayload($store, $product, [
        'items' => [['product_id' => $foreign->id, 'quantity' => 1]],
    ]))->assertStatus(422);

    $product->update(['is_active' => false]);
    $this->postJson('/api/guest/delivery-order', dsoPayload($store, $product))->assertStatus(422);

    expect(Transaction::query()->where('store_id', $store->id)->count())->toBe(0);
});

it('memakai metode bayar toko dan kembali ke bayar di tempat bila pilihan tidak aktif', function () {
    [$store, , $product] = dsoShop(true, [
        'scan_meja_payment_methods' => [
            ['type' => 'cash', 'label' => 'Tunai', 'active' => true],
            ['type' => 'qris', 'label' => 'QRIS', 'active' => false],
            ['type' => 'bank_transfer', 'label' => 'Transfer', 'active' => true, 'bank_name' => 'BCA', 'account_number' => '123'],
        ],
    ]);

    expect(dsoPlace($store, $product, ['payment_method' => 'bank_transfer'])->transaction->payment_method)->toBe('bank_transfer')
        // QRIS dimatikan toko → tidak bisa dipaksa dari browser.
        ->and(dsoPlace($store, $product, ['payment_method' => 'qris'])->transaction->payment_method)->toBe('cash');
});

it('membatasi banjir pesanan dari tautan publik', function () {
    [$store, , $product] = dsoShop();

    foreach (range(1, 6) as $i) {
        $this->postJson('/api/guest/delivery-order', dsoPayload($store, $product))->assertOk();
    }

    $this->postJson('/api/guest/delivery-order', dsoPayload($store, $product))->assertStatus(429);
});

// ── Kasir: terima / tolak / antar ──────────────────────────────────────────

it('kasir menerima pesanan lalu pelanggan melihat tiap tahap pengantaran', function () {
    [$store, $admin, $product] = dsoShop();
    $delivery = dsoPlace($store, $product);
    $token = $delivery->transaction->status_token;
    $statusOf = fn () => $this->getJson("/api/guest/delivery-order/{$token}/status")->assertOk()->json('status');

    expect($statusOf())->toBe('pending');

    $this->actingAs($admin)->post("/deliveries/{$delivery->id}/accept")->assertRedirect();
    $delivery->refresh();
    expect($delivery->status)->toBe(DeliveryOrder::STATUS_PREPARING)
        ->and($delivery->transaction->order_status)->toBe('accepted')
        ->and($delivery->transaction->user_id)->toBe($admin->id)
        ->and($statusOf())->toBe('accepted');

    $this->actingAs($admin)->patch("/deliveries/{$delivery->id}/status", ['status' => 'on_the_way'])->assertSessionHasNoErrors();
    expect($statusOf())->toBe('on_the_way');

    $this->actingAs($admin)->patch("/deliveries/{$delivery->id}/status", ['status' => 'delivered'])->assertSessionHasNoErrors();
    expect($statusOf())->toBe('completed');
});

it('pesanan yang menunggu konfirmasi hanya bisa diterima atau ditolak', function () {
    [$store, $admin, $product] = dsoShop();
    $delivery = dsoPlace($store, $product);

    // Tidak bisa langsung "diantar" tanpa diterima dulu.
    $this->actingAs($admin)->patch("/deliveries/{$delivery->id}/status", ['status' => 'on_the_way'])->assertSessionHasErrors('status');
    expect($delivery->fresh()->status)->toBe(DeliveryOrder::STATUS_PENDING);

    // Menerima dua kali tidak mengubah apa-apa.
    $this->actingAs($admin)->post("/deliveries/{$delivery->id}/accept");
    $this->actingAs($admin)->post("/deliveries/{$delivery->id}/accept")->assertSessionHas('error');
    $this->actingAs($admin)->post("/deliveries/{$delivery->id}/reject", ['reason' => 'terlambat'])->assertSessionHas('error');
    expect($delivery->fresh()->status)->toBe(DeliveryOrder::STATUS_PREPARING);
});

it('kasir menolak pesanan dan alasannya terlihat oleh pelanggan', function () {
    [$store, $admin, $product] = dsoShop();
    $delivery = dsoPlace($store, $product);

    $this->actingAs($admin)->post("/deliveries/{$delivery->id}/reject", ['reason' => 'Di luar jangkauan antar'])->assertRedirect();

    $delivery->refresh();
    expect($delivery->status)->toBe(DeliveryOrder::STATUS_CANCELLED)
        ->and($delivery->cancelled_at)->not->toBeNull()
        ->and($delivery->transaction->status)->toBe('cancelled')
        ->and($delivery->transaction->order_status)->toBe('cancelled');

    $this->getJson("/api/guest/delivery-order/{$delivery->transaction->status_token}/status")
        ->assertOk()
        ->assertJsonPath('status', 'cancelled')
        ->assertJsonPath('reject_reason', 'Di luar jangkauan antar');
});

it('membatalkan pesanan yang sudah diterima tetapi belum dibayar ikut membatalkan transaksinya', function () {
    [$store, $admin, $product] = dsoShop();
    $delivery = dsoPlace($store, $product);
    $this->actingAs($admin)->post("/deliveries/{$delivery->id}/accept");

    $this->actingAs($admin)->patch("/deliveries/{$delivery->id}/status", ['status' => 'cancelled'])->assertSessionHasNoErrors();

    $delivery->refresh();
    expect($delivery->status)->toBe(DeliveryOrder::STATUS_CANCELLED)
        ->and($delivery->transaction->status)->toBe('cancelled');
});

it('pengguna toko lain tidak bisa menerima, menolak, atau membayar pesanan', function () {
    [$store, , $product] = dsoShop();
    [, $outsider] = dsoShop();
    $delivery = dsoPlace($store, $product);

    $this->actingAs($outsider)->post("/deliveries/{$delivery->id}/accept")->assertNotFound();
    $this->actingAs($outsider)->post("/deliveries/{$delivery->id}/reject")->assertNotFound();
    $this->actingAs($outsider)->post("/deliveries/{$delivery->id}/pay", ['payment_method' => 'cash'])->assertNotFound();

    expect($delivery->fresh()->status)->toBe(DeliveryOrder::STATUS_PENDING);
});

// ── Pembayaran ─────────────────────────────────────────────────────────────

it('mencatat pembayaran pesanan dari tautan ke shift kasir yang terbuka', function () {
    [$store, $admin, $product] = dsoShop();
    $delivery = dsoPlace($store, $product);

    // Belum diterima → belum bisa dibayar.
    $this->actingAs($admin)->post("/deliveries/{$delivery->id}/pay", ['payment_method' => 'cash'])->assertSessionHas('error');

    $this->actingAs($admin)->post("/deliveries/{$delivery->id}/accept");

    // Tanpa shift terbuka → ditolak.
    $this->actingAs($admin)->post("/deliveries/{$delivery->id}/pay", ['payment_method' => 'cash'])->assertSessionHas('error');
    expect($delivery->transaction->fresh()->payment_status)->toBe('unpaid');

    $shift = dsoOpenShift($store, $admin);
    $this->actingAs($admin)->post("/deliveries/{$delivery->id}/pay", ['payment_method' => 'cash'])->assertSessionHas('success');

    $txn = $delivery->transaction->fresh();
    expect($txn->payment_status)->toBe('paid')
        ->and($txn->status)->toBe('completed')
        ->and($txn->payment_method)->toBe('cash')
        ->and((float) $txn->amount_paid)->toBe(48000.0)
        ->and($txn->cash_shift_id)->toBe($shift->id)
        ->and($txn->completed_at)->not->toBeNull();

    // Pembayaran tidak mengubah tahap pengantaran, dan tidak bisa dicatat dua kali.
    expect($delivery->fresh()->status)->toBe(DeliveryOrder::STATUS_PREPARING);
    $this->actingAs($admin)->post("/deliveries/{$delivery->id}/pay", ['payment_method' => 'cash'])->assertSessionHas('error');

    // Sudah lunas: membatalkan pengantaran tidak membatalkan transaksinya.
    $this->actingAs($admin)->patch("/deliveries/{$delivery->id}/status", ['status' => 'cancelled']);
    expect($delivery->transaction->fresh()->status)->toBe('completed');
});

// ── Halaman kasir & pengaturan ─────────────────────────────────────────────

it('menampilkan pesanan masuk di Pengantaran lengkap dengan isi pesanan dan lencana', function () {
    [$store, $admin, $product] = dsoShop();
    dsoPlace($store, $product);

    // Tanpa memilih tab: langsung ke "Pesanan masuk" karena ada yang menunggu.
    $this->actingAs($admin)->get('/deliveries')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Deliveries/Index')
            ->where('filters.status', 'pending')
            ->where('counts.pending', 1)
            ->where('pending_delivery_order_count', 1)
            ->where('selfOrder.enabled', true)
            ->where('selfOrder.url', url('/antar/'.$store->slug))
            ->has('orders.data', 1)
            ->where('orders.data.0.status', 'pending')
            ->where('orders.data.0.is_self_order', true)
            ->where('orders.data.0.next_statuses', [])
            ->where('orders.data.0.items.0.name', 'Geprek')
            ->where('orders.data.0.items.0.quantity', 2)
            ->where('orders.data.0.items.0.note', 'Pedas level 3')
            ->where('orders.data.0.transaction.payment_method', 'cash'));

    // Pesanan yang menunggu konfirmasi tidak tercampur ke tab Aktif.
    $this->actingAs($admin)->get('/deliveries?status=active')
        ->assertInertia(fn ($page) => $page->where('filters.status', 'active')->has('orders.data', 0));
});

it('menyimpan saklar pemesanan lewat tautan dari pengaturan Pengantaran', function () {
    [$store, $admin] = dsoShop(false);
    expect(DeliveryFeatures::selfOrderEnabled($store))->toBeFalse();

    $this->actingAs($admin)
        ->post('/deliveries/settings', ['enabled' => true, 'default_fee' => 10000, 'self_order_enabled' => true])
        ->assertSessionHasNoErrors();

    $store->refresh();
    expect(DeliveryFeatures::selfOrderEnabled($store))->toBeTrue()
        ->and(DeliveryFeatures::defaultFee($store))->toBe(10000.0);

    // Form lama yang tidak mengirim saklar ini tidak mengubahnya.
    $this->actingAs($admin)->post('/deliveries/settings', ['enabled' => true, 'default_fee' => 10000]);
    expect(DeliveryFeatures::selfOrderEnabled($store->fresh()))->toBeTrue();
});

it('menampilkan halaman status pesanan antar untuk pelanggan', function () {
    [$store, , $product] = dsoShop();
    $delivery = dsoPlace($store, $product);
    $token = $delivery->transaction->status_token;

    $this->get("/antar/{$store->slug}/status/{$token}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('ScanMeja/Guest/OrderStatus')
            ->where('table', null)
            ->where('delivery.slug', $store->slug)
            ->where('delivery.address', 'Jl. Melati No. 5, pagar hijau depan masjid')
            ->where('transaction.status', 'pending')
            ->where('transaction.delivery_fee', 8000)
            ->where('transaction.items.0.name', 'Geprek')
            ->where('paymentMethod.label', 'Bayar di tempat'));

    // Token pesanan meja / token asal tidak membuka pesanan antar.
    $this->get("/antar/{$store->slug}/status/".str_repeat('x', 64))->assertNotFound();
    // Token tidak berlaku di tautan toko lain.
    [$other] = dsoShop();
    $this->get("/antar/{$other->slug}/status/{$token}")->assertNotFound();
});
