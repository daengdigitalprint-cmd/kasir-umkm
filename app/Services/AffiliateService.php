<?php

namespace App\Services;

use App\Models\Affiliate;
use App\Models\AffiliateClick;
use App\Models\AffiliateCommission;
use App\Models\AffiliateNotification;
use App\Models\AffiliatePayout;
use App\Models\AffiliateReferral;
use App\Models\AffiliateSetting;
use App\Models\Organization;
use App\Models\Store;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
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
 * Bawaan: sekali per toko, dari paket pertama yang dibelinya (aturan `commission_months` = 0).
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
            max(1, (int) AffiliateSetting::value('cookie_days')) * 24 * 60,
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

        if ($referral->wasRecentlyCreated) {
            self::notify($affiliate, 'signup', 'Ada pendaftar baru lewat link Anda', $referral->maskedName().' baru saja mendaftar. Komisi muncul setelah tokonya membayar langganan.');
        }

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
        $days = (int) AffiliateSetting::value('referred_trial_days');
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
        $months = (int) AffiliateSetting::value('commission_months');

        if ($months <= 0) {
            // Komisi sekali saja per toko: hanya dari paket pertama. Komisi yang dibatalkan (mis. refund)
            // tidak dihitung, jadi pembayaran sah berikutnya masih bisa menghasilkan komisi.
            if ($referral->commissions()->where('status', '!=', AffiliateCommission::STATUS_CANCELLED)->exists()) {
                return null;
            }
        } else {
            $until = $referral->commission_until ?? $referral->first_paid_at?->copy()->addMonthsNoOverflow($months);
            if ($until && $now->gt($until)) {
                return null;
            }
        }

        $commission = DB::transaction(function () use ($payment, $referral, $affiliate, $store, $now, $months) {
            if (! $referral->first_paid_at) {
                $referral->forceFill([
                    'first_paid_at' => $now,
                    'commission_until' => $months > 0 ? $now->copy()->addMonthsNoOverflow($months) : null,
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
                    'available_at' => $now->copy()->addDays(max(0, (int) AffiliateSetting::value('hold_days'))),
                ],
            );
        });

        if ($commission->wasRecentlyCreated) {
            self::notify(
                $affiliate,
                'commission',
                'Komisi baru '.self::rupiah((float) $commission->amount),
                $referral->maskedName().' membayar langganan. Komisi ini bisa ditarik mulai '.$commission->available_at->translatedFormat('j M Y').'.',
            );
        }

        return $commission;
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
            $minimum = (int) AffiliateSetting::value('min_payout');

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

            self::notify($payout->affiliate, 'payout_paid', 'Pencairan '.self::rupiah($amount).' sudah ditransfer', 'Cek rekening Anda. Rinciannya ada di Riwayat pencairan.');

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

            self::notify($payout->affiliate, 'payout_rejected', 'Permintaan pencairan ditolak', 'Alasan: '.$reason.' Komisinya kembali bisa ditarik.');

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

            self::notify($affiliate, 'payout_paid', 'Komisi '.self::rupiah($total).' sudah ditransfer', 'Cek rekening Anda. Rinciannya ada di Riwayat pencairan.');

            return $payout;
        });
    }

    // ───────────────────────── Tukar komisi jadi langganan ─────────────────────────

    /**
     * Paket yang bisa dibayar dengan komisi oleh afiliator yang punya toko sendiri.
     * null bila pengguna tidak mengelola toko (afiliator tanpa toko, atau hanya staf).
     *
     * @return array{store_name:string, has_pending_payment:bool, plans:list<array{id:int, name:string, price:float, duration_days:int, cycle:string}>}|null
     */
    public static function redeemContext(User $user): ?array
    {
        $store = $user->store;
        if (! $store || ! $user->canManageStoreSettings()) {
            return null;
        }

        return [
            'store_name' => (string) $store->name,
            'has_pending_payment' => SubscriptionPayment::where('store_id', $store->id)->where('status', 'pending')->exists(),
            'plans' => self::redeemablePlans()->map(fn (SubscriptionPlan $plan) => [
                'id' => $plan->id,
                'name' => $plan->name,
                'price' => (float) $plan->price,
                'duration_days' => (int) $plan->duration_days,
                'cycle' => ($plan->billing_cycle ?? 'monthly') === 'yearly' ? 'tahun' : 'bulan',
            ])->values()->all(),
        ];
    }

    /** Paket berbayar biasa (bukan promo) yang sedang dijual. */
    private static function redeemablePlans(): \Illuminate\Support\Collection
    {
        return SubscriptionPlan::active()
            ->where('price', '>', 0)
            ->where('duration_days', '>', 0)
            ->where('slug', 'not like', 'promo-%')
            ->ordered()
            ->get();
    }

    /**
     * Bayar paket langganan toko afiliator sendiri dengan komisi yang bisa ditarik.
     * Langganan langsung diperpanjang; tidak perlu konfirmasi super admin karena tidak ada uang yang ditransfer.
     *
     * @throws ValidationException bila syaratnya belum terpenuhi
     */
    public static function redeemForSubscription(Affiliate $affiliate, User $user, int $planId): AffiliatePayout
    {
        $fail = fn (string $message) => throw ValidationException::withMessages(['redeem' => $message]);

        if (! $affiliate->isActive()) {
            $fail('Akun afiliasi Anda sedang dibekukan.');
        }

        $store = $user->store;
        if (! $store || ! $user->canManageStoreSettings()) {
            $fail('Hanya pemilik atau admin toko yang bisa memakai komisi untuk langganan.');
        }

        $plan = self::redeemablePlans()->firstWhere('id', $planId);
        if (! $plan) {
            $fail('Paket ini tidak bisa dibayar dengan komisi.');
        }

        if (SubscriptionPayment::where('store_id', $store->id)->where('status', 'pending')->exists()) {
            $fail('Toko Anda masih punya pembayaran yang menunggu konfirmasi. Tunggu sampai selesai dulu.');
        }

        return DB::transaction(function () use ($affiliate, $user, $store, $plan, $fail) {
            $ready = $affiliate->commissions()->ready()->orderBy('available_at')->orderBy('id')->lockForUpdate()->get();
            $price = round((float) $plan->price, 2);

            if (round((float) $ready->sum('amount'), 2) < $price) {
                $fail('Komisi yang bisa ditarik belum cukup untuk paket ini.');
            }

            $now = now();
            $payment = SubscriptionPayment::create([
                'store_id' => $store->id,
                'user_id' => $user->id,
                'subscription_plan_id' => $plan->id,
                'amount' => $price,
                'duration_days' => $plan->duration_days,
                'payment_method' => 'affiliate_balance',
                'status' => 'confirmed',
                'confirmed_at' => $now,
                'notes' => 'Dibayar dengan saldo komisi afiliasi.',
            ]);

            $payout = AffiliatePayout::create([
                'affiliate_id' => $affiliate->id,
                'amount' => $price,
                'status' => AffiliatePayout::STATUS_PAID,
                'type' => AffiliatePayout::TYPE_SUBSCRIPTION,
                'subscription_payment_id' => $payment->id,
                'note' => 'Langganan '.$plan->name.' untuk '.$store->name,
                'processed_at' => $now,
            ]);

            // Pakai komisi dari yang paling lama; komisi terakhir dipecah bila melebihi sisa harga.
            $remaining = $price;
            $paid = [
                'status' => AffiliateCommission::STATUS_PAID,
                'affiliate_payout_id' => $payout->id,
                'paid_at' => $now,
                'paid_note' => 'Ditukar langganan '.$plan->name,
            ];

            foreach ($ready as $commission) {
                if ($remaining <= 0) {
                    break;
                }

                $amount = round((float) $commission->amount, 2);

                if ($amount <= $remaining) {
                    $commission->update($paid);
                    $remaining = round($remaining - $amount, 2);

                    continue;
                }

                $usedBase = round((float) $commission->base_amount * $remaining / $amount, 2);

                $rest = $commission->replicate(['subscription_payment_id']);
                $rest->amount = round($amount - $remaining, 2);
                $rest->base_amount = round((float) $commission->base_amount - $usedBase, 2);
                $rest->created_at = $commission->created_at;
                $rest->save();

                $commission->update([...$paid, 'amount' => $remaining, 'base_amount' => $usedBase]);
                $remaining = 0;
            }

            // Sama seperti pembayaran yang dikonfirmasi super admin.
            $store->extendSubscription((int) $plan->duration_days);
            $store->is_active = true;
            $store->subscription_notes = "Dibayar dengan komisi afiliasi #{$payment->invoice_number}";
            $store->subscription_plan_id = $plan->id;
            $store->save();
            TrialService::convertToPaid($store, null, "Trial Pro selesai: berlangganan via komisi afiliasi #{$payment->invoice_number}.");

            return $payout;
        });
    }

    // ───────────────────────── Notifikasi ─────────────────────────

    /** Kabar untuk afiliator di dashboardnya. Tidak pernah menggagalkan proses yang memanggilnya. */
    public static function notify(?Affiliate $affiliate, string $type, string $title, ?string $body = null): void
    {
        if (! $affiliate) {
            return;
        }

        rescue(fn () => AffiliateNotification::create([
            'affiliate_id' => $affiliate->id,
            'type' => $type,
            'title' => Str::limit($title, 250, ''),
            'body' => $body !== null ? Str::limit($body, 490, '…') : null,
        ]));
    }

    private static function rupiah(float $amount): string
    {
        return 'Rp'.number_format($amount, 0, ',', '.');
    }

    // ───────────────────────── Papan peringkat ─────────────────────────

    /**
     * Peringkat afiliator bulan berjalan menurut komisi yang didapat bulan ini.
     * Nama afiliator lain disamarkan kecuali $mask dimatikan (super admin).
     *
     * @return array{period:string, rows:list<array{rank:int, name:string, paying:int, earned:float, is_me:bool}>, me:array{rank:int|null, paying:int, earned:float}|null}
     */
    public static function leaderboard(?Affiliate $me = null, int $limit = 5, bool $mask = true): array
    {
        $start = now()->startOfMonth();

        $ranked = Affiliate::query()
            ->where('status', Affiliate::STATUS_ACTIVE)
            ->with('user:id,name')
            ->withSum(['commissions as earned_sum' => fn ($q) => $q
                ->where('status', '!=', AffiliateCommission::STATUS_CANCELLED)
                ->where('created_at', '>=', $start)], 'amount')
            ->withCount(['referrals as paying_count' => fn ($q) => $q->where('first_paid_at', '>=', $start)])
            ->get()
            ->filter(fn (Affiliate $a) => (float) $a->earned_sum > 0)
            ->sort(fn (Affiliate $a, Affiliate $b) => [(float) $b->earned_sum, (int) $b->paying_count, $a->id] <=> [(float) $a->earned_sum, (int) $a->paying_count, $b->id])
            ->values();

        $row = fn (Affiliate $a, int $index) => [
            'rank' => $index + 1,
            'name' => ($mask && $a->id !== $me?->id) ? AffiliateReferral::mask((string) $a->user?->name) : (string) ($a->user?->name ?? 'Afiliator'),
            'paying' => (int) $a->paying_count,
            'earned' => (float) $a->earned_sum,
            'is_me' => $a->id === $me?->id,
        ];

        $myIndex = $me ? $ranked->search(fn (Affiliate $a) => $a->id === $me->id) : false;

        return [
            'period' => $start->translatedFormat('F Y'),
            'rows' => $ranked->take($limit)->map($row)->values()->all(),
            'me' => $me ? [
                'rank' => $myIndex === false ? null : $myIndex + 1,
                'paying' => $myIndex === false ? 0 : (int) $ranked[$myIndex]->paying_count,
                'earned' => $myIndex === false ? 0.0 : (float) $ranked[$myIndex]->earned_sum,
            ] : null,
        ];
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
     * @return array{percent:float, months:int, once:bool, cookie_days:int, hold_days:int, min_payout:int, referred_trial_days:int, default_trial_days:int}
     */
    public static function rules(?Affiliate $affiliate = null): array
    {
        return [
            'percent' => $affiliate ? $affiliate->effectivePercent() : (float) AffiliateSetting::value('commission_percent'),
            'months' => (int) AffiliateSetting::value('commission_months'),
            // Komisi sekali per toko (dari paket pertama), bukan berulang.
            'once' => (int) AffiliateSetting::value('commission_months') <= 0,
            'cookie_days' => (int) AffiliateSetting::value('cookie_days'),
            'hold_days' => (int) AffiliateSetting::value('hold_days'),
            'min_payout' => (int) AffiliateSetting::value('min_payout'),
            // Keuntungan untuk yang diajak: trial Pro lebih panjang (0 = tidak ada).
            'referred_trial_days' => max(0, (int) AffiliateSetting::value('referred_trial_days')),
            'default_trial_days' => TrialService::TRIAL_DAYS,
        ];
    }
}
