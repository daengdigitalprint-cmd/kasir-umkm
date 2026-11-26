<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Show the login page.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => $request->session()->get('status'),
            // Datang dari tombol "Masuk akun afiliasi": halaman login menyesuaikan judulnya.
            'affiliateLogin' => self::intendsAffiliateArea($request),
        ]);
    }

    /** Pengunjung diarahkan ke login dari area program afiliasi (/afiliasi/...). */
    private static function intendsAffiliateArea(Request $request): bool
    {
        $path = (string) parse_url((string) $request->session()->get('url.intended', ''), PHP_URL_PATH);

        return str_starts_with($path, '/afiliasi');
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        $user = $request->user();

        if ($user?->isSuperAdmin()) {
            return redirect()->route('superadmin.dashboard');
        }

        // Log aktivitas login
        if ($user && $user->store) {
            \App\Services\ActivityLogger::logLogin($user, $user->store);
        }

        // Masuk lewat tombol "Masuk akun afiliasi": kembali ke area afiliasi, juga untuk pemilik toko
        // (yang biasanya diarahkan ke dashboard owner). Yang belum bergabung akan melihat ajakan gabung.
        if (self::intendsAffiliateArea($request)) {
            return redirect()->intended(route('affiliate.dashboard', absolute: false));
        }

        // Afiliator tanpa toko: areanya hanya program afiliasi.
        if ($user?->isAffiliateOnly()) {
            return redirect()->route('affiliate.dashboard');
        }

        // Owner diarahkan langsung ke dashboard owner (laporan lintas outlet)
        if ($user?->isOwner()) {
            return redirect()->route('owner.dashboard');
        }

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();
        $store = $user?->store;

        // Log aktivitas logout
        if ($user && $store) {
            \App\Services\ActivityLogger::logLogout($user, $store);
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
