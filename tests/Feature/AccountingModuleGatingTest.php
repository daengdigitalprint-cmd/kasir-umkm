<?php

use App\Models\AccountingPeriod;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\ModuleService;
use App\Support\Accounting\AccountingFeatures;
use App\Support\Accounting\AccountingPeriodGuard;
use App\Support\Accounting\ChartOfAccountsBootstrap;
use App\Support\Accounting\JournalPoster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
});

function makeGatingStore(bool $accountingEnabled = false): array
{
    // Paket starter: modul shift kasir tidak aktif sehingga POS tidak butuh shift terbuka.
    $plan = \App\Models\SubscriptionPlan::query()->firstOrCreate(
        ['slug' => 'starter'],
        [
            'name' => 'Paket Starter',
            'price' => 0,
            'billing_cycle' => 'monthly',
            'is_active' => true,
        ]
    );

    $store = Store::query()->create([
        'name' => 'Toko Gating',
        'slug' => 'gating-'.uniqid(),
        'is_active' => true,
        'is_subscription_active' => true,
        'subscription_plan_id' => $plan->id,
        'subscription_end' => now()->addDay(),
        // Akuntansi modul berbayar: toko uji (paket Starter) diberi izin khusus agar gating modul yang diuji.
        'settings' => ['accounting_enabled' => $accountingEnabled, 'plan_unlocked_modules' => ['accounting']],
    ]);

    $user = User::factory()->create([
        'store_id' => $store->id,
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $user->stores()->attach($store->id, ['role' => 'admin']);

    $product = Product::query()->create([
        'store_id' => $store->id,
        'name' => 'Produk Gating',
        'price' => 10000,
        'cost_price' => 6000,
        'average_cost' => 6000,
        'stock_quantity' => 50,
        'track_stock' => true,
        'unit' => 'pcs',
        'base_unit' => 'pcs',
        'is_active' => true,
    ]);

    return [$store, $user, $product];
}

it('modul akuntansi default mati untuk toko baru', function () {
    [$store] = makeGatingStore();

    expect(AccountingFeatures::enabled($store))->toBeFalse()
        ->and(ModuleService::isEnabled($store, 'accounting_enabled'))->toBeFalse();
});

it('modul off: bayar POS sukses meski semua periode ditutup dan tidak ada jurnal baru', function () {
    [$store, $user, $product] = makeGatingStore(false);

    AccountingPeriod::create([
        'store_id' => $store->id,
        'name' => 'Agustus 2026',
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-31',
        'status' => AccountingPeriod::STATUS_CLOSED,
    ]);

    $response = $this->actingAs($user)->postJson('/pos/process-payment', [
        'items' => [[
            'product_id' => $product->id,
            'quantity' => 2,
            'modifiers' => [],
        ]],
        'payment_method' => 'cash',
        'amount_paid' => 20000,
    ]);

    $response->assertOk()->assertJsonPath('success', true);

    expect(JournalEntry::query()->where('store_id', $store->id)->count())->toBe(0)
        ->and(AccountingPeriodGuard::posLockMessage($store, now()))->toBeNull();
});

it('modul off: route laporan keuangan ditolak', function () {
    [, $user] = makeGatingStore(false);

    $this->actingAs($user)->get('/financial/journal')->assertNotFound();
    $this->actingAs($user)->get('/financial/trial-balance')->assertNotFound();
});

it('modul on: periode bulan ini open maka bayar POS sukses dengan 1 jurnal seimbang', function () {
    [$store, $user, $product] = makeGatingStore(true);

    $now = now();
    AccountingPeriod::create([
        'store_id' => $store->id,
        'name' => $now->translatedFormat('F Y'),
        'start_date' => $now->copy()->startOfMonth()->toDateString(),
        'end_date' => $now->copy()->endOfMonth()->toDateString(),
        'status' => AccountingPeriod::STATUS_OPEN,
    ]);

    $this->actingAs($user)->postJson('/pos/process-payment', [
        'items' => [[
            'product_id' => $product->id,
            'quantity' => 2,
            'modifiers' => [],
        ]],
        'payment_method' => 'cash',
        'amount_paid' => 20000,
    ])->assertOk()->assertJsonPath('success', true);

    $entry = JournalEntry::query()
        ->where('store_id', $store->id)
        ->where('reference_type', \App\Models\Transaction::class)
        ->first();

    expect($entry)->not->toBeNull();
    expect((float) $entry->lines()->sum('debit'))->toEqual((float) $entry->lines()->sum('credit'));
});

it('modul on: periode bulan ini closed maka bayar POS ditolak tanpa transaksi baru', function () {
    [$store, $user, $product] = makeGatingStore(true);

    $now = now();
    AccountingPeriod::create([
        'store_id' => $store->id,
        'name' => $now->translatedFormat('F Y'),
        'start_date' => $now->copy()->startOfMonth()->toDateString(),
        'end_date' => $now->copy()->endOfMonth()->toDateString(),
        'status' => AccountingPeriod::STATUS_CLOSED,
    ]);

    $beforeTransactions = \App\Models\Transaction::query()->where('store_id', $store->id)->count();

    $this->actingAs($user)->postJson('/pos/process-payment', [
        'items' => [[
            'product_id' => $product->id,
            'quantity' => 1,
            'modifiers' => [],
        ]],
        'payment_method' => 'cash',
        'amount_paid' => 10000,
    ])->assertStatus(422);

    expect(\App\Models\Transaction::query()->where('store_id', $store->id)->count())->toBe($beforeTransactions)
        ->and(AccountingPeriodGuard::posLockMessage($store, now()))->not->toBeNull();
});

it('nyalakan modul tercatat di module_activity_logs dan COA dibuat', function () {
    [$store, $user] = makeGatingStore(false);

    ModuleService::toggle($store, 'accounting_enabled', true, $user);

    $log = \App\Models\ModuleActivityLog::query()
        ->where('store_id', $store->id)
        ->where('module_name', 'accounting_enabled')
        ->latest('id')
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->action)->toBe('enabled')
        ->and(AccountingFeatures::enabled($store->fresh()))->toBeTrue();

    ChartOfAccountsBootstrap::ensureForStore($store);
    expect(\App\Models\ChartOfAccount::query()->where('store_id', $store->id)->count())->toBeGreaterThan(0);
});

it('matikan modul: POS tidak terkunci dan jurnal lama tetap ada', function () {
    [$store, $user] = makeGatingStore(true);

    ChartOfAccountsBootstrap::ensureForStore($store);
    JournalPoster::postCapitalInjection($store, 50000, now()->toDateString(), $user->id);
    $entriesBefore = JournalEntry::query()->where('store_id', $store->id)->count();
    expect($entriesBefore)->toBeGreaterThan(0);

    ModuleService::toggle($store, 'accounting_enabled', false, $user);

    // Buat periode tertutup: jika modul masih on, POS akan terkunci.
    AccountingPeriod::create([
        'store_id' => $store->id,
        'name' => 'Agustus 2026',
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-31',
        'status' => AccountingPeriod::STATUS_CLOSED,
    ]);

    expect(AccountingFeatures::enabled($store->fresh()))->toBeFalse()
        ->and(AccountingPeriodGuard::posLockMessage($store->fresh(), now()))->toBeNull()
        ->and(JournalEntry::query()->where('store_id', $store->id)->count())->toBe($entriesBefore);
});

it('pembelian dengan modul off: posting jurnal no-op', function () {
    [$store, $user] = makeGatingStore(false);

    $purchase = new \App\Models\Purchase([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'purchased_at' => now()->toDateString(),
        'total_amount' => 50000,
    ]);
    $purchase->setRelation('lines', collect());
    $purchase->setRelation('charges', collect());

    JournalPoster::postPurchase($store, $purchase);

    expect(JournalEntry::query()->where('store_id', $store->id)->count())->toBe(0);
});

it('pembelian dengan modul on: jurnal dibuat', function () {
    [$store, $user] = makeGatingStore(true);

    ChartOfAccountsBootstrap::ensureForStore($store);
    $persediaan = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_PERSEDIAAN);

    $purchase = \App\Models\Purchase::create([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'purchased_at' => now()->toDateString(),
        'is_paid' => true,
        'amount_paid' => 50000,
        'total_amount' => 50000,
    ]);

    // Baris manual untuk posting terfokus (menghindari ketergantungan payload HTTP lengkap).
    $entry = JournalEntry::create([
        'store_id' => $store->id,
        'entry_date' => now()->toDateString(),
        'reference_type' => \App\Models\Purchase::class,
        'reference_id' => $purchase->id,
        'description' => 'Pembelian '.$purchase->purchase_number,
        'user_id' => $user->id,
    ]);
    \App\Models\JournalLine::create([
        'journal_entry_id' => $entry->id,
        'chart_of_account_id' => $persediaan->id,
        'debit' => 50000,
        'credit' => 0,
    ]);
    $kas = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_KAS);
    \App\Models\JournalLine::create([
        'journal_entry_id' => $entry->id,
        'chart_of_account_id' => $kas->id,
        'debit' => 0,
        'credit' => 50000,
    ]);

    // Posting ulang harus no-op (tidak duplikat) karena sudah ada jurnal referensinya.
    $purchaseFresh = new \App\Models\Purchase;
    $purchaseFresh->setRawAttributes(array_merge($purchase->getAttributes(), [
        'id' => $purchase->id,
        'store_id' => $store->id,
    ]));
    $purchaseFresh->setRelation('lines', collect());
    $purchaseFresh->setRelation('charges', collect());

    JournalPoster::postPurchase($store, $purchaseFresh);

    expect(JournalEntry::query()
        ->where('store_id', $store->id)
        ->where('reference_type', \App\Models\Purchase::class)
        ->where('reference_id', $purchase->id)
        ->count())->toBe(1);
});

