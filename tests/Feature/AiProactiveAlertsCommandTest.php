<?php

use App\Events\AiAlertCreated;
use App\Models\AiAlert;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Broadcast WebSocket dipalsukan agar test tidak bergantung pada server Reverb.
    Event::fake([AiAlertCreated::class]);
});

function createProactiveAlertStore(): Store
{
    return Store::query()->create([
        'name' => 'Toko Peringatan',
        'slug' => 'toko-peringatan-'.uniqid(),
        'is_active' => true,
    ]);
}

function createProactiveAlertProduct(Store $store, array $overrides = []): Product
{
    return Product::query()->create(array_merge([
        'store_id' => $store->id,
        'name' => 'Gula Pasir',
        'price' => 15000,
        'stock_quantity' => 2,
        'min_stock_level' => 10,
        'track_stock' => true,
        'is_active' => true,
    ], $overrides));
}

it('membuat peringatan stok kritis untuk penerima owner/admin', function () {
    $store = createProactiveAlertStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);
    createProactiveAlertProduct($store);

    $this->artisan('ai:proactive-alerts')->assertExitCode(0);

    $alert = AiAlert::query()->where('type', 'low_stock')->first();

    expect($alert)->not->toBeNull()
        ->and((int) $alert->user_id)->toBe((int) $user->id)
        ->and((int) $alert->store_id)->toBe((int) $store->id)
        ->and($alert->title)->toBe('Stok kritis')
        ->and($alert->is_read)->toBeFalse()
        ->and($alert->message)->toContain('Gula Pasir')
        ->and($alert->message)->toContain('sisa 2')
        ->and($alert->data['count'])->toBe(1);
});

it('menyiarkan event peringatan baru ke WebSocket', function () {
    $store = createProactiveAlertStore();
    User::factory()->create(['store_id' => $store->id, 'role' => 'owner']);
    createProactiveAlertProduct($store);

    $this->artisan('ai:proactive-alerts')->assertExitCode(0);

    Event::assertDispatched(
        AiAlertCreated::class,
        fn (AiAlertCreated $event): bool => $event->alert->type === 'low_stock',
    );
});

it('tidak membuat peringatan duplikat saat dijalankan dua kali', function () {
    $store = createProactiveAlertStore();
    User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);
    createProactiveAlertProduct($store);

    $this->artisan('ai:proactive-alerts')->assertExitCode(0);
    $this->artisan('ai:proactive-alerts')->assertExitCode(0);

    expect(AiAlert::query()->count())->toBe(1);
});

it('mode dry-run tidak menyimpan peringatan', function () {
    $store = createProactiveAlertStore();
    User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);
    createProactiveAlertProduct($store);

    $this->artisan('ai:proactive-alerts --dry-run')->assertExitCode(0);

    expect(AiAlert::query()->count())->toBe(0);
});

it('mendeteksi anomali diskon transaksi 24 jam terakhir', function () {
    $store = createProactiveAlertStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);

    $transaction = Transaction::create([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'transaction_number' => 'TRX-ALT-'.uniqid(),
        'subtotal' => 100000,
        'total_amount' => 65000,
        'discount_amount' => 35000,
        'amount_paid' => 65000,
        'change_amount' => 0,
        'payment_method' => 'cash',
        'status' => 'completed',
    ]);
    $transaction->forceFill(['created_at' => now()])->save();

    $this->artisan('ai:proactive-alerts')->assertExitCode(0);

    $alert = AiAlert::query()->where('type', 'transaction_anomaly')->first();

    expect($alert)->not->toBeNull()
        ->and($alert->message)->toContain('diskon 35%');
});

it('mendeteksi pelanggan yang hampir churn', function () {
    $store = createProactiveAlertStore();
    User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);

    Customer::query()->create([
        'store_id' => $store->id,
        'name' => 'Bu Sari',
        'total_orders' => 3,
        'last_purchase_at' => now()->subDays(40),
        'is_active' => true,
    ]);

    $this->artisan('ai:proactive-alerts')->assertExitCode(0);

    $alert = AiAlert::query()->where('type', 'churn_risk')->first();

    expect($alert)->not->toBeNull()
        ->and($alert->message)->toContain('Bu Sari');
});

it('tidak memberi peringatan kepada kasir', function () {
    $store = createProactiveAlertStore();
    User::factory()->create(['store_id' => $store->id, 'role' => 'cashier']);
    createProactiveAlertProduct($store);

    $this->artisan('ai:proactive-alerts')->assertExitCode(0);

    expect(AiAlert::query()->count())->toBe(0);
});

it('melewati toko tanpa temuan', function () {
    $store = createProactiveAlertStore();
    User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);

    $this->artisan('ai:proactive-alerts')->assertExitCode(0);

    expect(AiAlert::query()->count())->toBe(0);
});

