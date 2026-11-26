<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Affiliate;
use App\Models\AffiliateCommission;
use App\Models\AffiliateReferral;
use App\Services\AffiliateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Program afiliasi di panel Super Admin: daftar afiliator, komisi,
 * pencatatan transfer komisi, dan pembatalan komisi.
 */
class AffiliateController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('q', ''));
        $state = (string) $request->query('state', '');

        $affiliates = Affiliate::query()
            ->with('user:id,name,email,phone')
            ->withCount(['clicks', 'referrals'])
            ->withCount(['referrals as paying_count' => fn ($q) => $q->whereNotNull('first_paid_at')])
            ->withSum(['commissions as held_sum' => fn ($q) => $q->held()], 'amount')
            ->withSum(['commissions as ready_sum' => fn ($q) => $q->ready()], 'amount')
            ->withSum(['commissions as paid_sum' => fn ($q) => $q->paid()], 'amount')
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('code', 'like', "%{$search}%")
                        ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%"));
                });
            })
            // Yang punya komisi siap dibayar tampil paling atas.
            ->orderByDesc('ready_sum')
            ->orderByDesc('id')
            ->paginate(20, ['*'], 'page')
            ->withQueryString()
            ->through(fn (Affiliate $affiliate) => [
                'id' => $affiliate->id,
                'name' => $affiliate->user?->name ?? '—',
                'email' => $affiliate->user?->email,
                'whatsapp' => $affiliate->user?->phone,
                'code' => $affiliate->code,
                'link' => $affiliate->shareUrl(),
                'is_active' => $affiliate->isActive(),
                'percent' => $affiliate->effectivePercent(),
                'clicks' => (int) $affiliate->clicks_count,
                'signups' => (int) $affiliate->referrals_count,
                'paying' => (int) $affiliate->paying_count,
                'held' => (float) ($affiliate->held_sum ?? 0),
                'ready' => (float) ($affiliate->ready_sum ?? 0),
                'paid' => (float) ($affiliate->paid_sum ?? 0),
                'payout_provider' => $affiliate->payout_provider,
                'payout_account_number' => $affiliate->payout_account_number,
                'payout_account_holder' => $affiliate->payout_account_holder,
                'has_payout_account' => $affiliate->hasPayoutAccount(),
                'joined_at' => $affiliate->created_at?->toISOString(),
            ]);

        $commissions = AffiliateCommission::query()
            ->with(['affiliate.user:id,name', 'referral.organization:id,name', 'referral.user:id,name'])
            ->when($state === 'held', fn ($q) => $q->held())
            ->when($state === 'ready', fn ($q) => $q->ready())
            ->when($state === 'paid', fn ($q) => $q->paid())
            ->when($state === 'cancelled', fn ($q) => $q->where('status', AffiliateCommission::STATUS_CANCELLED))
            ->latest('id')
            ->paginate(20, ['*'], 'komisi_page')
            ->withQueryString()
            ->through(fn (AffiliateCommission $commission) => [
                'id' => $commission->id,
                'affiliate_name' => $commission->affiliate?->user?->name ?? '—',
                'affiliate_code' => $commission->affiliate?->code,
                'referred' => $commission->referral?->organization?->name
                    ?? $commission->referral?->user?->name
                    ?? $commission->referral?->referred_name
                    ?? '—',
                'invoice_number' => $commission->invoice_number,
                'payment_type' => $commission->payment_type,
                'base_amount' => (float) $commission->base_amount,
                'percent' => (float) $commission->percent,
                'amount' => (float) $commission->amount,
                'state' => $commission->state(),
                'created_at' => $commission->created_at?->toISOString(),
                'available_at' => $commission->available_at?->toISOString(),
                'paid_at' => $commission->paid_at?->toISOString(),
                'paid_note' => $commission->paid_note,
                'cancel_reason' => $commission->cancel_reason,
            ]);

        return Inertia::render('SuperAdmin/Affiliates/Index', [
            'affiliates' => $affiliates,
            'commissions' => $commissions,
            'stats' => [
                'affiliates' => Affiliate::count(),
                'signups' => AffiliateReferral::count(),
                'paying' => AffiliateReferral::whereNotNull('first_paid_at')->count(),
                'held' => (float) AffiliateCommission::held()->sum('amount'),
                'ready' => (float) AffiliateCommission::ready()->sum('amount'),
                'paid' => (float) AffiliateCommission::paid()->sum('amount'),
            ],
            'rules' => AffiliateService::rules(),
            'filters' => ['q' => $search ?: null, 'state' => $state ?: null],
        ]);
    }

    /** Catat bahwa semua komisi siap bayar milik afiliator ini sudah ditransfer. */
    public function pay(Request $request, Affiliate $affiliate): RedirectResponse
    {
        $validated = $request->validate([
            'note' => 'nullable|string|max:500',
        ]);

        $total = DB::transaction(function () use ($affiliate, $validated) {
            $ready = $affiliate->commissions()->ready()->lockForUpdate()->get();
            $sum = (float) $ready->sum('amount');

            if ($ready->isNotEmpty()) {
                AffiliateCommission::whereKey($ready->modelKeys())->update([
                    'status' => AffiliateCommission::STATUS_PAID,
                    'paid_at' => now(),
                    'paid_note' => $validated['note'] ?? null,
                    'processed_by' => auth()->id(),
                    'updated_at' => now(),
                ]);
            }

            return $sum;
        });

        if ($total <= 0) {
            return back()->with('error', 'Tidak ada komisi yang siap dibayar untuk afiliator ini.');
        }

        return back()->with('success', 'Komisi Rp'.number_format($total, 0, ',', '.').' dicatat sudah ditransfer.');
    }

    /** Batalkan komisi (mis. pembayarannya di-refund). Komisi yang sudah ditransfer tidak bisa dibatalkan. */
    public function cancelCommission(Request $request, AffiliateCommission $commission): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ], [
            'reason.required' => 'Tulis alasan pembatalan.',
        ]);

        if ($commission->status !== AffiliateCommission::STATUS_EARNED) {
            return back()->with('error', 'Komisi ini sudah dibayar atau sudah dibatalkan.');
        }

        $commission->update([
            'status' => AffiliateCommission::STATUS_CANCELLED,
            'cancel_reason' => $validated['reason'],
            'processed_by' => auth()->id(),
        ]);

        return back()->with('success', 'Komisi dibatalkan.');
    }

    /** Bekukan / aktifkan lagi afiliator. Selagi beku: linknya mati dan tidak ada komisi baru. */
    public function toggle(Affiliate $affiliate): RedirectResponse
    {
        $affiliate->update([
            'status' => $affiliate->isActive() ? Affiliate::STATUS_SUSPENDED : Affiliate::STATUS_ACTIVE,
        ]);

        return back()->with('success', $affiliate->isActive() ? 'Afiliator diaktifkan lagi.' : 'Afiliator dibekukan.');
    }
}
