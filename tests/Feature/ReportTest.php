<?php

use App\Models\AccountingPeriod;
use App\Models\CashShift;
use App\Models\CashTransaction;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\Purchase;
use App\Models\PurchasePayment;
use App\Models\Recipe;
use App\Models\StockAdjustment;
use App\Models\Store;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\ChartOfAccount;
use App\Models\FinancialReportStatus;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use App\Support\Accounting\AccountingPeriodCloser;
use App\Support\Accounting\ChartOfAccountsBootstrap;
use App\Support\Accounting\JournalPoster;
use App\Support\BaseUnit;
use App\Support\InventoryType;
use App\Support\StoreLocationType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeReportOrg(string $prefix): array
{
    $store = Store::query()->create([
        'name' => "{$prefix} Store",
        'slug' => 'report-test-'.uniqid(),
        'location_type' => StoreLocationType::OUTLET,
        'is_active' => true,
        'is_subscription_active' => true,
        // File ini menguji laporan akuntansi & posting jurnal → modul harus aktif.
        'settings' => ['accounting_enabled' => true],
    ]);

    $user = User::factory()->create([
        'store_id' => $store->id,
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $store->members()->attach($user->id, ['role' => 'admin']);

    return [$store, $user];
}

function createReportProduct(Store $store, string $name, string $inventoryType = InventoryType::SELLABLE): Product
{
    return Product::query()->create([
        'store_id' => $store->id,
        'name' => $name,
        'price' => 20000,
        'cost_price' => 8000,
        'average_cost' => '8.000000',
        'stock_quantity' => 50,
        'inventory_type' => $inventoryType,
        'base_unit' => BaseUnit::PIECE,
        'track_stock' => true,
        'is_active' => true,
    ]);
}

// ═══════════════════════════════════════════════════════
// Sales Report with HPP
// ═══════════════════════════════════════════════════════

test('sales report menampilkan data transaksi', function () {
    [$store, $user] = makeReportOrg('SR1');
    $product = createReportProduct($store, 'Es Teh');

    $shift = CashShift::create([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'opening_balance' => 0,
        'status' => 'open',
    ]);

    $txn = Transaction::create([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'cash_shift_id' => $shift->id,
        'status' => 'completed',
        'payment_method' => 'cash',
        'subtotal' => 20000,
        'total_amount' => 20000,
        'amount_paid' => 20000,
        'change_amount' => 0,
        'completed_at' => now(),
    ]);

    TransactionItem::create([
        'transaction_id' => $txn->id,
        'product_id' => $product->id,
        'product_name' => 'Es Teh',
        'quantity' => '1.0000',
        'unit_price' => '20000.00',
        'total_price' => '20000.00',
        'unit_cost_snapshot' => '8.000000',
        'total_cost_snapshot' => '8.00',
        'gross_profit_snapshot' => '12.00',
    ]);

    $this->actingAs($user);
    $response = $this->get('/reports/sales');
    $response->assertStatus(200);

    $page = $response->viewData('page');
    // Inertia render returns the props as a nested array under 'props'
    $props = data_get($page, 'props', $page);
    expect($props)->toHaveKey('hppSummary')
        ->and((int) $props['hppSummary']['items_with_hpp'])->toBe(1);
});

test('sales report dengan filter tanggal', function () {
    [$store, $user] = makeReportOrg('SR2');

    $this->actingAs($user);
    $response = $this->get('/reports/sales?start_date=2026-01-01&end_date=2026-12-31');
    $response->assertStatus(200);
});

// ═══════════════════════════════════════════════════════
// Production Report
// ═══════════════════════════════════════════════════════

test('production report menampilkan data batch', function () {
    [$store, $user] = makeReportOrg('PR1');

    $output = createReportProduct($store, 'Biang Teh', InventoryType::PRODUCTION_OUTPUT);
    $output->update(['base_unit' => BaseUnit::MILLILITER]);

    $recipe = Recipe::create([
        'store_id' => $store->id,
        'name' => 'Resep Teh',
        'code' => 'R-PR1-'.uniqid(),
        'product_id' => $output->id,
        'output_quantity' => '1000.0000',
        'output_unit' => BaseUnit::MILLILITER,
        'is_active' => true,
    ]);

    ProductionBatch::create([
        'store_id' => $store->id,
        'product_id' => $output->id,
        'recipe_id' => $recipe->id,
        'status' => ProductionBatch::STATUS_COMPLETED,
        'multiplier' => '2.0000',
        'output_quantity_initial' => '2000.0000',
        'output_quantity_remaining' => '2000.0000',
        'total_cost' => 50000,
        'unit_cost' => '25.000000',
        'produced_by' => $user->id,
        'produced_at' => now(),
        'recipe_name' => $recipe->name,
        'recipe_code' => $recipe->code,
        'recipe_version' => 1,
        'recipe_output_quantity' => '1000.0000',
        'recipe_output_unit' => BaseUnit::MILLILITER,
    ]);

    $this->actingAs($user);
    $response = $this->get('/reports/production');
    $response->assertStatus(200);

    $props = $response->viewData('page')['props'];
    expect($props['summary']['total_batches'])->toBe(1);
    expect((float) $props['summary']['total_output'])->toBe(2000.0);
    expect((float) $props['summary']['total_cost'])->toBe(50000.0);
    expect((float) $props['summary']['avg_cost_per_unit'])->toBe(25.0);
});

test('production report dengan filter tanggal', function () {
    [$store, $user] = makeReportOrg('PR2');

    $this->actingAs($user);
    $response = $this->get('/reports/production?start_date=2026-01-01&end_date=2026-12-31');
    $response->assertStatus(200);
});

test('laporan keuangan memvalidasi tanggal dan menghitung saldo serta laba', function () {
    [$store, $user] = makeReportOrg('FR1');

    $cash = \App\Models\ChartOfAccount::create(['store_id' => $store->id, 'code' => '1.1.99', 'name' => 'Kas uji', 'type' => 'asset']);
    $revenue = \App\Models\ChartOfAccount::create(['store_id' => $store->id, 'code' => '4.1.99', 'name' => 'Pendapatan uji', 'type' => 'revenue']);
    $expense = \App\Models\ChartOfAccount::create(['store_id' => $store->id, 'code' => '6.1.99', 'name' => 'Beban uji', 'type' => 'expense']);

    $opening = \App\Models\JournalEntry::create(['store_id' => $store->id, 'user_id' => $user->id, 'entry_date' => '2026-01-01', 'description' => 'Saldo awal']);
    \App\Models\JournalLine::insert([
        ['journal_entry_id' => $opening->id, 'chart_of_account_id' => $cash->id, 'debit' => 100000, 'credit' => 0],
        ['journal_entry_id' => $opening->id, 'chart_of_account_id' => $revenue->id, 'debit' => 0, 'credit' => 100000],
    ]);
    $period = \App\Models\JournalEntry::create(['store_id' => $store->id, 'user_id' => $user->id, 'entry_date' => '2026-02-01', 'description' => 'Beban']);
    \App\Models\JournalLine::insert([
        ['journal_entry_id' => $period->id, 'chart_of_account_id' => $expense->id, 'debit' => 25000, 'credit' => 0],
        ['journal_entry_id' => $period->id, 'chart_of_account_id' => $cash->id, 'debit' => 0, 'credit' => 25000],
    ]);

    $this->actingAs($user);
    $trialBalance = $this->get('/reports/financial/trial-balance?start_date=2026-02-01&end_date=2026-02-28');
    $trialBalance->assertOk();
    $trialProps = $trialBalance->viewData('page')['props'];
    expect(collect($trialProps['lines'])->firstWhere('code', '1.1.99'))->toMatchArray([
        'opening_balance' => 100000.0,
        'credit' => 25000.0,
        'closing_balance' => 75000.0,
    ]);

    $income = $this->get('/reports/financial/income-statement?start_date=2026-02-01&end_date=2026-02-28');
    $income->assertOk();
    $incomeProps = $income->viewData('page')['props'];
    expect($incomeProps['amounts'])->toMatchArray([
        'operating_income' => -25000.0,
        'income_before_tax' => -25000.0,
        'tax_expense' => 0.0,
        'net_income' => -25000.0,
    ]);

    $this->get('/reports/financial/trial-balance?start_date=2026-02-28&end_date=2026-02-01')->assertSessionHasErrors('end_date');
    $this->get('/reports/financial/balance-sheet?as_of=not-a-date')->assertSessionHasErrors('as_of');
    $this->get('/reports/financial/cash-flow?start_date=2026-02-28&end_date=2026-02-01')->assertSessionHasErrors('end_date');
    $this->get('/reports/financial/statement-of-equity-changes?start_date=2026-02-28&end_date=2026-02-01')->assertSessionHasErrors('end_date');
    $this->get('/tax/ppn-summary?start_date=2026-02-28&end_date=2026-02-01')->assertSessionHasErrors('end_date');

    // Validasi juga berlaku untuk laporan non-keuangan
    $this->get('/reports?start_date=2026-02-28&end_date=2026-02-01')->assertSessionHasErrors('end_date');
    $this->get('/reports/sales?start_date=2026-02-28&end_date=2026-02-01')->assertSessionHasErrors('end_date');
    $this->get('/reports/purchases?start_date=2026-02-28&end_date=2026-02-01')->assertSessionHasErrors('end_date');
    $this->get('/reports/production?start_date=2026-02-28&end_date=2026-02-01')->assertSessionHasErrors('end_date');
    $this->get('/reports?start_date=not-a-date')->assertSessionHasErrors('start_date');
});

test('laba rugi membandingkan periode sebelumnya dan margin', function () {
    [$store, $user] = makeReportOrg('FR2');

    $kas = ChartOfAccount::create(['store_id' => $store->id, 'code' => '1.1.98', 'name' => 'Kas', 'type' => 'asset']);
    $revenue = ChartOfAccount::create(['store_id' => $store->id, 'code' => '4.1.98', 'name' => 'Pendapatan', 'type' => 'revenue']);
    $cogs = ChartOfAccount::create(['store_id' => $store->id, 'code' => '5.1.98', 'name' => 'HPP', 'type' => 'cogs']);
    $expense = ChartOfAccount::create(['store_id' => $store->id, 'code' => '6.1.98', 'name' => 'Beban', 'type' => 'expense']);

    // Periode sebelumnya: pendapatan 80.000.
    $prev = JournalEntry::create(['store_id' => $store->id, 'user_id' => $user->id, 'entry_date' => '2026-01-15', 'description' => 'Pendapatan periode lalu']);
    JournalLine::insert([
        ['journal_entry_id' => $prev->id, 'chart_of_account_id' => $kas->id, 'debit' => 80000, 'credit' => 0],
        ['journal_entry_id' => $prev->id, 'chart_of_account_id' => $revenue->id, 'debit' => 0, 'credit' => 80000],
    ]);

    // Periode berjalan: pendapatan 100.000, HPP 40.000, beban 10.000.
    $current = JournalEntry::create(['store_id' => $store->id, 'user_id' => $user->id, 'entry_date' => '2026-02-10', 'description' => 'Aktivitas berjalan']);
    JournalLine::insert([
        ['journal_entry_id' => $current->id, 'chart_of_account_id' => $kas->id, 'debit' => 100000, 'credit' => 0],
        ['journal_entry_id' => $current->id, 'chart_of_account_id' => $revenue->id, 'debit' => 0, 'credit' => 100000],
        ['journal_entry_id' => $current->id, 'chart_of_account_id' => $cogs->id, 'debit' => 40000, 'credit' => 0],
        ['journal_entry_id' => $current->id, 'chart_of_account_id' => $kas->id, 'debit' => 0, 'credit' => 40000],
        ['journal_entry_id' => $current->id, 'chart_of_account_id' => $expense->id, 'debit' => 10000, 'credit' => 0],
        ['journal_entry_id' => $current->id, 'chart_of_account_id' => $kas->id, 'debit' => 0, 'credit' => 10000],
    ]);

    $this->actingAs($user);
    $response = $this->get('/reports/financial/income-statement?start_date=2026-02-01&end_date=2026-02-28');
    $response->assertOk();
    $props = $response->viewData('page')['props'];

    expect($props['amounts']['gross_profit'])->toBe(60000.0)
        ->and($props['amounts']['net_income'])->toBe(50000.0)
        ->and($props['margins']['gross_margin'])->toBe(60.0)
        ->and($props['margins']['net_margin'])->toBe(50.0)
        ->and($props['comparison']['prev_revenue'])->toBe(80000.0)
        ->and($props['comparison']['revenue_change_pct'])->toBe(25.0)
        ->and($props['comparison']['net_income_change_pct'])->toBe(-37.5);
});

test('laba rugi memisahkan beban pajak dari beban operasional', function () {
    [$store, $user] = makeReportOrg('FR3');

    $kas = ChartOfAccount::create(['store_id' => $store->id, 'code' => '1.1.97', 'name' => 'Kas', 'type' => 'asset']);
    $revenue = ChartOfAccount::create(['store_id' => $store->id, 'code' => '4.1.97', 'name' => 'Pendapatan', 'type' => 'revenue']);
    $cogs = ChartOfAccount::create(['store_id' => $store->id, 'code' => '5.1.97', 'name' => 'HPP', 'type' => 'cogs']);
    $expense = ChartOfAccount::create(['store_id' => $store->id, 'code' => '6.1.97', 'name' => 'Beban operasional', 'type' => 'expense']);
    // Akun 6.1.03 sudah di-seed oleh makeReportOrg, ambil yang sudah ada
    $taxExpense = ChartOfAccount::where('store_id', $store->id)->where('code', '6.1.03')->first();

    // Periode berjalan: pendapatan 100.000, HPP 40.000, beban 10.000, pajak 5.000
    $entry = JournalEntry::create(['store_id' => $store->id, 'user_id' => $user->id, 'entry_date' => '2026-02-15', 'description' => 'Aktivitas berjalan']);
    JournalLine::insert([
        ['journal_entry_id' => $entry->id, 'chart_of_account_id' => $kas->id, 'debit' => 100000, 'credit' => 0],
        ['journal_entry_id' => $entry->id, 'chart_of_account_id' => $revenue->id, 'debit' => 0, 'credit' => 100000],
        ['journal_entry_id' => $entry->id, 'chart_of_account_id' => $cogs->id, 'debit' => 40000, 'credit' => 0],
        ['journal_entry_id' => $entry->id, 'chart_of_account_id' => $kas->id, 'debit' => 0, 'credit' => 40000],
        ['journal_entry_id' => $entry->id, 'chart_of_account_id' => $expense->id, 'debit' => 10000, 'credit' => 0],
        ['journal_entry_id' => $entry->id, 'chart_of_account_id' => $kas->id, 'debit' => 0, 'credit' => 10000],
        ['journal_entry_id' => $entry->id, 'chart_of_account_id' => $taxExpense->id, 'debit' => 5000, 'credit' => 0],
        ['journal_entry_id' => $entry->id, 'chart_of_account_id' => $kas->id, 'debit' => 0, 'credit' => 5000],
    ]);

    $this->actingAs($user);
    $response = $this->get('/reports/financial/income-statement?start_date=2026-02-01&end_date=2026-02-28');
    $response->assertOk();
    $props = $response->viewData('page')['props'];

    // Harusnya pajak terpisah dari beban operasional
    expect($props['amounts']['expense'])->toBe(10000.0)
        ->and($props['amounts']['tax_expense'])->toBe(5000.0)
        ->and($props['amounts']['operating_income'])->toBe(50000.0) // 100k - 40k - 10k
        ->and($props['amounts']['net_income'])->toBe(45000.0); // 50k - 5k
});

test('neraca mengelompokkan akun dan menghitung subtotal', function () {
    [$store, $user] = makeReportOrg('BS1');

    $accounts = collect([
        ['code' => '1.1.90', 'name' => 'Kas lancar', 'type' => 'asset', 'balance_sheet_group' => 'current_asset'],
        ['code' => '1.2.90', 'name' => 'Peralatan', 'type' => 'asset', 'balance_sheet_group' => 'non_current_asset'],
        ['code' => '2.1.90', 'name' => 'Utang lancar', 'type' => 'liability', 'balance_sheet_group' => 'current_liability'],
        ['code' => '2.2.90', 'name' => 'Utang jangka panjang', 'type' => 'liability', 'balance_sheet_group' => 'non_current_liability'],
        ['code' => '3.1.90', 'name' => 'Modal uji', 'type' => 'equity', 'balance_sheet_group' => 'equity'],
    ])->map(fn (array $account) => \App\Models\ChartOfAccount::create($account + ['store_id' => $store->id]));

    $entry = JournalEntry::create([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'entry_date' => '2026-08-20',
        'description' => 'Saldo neraca',
    ]);
    JournalLine::insert([
        ['journal_entry_id' => $entry->id, 'chart_of_account_id' => $accounts[0]->id, 'debit' => 100000, 'credit' => 0],
        ['journal_entry_id' => $entry->id, 'chart_of_account_id' => $accounts[1]->id, 'debit' => 200000, 'credit' => 0],
        ['journal_entry_id' => $entry->id, 'chart_of_account_id' => $accounts[2]->id, 'debit' => 0, 'credit' => 50000],
        ['journal_entry_id' => $entry->id, 'chart_of_account_id' => $accounts[3]->id, 'debit' => 0, 'credit' => 100000],
        ['journal_entry_id' => $entry->id, 'chart_of_account_id' => $accounts[4]->id, 'debit' => 0, 'credit' => 150000],
    ]);

    $this->actingAs($user);
    $response = $this->get('/reports/financial/balance-sheet?as_of=2026-08-31');
    $response->assertOk();
    $props = $response->viewData('page')['props'];

    expect($props['assets']['current_asset']['subtotal'])->toBe(100000.0)
        ->and($props['assets']['non_current_asset']['subtotal'])->toBe(200000.0)
        ->and($props['liabilities']['current_liability']['subtotal'])->toBe(50000.0)
        ->and($props['liabilities']['non_current_liability']['subtotal'])->toBe(100000.0)
        ->and($props['equity']['subtotal'])->toBe(150000.0)
        ->and($props['totals']['balanced'])->toBeTrue();
});

// ═══════════════════════════════════════════════════════
// JournalPoster — kas masuk/keluar
// ═══════════════════════════════════════════════════════

test('posting kas masuk revenue menghasilkan jurnal seimbang', function () {
    [$store, $user] = makeReportOrg('CT1');

    $shift = CashShift::create([
        'store_id'     => $store->id,
        'user_id'      => $user->id,
        'opening_cash' => 0,
        'shift_number' => 1,
    ]);

    $ct = CashTransaction::create([
        'store_id'      => $store->id,
        'user_id'       => $user->id,
        'cash_shift_id' => $shift->id,
        'type'          => CashTransaction::TYPE_IN,
        'category'      => CashTransaction::CATEGORY_REVENUE,
        'amount'        => 50000,
        'notes'         => 'Jasa titip',
    ]);

    JournalPoster::postCashTransaction($store, $ct);

    $ct->refresh();
    expect($ct->journal_entry_id)->not->toBeNull();

    $entry = $ct->journalEntry()->with('lines')->first();
    $totalDebit  = $entry->lines->sum('debit');
    $totalCredit = $entry->lines->sum('credit');
    expect($totalDebit)->toEqual($totalCredit);
    expect(count($entry->lines))->toBe(2);
});

test('posting kas keluar expense menghasilkan jurnal seimbang', function () {
    [$store, $user] = makeReportOrg('CT2');

    $shift = CashShift::create([
        'store_id'     => $store->id,
        'user_id'      => $user->id,
        'opening_cash' => 0,
        'shift_number' => 1,
    ]);

    $ct = CashTransaction::create([
        'store_id'      => $store->id,
        'user_id'       => $user->id,
        'cash_shift_id' => $shift->id,
        'type'          => CashTransaction::TYPE_OUT,
        'category'      => CashTransaction::CATEGORY_EXPENSE,
        'amount'        => 15000,
        'notes'         => 'Beli perlengkapan',
    ]);

    JournalPoster::postCashTransaction($store, $ct);

    $ct->refresh();
    $entry = $ct->journalEntry()->with('lines')->first();
    $totalDebit  = $entry->lines->sum('debit');
    $totalCredit = $entry->lines->sum('credit');
    expect($totalDebit)->toEqual($totalCredit);
});

test('posting ulang tidak membuat duplikat jurnal', function () {
    [$store, $user] = makeReportOrg('CT3');

    $shift = CashShift::create([
        'store_id'     => $store->id,
        'user_id'      => $user->id,
        'opening_cash' => 0,
        'shift_number' => 1,
    ]);

    $ct = CashTransaction::create([
        'store_id'      => $store->id,
        'user_id'       => $user->id,
        'cash_shift_id' => $shift->id,
        'type'          => CashTransaction::TYPE_IN,
        'category'      => CashTransaction::CATEGORY_NON_REVENUE,
        'amount'        => 100000,
        'notes'         => 'Setoran modal',
    ]);

    JournalPoster::postCashTransaction($store, $ct);
    JournalPoster::postCashTransaction($store, $ct); // dipanggil 2x

    $count = \App\Models\JournalEntry::query()
        ->where('store_id', $store->id)
        ->where('reference_type', CashTransaction::class)
        ->where('reference_id', $ct->id)
        ->count();

    expect($count)->toBe(1);
});

test('tutup buku memindahkan laba ke saldo laba dan mengunci periode', function () {
    [$store, $user] = makeReportOrg('AP1');
    ChartOfAccountsBootstrap::ensureForStore($store);
    $pendapatan = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_PENDAPATAN_PENJUALAN);
    $kas = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_KAS);

    $source = JournalEntry::create([
        'store_id' => $store->id,
        'entry_date' => '2026-08-10',
        'description' => 'Pendapatan periode',
        'user_id' => $user->id,
    ]);
    JournalLine::create(['journal_entry_id' => $source->id, 'chart_of_account_id' => $kas->id, 'debit' => 100000, 'credit' => 0]);
    JournalLine::create(['journal_entry_id' => $source->id, 'chart_of_account_id' => $pendapatan->id, 'debit' => 0, 'credit' => 100000]);

    $period = AccountingPeriod::create([
        'store_id' => $store->id,
        'name' => 'Agustus 2026',
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-31',
    ]);

    AccountingPeriodCloser::close($period, $user);

    $period->refresh();
    expect($period->status)->toBe(AccountingPeriod::STATUS_CLOSED);
    expect($period->closing_journal_entry_id)->not->toBeNull();

    $closing = $period->closingJournalEntry()->with('lines.chartOfAccount')->first();
    expect($closing->lines->sum('debit'))->toEqual($closing->lines->sum('credit'));
    expect($closing->lines->pluck('chartOfAccount.code'))->toContain(ChartOfAccountsBootstrap::CODE_SALDO_LABA);
});

