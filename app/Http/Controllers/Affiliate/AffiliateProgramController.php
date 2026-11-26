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
    /** Penjelasan program. Afiliator yang sudah masuk langsung ke dashboardnya. */
    public function landing(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if ($user?->isSuperAdmin()) {
            return redirect()->route('superadmin.affiliates.index');
        }

        if ($user && $user->affiliate()->exists()) {
            return redirect()->route('affiliate.dashboard');
        }

        return Inertia::render('Affiliate/Landing', [
            'rules' => AffiliateService::rules(),
            'examples' => $this->earningExamples(),
            'viewer' => $user ? ['name' => $user->name] : null,
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
     * @return list<array{plan:string, price:float, commission:float, cycle:string}>
     */
    private function earningExamples(): array
    {
        $percent = (float) \App\Models\AffiliateSetting::value('commission_percent');

        return SubscriptionPlan::active()
            ->where('price', '>', 0)
            ->ordered()
            ->limit(2)
            ->get()
            ->map(fn (SubscriptionPlan $plan) => [
                'plan' => $plan->name,
                'price' => (float) $plan->price,
                'commission' => round((float) $plan->price * $percent / 100),
                'cycle' => ($plan->billing_cycle ?? 'monthly') === 'yearly' ? 'tahun' : 'bulan',
            ])
            ->values()
            ->all();
    }
}
