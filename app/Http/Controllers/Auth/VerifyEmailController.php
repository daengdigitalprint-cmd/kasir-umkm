<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;

class VerifyEmailController extends Controller
{
    private function verifiedRedirect(User $user): RedirectResponse
    {
        if ($user->isSuperAdmin()) {
            return redirect()->route('superadmin.dashboard')->with('verified', 1);
        }

        return redirect()->intended(route('dashboard', absolute: false).'?verified=1');
    }

    /**
     * Mark the authenticated user's email address as verified.
     */
    public function __invoke(EmailVerificationRequest $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return $this->verifiedRedirect($request->user());
        }

        $request->fulfill();

        return $this->verifiedRedirect($request->user());
    }
}