test('periode tertutup menolak jurnal baru', function () {
    [$store, $user] = makeReportOrg('AP2');
    AccountingPeriod::create([
        'store_id' => $store->id,
        'name' => 'Agustus 2026',
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-31',
        'status' => AccountingPeriod::STATUS_CLOSED,
    ]);

    expect(fn () => JournalPoster::postCapitalInjection($store, 50000, '2026-08-15', $user->id))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

test('periode tertutup menolak biaya operasional', function () {
    [$store, $user] = makeReportOrg('AP3');
    AccountingPeriod::create([
        'store_id' => $store->id,
        'name' => 'Agustus 2026',
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-31',
        'status' => AccountingPeriod::STATUS_CLOSED,
    ]);

    $this->actingAs($user)
        ->post('/expenses', [
            'category' => 'listrik',
            'description' => 'Biaya periode tertutup',
            'amount' => 10000,
            'expense_date' => '2026-08-15',
        ])
        ->assertSessionHasErrors('entry_date');

    expect(\App\Models\OperationalExpense::query()->where('store_id', $store->id)->exists())->toBeFalse();
});

test('hapus transaksi pada periode tertutup ditolak', function () {
    [$store, $user] = makeReportOrg('AP4');
    AccountingPeriod::create([
        'store_id' => $store->id,
        'name' => 'Agustus 2026',
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-31',
        'status' => AccountingPeriod::STATUS_CLOSED,
    ]);

    $shift = CashShift::create([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'opening_balance' => 0,
        'status' => 'open',
    ]);

    $txn = Transaction::create([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'cash_shift_id' => $shift->id,
        'status' => 'completed',
        'payment_method' => 'cash',
        'subtotal' => 20000,
        'total_amount' => 20000,
        'amount_paid' => 20000,
        'change_amount' => 0,
        'completed_at' => '2026-08-15 00:00:00',
    ]);

    JournalEntry::create([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'entry_date' => '2026-08-15',
        'reference_type' => Transaction::class,
        'reference_id' => $txn->id,
        'description' => 'Penjualan '.$txn->transaction_number,
    ]);

    $this->actingAs($user)
        ->delete("/transactions/{$txn->id}")
        ->assertSessionHasErrors('entry_date');

    $this->assertDatabaseHas('transactions', ['id' => $txn->id]);
});

test('hapus pembelian pada periode tertutup ditolak', function () {
    [$store, $user] = makeReportOrg('AP5');
    AccountingPeriod::create([
        'store_id' => $store->id,
        'name' => 'Agustus 2026',
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-31',
        'status' => AccountingPeriod::STATUS_CLOSED,
    ]);

    $purchase = Purchase::create([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'purchased_at' => '2026-08-01',
        'is_paid' => true,
        'amount_paid' => 100000,
        'total_amount' => 100000,
    ]);

    JournalEntry::create([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'entry_date' => '2026-08-01',
        'reference_type' => Purchase::class,
        'reference_id' => $purchase->id,
        'description' => 'Pembelian '.$purchase->purchase_number,
    ]);

    $this->actingAs($user)
        ->delete("/purchases/{$purchase->id}")
        ->assertSessionHasErrors('entry_date');

    $this->assertDatabaseHas('purchases', ['id' => $purchase->id]);
});

test('pelunasan pembelian pada periode tertutup ditolak', function () {
    [$store, $user] = makeReportOrg('AP6');
    AccountingPeriod::create([
        'store_id' => $store->id,
        'name' => 'Agustus 2026',
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-31',
        'status' => AccountingPeriod::STATUS_CLOSED,
    ]);

    $purchase = Purchase::create([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'purchased_at' => '2026-08-01',
        'is_paid' => false,
        'amount_paid' => 0,
        'total_amount' => 100000,
    ]);

    $this->actingAs($user)
        ->post('/purchase-payments', [
            'purchase_id' => $purchase->id,
            'amount' => 40000,
            'payment_method' => 'cash',
            'payment_date' => '2026-08-10',
        ])
        ->assertSessionHasErrors('entry_date');

    expect(PurchasePayment::query()->where('purchase_id', $purchase->id)->exists())->toBeFalse();
});

test('pembatalan penyesuaian stok pada periode tertutup ditolak', function () {
    [$store, $user] = makeReportOrg('AP7');
    AccountingPeriod::create([
        'store_id' => $store->id,
        'name' => 'Agustus 2026',
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-31',
        'status' => AccountingPeriod::STATUS_CLOSED,
    ]);

    $document = StockAdjustment::create([
        'store_id' => $store->id,
        'type' => StockAdjustment::TYPE_ADJUSTMENT,
        'reason_code' => StockAdjustment::REASON_CORRECTION,
        'status' => StockAdjustment::STATUS_POSTED,
        'created_by' => $user->id,
        'posted_by' => $user->id,
        'posted_at' => '2026-08-15 00:00:00',
        'total_variance_value' => -5000,
    ]);

    JournalEntry::create([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'entry_date' => '2026-08-15',
        'reference_type' => StockAdjustment::class,
        'reference_id' => $document->id,
        'description' => 'Penyesuaian stok '.$document->adjustment_number,
    ]);

    $this->actingAs($user)
        ->post("/stock-adjustments/{$document->id}/cancel", [
            'cancellation_reason' => 'Salah input',
        ])
        ->assertSessionHasErrors('cancellation_reason');

    $this->assertDatabaseHas('stock_adjustments', [
        'id' => $document->id,
        'status' => StockAdjustment::STATUS_POSTED,
    ]);
});

test('cicilan pembelian membuat jurnal unik untuk setiap pelunasan', function () {
    [$store, $user] = makeReportOrg('PP1');
    $purchase = Purchase::create([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'purchased_at' => '2026-08-01',
        'is_paid' => false,
        'amount_paid' => 0,
        'total_amount' => 100000,
    ]);

    $this->actingAs($user)
        ->post('/purchase-payments', [
            'purchase_id' => $purchase->id,
            'amount' => 40000,
            'payment_method' => 'cash',
            'payment_date' => '2026-08-10',
        ])
        ->assertRedirect();

    $this->post('/purchase-payments', [
        'purchase_id' => $purchase->id,
        'amount' => 60000,
        'payment_method' => 'transfer',
        'payment_date' => '2026-08-11',
    ])->assertRedirect();

    $purchase->refresh();
    $payments = PurchasePayment::query()->where('purchase_id', $purchase->id)->get();
    $entries = JournalEntry::query()
        ->where('reference_type', PurchasePayment::class)
        ->whereIn('reference_id', $payments->modelKeys())
        ->with('lines')
        ->get();

    expect($purchase->is_paid)->toBeTrue()
        ->and((float) $purchase->amount_paid)->toBe(100000.0)
        ->and($payments)->toHaveCount(2)
        ->and($entries)->toHaveCount(2)
        ->and($entries->every(fn (JournalEntry $entry) => $entry->lines->sum('debit') === $entry->lines->sum('credit')))->toBeTrue();
});

test('rekonsiliasi membandingkan saldo buku dengan fisik', function () {
    [$store, $user] = makeReportOrg('RC1');
    ChartOfAccountsBootstrap::ensureForStore($store);

    $kas = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_KAS);
    $persediaan = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_PERSEDIAAN);
    $utang = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_UTANG_USAHA);
    $modal = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_MODAL);

    $entry = JournalEntry::create([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'entry_date' => '2026-08-20',
        'description' => 'Saldo rekonsiliasi',
    ]);
    JournalLine::insert([
        ['journal_entry_id' => $entry->id, 'chart_of_account_id' => $kas->id, 'debit' => 100000, 'credit' => 0],
        ['journal_entry_id' => $entry->id, 'chart_of_account_id' => $persediaan->id, 'debit' => 40000, 'credit' => 0],
        ['journal_entry_id' => $entry->id, 'chart_of_account_id' => $utang->id, 'debit' => 0, 'credit' => 40000],
        ['journal_entry_id' => $entry->id, 'chart_of_account_id' => $modal->id, 'debit' => 0, 'credit' => 100000],
    ]);

    CashShift::create([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'opening_cash' => 0,
        'closing_cash_counted' => 100000,
        'closed_at' => '2026-08-20 22:00:00',
    ]);

    Purchase::create([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'purchased_at' => '2026-08-20',
        'is_paid' => false,
        'amount_paid' => 0,
        'total_amount' => 40000,
    ]);

    $product = createReportProduct($store, 'Bahan Rekonsiliasi');
    $product->update(['stock_quantity' => '5000.0000', 'average_cost' => '8.000000']);

    $this->actingAs($user);
    $response = $this->get('/reports/financial/reconciliation?as_of=2026-08-31');
    $response->assertOk();
    $props = $response->viewData('page')['props'];

    $rows = collect($props['rows'])->keyBy('code');
    expect($rows[ChartOfAccountsBootstrap::CODE_KAS]['status'])->toBe('cocok')
        ->and($rows[ChartOfAccountsBootstrap::CODE_KAS]['book_balance'])->toBe(100000.0)
        ->and($rows[ChartOfAccountsBootstrap::CODE_UTANG_USAHA]['status'])->toBe('cocok')
        ->and($rows[ChartOfAccountsBootstrap::CODE_PERSEDIAAN]['status'])->toBe('cocok')
        ->and($rows[ChartOfAccountsBootstrap::CODE_BANK]['status'])->toBe('belum_tersedia')
        ->and($rows[ChartOfAccountsBootstrap::CODE_PIUTANG_USAHA]['status'])->toBe('belum_tersedia')
        ->and($props['summary'])->toMatchArray([
            'cocok' => 3,
            'selisih' => 0,
            'belum_tersedia' => 2,
        ]);
});

