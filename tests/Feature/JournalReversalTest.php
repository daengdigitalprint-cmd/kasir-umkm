<?php

use App\Models\AccountingPeriod;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Store;
use App\Models\User;
use App\Support\Accounting\ChartOfAccountsBootstrap;
use App\Support\Accounting\JournalPoster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function makeReversalStore(): array
{
    $store = Store::query()->create([
        'name' => 'Toko Pembalik',
        'slug' => 'pembalik-'.uniqid(),
        'is_active' => true,
        'is_subscription_active' => true,
        // Tes ini menguji perilaku posting jurnal → modul harus aktif.
        // Tanggal uji statis (2026-08/09) di masa lalu, longgarkan limit backdate
        // default 7 hari agar lolos AccountingPeriodGuard.
        'settings' => ['accounting_enabled' => true, 'max_backdate_days' => 9999],
    ]);

    $user = User::factory()->create([
        'store_id' => $store->id,
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    return [$store, $user];
}

function makeReversalEntry(Store $store, int $userId): JournalEntry
{
    ChartOfAccountsBootstrap::ensureForStore($store);
    $kas = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_KAS);
    $modal = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_MODAL);

    $entry = JournalEntry::create([
        'store_id' => $store->id,
        'entry_date' => '2026-08-10',
        'reference_type' => 'capital_injection',
        'description' => 'Setoran modal awal',
        'user_id' => $userId,
    ]);

    JournalLine::create(['journal_entry_id' => $entry->id, 'chart_of_account_id' => $kas->id, 'debit' => 50000, 'credit' => 0]);
    JournalLine::create(['journal_entry_id' => $entry->id, 'chart_of_account_id' => $modal->id, 'debit' => 0, 'credit' => 50000]);

    return $entry->fresh();
}

it('membalik debit dan kredit entri asli', function () {
    [$store, $user] = makeReversalStore();
    $original = makeReversalEntry($store, $user->id);

    $reversal = JournalPoster::postReversal($store, $original, '2026-09-01', $user->id, 'Koreksi');

    expect($reversal->reference_type)->toBe('journal_reversal')
        ->and((int) $reversal->reference_id)->toBe($original->id);

    $lines = $reversal->lines()->with('chartOfAccount')->get();
    $kas = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_KAS);
    $modal = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_MODAL);

    $kasLine = $lines->firstWhere('chart_of_account_id', $kas->id);
    $modalLine = $lines->firstWhere('chart_of_account_id', $modal->id);

    expect((float) $kasLine->debit)->toBe(0.0)
        ->and((float) $kasLine->credit)->toBe(50000.0)
        ->and((float) $modalLine->debit)->toBe(50000.0)
        ->and((float) $modalLine->credit)->toBe(0.0);
});

it('jurnal pembalik selalu seimbang', function () {
    [$store, $user] = makeReversalStore();
    $original = makeReversalEntry($store, $user->id);

    $reversal = JournalPoster::postReversal($store, $original, '2026-09-01', $user->id);

    $totalDebit = (float) $reversal->lines()->sum('debit');
    $totalCredit = (float) $reversal->lines()->sum('credit');

    expect($totalDebit)->toBe(50000.0)
        ->and($totalCredit)->toBe(50000.0);
});

it('menolak membalik jurnal pembalik', function () {
    [$store, $user] = makeReversalStore();
    $original = makeReversalEntry($store, $user->id);
    $reversal = JournalPoster::postReversal($store, $original, '2026-09-01', $user->id);

    expect(fn () => JournalPoster::postReversal($store, $reversal, '2026-09-02', $user->id))
        ->toThrow(\RuntimeException::class);
});

it('menolak jurnal pembalik pada periode tertutup', function () {
    [$store, $user] = makeReversalStore();
    $original = makeReversalEntry($store, $user->id);

    AccountingPeriod::create([
        'store_id' => $store->id,
        'name' => 'September 2026',
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-30',
        'status' => AccountingPeriod::STATUS_CLOSED,
    ]);

    expect(fn () => JournalPoster::postReversal($store, $original, '2026-09-15', $user->id))
        ->toThrow(ValidationException::class);
});

it('menampilkan daftar jurnal dan membuat pembalik lewat endpoint', function () {
    [$store, $user] = makeReversalStore();
    $original = makeReversalEntry($store, $user->id);

    $this->actingAs($user)->get('/financial/journal')->assertOk();

    $this->actingAs($user)
        ->post("/financial/journal/{$original->id}/reverse", [
            'entry_date' => '2026-09-01',
            'note' => 'Koreksi salah catat',
        ])
        ->assertRedirect();

    $reversal = JournalEntry::query()
        ->where('reference_type', 'journal_reversal')
        ->where('reference_id', $original->id)
        ->first();

    expect($reversal)->not->toBeNull()
        ->and($reversal->description)->toContain('Setoran modal awal');
});

it('mencatat audit trail saat membuat jurnal pembalik', function () {
    [$store, $user] = makeReversalStore();
    $original = makeReversalEntry($store, $user->id);

    JournalPoster::postReversal($store, $original, '2026-09-01', $user->id, 'Koreksi');

    $log = \App\Models\ActivityLog::query()
        ->where('store_id', $store->id)
        ->where('action', 'journal.reversed')
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->metadata['original_entry_id'])->toBe($original->id)
        ->and($log->metadata['reversal_entry_id'])->not->toBeNull()
        ->and($log->user_id)->toBe($user->id);
});
