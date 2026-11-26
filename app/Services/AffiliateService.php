<?php

namespace App\Services;

use App\Models\Affiliate;
use App\Models\AffiliateClick;
use App\Models\AffiliateCommission;
use App\Models\AffiliatePayout;
use App\Models\AffiliateReferral;
use App\Models\Organization;
use App\Models\Store;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Support\JenisUsaha;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Program afiliasi.
 *
 * Alur: klik link (/r/KODE atau ?ref=KODE) → cookie → daftar → atribusi terkunci di akun →
 * toko pertama dibuat (atribusi ikut grup usaha) → pembayaran dikonfirmasi super admin → komisi.
 * Komisi hanya lahir dari pembayaran yang sudah dikonfirmasi, tidak pernah dari klik atau pendaftaran.
 */
final class AffiliateService
{
    /** Kode ditulis tanpa spasi dan huruf besar, mis. "budi 482" → "BUDI482". */
    public static function normalizeCode(mixed $code): string
    {
        return Str::upper(preg_replace('/[^A-Za-z0-9]/', '', (string) $code) ?? '');
    }

    public static function findByCode(mixed $code, bool $activeOnly = true): ?Affiliate
    {
        $code = self::normalizeCode($code);
        if ($code === '' || strlen($code) > 20) {
            return null;
        }

        return Affiliate::query()
            ->where('code', $code)
            ->when($activeOnly, fn ($q) => $q->where('status', Affiliate::STATUS_ACTIVE))
            ->first();
    }

    /** Daftarkan pengguna sebagai afiliator (aman dipanggil ulang). */
    public static function enroll(User $user): Affiliate
    {
        return Affiliate::firstOrCreate(
            ['user_id' => $user->id],
            [
                'code' => self::generateCode($user),
                'status' => Affiliate::STATUS_ACTIVE,
                'agreed_terms_at' => now(),
            ],
        );
    }

    /** Kode mudah diingat: nama depan + 3 angka, mis. BUDI482. */
    public static function generateCode(User $user): string
    {
        $first = preg_split('/\s+/', trim((string) $user->name))[0] ?? '';
        $base = Str::upper(Str::limit(preg_replace('/[^A-Za-z]/', '', Str::ascii($first)) ?? '', 8, ''));
        if (strlen($base) < 3) {
            $base = 'MITRA';
        }

        do {
            $code = $base.random_int(100, 999);
        } while (Affiliate::where('code', $code)->exists());

        return $code;
    }

    // ───────────────────────── Klik & cookie ─────────────────────────

    /** Ingat afiliator di perangkat pengunjung; klik terakhir menimpa yang lama. */
    public static function rememberVisit(Request $request, Affiliate $affiliate): void
    {
        Cookie::queue(
            config('affiliate.cookie_name'),
            $affiliate->code,
            max(1, (int) config('affiliate.cookie_days')) * 24 * 60,
        );

        self::recordClick($request, $affiliate);
    }

    /** Satu klik per pengunjung per afiliator per hari; bot & pratinjau tautan tidak dihitung. */
    public static function recordClick(Request $request, Affiliate $affiliate): void
    {
        // Pengguna yang sudah masuk bukan calon pendaftar baru.
        if ($request->user() || self::looksLikeBot((string) $request->userAgent())) {
            return;
        }

        $ipHash = hash('sha256', $request->ip().'|'.config('app.key'));

        $alreadyToday = AffiliateClick::query()
            ->where('affiliate_id', $affiliate->id)
            ->where('ip_hash', $ipHash)
            ->where('created_at', '>=', now()->startOfDay())
            ->exists();

        if (! $alreadyToday) {
            AffiliateClick::create([
                'affiliate_id' => $affiliate->id,
                'ip_hash' => $ipHash,
                'landing_path' => Str::limit('/'.ltrim($request->path(), '/'), 250, ''),
            ]);
        }
    }

    private static function looksLikeBot(string $userAgent): bool
    {
        return $userAgent === ''
            || preg_match('/bot|crawl|spider|slurp|preview|facebookexternalhit|whatsapp|telegram|curl|wget|python|headless/i', $userAgent) === 1;
    }

    /**
     * Kode afiliator milik pengunjung ini (bila masih aktif). ?ref= di alamat didahulukan:
     * pada kunjungan pertama cookie baru dikirim bersama respons, belum terbaca di request.
     */
    public static function codeFromCookie(Request $request): ?string
    {
        $ref = $request->query('ref');
        $affiliate = (is_string($ref) ? self::findByCode($ref) : null)
            ?? self::findByCode($request->cookie(config('affiliate.cookie_name')));

        return $affiliate?->code;
    }

    // ───────────────────────── Atribusi ─────────────────────────

