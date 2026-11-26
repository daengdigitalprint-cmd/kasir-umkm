<?php

namespace App\Http\Controllers\Affiliate;

use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Controller;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\AffiliateService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Inertia\Inertia;
use Inertia\Response;

/** Halaman publik program afiliasi + pendaftaran afiliator. */
class AffiliateProgramController extends Controller
{
    /**
     * Penjelasan program. Afiliator yang sudah masuk langsung ke dashboardnya.
     * Super admin melihat halaman ini sebagai pratinjau (persis seperti pengunjung), tanpa dialihkan.
     */
    public function landing(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        $adminPreview = (bool) $user?->isSuperAdmin();

        if ($user && ! $adminPreview && $user->affiliate()->exists()) {
            return redirect()->route('affiliate.dashboard');
        }

        return Inertia::render('Affiliate/Landing', [
            'rules' => AffiliateService::rules(),
            'examples' => $this->earningExamples(),
            // Super admin tidak bisa bergabung: ia melihat tampilan pengunjung.
            'viewer' => ($user && ! $adminPreview) ? ['name' => $user->name] : null,
            'adminPreview' => $adminPreview,
        ]);
    }

    public function showRegister(): Response
    {
        return Inertia::render('Affiliate/Register', [
            'rules' => AffiliateService::rules(),
        ]);
    }

    /** Daftar sebagai afiliator tanpa toko: akun baru + keanggotaan program. */
    public function register(Request $request): RedirectResponse
    {
        $request->merge(['whatsapp' => RegisteredUserController::normalizeWhatsapp($request->input('whatsapp'))]);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:'.User::class,
            'whatsapp' => ['required', 'string', 'regex:/^628\d{7,12}$/'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'agree' => 'accepted',
        ], [
            'email.unique' => 'Email ini sudah terdaftar. Silakan masuk, lalu gabung dari halaman Program Afiliasi.',
            'whatsapp.required' => 'Nomor WhatsApp wajib diisi.',
            'whatsapp.regex' => 'Nomor WhatsApp tidak valid. Contoh: 0812 3456 7890.',
            'agree.accepted' => 'Centang persetujuan ketentuan program untuk melanjutkan.',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['whatsapp'],
            'business_type' => 'general',
            'password' => Hash::make($validated['password']),
        ]);

        event(new Registered($user));

        // Afiliator yang datang lewat link afiliator lain tetap tercatat, bila kelak ia membuka toko.
        AffiliateService::attributeRegistration($user, $request);
        AffiliateService::enroll($user);

        Auth::login($user);

        return to_route('affiliate.dashboard')->with('success', 'Selamat bergabung! Link afiliasi Anda sudah siap dibagikan.');
    }

    /** Pengguna yang sudah punya akun (mis. pemilik toko) bergabung dengan akun yang sama. */
    public function join(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->isSuperAdmin()) {
            return redirect()->route('superadmin.affiliates.index');
        }

        $request->validate(['agree' => 'accepted'], [
            'agree.accepted' => 'Centang persetujuan ketentuan program untuk melanjutkan.',
        ]);

        AffiliateService::enroll($user);

        return to_route('affiliate.dashboard')->with('success', 'Selamat bergabung! Link afiliasi Anda sudah siap dibagikan.');
    }

    /**
     * Contoh penghasilan dari harga paket yang sedang dijual.
     *
     * @return list<array{plan:string, price:float, commission:float, months:int, cycle:string, label:string}>
     */
    private function earningExamples(): array
    {
        $percent = (float) \App\Models\AffiliateSetting::value('commission_percent');

        $byTerm = $this->examplesBySubscriptionTerm($percent);
        if ($byTerm !== []) {
            return $byTerm;
        }

        // Satu paket per lama langganan (1, 3, 6, 12 bulan, ...), dari terpendek ke terpanjang.
        // Paket terpanjang ditonjolkan di halaman karena komisinya paling besar.
        return SubscriptionPlan::active()
            ->where('price', '>', 0)
            ->where('duration_days', '>', 0)
            ->where('slug', 'not like', 'promo-%')
            ->orderBy('duration_days')
            ->ordered()
            ->get()
            ->unique(fn (SubscriptionPlan $plan) => self::planMonths($plan))
            ->take(4)
            ->map(function (SubscriptionPlan $plan) use ($percent) {
                $months = self::planMonths($plan);

                return [
                    'plan' => $plan->name,
                    'price' => (float) $plan->price,
                    'commission' => round((float) $plan->price * $percent / 100),
                    'months' => $months,
                    // "per" di samping harga: /bulan, /tahun, /3 bulan.
                    'cycle' => match (true) {
                        $months === 1 => 'bulan',
                        $months === 12 => 'tahun',
                        default => $months.' bulan',
                    },
                    'label' => match (true) {
                        $months === 1 => 'Paket bulanan',
                        $months === 12 => 'Paket tahunan',
                        default => 'Paket '.$months.' bulan',
                    },
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Contoh per lama langganan yang ditawarkan (config affiliate.subscription_months, mis. 3/6/12 bulan).
     * Harga N bulan = harga paket yang lamanya memang N bulan bila ada (mis. paket tahunan untuk 12 bulan),
     * selain itu harga paket bulanan × N. Kosong bila lama langganan tidak diatur atau tidak ada paket bulanan.
     *
     * @return list<array{plan:string, price:float, commission:float, months:int, cycle:string, label:string}>
     */
    private function examplesBySubscriptionTerm(float $percent): array
    {
        $terms = AffiliateService::subscriptionMonths();
        if ($terms === []) {
            return [];
        }

        $plans = SubscriptionPlan::active()
            ->where('price', '>', 0)
            ->where('duration_days', '>', 0)
            ->where('slug', 'not like', 'promo-%')
            ->ordered()
            ->get();

        $monthly = $plans->first(fn (SubscriptionPlan $plan) => self::planMonths($plan) === 1);
        if (! $monthly) {
            return [];
        }

        return collect($terms)->map(function (int $months) use ($plans, $monthly, $percent) {
            $exact = $plans->first(fn (SubscriptionPlan $plan) => self::planMonths($plan) === $months);
            $price = $exact ? (float) $exact->price : (float) $monthly->price * $months;

            return [
                'plan' => $exact ? $exact->name : $monthly->name,
                'price' => $price,
                'commission' => round($price * $percent / 100),
                'months' => $months,
                'cycle' => $months.' bulan',
                'label' => 'Langganan '.$months.' bulan',
            ];
        })->values()->all();
    }

    /** Lama paket dalam bulan (30 hari = 1, 365 hari = 12). */
    private static function planMonths(SubscriptionPlan $plan): int
    {
        return max(1, (int) round(((int) $plan->duration_days) / 30.4));
    }
}