test('laporan perubahan ekuitas menghitung saldo awal, perubahan, dan saldo akhir', function () {
    [$store, $user] = makeReportOrg('EQ1');
    ChartOfAccountsBootstrap::ensureForStore($store);

    $kas = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_KAS);
    $modal = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_MODAL);
    $pendapatan = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_PENDAPATAN_PENJUALAN);
    $beban = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_BEBAN_OPERASIONAL);

    // Saldo awal: modal 1.000.000 (sebelum periode).
    $opening = JournalEntry::create([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'entry_date' => '2025-12-31',
        'description' => 'Modal awal',
    ]);
    JournalLine::insert([
        ['journal_entry_id' => $opening->id, 'chart_of_account_id' => $kas->id, 'debit' => 1000000, 'credit' => 0],
        ['journal_entry_id' => $opening->id, 'chart_of_account_id' => $modal->id, 'debit' => 0, 'credit' => 1000000],
    ]);

    // Dalam periode: setoran modal 200.000 + pendapatan 500.000 + beban 100.000.
    $period = JournalEntry::create([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'entry_date' => '2026-08-15',
        'description' => 'Aktivitas periode',
    ]);
    JournalLine::insert([
        ['journal_entry_id' => $period->id, 'chart_of_account_id' => $kas->id, 'debit' => 200000, 'credit' => 0],
        ['journal_entry_id' => $period->id, 'chart_of_account_id' => $modal->id, 'debit' => 0, 'credit' => 200000],
    ]);

    $revenue = JournalEntry::create([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'entry_date' => '2026-08-16',
        'description' => 'Pendapatan',
    ]);
    JournalLine::insert([
        ['journal_entry_id' => $revenue->id, 'chart_of_account_id' => $kas->id, 'debit' => 500000, 'credit' => 0],
        ['journal_entry_id' => $revenue->id, 'chart_of_account_id' => $pendapatan->id, 'debit' => 0, 'credit' => 500000],
    ]);

    $expense = JournalEntry::create([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'entry_date' => '2026-08-17',
        'description' => 'Beban',
    ]);
    JournalLine::insert([
        ['journal_entry_id' => $expense->id, 'chart_of_account_id' => $beban->id, 'debit' => 100000, 'credit' => 0],
        ['journal_entry_id' => $expense->id, 'chart_of_account_id' => $kas->id, 'debit' => 0, 'credit' => 100000],
    ]);

    $this->actingAs($user);
    $response = $this->get('/reports/financial/statement-of-equity-changes?start_date=2026-08-01&end_date=2026-08-31');
    $response->assertOk();
    $props = $response->viewData('page')['props'];

    expect($props['rows'])->toMatchArray([
        'opening_modal' => 1000000.0,
        'opening_saldo_laba' => 0.0,
        'opening_total' => 1000000.0,
        'setoran_modal' => 200000.0,
        'penarikan_modal' => 0.0,
        'laba_bersih' => 400000.0,
        'closing_modal' => 1200000.0,
        'closing_saldo_laba' => 400000.0,
        'closing_total' => 1600000.0,
    ]);
});