    /**
     * Kaitkan akun yang baru dibuat dengan afiliatornya. Kode yang diketik di form
     * mengalahkan cookie. Sekali terkait, tidak pernah berpindah.
     */
    public static function attributeRegistration(User $user, Request $request, ?string $typedCode = null): ?AffiliateReferral
    {
        $source = 'code';
        $affiliate = self::findByCode($typedCode);
        if (! $affiliate) {
            $source = 'link';
            $affiliate = self::findByCode($request->cookie(config('affiliate.cookie_name')));
        }

        if (! $affiliate || self::isSamePerson($affiliate, $user)) {
            return null;
        }

        $referral = AffiliateReferral::firstOrCreate(
            ['user_id' => $user->id],
            [
                'affiliate_id' => $affiliate->id,
                'referred_name' => $user->name,
                'source' => $source,
            ],
        );

        Cookie::queue(Cookie::forget(config('affiliate.cookie_name')));

        return $referral;
    }

    /** Rujukan diri sendiri: akun, email, atau nomor WhatsApp yang sama dengan afiliator. */
    private static function isSamePerson(Affiliate $affiliate, User $user): bool
    {
        $owner = $affiliate->user;
        if (! $owner) {
            return true;
        }

        if ($owner->id === $user->id || Str::lower((string) $owner->email) === Str::lower((string) $user->email)) {
            return true;
        }

        return filled($owner->phone) && filled($user->phone) && $owner->phone === $user->phone;
    }

    /** Saat toko pertama dibuat, atribusi ikut ke grup usahanya (komisi dihitung per grup). */
    public static function linkOrganization(User $user, Store $store): void
    {
        if (! $store->organization_id) {
            return;
        }

        AffiliateReferral::query()
            ->where('user_id', $user->id)
            ->whereNull('organization_id')
            ->update(['organization_id' => $store->organization_id]);
    }

    /**
     * Lama trial Pro untuk toko pertama pengguna ini: lebih panjang bila ia datang lewat
     * link/kode afiliator yang aktif. null = pakai lama trial biasa.
     */
    public static function trialDaysFor(User $user): ?int
    {
        $days = (int) config('affiliate.referred_trial_days');
        if ($days <= 0) {
            return null;
        }

        $referred = AffiliateReferral::query()
            ->where('user_id', $user->id)
            ->whereHas('affiliate', fn ($q) => $q->where('status', Affiliate::STATUS_ACTIVE))
            ->exists();

        return $referred ? $days : null;
    }

    // ───────────────────────── Komisi ─────────────────────────

    /**
     * Catat komisi untuk pembayaran yang baru dikonfirmasi. Mengembalikan null bila
     * pembayaran ini tidak menghasilkan komisi (bukan rujukan, masa komisi habis, dst.).
     */
    public static function recordCommission(SubscriptionPayment $payment): ?AffiliateCommission
    {
        if (! $payment->isConfirmed() || (float) $payment->amount <= 0) {
            return null;
        }

        $store = $payment->store;
        if (! $store) {
            return null;
        }

        $referral = self::referralForStore($store, $payment);
        if (! $referral) {
            return null;
        }

        $affiliate = $referral->affiliate;
        if (! $affiliate || ! $affiliate->isActive() || self::affiliateOwnsStore($affiliate, $store)) {
            return null;
        }

        $now = now();
        if ($referral->commission_until && $now->gt($referral->commission_until)) {
            return null;
        }

        return DB::transaction(function () use ($payment, $referral, $affiliate, $store, $now) {
            if (! $referral->first_paid_at) {
                $referral->forceFill([
                    'first_paid_at' => $now,
                    'commission_until' => $now->copy()->addMonthsNoOverflow(max(1, (int) config('affiliate.commission_months'))),
                    'organization_id' => $referral->organization_id ?? $store->organization_id,
                ])->save();
            }

            $percent = $affiliate->effectivePercent();
            $base = round((float) $payment->amount, 2);

            return AffiliateCommission::firstOrCreate(
                ['subscription_payment_id' => $payment->id],
                [
                    'affiliate_id' => $affiliate->id,
                    'affiliate_referral_id' => $referral->id,
                    'invoice_number' => $payment->invoice_number,
                    'payment_type' => $payment->type ?? 'subscription',
                    'base_amount' => $base,
                    'percent' => $percent,
                    'amount' => round($base * $percent / 100, 2),
                    'status' => AffiliateCommission::STATUS_EARNED,
                    'available_at' => $now->copy()->addDays(max(0, (int) config('affiliate.hold_days'))),
                ],
            );
        });
    }

