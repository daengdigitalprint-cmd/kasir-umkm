<?php

use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\OperationalExpense;
use App\Models\Store;
use App\Models\User;
use App\Support\StoreLocationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function expensePageStore(bool $accounting = false): array
{
    $store = Store::query()->create([
        'name' => 'Toko Biaya',
        'slug' => 'biaya-'.uniqid(),
        'location_type' => StoreLocationType::OUTLET,
        'is_active' => true,
        'is_subscription_active' => true,
        'timezone' => 'Asia/Jakarta',
        'settings' => ['accounting_enabled' => $accounting, 'max_backdate_days' => 9999],
    ]);
    $admin = User::factory()->create(['store_id' => $store->id, 'role' => 'admin', 'email_verified_at' => now()]);
    $store->members()->attach($admin->id, ['role' => 'admin']);

    return [$store, $admin];
}

function expenseRow(Store $store, User $user, string $date, float $amount, string $category = 'sewa', string $desc = 'Biaya'): OperationalExpense
{
    return OperationalExpense::query()->create([
        'store_id' => $store->id, 'user_id' => $user->id, 'category' => $category,
        'description' => $desc, 'amount' => $amount, 'expense_date' => $date, 'payment_method' => 'cash',
    ]);
}

afterEach(fn () => Carbon::setTestNow());

test('ringkasan mengikuti periode (default bulan ini) dan dibandingkan dengan bulan lalu', function () {
    Carbon::setTestNow('2026-11-20 10:00:00');
    [$store, $admin] = expensePageStore();
    expenseRow($store, $admin, '2026-11-05', 300000, 'sewa', 'Sewa ruko');
    expenseRow($store, $admin, '2026-11-18', 100000, 'listrik', 'Token listrik');
    expenseRow($store, $admin, '2026-10-10', 200000, 'sewa');   // bulan lalu, s.d. tgl 20
    expenseRow($store, $admin, '2026-10-25', 999000, 'gaji');   // bulan lalu, lewat tgl 20 → tidak dibandingkan
    expenseRow($store, $admin, '2026-09-01', 50000, 'air');

    $this->actingAs($admin)->get('/expenses')->assertInertia(fn ($page) => $page
        ->where('filters.start_date', '2026-11-01')
        ->where('filters.end_date', '2026-11-20')
        ->where('summary.total', 400000)
        ->where('summary.count', 2)
        ->where('summary.previous_total', 200000)
        ->where('summary.previous_start', '2026-10-01')
        ->where('summary.previous_end', '2026-10-20')
        ->has('byCategory', 2)
        ->where('byCategory.0.category', 'sewa')
        ->where('dailyTotals.2026-11-18', 100000));

    $this->actingAs($admin)->get('/expenses?category=listrik&start_date=2026-11-01&end_date=2026-11-30')
        ->assertInertia(fn ($page) => $page
            ->where('summary.total', 100000)
            ->has('expenses.data', 1)
            ->has('byCategory', 2));

    $this->actingAs($admin)->get('/expenses?q=ruko')
        ->assertInertia(fn ($page) => $page->has('expenses.data', 1)->where('expenses.data.0.description', 'Sewa ruko'));
});

test('biaya bisa diubah dan jurnalnya ikut diperbarui', function () {
    [$store, $admin] = expensePageStore(accounting: true);

    $this->actingAs($admin)->post('/expenses', [
        'category' => 'listrik', 'description' => 'Token', 'amount' => 50000,
        'expense_date' => now()->toDateString(), 'payment_method' => 'cash',
    ])->assertSessionHasNoErrors();

    $expense = OperationalExpense::query()->where('store_id', $store->id)->firstOrFail();
    $oldEntry = $expense->journal_entry_id;
    expect($oldEntry)->not->toBeNull();

    $this->actingAs($admin)->patch("/expenses/{$expense->id}", [
        'category' => 'listrik', 'description' => 'Token listrik', 'amount' => 75000,
        'expense_date' => now()->toDateString(), 'payment_method' => 'ewallet',
    ])->assertSessionHasNoErrors();

    $expense->refresh();
    expect((float) $expense->amount)->toBe(75000.0)
        ->and($expense->payment_method)->toBe('ewallet')
        ->and(JournalEntry::query()->find($oldEntry))->toBeNull()
        ->and((float) JournalLine::query()->where('journal_entry_id', $expense->journal_entry_id)->sum('debit'))->toBe(75000.0);

    $this->actingAs($admin)->delete("/expenses/{$expense->id}")->assertSessionHas('success');
    expect(JournalEntry::query()->where('store_id', $store->id)->count())->toBe(0);
});

test('biaya toko lain tidak bisa diubah', function () {
    [$store, $admin] = expensePageStore();
    [$other, $otherAdmin] = expensePageStore();
    $asing = expenseRow($other, $otherAdmin, now()->toDateString(), 1000);

    $this->actingAs($admin)->patch("/expenses/{$asing->id}", [
        'category' => 'sewa', 'description' => 'X', 'amount' => 1, 'expense_date' => now()->toDateString(),
    ])->assertForbidden();
});