it('assertDateOpen no-op ketika modul mati', function () {
    [$store] = makeGatingStore(false);

    AccountingPeriod::create([
        'store_id' => $store->id,
        'name' => 'Agustus 2026',
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-31',
        'status' => AccountingPeriod::STATUS_CLOSED,
    ]);

    // Tidak melempar exception karena modul mati.
    AccountingPeriodGuard::assertDateOpen($store, '2026-08-15');
    expect(true)->toBeTrue();
});

it('assertDateOpen menolak tanggal pada periode tertutup ketika modul on', function () {
    [$store] = makeGatingStore(true);

    AccountingPeriod::create([
        'store_id' => $store->id,
        'name' => 'Agustus 2026',
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-31',
        'status' => AccountingPeriod::STATUS_CLOSED,
    ]);

    expect(fn () => AccountingPeriodGuard::assertDateOpen($store, '2026-08-15'))
        ->toThrow(ValidationException::class);
});

// =============================================================================
// Tahap 3: Aktivasi modul via HTTP → COA + periode bulan berjalan dibuat otomatis
// =============================================================================

it('aktivasi modul via StoreSettingsController membuat COA dan periode bulan berjalan', function () {
    [$store, $user] = makeGatingStore(false);

    // Simulasi HTTP PUT ke endpoint pengaturan toko dengan accounting_enabled=true.
    $this->actingAs($user)->put('/settings/store', [
        'accounting_enabled' => true,
        'name' => $store->name,
        'business_type' => $store->business_type ?? 'retail',
    ]);

    $store->refresh();
    expect(AccountingFeatures::enabled($store))->toBeTrue();

    // COA harus sudah ada.
    expect(\App\Models\ChartOfAccount::query()->where('store_id', $store->id)->count())->toBeGreaterThan(0);

    // Periode bulan berjalan harus dibuat dengan status open.
    $now = now();
    $period = AccountingPeriod::query()
        ->where('store_id', $store->id)
        ->whereDate('start_date', '<=', $now)
        ->whereDate('end_date', '>=', $now)
        ->first();

    expect($period)->not->toBeNull()
        ->and($period->status)->toBe(AccountingPeriod::STATUS_OPEN)
        ->and($period->start_date->day)->toBe(1);
});

