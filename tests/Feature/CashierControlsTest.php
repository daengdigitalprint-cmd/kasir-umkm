<?php

use App\Models\CashShift;
use App\Models\Product;
use App\Models\Store;
use App\Models\SubscriptionPlan;
use App\Models\Transaction;
use App\Models\User;
use App\Support\BaseUnit;
use App\Support\InventoryType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * Kontrol kasir: void & diskon besar perlu PIN persetujuan, tutup shift hitung buta,
 * dan POS menjelaskan bila shift masih dipegang orang lain.
 */
function cashierStore(array $settings = []): Store
{
    $plan = SubscriptionPlan::query()->firstOrCreate(
        ['slug' => 'bisnis'],
        ['name' => 'Paket GrowUp', 'price' => 99000, 'billing_cycle' => 'monthly', 'is_active' => true],
    );

    return Store::query()->create([
        'name' => 'Toko Kontrol Kasir',
        'slug' => 'kontrol-kasir-'.uniqid(),
        'business_type' => 'general',
        'settings' => array_merge(['product_discount_enabled' => true], $settings),
        'is_active' => true,
        'is_subscription_active' => true,
        'subscription_plan_id' => $plan->id,
        'subscription_end' => now()->addMonth(),
    ]);
}

function cashierMember(Store $store, string $role): User
{
    $user = User::factory()->create(['store_id' => $store->id, 'role' => $role, 'email_verified_at' => now()]);
    $store->members()->attach($user->id, ['role' => $role]);

    return $user;
}

function cashierProduct(Store $store): Product
{
    return Product::query()->create([
        'store_id' => $store->id,
        'name' => 'Kopi Susu',
        'sku' => 'KK-'.uniqid(),
        'price' => 10000,
        'stock_quantity' => 50,
        'unit' => 'cup',
        'inventory_type' => InventoryType::SELLABLE,
        'base_unit' => BaseUnit::PIECE,
        'track_stock' => true,
        'is_active' => true,
    ]);
}

function cashierShift(Store $store, User $user): CashShift
{
    return CashShift::query()->create([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'shift_number' => 'SH-'.uniqid(),
        'opened_at' => now()->subHour(),
        'opening_cash' => 100000,
    ]);
}

function cashierSale($test, User $cashier, Product $product, float $lineDiscount = 0, ?string $pin = null)
{
    $payload = [
        'items' => [[
            'product_id' => $product->id,
            'quantity' => 2,
            'unit_price' => 10000,
            'subtotal' => 20000,
            'line_discount' => $lineDiscount,
            'product_name' => 'Kopi Susu',
        ]],
        'payment_method' => 'cash',
        'amount_paid' => 20000,
    ];
    if ($pin !== null) {
        $payload['approval_pin'] = $pin;
    }

    return $test->actingAs($cashier)->postJson('/pos/process-payment', $payload);
}

$pinSettings = fn () => ['cash_approval_pin_hash' => Hash::make('4826')];

// ---------- 1. Void ----------

test('persetujuan void aktif tanpa PIN: kasir tidak bisa void', function () {
    $store = cashierStore(['cashier_void_requires_approval' => true]);
    $cashier = cashierMember($store, 'cashier');
    cashierShift($store, $cashier);
    cashierSale($this, $cashier, cashierProduct($store))->assertOk();
    $trx = Transaction::query()->where('store_id', $store->id)->firstOrFail();

    $this->actingAs($cashier)->postJson("/pos/transactions/{$trx->id}/void", ['void_reason' => 'Salah'])
        ->assertForbidden()
        ->assertJsonPath('approval_pin_missing', true);

    expect($trx->fresh()->status)->toBe('completed');
});

test('toko tanpa PIN & belum mengatur saklar: kasir tetap bisa void seperti biasa', function () {
    $store = cashierStore();
    $cashier = cashierMember($store, 'cashier');
    cashierShift($store, $cashier);
    cashierSale($this, $cashier, cashierProduct($store))->assertOk();
    $trx = Transaction::query()->where('store_id', $store->id)->firstOrFail();

    $this->actingAs($cashier)->postJson("/pos/transactions/{$trx->id}/void", ['void_reason' => 'Salah'])->assertOk();
    expect($trx->fresh()->status)->toBe('void');
});

test('persetujuan void dimatikan: kasir void tanpa PIN walau toko punya PIN', function () use ($pinSettings) {
    $store = cashierStore($pinSettings() + ['cashier_void_requires_approval' => false]);
    $cashier = cashierMember($store, 'cashier');
    cashierShift($store, $cashier);
    cashierSale($this, $cashier, cashierProduct($store))->assertOk();
    $trx = Transaction::query()->where('store_id', $store->id)->firstOrFail();

    $this->actingAs($cashier)->get('/pos/transactions')
        ->assertInertia(fn (Assert $page) => $page->where('voidNeedsPin', false));
    $this->actingAs($cashier)->postJson("/pos/transactions/{$trx->id}/void", ['void_reason' => 'Salah'])->assertOk();
    expect($trx->fresh()->status)->toBe('void');
});

test('pemilik dapat menyalakan dan mematikan persetujuan void', function () {
    $store = cashierStore();
    $admin = cashierMember($store, 'admin');

    $payload = ['approval_limit' => 0, 'presets' => [], 'approval_pin' => '4826', 'void_requires_approval' => true];
    $this->actingAs($admin)->put('/cash-shifts/transactions/settings', $payload)->assertSessionHasNoErrors();
    expect(\App\Support\CashDrawerSettings::voidRequiresApproval($store->fresh()))->toBeTrue();

    $this->actingAs($admin)->put('/cash-shifts/transactions/settings', ['approval_limit' => 0, 'presets' => [], 'void_requires_approval' => false])
        ->assertSessionHasNoErrors();
    expect(\App\Support\CashDrawerSettings::voidRequiresApproval($store->fresh()))->toBeFalse();
});

