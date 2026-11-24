<?php

use App\Enums\SalesChannel;
use App\Models\CashShift;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use App\Support\OnlinePrices;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Harga per kanal: harga toko (biasa) vs harga toko online GoFood/GrabFood/ShopeeFood.
 *
 * @return array{0: Store, 1: User}
 */
function ocpStore(bool $onlinePriceEnabled = true, array $settings = []): array
{
    $store = Store::query()->create([
        'name' => 'Kedai Harga Kanal',
        'slug' => 'harga-kanal-'.uniqid(),
        'business_type' => 'fnb',
        // Pesanan online selalu aktif; yang diuji adalah saklar "Harga khusus pesanan online".
        'settings' => array_merge(['online_sales_enabled' => true, 'online_price_enabled' => $onlinePriceEnabled], $settings),
        'is_active' => true,
        'is_subscription_active' => true,
    ]);

    $admin = User::factory()->create([
        'store_id' => $store->id,
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $store->members()->attach($admin->id, ['role' => 'admin']);

    CashShift::query()->create([
        'store_id' => $store->id,
        'user_id' => $admin->id,
        'opening_cash' => 100000,
        'opened_at' => now(),
    ]);

    return [$store, $admin];
}

function ocpProduct(Store $store, array $attributes = []): Product
{
    return Product::query()->create(array_merge([
        'store_id' => $store->id,
        'name' => 'Kopi Susu',
        'price' => 15000,
        'stock_quantity' => '100.0000',
        'track_stock' => true,
        'is_active' => true,
    ], $attributes));
}

/** Payload form produk minimal (Products/Create & Edit). */
function ocpProductForm(array $overrides = []): array
{
    return array_merge([
        'name' => 'Es Kopi Gula Aren',
        // Form selalu mengirim kolom-kolom ini (boleh kosong); update() mengandalkannya.
        'sku' => null,
        'barcode' => null,
        'description' => null,
        'brand' => null,
        'cost_price' => null,
        'tax_rate' => 0,
        'price' => 15000,
        'stock_quantity' => 0,
        'min_stock_level' => 0,
        'unit' => 'pcs',
        'inventory_type' => 'sellable',
        'track_stock' => false,
        'is_active' => true,
    ], $overrides);
}

/** Bayar satu baris di POS; `unit_price` sengaja bisa dipalsukan untuk menguji server. */
function ocpPay(User $user, Product $product, string $channel, array $itemOverrides = [], array $payloadOverrides = [])
{
    return test()->actingAs($user)
        ->withSession(['_token' => 'csrf-token'])
        ->postJson('/pos/process-payment', array_merge([
            'items' => [array_merge([
                'product_id' => $product->id,
                'product_name' => $product->name,
                'quantity' => 1,
                'unit_price' => (float) $product->price,
                'subtotal' => (float) $product->price,
            ], $itemOverrides)],
            'payment_method' => 'cash',
            'amount_paid' => 1000000,
            'sales_channel' => $channel,
        ], $payloadOverrides));
}

function ocpLastTransaction(Store $store): Transaction
{
    return Transaction::query()->where('store_id', $store->id)->with('items')->latest('id')->firstOrFail();
}

// ── Form produk ────────────────────────────────────────────────────────────

it('menyimpan harga toko online saat membuat produk', function () {
    [$store, $admin] = ocpStore();

    $this->actingAs($admin)
        ->post('/products', ocpProductForm([
            'online_prices' => ['gofood' => 18000, 'grabfood' => '18500', 'shopeefood' => ''],
        ]))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $product = Product::query()->where('store_id', $store->id)->where('name', 'Es Kopi Gula Aren')->firstOrFail();

    expect((float) $product->price)->toBe(15000.0)
        ->and($product->online_prices)->toEqual(['gofood' => 18000.0, 'grabfood' => 18500.0]);
});

it('mengubah dan mengosongkan harga toko online lewat form edit', function () {
    [$store, $admin] = ocpStore();
    $product = ocpProduct($store, ['online_prices' => ['gofood' => 18000, 'grabfood' => 18000]]);

    $this->actingAs($admin)
        ->put("/products/{$product->id}", ocpProductForm([
            'name' => $product->name,
            'online_prices' => ['gofood' => 19000, 'grabfood' => null, 'shopeefood' => 17500],
        ]))
        ->assertSessionHasNoErrors();

    expect($product->fresh()->online_prices)->toEqual(['gofood' => 19000.0, 'shopeefood' => 17500.0]);

    $this->actingAs($admin)
        ->put("/products/{$product->id}", ocpProductForm([
            'name' => $product->name,
            'online_prices' => ['gofood' => null, 'grabfood' => null, 'shopeefood' => null],
        ]))
        ->assertSessionHasNoErrors();

    expect($product->fresh()->online_prices)->toBeNull();
});

it('menolak harga toko online yang bukan angka atau negatif', function () {
    [, $admin] = ocpStore();

    $this->actingAs($admin)
        ->post('/products', ocpProductForm(['online_prices' => ['gofood' => -500, 'grabfood' => 'mahal']]))
        ->assertSessionHasErrors(['online_prices.gofood', 'online_prices.grabfood']);
});

it('tidak menyimpan harga toko online bila saklar harga online mati', function () {
    [$store, $admin] = ocpStore(false);

    $this->actingAs($admin)
        ->post('/products', ocpProductForm(['online_prices' => ['gofood' => 18000]]))
        ->assertSessionHasNoErrors();

    $product = Product::query()->where('store_id', $store->id)->where('name', 'Es Kopi Gula Aren')->firstOrFail();
    expect($product->online_prices)->toBeNull();
});

it('mempertahankan harga toko online lama saat produk diedit ketika saklar mati', function () {
    [$store, $admin] = ocpStore(false);
    $product = ocpProduct($store, ['online_prices' => ['gofood' => 18000]]);

    $this->actingAs($admin)
        ->put("/products/{$product->id}", ocpProductForm(['name' => 'Kopi Susu Baru']))
        ->assertSessionHasNoErrors();

    $fresh = $product->fresh();
    expect($fresh->name)->toBe('Kopi Susu Baru')
        ->and($fresh->online_prices)->toEqual(['gofood' => 18000]);
});

it('tidak menyimpan harga toko online untuk bahan baku', function () {
    [$store, $admin] = ocpStore();

    $this->actingAs($admin)
        ->post('/products', ocpProductForm([
            'name' => 'Gula Aren Cair',
            'price' => 0,
            'inventory_type' => 'raw_material',
            'track_stock' => true,
            'online_prices' => ['gofood' => 18000],
        ]))
        ->assertSessionHasNoErrors();

    $product = Product::query()->where('store_id', $store->id)->where('name', 'Gula Aren Cair')->firstOrFail();
    expect($product->online_prices)->toBeNull();
});

it('menyimpan harga toko online per varian dari form produk', function () {
    [$store, $admin] = ocpStore();

    $this->actingAs($admin)
        ->post('/products', ocpProductForm([
            'has_variants' => true,
            'variants_json' => json_encode([
                ['name' => 'Regular', 'price' => 15000, 'stock_quantity' => 0, 'min_stock_level' => 0, 'online_prices' => ['gofood' => 18000, 'grabfood' => null, 'shopeefood' => null]],
                ['name' => 'Large', 'price' => 20000, 'stock_quantity' => 0, 'min_stock_level' => 0, 'online_prices' => ['gofood' => 24000, 'grabfood' => 24500, 'shopeefood' => null]],
            ]),
        ]))
        ->assertSessionHasNoErrors();

    $product = Product::query()->where('store_id', $store->id)->where('name', 'Es Kopi Gula Aren')->firstOrFail();
    $variants = $product->variants()->orderBy('sort_order')->get();

    expect($product->has_variants)->toBeTrue()
        ->and($variants)->toHaveCount(2)
        ->and($variants[0]->online_prices)->toEqual(['gofood' => 18000.0])
        ->and($variants[1]->online_prices)->toEqual(['gofood' => 24000.0, 'grabfood' => 24500.0]);
});

// ── POS ────────────────────────────────────────────────────────────────────

it('memakai harga kanal di POS saat sumber pesanan GoFood', function () {
    [$store, $admin] = ocpStore();
    $product = ocpProduct($store, ['online_prices' => ['gofood' => 18000]]);

    // Browser sengaja mengirim harga toko: server harus menghitung sendiri harga GoFood.
    ocpPay($admin, $product, 'gofood', ['quantity' => 2], ['commission_percent' => 20])
        ->assertStatus(200)
        ->assertJsonPath('success', true);

    $txn = ocpLastTransaction($store);
    expect($txn->sales_channel)->toBe(SalesChannel::GoFood)
        ->and((float) $txn->items[0]->unit_price)->toBe(18000.0)
        ->and((float) $txn->items[0]->total_price)->toBe(36000.0)
        ->and((float) $txn->subtotal)->toBe(36000.0)
        ->and((float) $txn->total_amount)->toBe(36000.0)
        // Komisi dihitung dari total harga online, bukan harga toko.
        ->and((float) $txn->commission_amount)->toBe(7200.0);
});

it('kembali ke harga toko bila harga kanal itu tidak diisi', function () {
    [$store, $admin] = ocpStore();
    $product = ocpProduct($store, ['online_prices' => ['gofood' => 18000]]);

    ocpPay($admin, $product, 'grabfood')->assertStatus(200)->assertJsonPath('success', true);

    $txn = ocpLastTransaction($store);
    expect($txn->sales_channel)->toBe(SalesChannel::GrabFood)
        ->and((float) $txn->items[0]->unit_price)->toBe(15000.0)
        ->and((float) $txn->total_amount)->toBe(15000.0);
});

it('memakai harga toko untuk penjualan langsung walau harga online diisi', function () {
    [$store, $admin] = ocpStore();
    $product = ocpProduct($store, ['online_prices' => ['gofood' => 18000, 'grabfood' => 18000, 'shopeefood' => 18000]]);

    // Harga online yang dikirim browser pada kanal Walk In harus diabaikan server.
    ocpPay($admin, $product, 'walk_in', ['unit_price' => 18000, 'subtotal' => 18000])
        ->assertStatus(200)
        ->assertJsonPath('success', true);

    $txn = ocpLastTransaction($store);
    expect($txn->sales_channel)->toBe(SalesChannel::WalkIn)
        ->and((float) $txn->items[0]->unit_price)->toBe(15000.0)
        ->and((float) $txn->total_amount)->toBe(15000.0);
});

it('mengabaikan harga murah kiriman browser pada kanal online', function () {
    [$store, $admin] = ocpStore();
    $product = ocpProduct($store, ['online_prices' => ['shopeefood' => 17000]]);

    ocpPay($admin, $product, 'shopeefood', ['unit_price' => 1, 'subtotal' => 1])
        ->assertStatus(200)
        ->assertJsonPath('success', true);

    expect((float) ocpLastTransaction($store)->items[0]->unit_price)->toBe(17000.0);
});

it('mendahulukan harga online di atas harga grosir pada kanal online', function () {
    [$store, $admin] = ocpStore(true, ['wholesale_enabled' => true]);
    $product = ocpProduct($store, ['wholesale_price' => 12000, 'online_prices' => ['gofood' => 18000]]);

    ocpPay($admin, $product, 'gofood', [], ['customer_type' => 'grosir'])->assertStatus(200);
    expect((float) ocpLastTransaction($store)->items[0]->unit_price)->toBe(18000.0);

    // Kanal online tanpa harga online → aturan lama (grosir) tetap berlaku.
    ocpPay($admin, $product, 'grabfood', [], ['customer_type' => 'grosir'])->assertStatus(200);
    expect((float) ocpLastTransaction($store)->items[0]->unit_price)->toBe(12000.0);
});

it('menambahkan harga pilihan tambahan di atas harga kanal', function () {
    [$store, $admin] = ocpStore();
    $product = ocpProduct($store, [
        'online_prices' => ['gofood' => 18000],
        'modifier_options' => [['key' => 'extra_shot', 'label' => 'Extra Shot', 'price' => 5000]],
    ]);

    ocpPay($admin, $product, 'gofood', ['modifiers' => [['key' => 'extra_shot']]])->assertStatus(200);

    expect((float) ocpLastTransaction($store)->items[0]->unit_price)->toBe(23000.0);
});

it('menerapkan harga kanal per varian dengan pewarisan yang aman', function () {
    [$store, $admin] = ocpStore();
    $product = ocpProduct($store, [
        'has_variants' => true,
        'track_stock' => false,
        'online_prices' => ['gofood' => 18000],
    ]);
    $variant = fn (array $attrs) => ProductVariant::query()->create(array_merge([
        'store_id' => $store->id,
        'product_id' => $product->id,
        'stock_quantity' => '50.0000',
        'is_active' => true,
    ], $attrs));

    $large = $variant(['name' => 'Large', 'price' => 20000, 'online_prices' => ['gofood' => 24000]]);
    $jumbo = $variant(['name' => 'Jumbo', 'price' => 25000]);
    $regular = $variant(['name' => 'Regular', 'price' => null]);

    $unitPriceFor = function (ProductVariant $v, string $channel) use ($admin, $product, $store): float {
        ocpPay($admin, $product, $channel, ['product_variant_id' => $v->id])->assertStatus(200)->assertJsonPath('success', true);

        return (float) ocpLastTransaction($store)->items[0]->unit_price;
    };

    // Varian dengan harga online sendiri.
    expect($unitPriceFor($large, 'gofood'))->toBe(24000.0)
        // Varian berharga sendiri tanpa harga online: tetap harga toko varian, bukan harga online induk yang lebih murah.
        ->and($unitPriceFor($jumbo, 'gofood'))->toBe(25000.0)
        // Varian yang mengikuti harga induk ikut mewarisi harga online induk.
        ->and($unitPriceFor($regular, 'gofood'))->toBe(18000.0)
        // Penjualan langsung: harga toko masing-masing.
        ->and($unitPriceFor($large, 'walk_in'))->toBe(20000.0)
        ->and($unitPriceFor($regular, 'walk_in'))->toBe(15000.0);
});

it('mengirim harga toko online ke halaman POS', function () {
    [$store, $admin] = ocpStore();
    ocpProduct($store, ['online_prices' => ['gofood' => 18000]]);

    $this->actingAs($admin)
        ->get('/pos')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('POS/Index')
            ->where('store.online_sales_enabled', true)
            ->where('store.online_price_enabled', true)
            ->where('products.0.online_prices.gofood', 18000));
});

// ── Saklar di Pengaturan ───────────────────────────────────────────────────

it('memakai harga toko di kanal online bila saklar harga online mati', function () {
    [$store, $admin] = ocpStore(false);
    $product = ocpProduct($store, ['online_prices' => ['gofood' => 18000]]);

    // Pesanan GoFood tetap diterima, tetapi harga online yang tersimpan tidak dipakai.
    ocpPay($admin, $product, 'gofood', ['unit_price' => 18000, 'subtotal' => 18000])
        ->assertStatus(200)
        ->assertJsonPath('success', true);

    $txn = ocpLastTransaction($store);
    expect($txn->sales_channel)->toBe(SalesChannel::GoFood)
        ->and((float) $txn->items[0]->unit_price)->toBe(15000.0)
        ->and((float) $txn->total_amount)->toBe(15000.0);
});

it('menampilkan isian harga online di form produk hanya bila saklarnya aktif', function (bool $enabled) {
    [$store, $admin] = ocpStore($enabled);
    $product = ocpProduct($store);

    $this->actingAs($admin)->get('/products/create')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Products/Create')->where('onlinePricesEnabled', $enabled));

    $this->actingAs($admin)->get("/products/{$product->id}/edit")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Products/Edit')->where('onlinePricesEnabled', $enabled));

    $this->actingAs($admin)->get('/pos')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('store.online_price_enabled', $enabled));
})->with([true, false]);