test('arus kas akuntansi mengelompokkan mutasi kas ke operasi, investasi, dan pendanaan', function () {
    [$store, $user] = makeReportOrg('CF1');
    ChartOfAccountsBootstrap::ensureForStore($store);

    $kas = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_KAS);
    $pendapatan = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_PENDAPATAN_PENJUALAN);
    $beban = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_BEBAN_OPERASIONAL);
    $modal = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_MODAL);

    // Operasi: penjualan tunai 500.000.
    $sale = JournalEntry::create([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'entry_date' => '2026-08-10',
        'description' => 'Penjualan tunai',
    ]);
    JournalLine::insert([
        ['journal_entry_id' => $sale->id, 'chart_of_account_id' => $kas->id, 'debit' => 500000, 'credit' => 0],
        ['journal_entry_id' => $sale->id, 'chart_of_account_id' => $pendapatan->id, 'debit' => 0, 'credit' => 500000],
    ]);

    // Operasi: beban 100.000.
    $expense = JournalEntry::create([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'entry_date' => '2026-08-11',
        'description' => 'Beban operasional',
    ]);
    JournalLine::insert([
        ['journal_entry_id' => $expense->id, 'chart_of_account_id' => $beban->id, 'debit' => 100000, 'credit' => 0],
        ['journal_entry_id' => $expense->id, 'chart_of_account_id' => $kas->id, 'debit' => 0, 'credit' => 100000],
    ]);

    // Pendanaan: setoran modal 200.000.
    $capital = JournalEntry::create([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'entry_date' => '2026-08-12',
        'description' => 'Setoran modal',
    ]);
    JournalLine::insert([
        ['journal_entry_id' => $capital->id, 'chart_of_account_id' => $kas->id, 'debit' => 200000, 'credit' => 0],
        ['journal_entry_id' => $capital->id, 'chart_of_account_id' => $modal->id, 'debit' => 0, 'credit' => 200000],
    ]);

    $this->actingAs($user);
    $response = $this->get('/reports/financial/cash-flow?start_date=2026-08-01&end_date=2026-08-31');
    $response->assertOk();
    $props = $response->viewData('page')['props'];

    expect($props['operating'])->toMatchArray([
        'inflow' => 500000.0,
        'outflow' => 100000.0,
        'net' => 400000.0,
    ])
        ->and($props['financing'])->toMatchArray([
            'inflow' => 200000.0,
            'outflow' => 0.0,
            'net' => 200000.0,
        ])
        ->and($props['investing']['net'])->toBe(0.0)
        ->and($props['netIncrease'])->toBe(600000.0);
});