test('kasir void perlu PIN yang benar', function () use ($pinSettings) {
    $store = cashierStore($pinSettings());
    $cashier = cashierMember($store, 'cashier');
    cashierShift($store, $cashier);
    cashierSale($this, $cashier, cashierProduct($store))->assertOk();
    $trx = Transaction::query()->where('store_id', $store->id)->firstOrFail();

    $this->actingAs($cashier)->postJson("/pos/transactions/{$trx->id}/void", ['void_reason' => 'Salah', 'approval_pin' => '1111'])
        ->assertStatus(422)->assertJsonPath('needs_approval_pin', true);
    expect($trx->fresh()->status)->toBe('completed');

    $this->actingAs($cashier)->postJson("/pos/transactions/{$trx->id}/void", ['void_reason' => 'Salah', 'approval_pin' => '4826'])
        ->assertOk();
    expect($trx->fresh()->status)->toBe('void');
});

test('admin void tanpa PIN', function () {
    $store = cashierStore();
    $admin = cashierMember($store, 'admin');
    cashierShift($store, $admin);
    cashierSale($this, $admin, cashierProduct($store))->assertOk();
    $trx = Transaction::query()->where('store_id', $store->id)->firstOrFail();

    $this->actingAs($admin)->postJson("/transactions/{$trx->id}/void", ['void_reason' => 'Salah'])->assertOk();
    expect($trx->fresh()->status)->toBe('void');
});

// ---------- 2. Diskon manual ----------

test('diskon kasir dalam batas tidak perlu PIN', function () use ($pinSettings) {
    $store = cashierStore($pinSettings());
    $cashier = cashierMember($store, 'cashier');
    cashierShift($store, $cashier);

    cashierSale($this, $cashier, cashierProduct($store), 4000)->assertOk(); // 20% dari 20.000
});

test('diskon kasir di atas batas perlu PIN persetujuan', function () use ($pinSettings) {
    $store = cashierStore($pinSettings());
    $cashier = cashierMember($store, 'cashier');
    cashierShift($store, $cashier);
    $product = cashierProduct($store);

    cashierSale($this, $cashier, $product, 10000)->assertStatus(422)->assertJsonValidationErrors('approval_pin');
    expect(Transaction::query()->where('store_id', $store->id)->count())->toBe(0);

    cashierSale($this, $cashier, $product, 10000, '4826')->assertOk();
    expect((float) Transaction::query()->where('store_id', $store->id)->firstOrFail()->discount_amount)->toBe(10000.0);
});

test('batas diskon kasir bisa diatur dan admin tidak dibatasi', function () use ($pinSettings) {
    $store = cashierStore($pinSettings() + ['cashier_discount_limit_percent' => 50]);
    $cashier = cashierMember($store, 'cashier');
    cashierShift($store, $cashier);
    $product = cashierProduct($store);

    cashierSale($this, $cashier, $product, 10000)->assertOk(); // 50%

    $admin = cashierMember($store, 'admin');
    CashShift::query()->whereNull('closed_at')->update(['closed_at' => now()]);
    cashierShift($store, $admin);
    cashierSale($this, $admin, $product, 20000)->assertOk(); // 100%, tanpa PIN
});

// ---------- 3. Tutup shift hitung buta ----------

test('kasir tidak melihat kas seharusnya saat shift buka, admin melihat', function () {
    $store = cashierStore();
    $cashier = cashierMember($store, 'cashier');
    cashierShift($store, $cashier);

    $this->actingAs($cashier)->get('/cash-shifts')
        ->assertInertia(fn (Assert $page) => $page->where('openShift.expected_cash', null));
    $this->actingAs($cashier)->getJson('/pos/cash-shift-current')
        ->assertJsonPath('cashShiftOpen.expected_cash', null);

    CashShift::query()->update(['closed_at' => now()]);
    $admin = cashierMember($store, 'admin');
    cashierShift($store, $admin);
    $this->actingAs($admin)->get('/cash-shifts')
        ->assertInertia(fn (Assert $page) => $page->where('openShift.expected_cash', fn ($v) => (float) $v === 100000.0));
});

// ---------- 4. Shift dipegang orang lain ----------

test('POS memberi tahu kasir siapa yang masih memegang shift', function () {
    $store = cashierStore();
    $owner = cashierMember($store, 'owner');
    $owner->forceFill(['name' => 'Hendra'])->save();
    cashierShift($store, $owner);
    $cashier = cashierMember($store, 'cashier');

    $this->actingAs($cashier)->get('/pos')
        ->assertInertia(fn (Assert $page) => $page
            ->where('cashShiftOpen', null)
            ->where('shiftHeldBy.user_name', 'Hendra'));
});

test('halaman kas: kasir tidak melihat saldo laci, pengelola bisa membuka pengaturan tanpa shift', function () {
    $store = cashierStore();
    $cashier = cashierMember($store, 'cashier');
    cashierShift($store, $cashier);

    $this->actingAs($cashier)->get('/cash-shifts/transactions')
        ->assertInertia(fn (Assert $page) => $page
            ->component('CashTransactions/Index')
            ->where('summary.expected_cash', null)
            ->where('summary.sales_cash', null)
            ->where('canManage', false));

    CashShift::query()->update(['closed_at' => now()]);
    $admin = cashierMember($store, 'admin');

    $this->actingAs($admin)->get('/cash-shifts/transactions')
        ->assertInertia(fn (Assert $page) => $page
            ->component('CashTransactions/Index')
            ->where('shift', null)
            ->where('canManage', true)
            ->has('drawerSettings.void_requires_approval'));
});