it('saklar harga online tidak berlaku bila pesanan online dimatikan', function () {
    [$store] = ocpStore(true, ['online_sales_enabled' => false]);

    expect(OnlinePrices::enabled($store))->toBeFalse()
        ->and(OnlinePrices::salesEnabled($store))->toBeFalse();
});

it('menyimpan saklar harga khusus pesanan online dari pengaturan toko', function () {
    [$store, $admin] = ocpStore(false);

    $this->actingAs($admin)
        ->put('/settings/store', [
            'name' => $store->name,
            'business_type' => $store->business_type,
            'online_price_enabled' => true,
        ])->assertRedirect();

    expect(OnlinePrices::enabled($store->fresh()))->toBeTrue();

    $this->actingAs($admin)
        ->put('/settings/store', [
            'name' => $store->name,
            'business_type' => $store->business_type,
            'online_price_enabled' => false,
        ])->assertRedirect();

    $fresh = $store->fresh();
    expect(OnlinePrices::enabled($fresh))->toBeFalse()
        // Mematikan harga online tidak mematikan pesanan online-nya.
        ->and(OnlinePrices::salesEnabled($fresh))->toBeTrue();
});

// ── Aturan harga (unit) ────────────────────────────────────────────────────

it('merapikan masukan harga online: hanya kanal dikenal dan bernilai positif', function () {
    expect(OnlinePrices::normalize(['gofood' => '18000', 'grabfood' => 0, 'shopeefood' => '', 'tokopedia' => 9000]))
        ->toEqual(['gofood' => 18000.0])
        ->and(OnlinePrices::normalize(['gofood' => null]))->toBeNull()
        ->and(OnlinePrices::normalize('bukan array'))->toBeNull();
});
