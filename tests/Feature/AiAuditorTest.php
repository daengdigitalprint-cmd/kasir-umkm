<?php

use App\Ai\Agents\AuditorAgent;
use App\Models\CashShift;
use App\Models\CashTransaction;
use App\Models\Product;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use App\Support\StoreLocationType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Setup organisasi toko + super admin + user target analisa.
 *
 * @return array{0: Store, 1: User, 2: User, 3: User}
 */
function makeAuditorOrg(string $prefix): array
{
    $store = Store::query()->create([
        'name' => "{$prefix} Store",
        'slug' => 'auditor-'.uniqid(),
        'location_type' => StoreLocationType::OUTLET,
        'is_active' => true,
        'is_subscription_active' => true,
    ]);

    $superAdmin = User::factory()->create([
        'name' => "{$prefix} SuperAdmin",
        'email' => 'sa-'.uniqid().'@test.com',
        'is_super_admin' => true,
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $cashier = User::factory()->create([
        'name' => "{$prefix} Kasir",
        'email' => 'cashier-'.uniqid().'@test.com',
        'store_id' => $store->id,
        'role' => 'cashier',
        'email_verified_at' => now(),
    ]);

    $store->members()->attach($cashier->id, ['role' => 'cashier']);

    return [$store, $superAdmin, $cashier, $cashier];
}

/**
 * Buat satu transaksi selesai dengan satu item.
 */
function makeAuditorTransaction(Store $store, User $cashier, string $date, float $unitPrice, ?float $unitCost, float $qty = 1): Transaction
{
    $product = Product::query()->create([
        'store_id' => $store->id,
        'name' => 'Produk Uji',
        'sku' => 'AUD-'.uniqid(),
        'price' => $unitPrice,
        'cost_price' => $unitCost,
    ]);

    $transaction = Transaction::query()->create([
        'store_id' => $store->id,
        'user_id' => $cashier->id,
        'transaction_number' => 'TXN-AUD-'.uniqid(),
        'status' => 'completed',
        'payment_method' => 'cash',
        'payment_status' => 'paid',
        'subtotal' => $unitPrice * $qty,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'total_amount' => $unitPrice * $qty,
        'amount_paid' => $unitPrice * $qty,
        'change_amount' => 0,
    ]);

    // created_at tidak mass-assignable; set manual agar masuk rentang analisa.
    $transaction->forceFill([
        'created_at' => $date,
        'updated_at' => $date,
    ])->saveQuietly();

    TransactionItem::query()->create([
        'transaction_id' => $transaction->id,
        'product_id' => $product->id,
        'product_name' => 'Produk Uji',
        'quantity' => $qty,
        'unit_price' => $unitPrice,
        'total_price' => $unitPrice * $qty,
        'unit_cost_snapshot' => $unitCost,
        'total_cost_snapshot' => $unitCost !== null ? $unitCost * $qty : 0,
        'gross_profit_snapshot' => $unitCost !== null ? ($unitPrice - $unitCost) * $qty : $unitPrice * $qty,
    ]);

    return $transaction;
}

// ═══════════════════════════════════════════════════════
// Akses & Keamanan
// ═══════════════════════════════════════════════════════

test('guest diarahkan ke login saat mengakses halaman ai auditor', function () {
    $this->get('/superadmin/ai-auditor')->assertRedirect(route('login'));
});

test('user biasa ditolak (403) saat mengakses halaman ai auditor', function () {
    [, , $cashier] = makeAuditorOrg('SEC');

    $this->actingAs($cashier);

    $this->get('/superadmin/ai-auditor')->assertForbidden();
});

test('super admin dapat membuka halaman ai auditor', function () {
    [$store, $superAdmin, $cashier] = makeAuditorOrg('PAGE');

    $this->actingAs($superAdmin);

    $response = $this->get('/superadmin/ai-auditor');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('AiAuditor/Index')
        ->where('maxRangeDays', 31)
        ->has('users'));
});

test('halaman ai auditor menandai api key belum dikonfigurasi', function () {
    config(['ai.providers.openai.key' => null]);

    [, $superAdmin] = makeAuditorOrg('NOKEY');

    $this->actingAs($superAdmin);

    $this->get('/superadmin/ai-auditor')->assertInertia(
        fn ($page) => $page->where('hasApiKey', false)
    );
});

test('user biasa ditolak (403) saat memanggil endpoint analyze', function () {
    [, , $cashier] = makeAuditorOrg('DENY');

    $this->actingAs($cashier);

    $this->postJson('/superadmin/ai-auditor/analyze', [
        'user_id' => $cashier->id,
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-02',
    ])->assertForbidden();
});

// ═══════════════════════════════════════════════════════
// Validasi Input
// �══════════════════════════════════════════════════════

test('analyze gagal jika user_id tidak ada', function () {
    [, $superAdmin] = makeAuditorOrg('VAL1');

    $this->actingAs($superAdmin);

    $this->postJson('/superadmin/ai-auditor/analyze', [
        'user_id' => 99999,
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-02',
    ])->assertUnprocessable();
});

test('analyze gagal jika tanggal tidak lengkap', function () {
    [$store, $superAdmin, $cashier] = makeAuditorOrg('VAL2');

    $this->actingAs($superAdmin);

    $this->postJson('/superadmin/ai-auditor/analyze', [
        'user_id' => $cashier->id,
        'start_date' => '2026-08-01',
    ])->assertUnprocessable();
});

test('analyze gagal jika tanggal selesai sebelum tanggal mulai', function () {
    [$store, $superAdmin, $cashier] = makeAuditorOrg('VAL3');

    $this->actingAs($superAdmin);

    $this->postJson('/superadmin/ai-auditor/analyze', [
        'user_id' => $cashier->id,
        'start_date' => '2026-08-10',
        'end_date' => '2026-08-01',
    ])->assertUnprocessable();
});

test('analyze gagal jika rentang tanggal lebih dari 31 hari', function () {
    [$store, $superAdmin, $cashier] = makeAuditorOrg('VAL4');

    $this->actingAs($superAdmin);

    $this->postJson('/superadmin/ai-auditor/analyze', [
        'user_id' => $cashier->id,
        'start_date' => '2026-07-01',
        'end_date' => '2026-08-15',
    ])->assertStatus(422)->assertJson([
        'success' => false,
        'message' => 'Rentang tanggal terlalu panjang. Maksimal 31 hari per analisa.',
    ]);
});

test('analyze gagal jika api key belum dikonfigurasi', function () {
    [$store, $superAdmin, $cashier] = makeAuditorOrg('NOKEY2');

    config(['ai.providers.openai.key' => null]);

    $this->actingAs($superAdmin);

    $this->postJson('/superadmin/ai-auditor/analyze', [
        'user_id' => $cashier->id,
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-02',
    ])->assertStatus(503)->assertJson([
        'success' => false,
        'message' => 'API Key AI belum dikonfigurasi. Atur melalui menu Pengaturan AI di dashboard Super Admin.',
    ]);
});

test('analyze gagal jika target user adalah super admin lain', function () {
    [, $superAdmin] = makeAuditorOrg('TARGET1');

    $otherSuperAdmin = User::factory()->create([
        'is_super_admin' => true,
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($superAdmin);

    $this->postJson('/superadmin/ai-auditor/analyze', [
        'user_id' => $otherSuperAdmin->id,
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-02',
    ])->assertNotFound();
});

// ═══════════════════════════════════════════════════════
// Skenario Analisa (AuditorAgent difake)
// ═══════════════════════════════════════════════════════

test('analyze sukses dan meneruskan data transaksi ke auditor agent', function () {
    [$store, $superAdmin, $cashier] = makeAuditorOrg('OK1');
    config(['ai.providers.openai.key' => 'test-key']);

    $transaction = makeAuditorTransaction($store, $cashier, '2026-08-01 10:00:00', 10000, 8000);

    AuditorAgent::fake(fn () => [
        'summary' => 'Semua data sinkron dan normal.',
        'anomalies' => [],
    ]);

    $this->actingAs($superAdmin);

    $response = $this->postJson('/superadmin/ai-auditor/analyze', [
        'user_id' => $cashier->id,
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-01',
    ]);

    $response->assertOk()->assertJson([
        'success' => true,
        'summary' => 'Semua data sinkron dan normal.',
        'anomalies' => [],
    ]);

    AuditorAgent::assertPrompted(function ($prompt) use ($transaction, $cashier) {
        $payload = json_decode($prompt->prompt, true);

        return $payload['transactions'][0]['number'] === $transaction->transaction_number
            && $payload['meta']['target_user']['id'] === $cashier->id;
    });
});

test('analyze meneruskan item dengan harga jual di bawah hpp ke agent', function () {
    [$store, $superAdmin, $cashier] = makeAuditorOrg('HPP1');
    config(['ai.providers.openai.key' => 'test-key']);

    makeAuditorTransaction($store, $cashier, '2026-08-01 10:00:00', 5000, 8000);

    AuditorAgent::fake(fn () => [
        'summary' => 'Ditemukan item dijual di bawah HPP.',
        'anomalies' => [
            [
                'type' => 'harga_jual_dibawah_hpp',
                'severity' => 'tinggi',
                'description' => 'Produk Uji dijual 5000 padahal HPP 8000.',
                'evidence' => 'TXN ... pada 2026-08-01 oleh Kasir',
            ],
        ],
    ]);

    $this->actingAs($superAdmin);

    $response = $this->postJson('/superadmin/ai-auditor/analyze', [
        'user_id' => $cashier->id,
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-01',
    ]);

    $response->assertOk();
    expect($response->json('anomalies'))->toHaveCount(1);
    expect($response->json('anomalies.0.severity'))->toBe('tinggi');

    AuditorAgent::assertPrompted(function ($prompt) {
        $payload = json_decode($prompt->prompt, true);
        $item = $payload['transactions'][0]['items'][0];

        return $item['unit_price'] == 5000
            && $item['unit_cost'] == 8000
            && $payload['summary']['items_sold_below_cost'] === 1;
    });
});

test('analyze menghitung ringkasan kas dan selisih shift', function () {
    [$store, $superAdmin, $cashier] = makeAuditorOrg('CASH1');
    config(['ai.providers.openai.key' => 'test-key']);

    makeAuditorTransaction($store, $cashier, '2026-08-01 10:00:00', 10000, 8000);

    CashShift::query()->create([
        'store_id' => $store->id,
        'user_id' => $cashier->id,
        'shift_number' => 'SHIFT-'.uniqid(),
        'opened_at' => '2026-08-01 08:00:00',
        'closed_at' => '2026-08-01 15:00:00',
        'opening_cash' => 50000,
        'expected_cash' => 60000,
        'closing_cash_counted' => 55000,
        'difference_amount' => -5000,
    ]);

    $cash = CashTransaction::query()->create([
        'store_id' => $store->id,
        'user_id' => $cashier->id,
        'type' => 'in',
        'category' => 'revenue',
        'amount' => 25000,
        'notes' => 'Pendapatan lain',
        'created_at' => '2026-08-01 12:00:00',
        'updated_at' => '2026-08-01 12:00:00',
    ]);

    AuditorAgent::fake(fn () => [
        'summary' => 'Ada selisih kas.',
        'anomalies' => [],
    ]);

    $this->actingAs($superAdmin);

    $response = $this->postJson('/superadmin/ai-auditor/analyze', [
        'user_id' => $cashier->id,
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-01',
    ]);

    $response->assertOk();

    AuditorAgent::assertPrompted(function ($prompt) {
        $payload = json_decode($prompt->prompt, true);

        return $payload['summary']['total_sales_amount'] == 10000
            && $payload['summary']['cash_shifts_count'] === 1
            && $payload['summary']['cash_shifts_with_difference'] === 1
            && $payload['summary']['cash_shifts_total_difference'] == -5000
            && $payload['summary']['cash_in_total'] == 25000
            && $payload['cash_shifts'][0]['difference_amount'] == -5000;
    });
});

test('analyze hanya memuat transaksi dalam rentang tanggal', function () {
    [$store, $superAdmin, $cashier] = makeAuditorOrg('RANGE1');
    config(['ai.providers.openai.key' => 'test-key']);

    makeAuditorTransaction($store, $cashier, '2026-08-01 10:00:00', 10000, 8000);
    makeAuditorTransaction($store, $cashier, '2026-08-10 10:00:00', 20000, 10000);
    makeAuditorTransaction($store, $cashier, '2026-07-15 10:00:00', 15000, 12000);

    AuditorAgent::fake(fn () => [
        'summary' => 'ok',
        'anomalies' => [],
    ]);

    $this->actingAs($superAdmin);

    $response = $this->postJson('/superadmin/ai-auditor/analyze', [
        'user_id' => $cashier->id,
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-02',
    ]);

    $response->assertOk();

    AuditorAgent::assertPrompted(function ($prompt) {
        $payload = json_decode($prompt->prompt, true);

        return count($payload['transactions']) === 1
            && $payload['transactions'][0]['total_amount'] == 10000;
    });
});

test('analyze tidak mengirim data sensitif ke agent', function () {
    [$store, $superAdmin, $cashier] = makeAuditorOrg('PRIV1');
    config(['ai.providers.openai.key' => 'test-key']);

    makeAuditorTransaction($store, $cashier, '2026-08-01 10:00:00', 10000, 8000);

    AuditorAgent::fake(fn () => [
        'summary' => 'ok',
        'anomalies' => [],
    ]);

    $this->actingAs($superAdmin);

    $this->postJson('/superadmin/ai-auditor/analyze', [
        'user_id' => $cashier->id,
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-01',
    ])->assertOk();

    AuditorAgent::assertPrompted(function ($prompt) use ($cashier) {
        $payload = json_decode($prompt->prompt, true);

        // Data minimization: tidak boleh ada email, password, atau id internal di payload.
        $json = json_encode($payload);

        return ! str_contains($json, $cashier->email)
            && ! str_contains($json, '"password"')
            && ! isset($payload['transactions'][0]['customer_id'])
            && ! isset($payload['transactions'][0]['id']);
    });
});

test('analyze mengembalikan 500 dengan pesan jika agent gagal', function () {
    [$store, $superAdmin, $cashier] = makeAuditorOrg('ERR1');
    config(['ai.providers.openai.key' => 'test-key']);

    makeAuditorTransaction($store, $cashier, '2026-08-01 10:00:00', 10000, 8000);

    AuditorAgent::fake(fn () => throw new RuntimeException('Koneksi AI gagal.'));

    $this->actingAs($superAdmin);

    $response = $this->postJson('/superadmin/ai-auditor/analyze', [
        'user_id' => $cashier->id,
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-01',
    ]);

    $response->assertStatus(500)->assertJson([
        'success' => false,
        'message' => 'Analisa AI gagal dijalankan: Koneksi AI gagal.',
    ]);
});

test('analyze sukses meski user target tidak memiliki transaksi', function () {
    [$store, $superAdmin, $cashier] = makeAuditorOrg('EMPTY1');
    config(['ai.providers.openai.key' => 'test-key']);

    AuditorAgent::fake(fn () => [
        'summary' => 'Tidak ada transaksi pada rentang ini.',
        'anomalies' => [],
    ]);

    $this->actingAs($superAdmin);

    $response = $this->postJson('/superadmin/ai-auditor/analyze', [
        'user_id' => $cashier->id,
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-02',
    ]);

    $response->assertOk()->assertJsonPath('summary', 'Tidak ada transaksi pada rentang ini.');

    AuditorAgent::assertPrompted(function ($prompt) {
        $payload = json_decode($prompt->prompt, true);

        return $payload['transactions'] === []
            && $payload['summary']['transactions_total'] === 0;
    });
});
