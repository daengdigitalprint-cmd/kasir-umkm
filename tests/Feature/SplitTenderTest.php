<?php

use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\Store;
use App\Models\SubscriptionPlan;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Accounting\ChartOfAccountsBootstrap;
use App\Support\BaseUnit;
use App\Support\InventoryType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Outlet paket starter (tanpa wajib shift) dengan kasir login. */
function makeSplitTenderStore(): array
{
    $plan = SubscriptionPlan::query()->firstOrCreate(
        ['slug' => 'starter'],
        ['name' => 'Paket Starter', 'price' => 0, 'billing_cycle' => 'monthly', 'is_active' => true],
    );

    $store = Store::query()->create([
        'name' => 'Toko Uji Split',
        'slug' => 'split-tender-'.uniqid(),
        'business_type' => 'general',
        'is_active' => true,
        'is_subscription_active' => true,
        'subscription_plan_id' => $plan->id,
        'subscription_end' => now()->addDay(),
    ]);

    $cashier = User::factory()->create([
        'store_id' => $store->id,
        'role' => 'cashier',
        'email_verified_at' => now(),
    ]);
    $store->members()->attach($cashier->id, ['role' => 'cashier']);

    $product = Product::query()->create([
        'store_id' => $store->id,
        'name' => 'Es Teh',
        'sku' => 'SPL-'.uniqid(),
        'price' => 5000,
        'cost_price' => 2000,
        'stock_quantity' => 20,
        'unit' => 'cup',
        'inventory_type' => InventoryType::SELLABLE,
        'base_unit' => BaseUnit::PIECE,
        'track_stock' => true,
        'is_active' => true,
    ]);

    return [$store, $cashier, $product];
}

function splitTenderPayload(int $productId, array $overrides = []): array
{
    return array_merge([
        'items' => [[
            'product_id' => $productId,
            'quantity' => 2,
            'unit_price' => 5000,
            'subtotal' => 10000,
            'product_name' => 'Es Teh',
        ]],
        'payment_method' => 'cash',
        'amount_paid' => 0,
        'total_amount' => 10000,
    ], $overrides);
}

test('split tender menyimpan rincian per metode dan hanya porsi tunai yang masuk laci', function () {
    [$store, $cashier, $product] = makeSplitTenderStore();

    $this->actingAs($cashier)
        ->postJson('/pos/process-payment', splitTenderPayload($product->id, [
            'payments' => [
                ['method' => 'qris', 'amount' => 6000, 'reference' => 'QR-123'],
                ['method' => 'cash', 'amount' => 5000],
            ],
        ]))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(2, 'transaction.payments');

    $transaction = Transaction::query()->where('store_id', $store->id)->with('payments')->firstOrFail();

    expect((float) $transaction->amount_paid)->toBe(11000.0)
        ->and((float) $transaction->change_amount)->toBe(1000.0)
        ->and((float) $transaction->cash_amount)->toBe(4000.0)
        ->and($transaction->payment_method)->toBe('qris')
        ->and($transaction->payments)->toHaveCount(2)
        ->and($transaction->payments->firstWhere('method', 'qris')->reference)->toBe('QR-123')
        // Kembalian dipotong dari baris tunai: rincian = uang yang benar-benar diterima.
        ->and((float) $transaction->payments->firstWhere('method', 'cash')->amount)->toBe(4000.0)
        ->and((float) $transaction->payments->sum('amount'))->toBe(10000.0);
});

test('pembayaran satu metode tetap mencatat satu baris rincian tanpa kembalian', function () {
    [$store, $cashier, $product] = makeSplitTenderStore();

    $this->actingAs($cashier)
        ->postJson('/pos/process-payment', splitTenderPayload($product->id, ['amount_paid' => 20000]))
        ->assertOk();

    $transaction = Transaction::query()->where('store_id', $store->id)->with('payments')->firstOrFail();

    expect((float) $transaction->cash_amount)->toBe(10000.0)
        ->and($transaction->payments)->toHaveCount(1)
        ->and((float) $transaction->payments->first()->amount)->toBe(10000.0);
});

