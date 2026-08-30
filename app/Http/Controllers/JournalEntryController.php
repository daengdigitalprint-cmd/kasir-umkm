<?php

namespace App\Http\Controllers;

use App\Models\JournalEntry;
use App\Support\Accounting\AccountingFeatures;
use App\Support\Accounting\JournalPoster;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class JournalEntryController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        $store = $request->user()->store;
        if (! $store || ! AccountingFeatures::enabled($store)) {
            abort(404);
        }

        $entries = JournalEntry::query()
            ->where('store_id', $store->id)
            ->with(['lines.chartOfAccount', 'user:id,name'])
            ->latest('entry_date')
            ->latest('id')
            ->paginate(50)
            ->through(fn (JournalEntry $entry) => [
                'id' => $entry->id,
                'entry_date' => $entry->entry_date->toDateString(),
                'description' => $entry->description,
                'reference_type' => $entry->reference_type,
                'is_reversal' => $entry->reference_type === 'journal_reversal',
                'reversed_entry_id' => $entry->reference_type === 'journal_reversal' ? $entry->reference_id : null,
                'user' => $entry->user?->name,
                'lines' => $entry->lines->map(fn ($line) => [
                    'account_code' => $line->chartOfAccount?->code,
                    'account_name' => $line->chartOfAccount?->name,
                    'debit' => (float) $line->debit,
                    'credit' => (float) $line->credit,
                    'memo' => $line->memo,
                ]),
            ]);

        return Inertia::render('Reports/JournalEntries', [
            'store' => $store->only('id', 'name', 'currency'),
            'entries' => $entries,
        ]);
    }

    public function reverse(Request $request, JournalEntry $entry): RedirectResponse
    {
        $user = $request->user();
        $store = $user->store;
        abort_unless($store && AccountingFeatures::enabled($store) && (int) $entry->store_id === (int) $store->id, 404);

        if ($entry->reference_type === 'journal_reversal') {
            return back()->with('error', 'Jurnal pembalik tidak dapat dibalik lagi.');
        }

        $validated = $request->validate([
            'entry_date' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        JournalPoster::postReversal(
            $store,
            $entry,
            Carbon::parse($validated['entry_date'])->format('Y-m-d'),
            $user->id,
            $validated['note'] ?? null,
        );

        return back()->with('success', 'Jurnal pembalik berhasil dibuat.');
    }
}
