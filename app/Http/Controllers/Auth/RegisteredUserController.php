<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\BusinessTypePresets;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    /**
     * Show the registration page.
     */
    public function create(): Response
    {
        return Inertia::render('auth/Register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        // Nomor WhatsApp dinormalkan ke format 62xxxxxxxxxx (siap dipakai wa.me)
        // sebelum divalidasi, jadi pengguna bebas mengetik 08…, +62…, atau 62….
        $request->merge(['whatsapp' => self::normalizeWhatsapp($request->input('whatsapp'))]);

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:'.User::class,
            'whatsapp' => ['required', 'string', 'regex:/^628\d{7,12}$/'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ], [
            'whatsapp.required' => 'Nomor WhatsApp wajib diisi.',
            'whatsapp.regex' => 'Nomor WhatsApp tidak valid. Contoh: 0812 3456 7890.',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->whatsapp,
            // Jenis usaha dipilih saat membuat toko pertama; default general.
            'business_type' => in_array($request->business_type, array_keys(BusinessTypePresets::TYPES), true)
                ? $request->business_type
                : 'general',
            'password' => Hash::make($request->password),
        ]);

        event(new Registered($user));

        Auth::login($user);

        // Langsung ke langkah 2 (siapkan toko) — tanpa mampir dashboard yang
        // akan melempar balik dengan pesan error "perlu membuat toko".
        return to_route('stores.create');
    }

    /**
     * 0812-3456-7890 / +62 812 3456 7890 / 812… -> 6281234567890.
     */
    public static function normalizeWhatsapp(mixed $value): string
    {
        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';

        if (str_starts_with($digits, '0')) {
            $digits = '62'.substr($digits, 1);
        } elseif (str_starts_with($digits, '8')) {
            $digits = '62'.$digits;
        }

        return $digits;
    }
}