    /** Rujukan milik grup usaha toko ini; cadangan: pemilik grup atau akun yang membayar. */
    private static function referralForStore(Store $store, SubscriptionPayment $payment): ?AffiliateReferral
    {
        if ($store->organization_id) {
            $referral = AffiliateReferral::where('organization_id', $store->organization_id)->oldest('id')->first();
            if ($referral) {
                return $referral;
            }
        }

        $userIds = array_values(array_filter([
            $store->organization_id ? Organization::whereKey($store->organization_id)->value('owner_user_id') : null,
            $payment->user_id,
        ]));

        return $userIds === []
            ? null
            : AffiliateReferral::whereIn('user_id', $userIds)->whereNull('organization_id')->oldest('id')->first();
    }

    /** Afiliator tidak mendapat komisi dari tokonya sendiri. */
    private static function affiliateOwnsStore(Affiliate $affiliate, Store $store): bool
    {
        $query = DB::table('store_user')->where('user_id', $affiliate->user_id);

        if ($store->organization_id) {
            $query->whereIn('store_id', Store::where('organization_id', $store->organization_id)->select('id'));
        } else {
            $query->where('store_id', $store->id);
        }

        return $query->exists();
    }

    // ───────────────────────── Pencairan ─────────────────────────

    /** Permintaan pencairan afiliator yang masih menunggu diproses (maksimal satu). */
    public static function openPayout(Affiliate $affiliate): ?AffiliatePayout
    {
        return $affiliate->payouts()->requested()->latest('id')->first();
    }

    /**
     * Afiliator mengajukan pencairan seluruh komisi yang bisa ditarik.
     *
     * @throws ValidationException bila syaratnya belum terpenuhi
     */
    public static function requestPayout(Affiliate $affiliate): AffiliatePayout
    {
        $fail = fn (string $message) => throw ValidationException::withMessages(['payout' => $message]);

        if (! $affiliate->isActive()) {
            $fail('Akun afiliasi Anda sedang dibekukan, pencairan belum bisa diajukan.');
        }
        if (! $affiliate->hasPayoutAccount()) {
            $fail('Isi rekening pencairan dulu sebelum menarik komisi.');
        }
        if (self::openPayout($affiliate)) {
            $fail('Masih ada permintaan pencairan yang sedang diproses.');
        }

        return DB::transaction(function () use ($affiliate, $fail) {
            $ready = $affiliate->commissions()->ready()->lockForUpdate()->get();
            $total = round((float) $ready->sum('amount'), 2);
            $minimum = (int) config('affiliate.min_payout');

            if ($total <= 0 || $total < $minimum) {
                $fail('Komisi yang bisa ditarik belum mencapai minimal Rp'.number_format($minimum, 0, ',', '.').'.');
            }

            $payout = AffiliatePayout::create([
                'affiliate_id' => $affiliate->id,
                'amount' => $total,
                'status' => AffiliatePayout::STATUS_REQUESTED,
                'payout_provider' => $affiliate->payout_provider,
                'payout_account_number' => $affiliate->payout_account_number,
                'payout_account_holder' => $affiliate->payout_account_holder,
                'requested_at' => now(),
            ]);

            AffiliateCommission::whereKey($ready->modelKeys())->update(['affiliate_payout_id' => $payout->id]);

            return $payout;
        });
    }

    /** Super admin menandai permintaan pencairan sudah ditransfer. false bila sudah diproses sebelumnya. */
    public static function markPayoutPaid(AffiliatePayout $payout, User $admin, ?string $note = null, ?string $proofPath = null): bool
    {
        return DB::transaction(function () use ($payout, $admin, $note, $proofPath) {
            $payout = AffiliatePayout::whereKey($payout->id)->lockForUpdate()->first();
            if (! $payout || ! $payout->isRequested()) {
                return false;
            }

            $now = now();
            // Komisi yang dibatalkan setelah diajukan tidak ikut dibayar.
            $amount = round((float) $payout->commissions()->where('status', AffiliateCommission::STATUS_EARNED)->sum('amount'), 2);

            $payout->commissions()->where('status', AffiliateCommission::STATUS_EARNED)->update([
                'status' => AffiliateCommission::STATUS_PAID,
                'paid_at' => $now,
                'paid_note' => $note,
                'processed_by' => $admin->id,
                'updated_at' => $now,
            ]);

            $payout->update([
                'status' => AffiliatePayout::STATUS_PAID,
                'amount' => $amount,
                'note' => $note,
                'proof_path' => $proofPath,
                'processed_at' => $now,
                'processed_by' => $admin->id,
            ]);

            return true;
        });
    }

