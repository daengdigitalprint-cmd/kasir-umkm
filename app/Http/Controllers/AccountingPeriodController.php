<?php

namespace App\Http\Controllers;

use App\Models\AccountingPeriod;
use App\Support\Accounting\AccountingFeatures;
use App\Support\Accounting\AccountingPeriodCloser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AccountingPeriodController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        $store = $request->user()->store;
        if (! $store) {
            return redirect()->route('stores.create')->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }
        abort_unless(AccountingFeatures::enabled($store), 404);

        return Inertia::render('Reports/AccountingPeriods', [
            'store' => $store->only('id', 'name', 'currency'),
            'is_owner' => $request->user()->isOwner(),
            'periods' => AccountingPeriod::query()
                ->where('store_id', $store->id)
                ->with('closedBy:id,name', 'reopenedBy:id,name')
                ->latest('end_date')
                ->get()
                ->map(fn (AccountingPeriod $period) => [
                    'id' => $period->id,
                    'name' => $period->name,
                    'start_date' => $period->start_date->toDateString(),
                    'end_date' => $period->end_date->toDateString(),
                    'status' => $period->status,
                    'closed_at' => $period->closed_at?->toDateTimeString(),
                    'closed_by' => $period->closedBy?->only('name'),
                    'reopened_at' => $period->reopened_at?->toDateTimeString(),
                    'reopened_by' => $period->reopenedBy?->only('name'),
                    'reopen_reason' => $period->reopen_reason,
                ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $store = $request->user()->store;
        abort_unless($store && AccountingFeatures::enabled($store), 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ]);

        $start = Carbon::parse($data['start_date']);
        $end = Carbon::parse($data['end_date']);

        // Validasi: tanggal harus hari pertama dan hari terakhir bulan yang sama.
        if (! $start->isSameDay($start->copy()->startOfMonth()) || ! $end->isSameDay($end->copy()->endOfMonth()) || ! $start->isSameMonth($end)) {
            throw ValidationException::withMessages([
                'start_date' => 'Periode harus dimulai tanggal 1 dan berakhir pada hari terakhir bulan yang sama.',
            ]);
        }

        // Validasi: tidak boleh overlap dengan periode lain di toko yang sama.
        $overlap = AccountingPeriod::query()
            ->where('store_id', $store->id)
            ->where('id', '!=', 0)
            ->where(function ($query) use ($start, $end) {
                $query->where(function ($q) use ($start, $end) {
                    $q->whereDate('start_date', '<=', $end)->whereDate('end_date', '>=', $start);
                });
            })
            ->exists();

        if ($overlap) {
            throw ValidationException::withMessages([
                'start_date' => 'Pilih rentang yang berbeda; periode saling tumpang tindih dengan periode yang sudah ada.',
            ]);
        }

        AccountingPeriod::query()->create([
            'name' => $data['name'],
            'store_id' => $store->id,
            'start_date' => $start,
            'end_date' => $end,
            'status' => AccountingPeriod::STATUS_OPEN,
        ]);

        return back()->with('success', 'Periode akuntansi dibuat.');
    }

    public function checklist(Request $request, AccountingPeriod $period): JsonResponse
    {
        $store = $request->user()->store;
        abort_unless($store && AccountingFeatures::enabled($store) && $period->store_id === $store->id, 404);

        return response()->json(
            AccountingPeriodCloser::preCloseChecklist($period)
        );
    }

    public function close(Request $request, AccountingPeriod $period): RedirectResponse
    {
        $store = $request->user()->store;
        abort_unless($store && AccountingFeatures::enabled($store) && $period->store_id === $store->id, 404);

        AccountingPeriodCloser::close($period, $request->user());

        return back()->with('success', 'Periode ditutup dan jurnal penutup dibuat.');
    }

    public function reopen(Request $request, AccountingPeriod $period): RedirectResponse
    {
        $store = $request->user()->store;
        abort_unless($store && $request->user()->isOwner(), 403);
        abort_unless(AccountingFeatures::enabled($store) && $period->store_id === $store->id, 404);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        AccountingPeriodCloser::reopen($period, $request->user(), $data['reason']);

        return back()->with('success', 'Periode dibuka ulang. Jurnal penutup periode ini telah dihapus.');
    }
}
