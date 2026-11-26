<?php

namespace App\Http\Controllers\Affiliate;

use App\Http\Controllers\Controller;
use App\Models\AffiliateCommission;
use App\Models\AffiliatePayout;
use App\Models\AffiliateReferral;
use App\Services\AffiliateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Area afiliator: link, angka, saldo komisi, dan rekening pencairan. */
class AffiliateDashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $affiliate = $user->affiliate()->firstOrFail();

        $referrals = $affiliate->referrals()
            ->latest('id')
            ->limit(30)
            ->get()
            ->map(fn (AffiliateReferral $referral) => [
                'id' => $referral->id,
                // Nama disamarkan: afiliator tidak perlu melihat data lengkap orang yang diajaknya.
                'name' => $referral->maskedName(),
                'stage' => $referral->stage(),
                'registered_at' => $referral->created_at?->toISOString(),
                'commission_until' => $referral->commission_until?->toISOString(),
            ]);

        $commissions = $affiliate->commissions()
            ->with('referral')
            ->latest('id')
            ->limit(30)
            ->get()
            ->map(fn (AffiliateCommission $commission) => [
                'id' => $commission->id,
                'name' => $commission->referral?->maskedName() ?? 'Pengguna',
                'base_amount' => (float) $commission->base_amount,
                'percent' => (float) $commission->percent,
                'amount' => (float) $commission->amount,
                'state' => $commission->state(),
                'created_at' => $commission->created_at?->toISOString(),
                'available_at' => $commission->available_at?->toISOString(),
                'paid_at' => $commission->paid_at?->toISOString(),
            ]);

        $payouts = $affiliate->payouts()
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (AffiliatePayout $payout) => [
                'id' => $payout->id,
                'amount' => (float) $payout->amount,
                'status' => $payout->status,
                'account' => trim(($payout->payout_provider ?? '').' '.($payout->payout_account_number ?? '')),
                'requested_at' => ($payout->requested_at ?? $payout->created_at)?->toISOString(),
                'processed_at' => $payout->processed_at?->toISOString(),
                'proof_url' => $payout->proofUrl(),
                'note' => $payout->note,
                'reject_reason' => $payout->reject_reason,
            ]);

        $openPayout = AffiliateService::openPayout($affiliate);

        return Inertia::render('Affiliate/Dashboard', [
            'affiliate' => [
                'code' => $affiliate->code,
                'link' => $affiliate->shareUrl(),
                'is_active' => $affiliate->isActive(),
                'payout_provider' => $affiliate->payout_provider,
                'payout_account_number' => $affiliate->payout_account_number,
                'payout_account_holder' => $affiliate->payout_account_holder,
                'has_payout_account' => $affiliate->hasPayoutAccount(),
            ],
            'rules' => AffiliateService::rules($affiliate),
            'summary' => AffiliateService::summary($affiliate),
            'referrals' => $referrals,
            'commissions' => $commissions,
            'payouts' => $payouts,
            'openPayout' => $openPayout ? [
                'amount' => (float) $openPayout->amount,
                'requested_at' => $openPayout->requested_at?->toISOString(),
            ] : null,
            'promoTargets' => AffiliateService::promoTargets($affiliate),
        ]);
    }

    /** Ajukan pencairan seluruh komisi yang bisa ditarik. */
    public function requestPayout(Request $request): RedirectResponse
    {
        $payout = AffiliateService::requestPayout($request->user()->affiliate()->firstOrFail());

        return back()->with('success', 'Permintaan pencairan Rp'.number_format((float) $payout->amount, 0, ',', '.').' terkirim. Kami transfer ke rekening Anda setelah diperiksa.');
    }

    /** Simpan rekening/e-wallet tujuan transfer komisi. */
    public function updatePayout(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'payout_provider' => 'required|string|max:60',
            'payout_account_number' => ['required', 'string', 'max:40', 'regex:/^[0-9 \-]+$/'],
            'payout_account_holder' => 'required|string|max:100',
        ], [
            'payout_provider.required' => 'Isi nama bank atau e-wallet.',
            'payout_account_number.required' => 'Isi nomor rekening atau nomor e-wallet.',
            'payout_account_number.regex' => 'Nomor rekening hanya boleh berisi angka.',
            'payout_account_holder.required' => 'Isi nama pemilik rekening.',
        ]);

        $request->user()->affiliate()->firstOrFail()->update($validated);

        return back()->with('success', 'Rekening pencairan disimpan.');
    }
}