test('split tender ditolak bila total kurang dari tagihan', function () {
    [$store, $cashier, $product] = makeSplitTenderStore();

    $this->actingAs($cashier)
        ->postJson('/pos/process-payment', splitTenderPayload($product->id, [
            'payments' => [
                ['method' => 'card', 'amount' => 3000],
                ['method' => 'cash', 'amount' => 3000],
            ],
        ]))
        ->assertJsonPath('success', false);

    expect(Transaction::query()->where('store_id', $store->id)->count())->toBe(0)
        ->and((float) $product->fresh()->stock_quantity)->toBe(20.0);
});

test('split tender ditolak bila non-tunai melebihi tagihan', function () {
    [$store, $cashier, $product] = makeSplitTenderStore();

    $this->actingAs($cashier)
        ->postJson('/pos/process-payment', splitTenderPayload($product->id, [
            'payments' => [
                ['method' => 'card', 'amount' => 8000],
                ['method' => 'ewallet', 'amount' => 4000],
            ],
        ]))
        ->assertJsonPath('success', false);

    expect(Transaction::query()->where('store_id', $store->id)->count())->toBe(0);
});

test('metode pembayaran tidak dikenal ditolak validasi', function () {
    [$store, $cashier, $product] = makeSplitTenderStore();

    $this->actingAs($cashier)
        ->postJson('/pos/process-payment', splitTenderPayload($product->id, [
            'payments' => [
                ['method' => 'bitcoin', 'amount' => 10000],
            ],
        ]))
        ->assertUnprocessable();
});

test('jurnal split tender memisah debit kas dan bank', function () {
    [$store, $cashier, $product] = makeSplitTenderStore();
    $store->update(['settings' => ['accounting_enabled' => true, 'plan_unlocked_modules' => ['accounting']]]);

    $this->actingAs($cashier)
        ->postJson('/pos/process-payment', splitTenderPayload($product->id, [
            'payments' => [
                ['method' => 'card', 'amount' => 7000],
                ['method' => 'cash', 'amount' => 5000],
            ],
        ]))
        ->assertOk()
        ->assertJsonPath('success', true);

    $transaction = Transaction::query()->where('store_id', $store->id)->firstOrFail();
    $entry = JournalEntry::query()
        ->where('reference_type', Transaction::class)
        ->where('reference_id', $transaction->id)
        ->with('lines')
        ->firstOrFail();

    $kas = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_KAS);
    $bank = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_BANK);

    expect((float) $entry->lines->where('chart_of_account_id', $kas->id)->sum('debit'))->toBe(3000.0)
        ->and((float) $entry->lines->where('chart_of_account_id', $bank->id)->sum('debit'))->toBe(7000.0)
        ->and(round((float) $entry->lines->sum('debit'), 2))->toBe(round((float) $entry->lines->sum('credit'), 2));
});

test('open bill bisa dibayar dengan split tender', function () {
    [$store, $cashier, $product] = makeSplitTenderStore();
    $store->update(['settings' => ['open_bill_enabled' => true]]);

    $this->actingAs($cashier)
        ->postJson('/pos/order-hold', [
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 2,
                'unit_price' => 5000,
                'subtotal' => 10000,
                'product_name' => $product->name,
            ]],
            'total_amount' => 10000,
            'table_reference' => 'Meja 1',
        ])
        ->assertOk();

    $hold = Transaction::query()->where('store_id', $store->id)->firstOrFail();

    $this->actingAs($cashier)
        ->postJson("/pos/order-holds/{$hold->id}/pay", [
            'payment_method' => 'cash',
            'amount_paid' => 0,
            'payments' => [
                ['method' => 'ewallet', 'amount' => 2500],
                ['method' => 'cash', 'amount' => 10000],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    $hold->refresh()->load('payments');

    expect($hold->status)->toBe('completed')
        ->and((float) $hold->cash_amount)->toBe(7500.0)
        ->and((float) $hold->change_amount)->toBe(2500.0)
        ->and($hold->payments)->toHaveCount(2)
        ->and((float) $hold->payments->sum('amount'))->toBe(10000.0);
});