test('calk menampilkan saldo akun penting dari jurnal', function () {
    [$store, $user] = makeReportOrg('CL1');
    ChartOfAccountsBootstrap::ensureForStore($store);

    $kas = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_KAS);
    $modal = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_MODAL);
    $utang = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_UTANG_USAHA);

    // Setoran modal 1.000.000.
    $capital = JournalEntry::create([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'entry_date' => '2026-08-01',
        'description' => 'Setoran modal',
    ]);
    JournalLine::insert([
        ['journal_entry_id' => $capital->id, 'chart_of_account_id' => $kas->id, 'debit' => 1000000, 'credit' => 0],
        ['journal_entry_id' => $capital->id, 'chart_of_account_id' => $modal->id, 'debit' => 0, 'credit' => 1000000],
    ]);

    // Pembelian kredit (utang) 300.000.
    $purchase = JournalEntry::create([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'entry_date' => '2026-08-02',
        'description' => 'Pembelian kredit',
    ]);
    JournalLine::insert([
        ['journal_entry_id' => $purchase->id, 'chart_of_account_id' => $kas->id, 'debit' => 300000, 'credit' => 0],
        ['journal_entry_id' => $purchase->id, 'chart_of_account_id' => $utang->id, 'debit' => 0, 'credit' => 300000],
    ]);

    $this->actingAs($user);
    $response = $this->get('/reports/financial/calk?as_of=2026-08-31');
    $response->assertOk();
    $props = $response->viewData('page')['props'];

    expect($props['balances'])->toMatchArray([
        'kas' => 1300000.0,
        'bank' => 0.0,
        'piutang_usaha' => 0.0,
        'persediaan' => 0.0,
        'utang_usaha' => 300000.0,
        'modal' => 1000000.0,
    ]);
});