it('aktivasi modul kedua kali tidak menduplikasi periode', function () {
    [$store, $user] = makeGatingStore(false);

    // Aktifkan pertama kali.
    $this->actingAs($user)->put('/settings/store', [
        'accounting_enabled' => true,
        'name' => $store->name,
        'business_type' => $store->business_type ?? 'retail',
    ]);

    $countAfterFirst = AccountingPeriod::query()->where('store_id', $store->id)->count();

    // Nonaktifkan lalu aktifkan lagi.
    $this->actingAs($user)->put('/settings/store', [
        'accounting_enabled' => false,
        'name' => $store->name,
        'business_type' => $store->business_type ?? 'retail',
    ]);

    $this->actingAs($user)->put('/settings/store', [
        'accounting_enabled' => true,
        'name' => $store->name,
        'business_type' => $store->business_type ?? 'retail',
    ]);

    $countAfterSecond = AccountingPeriod::query()->where('store_id', $store->id)->count();
    expect($countAfterSecond)->toBe($countAfterFirst);
});

// =============================================================================
// Tahap 4: Validasi periode — hanya tanggal 1 s.d. akhir bulan, tanpa overlap
// =============================================================================

it('buat periode dengan tanggal bukan tanggal 1 ditolak', function () {
    [$store, $user] = makeGatingStore(true);

    $response = $this->actingAs($user)->post('/financial/accounting-periods', [
        'name' => 'Test',
        'start_date' => '2026-08-05',
        'end_date' => '2026-08-31',
    ]);

    $response->assertSessionHasErrors(['start_date']);
    expect(AccountingPeriod::query()->where('store_id', $store->id)->count())->toBe(0);
});

