<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Affiliate;
use App\Models\AffiliateCommission;
use App\Models\AffiliatePayout;
use App\Models\AffiliateReferral;
use App\Models\AffiliateSetting;
use App\Services\AffiliateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Program afiliasi di panel Super Admin: daftar afiliator, komisi,
 * pencatatan transfer komisi, dan pembatalan komisi.
 */
class AffiliateController extends Controller
{
    /** Pesan galat angka dalam Bahasa Indonesia (bawaan aplikasi berbahasa Inggris). */
    private const NUMBER_MESSAGES = [
        'numeric' => ':Attribute harus berupa angka.',
        'integer' => ':Attribute harus berupa bilangan bulat.',
        'min' => ':Attribute minimal :min.',
        'max' => ':Attribute maksimal :max.',
    ];

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
                'custom_percent' => $affiliate->commission_percent !== null ? (float) $affiliate->commission_percent : null,
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
            ->when($state === 'processing', fn ($q) => $q->processing())
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

        $payouts = AffiliatePayout::query()
            ->with('affiliate.user:id,name,phone')
            // Yang menunggu diproses di atas, sisanya riwayat terbaru.
            ->orderByRaw("CASE status WHEN 'requested' THEN 0 ELSE 1 END")
            ->latest('id')
            ->paginate(15, ['*'], 'pencairan_page')
            ->withQueryString()
            ->through(fn (AffiliatePayout $payout) => [
                'id' => $payout->id,
                'affiliate_name' => $payout->affiliate?->user?->name ?? '-',
                'affiliate_code' => $payout->affiliate?->code,
                'whatsapp' => $payout->affiliate?->user?->phone,
                'amount' => (float) $payout->amount,
                'status' => $payout->status,
                'type' => $payout->type ?? AffiliatePayout::TYPE_TRANSFER,
                'payout_provider' => $payout->payout_provider,
                'payout_account_number' => $payout->payout_account_number,
                'payout_account_holder' => $payout->payout_account_holder,
                'requested_at' => $payout->requested_at?->toISOString(),
                'processed_at' => $payout->processed_at?->toISOString(),
                'proof_url' => $payout->proofUrl(),
                'note' => $payout->note,
                'reject_reason' => $payout->reject_reason,
            ]);

