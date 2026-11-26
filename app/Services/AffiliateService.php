<?php

namespace App\Services;

use App\Models\Affiliate;
use App\Models\AffiliateClick;
use App\Models\AffiliateCommission;
use App\Models\AffiliateReferral;
use App\Models\Organization;
use App\Models\Store;
use App\Models\SubscriptionPayment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

    // ───────────────────────── Ringkasan ─────────────────────────

    /**
     * Angka untuk dashboard afiliator.
     *
     * @return array{clicks:int, signups:int, paying:int, held:float, ready:float, paid:float}
     */
    public static function summary(Affiliate $affiliate): array
    {
        return [
            'clicks' => $affiliate->clicks()->count(),
            'signups' => $affiliate->referrals()->count(),
            'paying' => $affiliate->referrals()->whereNotNull('first_paid_at')->count(),
            'held' => (float) $affiliate->commissions()->held()->sum('amount'),
            'ready' => (float) $affiliate->commissions()->ready()->sum('amount'),
            'paid' => (float) $affiliate->commissions()->paid()->sum('amount'),
        ];
    }

    /**
     * Aturan program untuk ditampilkan di halaman.
     *
     * @return array{percent:float, months:int, cookie_days:int, hold_days:int, min_payout:int}
     */
    public static function rules(?Affiliate $affiliate = null): array
    {
        return [
            'percent' => $affiliate ? $affiliate->effectivePercent() : (float) config('affiliate.commission_percent'),
            'months' => (int) config('affiliate.commission_months'),
            'cookie_days' => (int) config('affiliate.cookie_days'),
            'hold_days' => (int) config('affiliate.hold_days'),
            'min_payout' => (int) config('affiliate.min_payout'),
        ];
    }
}