function recordMarginSale(Store $store, User $user, Product $product, int $unitPrice, int $unitCost, \Carbon\Carbon $createdAt): void
{
    $transaction = Transaction::create([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'transaction_number' => 'TRX-MGN-'.uniqid(),
        'subtotal' => $unitPrice,
        'total_amount' => $unitPrice,
        'amount_paid' => $unitPrice,
        'change_amount' => 0,
        'payment_method' => 'cash',
        'status' => 'completed',
    ]);

    $transaction->forceFill(['created_at' => $createdAt])->save();

    TransactionItem::create([
        'transaction_id' => $transaction->id,
        'product_id' => $product->id,
        'product_name' => $product->name,
        'quantity' => 1,
        'unit_price' => $unitPrice,
        'total_price' => $unitPrice,
        'unit_cost_snapshot' => $unitCost,
        'total_cost_snapshot' => $unitCost,
        'gross_profit_snapshot' => $unitPrice - $unitCost,
    ]);
}

it('mendeteksi margin turun dibanding periode sebelumnya', function () {
    $store = createProactiveAlertStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);
    $product = createProactiveAlertProduct($store, ['stock_quantity' => 50, 'min_stock_level' => 5]);

    // Periode sebelumnya (10 hari lalu): margin 60%.
    recordMarginSale($store, $user, $product, 100000, 40000, now()->subDays(10));
    // Periode terkini (2 hari lalu): margin 10% — turun 50 poin.
    recordMarginSale($store, $user, $product, 100000, 90000, now()->subDays(2));

    $this->artisan('ai:proactive-alerts')->assertExitCode(0);

    $alert = AiAlert::query()->where('type', 'margin_drop')->first();

    expect($alert)->not->toBeNull()
        ->and($alert->title)->toBe('Margin turun')
        ->and($alert->message)->toContain('Margin kotor')
        ->and((float) $alert->data['drop_points'])->toBeGreaterThanOrEqual(5.0)
        ->and((float) $alert->data['recent_margin_pct'])->toBeLessThan((float) $alert->data['previous_margin_pct']);
});

it('tidak memberi peringatan margin ketika margin stabil', function () {
    $store = createProactiveAlertStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);
    $product = createProactiveAlertProduct($store, ['stock_quantity' => 50, 'min_stock_level' => 5]);

    // Kedua periode sama-sama margin 50%.
    recordMarginSale($store, $user, $product, 100000, 50000, now()->subDays(10));
    recordMarginSale($store, $user, $product, 100000, 50000, now()->subDays(2));

    $this->artisan('ai:proactive-alerts')->assertExitCode(0);

    expect(AiAlert::query()->where('type', 'margin_drop')->exists())->toBeFalse();
});

it('menampilkan daftar peringatan hanya untuk user login', function () {
    $store = createProactiveAlertStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);
    $other = User::factory()->create(['store_id' => $store->id, 'role' => 'cashier']);
    createProactiveAlertProduct($store);

    $this->artisan('ai:proactive-alerts')->assertExitCode(0);

    AiAlert::query()->create([
        'user_id' => $other->id,
        'store_id' => $store->id,
        'type' => 'low_stock',
        'title' => 'Milik orang lain',
        'message' => 'Tidak boleh tampil.',
        'alert_date' => now()->toDateString(),
        'is_read' => false,
    ]);

    $response = $this->actingAs($user)->getJson('/ai/alerts');

    $response->assertOk()
        ->assertJsonCount(1, 'alerts')
        ->assertJsonPath('alerts.0.type', 'low_stock')
        ->assertJsonPath('alerts.0.title', 'Stok kritis');
});

it('menandai peringatan sudah dibaca', function () {
    $store = createProactiveAlertStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);
    createProactiveAlertProduct($store);

    $this->artisan('ai:proactive-alerts')->assertExitCode(0);

    $alert = AiAlert::query()->firstOrFail();

    $this->actingAs($user)
        ->postJson("/ai/alerts/{$alert->id}/read")
        ->assertOk()
        ->assertJsonPath('alert.is_read', true);

    expect($alert->fresh()->is_read)->toBeTrue();
});

it('menolak menandai peringatan milik user lain', function () {
    $store = createProactiveAlertStore();
    User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);
    $other = User::factory()->create(['store_id' => $store->id, 'role' => 'cashier']);
    createProactiveAlertProduct($store);

    $this->artisan('ai:proactive-alerts')->assertExitCode(0);

    $alert = AiAlert::query()->firstOrFail();

    $this->actingAs($other)
        ->postJson("/ai/alerts/{$alert->id}/read")
        ->assertForbidden();

    expect($alert->fresh()->is_read)->toBeFalse();
});