        return Inertia::render('SuperAdmin/Affiliates/Index', [
            'affiliates' => $affiliates,
            'payouts' => $payouts,
            'commissions' => $commissions,
            'stats' => [
                'affiliates' => Affiliate::count(),
                'signups' => AffiliateReferral::count(),
                'paying' => AffiliateReferral::whereNotNull('first_paid_at')->count(),
                'held' => (float) AffiliateCommission::held()->sum('amount'),
                'ready' => (float) AffiliateCommission::ready()->sum('amount'),
                'paid' => (float) AffiliateCommission::paid()->sum('amount'),
                'pending_payouts' => AffiliatePayout::requested()->count(),
                'pending_payouts_amount' => (float) AffiliatePayout::requested()->sum('amount'),
            ],
            'rules' => AffiliateService::rules(),
            'settings' => AffiliateSetting::overview(),
            'leaderboard' => AffiliateService::leaderboard(null, 5, false),
            'filters' => ['q' => $search ?: null, 'state' => $state ?: null],
        ]);
    }

    /** Simpan aturan program. Kolom yang dikosongkan kembali memakai nilai bawaan. */
    public function updateSettings(Request $request): RedirectResponse
    {
        $rules = [];
        foreach (AffiliateSetting::RULES as $key => [$min, $max]) {
            $rules[$key] = ['nullable', $key === 'commission_percent' ? 'numeric' : 'integer', 'min:'.$min, 'max:'.$max];
        }

        $validated = $request->validate($rules, self::NUMBER_MESSAGES, [
            'commission_percent' => 'persen komisi',
            'commission_months' => 'lama komisi',
            'cookie_days' => 'masa berlaku link',
            'hold_days' => 'masa tahan',
            'min_payout' => 'minimum pencairan',
            'referred_trial_days' => 'trial untuk yang diajak',
        ]);

        $values = [];
        foreach (array_keys(AffiliateSetting::RULES) as $key) {
            $value = $validated[$key] ?? null;
            $values[$key] = ($value === null || $value === '') ? null : $value;
        }

        $setting = AffiliateSetting::query()->first() ?? new AffiliateSetting;
        $setting->fill($values)->save();

        return back()->with('success', 'Aturan program afiliasi disimpan. Komisi yang sudah tercatat tidak berubah.');
    }

    /** Tarif khusus untuk satu afiliator (kosong = ikut tarif program). */
    public function updateRate(Request $request, Affiliate $affiliate): RedirectResponse
    {
        $validated = $request->validate([
            'percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ], self::NUMBER_MESSAGES, ['percent' => 'tarif']);

        $percent = $validated['percent'] ?? null;
        $affiliate->update(['commission_percent' => ($percent === null || $percent === '') ? null : $percent]);

        return back()->with('success', $affiliate->commission_percent !== null
            ? 'Tarif khusus '.rtrim(rtrim(number_format((float) $affiliate->commission_percent, 2, ',', '.'), '0'), ',').'% disimpan untuk komisi berikutnya.'
            : 'Afiliator kembali memakai tarif program.');
    }

    /** Transfer tanpa menunggu permintaan: semua komisi yang bisa ditarik dicatat sudah dibayar. */
    public function pay(Request $request, Affiliate $affiliate): RedirectResponse
    {
        $validated = $this->validateTransfer($request);

        $payout = AffiliateService::payDirect($affiliate, $request->user(), $validated['note'] ?? null, $this->storeProof($request));

        if (! $payout) {
            return back()->with('error', 'Tidak ada komisi yang bisa dibayar untuk afiliator ini.');
        }

        return back()->with('success', 'Komisi Rp'.number_format((float) $payout->amount, 0, ',', '.').' dicatat sudah ditransfer.');
    }

    /** Permintaan pencairan dari afiliator: tandai sudah ditransfer. */
    public function payoutPaid(Request $request, AffiliatePayout $payout): RedirectResponse
    {
        $validated = $this->validateTransfer($request);

        if (! $payout->isRequested()
            || ! AffiliateService::markPayoutPaid($payout, $request->user(), $validated['note'] ?? null, $this->storeProof($request))) {
            return back()->with('error', 'Permintaan pencairan ini sudah diproses.');
        }

        return back()->with('success', 'Pencairan Rp'.number_format((float) $payout->refresh()->amount, 0, ',', '.').' ditandai sudah ditransfer.');
    }

    /** Tolak permintaan pencairan: komisinya kembali bisa ditarik afiliator. */
    public function payoutReject(Request $request, AffiliatePayout $payout): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ], [
            'reason.required' => 'Tulis alasan penolakan.',
        ]);

        if (! AffiliateService::rejectPayout($payout, $request->user(), $validated['reason'])) {
            return back()->with('error', 'Permintaan pencairan ini sudah diproses.');
        }

        return back()->with('success', 'Permintaan pencairan ditolak.');
    }

    /** @return array<string, mixed> */
    private function validateTransfer(Request $request): array
    {
        return $request->validate([
            'note' => 'nullable|string|max:500',
            'proof' => 'nullable|image|max:5120',
        ], [
            'proof.image' => 'Bukti transfer harus berupa gambar.',
            'proof.max' => 'Ukuran bukti transfer maksimal 5 MB.',
        ]);
    }

    private function storeProof(Request $request): ?string
    {
        return $request->hasFile('proof')
            ? $request->file('proof')->store('affiliate-payout-proofs', 'public')
            : null;
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

        if ($commission->affiliate_payout_id) {
            return back()->with('error', 'Komisi ini sedang dalam permintaan pencairan. Tolak dulu permintaannya, baru batalkan komisinya.');
        }

        $commission->update([
            'status' => AffiliateCommission::STATUS_CANCELLED,
            'cancel_reason' => $validated['reason'],
            'processed_by' => auth()->id(),
        ]);

        AffiliateService::notify(
            $commission->affiliate,
            'commission_cancelled',
            'Komisi Rp'.number_format((float) $commission->amount, 0, ',', '.').' dibatalkan',
            'Alasan: '.$validated['reason'],
        );

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
