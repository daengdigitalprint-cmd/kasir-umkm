<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;

class PasswordResetLinkController extends Controller
{
    /**
     * Show the password reset link request page.
     */
    public function create(Request $request): Response
    {
        $broker = (string) config('auth.defaults.passwords');

        return Inertia::render('auth/ForgotPassword', [
            // Email tujuan setelah tautan dikirim. Sengaja bukan flash "status" agar tidak
            // ikut muncul sebagai popup global (pesan jadi tampil dua kali).
            'sentTo' => $request->session()->get('reset_link_sent_to'),
            'resendSeconds' => (int) config("auth.passwords.{$broker}.throttle", 60),
            'expireMinutes' => (int) config("auth.passwords.{$broker}.expire", 60),
        ]);
    }

    /**
     * Handle an incoming password reset link request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => 'required|email',
        ], [
            'email.required' => 'Masukkan alamat email akun Anda.',
            'email.email' => 'Format email belum benar, contoh: nama@tokoanda.com.',
        ]);

        Password::sendResetLink(
            $request->only('email')
        );

        // Pesan sama untuk email terdaftar maupun tidak, supaya daftar akun tidak bisa ditebak.
        return back()->with('reset_link_sent_to', (string) $request->input('email'));
    }
}