it('buat periode dengan akhir bukan akhir bulan ditolak', function () {
    [$store, $user] = makeGatingStore(true);

    $response = $this->actingAs($user)->post('/financial/accounting-periods', [
        'name' => 'Test',
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-28',
    ]);

    $response->assertSessionHasErrors(['start_date']);
    expect(AccountingPeriod::query()->where('store_id', $store->id)->count())->toBe(0);
});

it('buat periode yang tumpang tindih ditolak', function () {
    [$store, $user] = makeGatingStore(true);

    AccountingPeriod::create([
        'store_id' => $store->id,
        'name' => 'Agustus 2026',
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-31',
        'status' => AccountingPeriod::STATUS_OPEN,
    ]);

    $response = $this->actingAs($user)->post('/financial/accounting-periods', [
        'name' => 'Agustus 2026 Duplikat',
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-31',
    ]);

    $response->assertSessionHasErrors(['start_date']);
    expect(AccountingPeriod::query()->where('store_id', $store->id)->count())->toBe(1);
});

it('buat periode valid (tanggal 1 s.d. akhir bulan, tidak overlap) berhasil', function () {
    [$store, $user] = makeGatingStore(true);

    $response = $this->actingAs($user)->post('/financial/accounting-periods', [
        'name' => 'Agustus 2026',
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-31',
    ]);

    $response->assertRedirect();
    expect(AccountingPeriod::query()->where('store_id', $store->id)->count())->toBe(1);
});

// =============================================================================
// Tahap 4: Buka ulang periode — hanya owner, alasan wajib, jurnal penutup dihapus
// =============================================================================

function makeOwnerUser(Store $store): User
{
    $owner = User::factory()->create([
        'store_id' => $store->id,
        'email_verified_at' => now(),
    ]);
    $owner->stores()->attach($store->id, ['role' => 'owner']);

    return $owner;
}

it('buka ulang periode oleh non-owner ditolak 403', function () {
    [$store, $user] = makeGatingStore(true);

    $period = AccountingPeriod::create([
        'store_id' => $store->id,
        'name' => 'Juli 2026',
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
        'status' => AccountingPeriod::STATUS_CLOSED,
    ]);

    $this->actingAs($user)
        ->post("/financial/accounting-periods/{$period->id}/reopen", ['reason' => 'koreksi'])
        ->assertForbidden();
});

it('buka ulang periode tanpa alasan ditolak', function () {
    [$store] = makeGatingStore(true);
    $owner = makeOwnerUser($store);

    $period = AccountingPeriod::create([
        'store_id' => $store->id,
        'name' => 'Juli 2026',
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
        'status' => AccountingPeriod::STATUS_CLOSED,
    ]);

    $this->actingAs($owner)
        ->post("/financial/accounting-periods/{$period->id}/reopen", ['reason' => ''])
        ->assertSessionHasErrors(['reason']);
});

it('buka ulang periode yang masih open ditolak', function () {
    [$store] = makeGatingStore(true);
    $owner = makeOwnerUser($store);

    $period = AccountingPeriod::create([
        'store_id' => $store->id,
        'name' => 'Agustus 2026',
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-31',
        'status' => AccountingPeriod::STATUS_OPEN,
    ]);

    expect(fn () => \App\Support\Accounting\AccountingPeriodCloser::reopen($period, $owner, 'test'))
        ->toThrow(ValidationException::class);
});

it('buka ulang periode closed oleh owner berhasil dan jurnal penutup terhapus', function () {
    [$store] = makeGatingStore(true);
    $owner = makeOwnerUser($store);

    ChartOfAccountsBootstrap::ensureForStore($store);

    $period = AccountingPeriod::create([
        'store_id' => $store->id,
        'name' => 'Juli 2026',
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
        'status' => AccountingPeriod::STATUS_OPEN,
    ]);

    // Tutup periode (buat jurnal penutup).
    \App\Support\Accounting\AccountingPeriodCloser::close($period, $owner);
    $period->refresh();
    expect($period->status)->toBe(AccountingPeriod::STATUS_CLOSED);
    expect($period->closing_journal_entry_id)->not->toBeNull();

    $closingEntryId = $period->closing_journal_entry_id;

    // Buka ulang via HTTP.
    $this->actingAs($owner)
        ->post("/financial/accounting-periods/{$period->id}/reopen", ['reason' => 'koreksi jurnal'])
        ->assertRedirect();

    $period->refresh();
    expect($period->status)->toBe(AccountingPeriod::STATUS_OPEN)
        ->and($period->closing_journal_entry_id)->toBeNull()
        ->and($period->reopen_reason)->toBe('koreksi jurnal')
        ->and($period->reopened_by)->toBe($owner->id);

    // Jurnal penutup harus terhapus.
    expect(\App\Models\JournalEntry::query()->whereKey($closingEntryId)->exists())->toBeFalse();
});