test('klasifikasi akun lanjutan menyediakan aset tetap, penyusutan, pajak, dan modal disetor', function () {
    [$store] = makeReportOrg('AK1');
    ChartOfAccountsBootstrap::ensureForStore($store);

    $fixed = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_ASET_TETAP);
    $accDep = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_AKUMULASI_PENYUSUTAN);
    $taxPayable = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_UTANG_PAJAK);
    $taxExpense = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_BEBAN_PAJAK);
    $modal = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_MODAL);

    expect($fixed->type)->toBe(ChartOfAccount::TYPE_ASSET)
        ->and($fixed->balance_sheet_group)->toBe(ChartOfAccount::GROUP_NON_CURRENT_ASSET)
        ->and($accDep->balance_sheet_group)->toBe(ChartOfAccount::GROUP_NON_CURRENT_ASSET)
        ->and($taxPayable->type)->toBe(ChartOfAccount::TYPE_LIABILITY)
        ->and($taxPayable->balance_sheet_group)->toBe(ChartOfAccount::GROUP_CURRENT_LIABILITY)
        ->and($taxExpense->type)->toBe(ChartOfAccount::TYPE_EXPENSE)
        ->and($modal->name)->toBe('Modal disetor');
});

test('arus kas mengklasifikasikan pembelian aset tetap sebagai aktivitas investasi', function () {
    [$store, $user] = makeReportOrg('AK2');
    ChartOfAccountsBootstrap::ensureForStore($store);

    $kas = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_KAS);
    $asetTetap = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_ASET_TETAP);

    $entry = JournalEntry::create([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'entry_date' => '2026-08-15',
        'description' => 'Pembelian peralatan',
    ]);
    JournalLine::insert([
        ['journal_entry_id' => $entry->id, 'chart_of_account_id' => $asetTetap->id, 'debit' => 2000000, 'credit' => 0],
        ['journal_entry_id' => $entry->id, 'chart_of_account_id' => $kas->id, 'debit' => 0, 'credit' => 2000000],
    ]);

    $this->actingAs($user);
    $response = $this->get('/reports/financial/cash-flow?start_date=2026-08-01&end_date=2026-08-31');
    $props = $response->viewData('page')['props'];

    expect($props['investing']['outflow'])->toBe(2000000.0)
        ->and($props['operating']['outflow'])->toBe(0.0);
});