    /** Super admin menolak permintaan: komisinya kembali bisa ditarik. false bila sudah diproses sebelumnya. */
    public static function rejectPayout(AffiliatePayout $payout, User $admin, string $reason): bool
    {
        return DB::transaction(function () use ($payout, $admin, $reason) {
            $payout = AffiliatePayout::whereKey($payout->id)->lockForUpdate()->first();
            if (! $payout || ! $payout->isRequested()) {
                return false;
            }

            $payout->commissions()->update(['affiliate_payout_id' => null]);
            $payout->update([
                'status' => AffiliatePayout::STATUS_REJECTED,
                'reject_reason' => $reason,
                'processed_at' => now(),
                'processed_by' => $admin->id,
            ]);

            return true;
        });
    }

    /**
     * Super admin mentransfer tanpa menunggu permintaan: semua komisi yang bisa ditarik
     * dicatat sebagai satu pencairan yang sudah dibayar. null bila tidak ada yang bisa dibayar.
     */
    public static function payDirect(Affiliate $affiliate, User $admin, ?string $note = null, ?string $proofPath = null): ?AffiliatePayout
    {
        return DB::transaction(function () use ($affiliate, $admin, $note, $proofPath) {
            $ready = $affiliate->commissions()->ready()->lockForUpdate()->get();
            $total = round((float) $ready->sum('amount'), 2);
            if ($total <= 0) {
                return null;
            }

            $now = now();
            $payout = AffiliatePayout::create([
                'affiliate_id' => $affiliate->id,
                'amount' => $total,
                'status' => AffiliatePayout::STATUS_PAID,
                'payout_provider' => $affiliate->payout_provider,
                'payout_account_number' => $affiliate->payout_account_number,
                'payout_account_holder' => $affiliate->payout_account_holder,
                'note' => $note,
                'proof_path' => $proofPath,
                'processed_at' => $now,
                'processed_by' => $admin->id,
            ]);

            AffiliateCommission::whereKey($ready->modelKeys())->update([
                'status' => AffiliateCommission::STATUS_PAID,
                'affiliate_payout_id' => $payout->id,
                'paid_at' => $now,
                'paid_note' => $note,
                'processed_by' => $admin->id,
                'updated_at' => $now,
            ]);

            return $payout;
        });
    }

    // ───────────────────────── Materi promosi ─────────────────────────

    /**
     * Tujuan link yang bisa dipilih afiliator: beranda (link pendek) atau halaman
     * khusus per jenis usaha (dengan ?ref=KODE).
     *
     * @return list<array{key:string, label:string, name:string|null, url:string}>
     */
    public static function promoTargets(Affiliate $affiliate): array
    {
        $targets = [[
            'key' => 'umum',
            'label' => 'Semua jenis usaha',
            'name' => null,
            'url' => $affiliate->shareUrl(),
        ]];

        foreach (JenisUsaha::slugs() as $slug) {
            $name = (string) (JenisUsaha::find($slug)['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $targets[] = [
                'key' => $slug,
                'label' => $name,
                'name' => $name,
                'url' => url('/'.$slug).'?ref='.$affiliate->code,
            ];
        }

        return $targets;
    }

    // ───────────────────────── Ringkasan ─────────────────────────

    /**
     * Angka untuk dashboard afiliator.
     *
     * @return array{clicks:int, signups:int, paying:int, held:float, ready:float, processing:float, paid:float}
     */
    public static function summary(Affiliate $affiliate): array
    {
        return [
            'clicks' => $affiliate->clicks()->count(),
            'signups' => $affiliate->referrals()->count(),
            'paying' => $affiliate->referrals()->whereNotNull('first_paid_at')->count(),
            'held' => (float) $affiliate->commissions()->held()->sum('amount'),
            'ready' => (float) $affiliate->commissions()->ready()->sum('amount'),
            'processing' => (float) $affiliate->commissions()->processing()->sum('amount'),
            'paid' => (float) $affiliate->commissions()->paid()->sum('amount'),
        ];
    }

    /**
     * Aturan program untuk ditampilkan di halaman.
     *
     * @return array{percent:float, months:int, cookie_days:int, hold_days:int, min_payout:int, referred_trial_days:int, default_trial_days:int}
     */
    public static function rules(?Affiliate $affiliate = null): array
    {
        return [
            'percent' => $affiliate ? $affiliate->effectivePercent() : (float) config('affiliate.commission_percent'),
            'months' => (int) config('affiliate.commission_months'),
            'cookie_days' => (int) config('affiliate.cookie_days'),
            'hold_days' => (int) config('affiliate.hold_days'),
            'min_payout' => (int) config('affiliate.min_payout'),
            // Keuntungan untuk yang diajak: trial Pro lebih panjang (0 = tidak ada).
            'referred_trial_days' => max(0, (int) config('affiliate.referred_trial_days')),
            'default_trial_days' => TrialService::TRIAL_DAYS,
        ];
    }
}