// ═══════════════════════════════════════════════════════
// Status Laporan (draft / final / periode ditutup)
// ═══════════════════════════════════════════════════════

test('status laporan default adalah draft', function () {
    [$store, $user] = makeReportOrg('STS1');
    ChartOfAccountsBootstrap::ensureForStore($store);

    $this->actingAs($user);
    $response = $this->get('/reports/financial/income-statement?start_date=2026-08-01&end_date=2026-08-31');
    $props = $response->viewData('page')['props'];

    expect($props['reportStatus'])->toMatchArray([
        'status' => 'draft',
        'label' => 'Draft',
        'finalized_at' => null,
        'finalized_by' => null,
    ]);
});

test('status laporan dapat dijadikan final dan kembali ke draft', function () {
    [$store, $user] = makeReportOrg('STS2');
    ChartOfAccountsBootstrap::ensureForStore($store);

    $this->actingAs($user);
    $this->post('/reports/financial/report-status', [
        'report_type' => 'laba_rugi',
        'period_end' => '2026-08-31',
        'status' => 'final',
    ])->assertSessionHas('success');

    $this->assertDatabaseHas('financial_report_statuses', [
        'store_id' => $store->id,
        'report_type' => 'laba_rugi',
        'period_end' => '2026-08-31 00:00:00',
        'status' => 'final',
        'finalized_by' => $user->id,
    ]);

    $response = $this->get('/reports/financial/income-statement?start_date=2026-08-01&end_date=2026-08-31');
    $props = $response->viewData('page')['props'];

    expect($props['reportStatus']['status'])->toBe('final')
        ->and($props['reportStatus']['finalized_by'])->toBe($user->name);

    $this->post('/reports/financial/report-status', [
        'report_type' => 'laba_rugi',
        'period_end' => '2026-08-31',
        'status' => 'draft',
    ])->assertSessionHas('success');

    $this->assertDatabaseMissing('financial_report_statuses', [
        'store_id' => $store->id,
        'report_type' => 'laba_rugi',
        'period_end' => '2026-08-31',
    ]);
});

test('finalisasi menghasilkan nomor laporan dan identitas dokumen', function () {
    [$store, $user] = makeReportOrg('STS4');
    ChartOfAccountsBootstrap::ensureForStore($store);

    $this->actingAs($user);
    $this->post('/reports/financial/report-status', [
        'report_type' => 'laba_rugi',
        'period_end' => '2026-08-31',
        'status' => 'final',
    ]);

    $this->assertDatabaseHas('financial_report_statuses', [
        'store_id' => $store->id,
        'report_type' => 'laba_rugi',
        'status' => 'final',
        'report_number' => 'LR-202608-0001',
        'prepared_by' => $user->id,
    ]);

    $response = $this->get('/reports/financial/income-statement?start_date=2026-08-01&end_date=2026-08-31');
    $props = $response->viewData('page')['props'];

    expect($props['reportStatus']['report_number'])->toBe('LR-202608-0001')
        ->and($props['reportStatus']['prepared_by'])->toBe($user->name)
        ->and($props['reportStatus']['issued_at'])->not->toBeNull();

    $this->post('/reports/financial/report-reviewer', [
        'report_type' => 'laba_rugi',
        'period_end' => '2026-08-31',
        'reviewed_by' => 'Budi Pemeriksa',
    ])->assertSessionHas('success');

    $response = $this->get('/reports/financial/income-statement?start_date=2026-08-01&end_date=2026-08-31');
    $props = $response->viewData('page')['props'];

    expect($props['reportStatus']['reviewed_by'])->toBe('Budi Pemeriksa');
});

test('status laporan terkunci saat periode ditutup', function () {
    [$store, $user] = makeReportOrg('STS3');
    ChartOfAccountsBootstrap::ensureForStore($store);

    AccountingPeriod::query()->create([
        'store_id' => $store->id,
        'name' => 'Agustus 2026',
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-31',
        'status' => AccountingPeriod::STATUS_CLOSED,
        'closed_at' => now(),
        'closed_by' => $user->id,
    ]);

    $this->actingAs($user);
    $response = $this->get('/reports/financial/income-statement?start_date=2026-08-01&end_date=2026-08-31');
    $props = $response->viewData('page')['props'];

    expect($props['reportStatus'])->toMatchArray([
        'status' => 'closed',
        'label' => 'Periode ditutup',
        'finalized_at' => null,
        'finalized_by' => null,
    ]);

    $this->post('/reports/financial/report-status', [
        'report_type' => 'laba_rugi',
        'period_end' => '2026-08-31',
        'status' => 'final',
    ])->assertSessionHas('error');

    $this->assertDatabaseMissing('financial_report_statuses', [
        'store_id' => $store->id,
        'report_type' => 'laba_rugi',
        'period_end' => '2026-08-31',
    ]);
});
