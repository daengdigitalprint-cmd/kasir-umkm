<?php

namespace App\NativeComponents\Auth;

use App\Models\User;
use App\Support\MobileDefaultData;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\NativeComponent;
use Throwable;

class Login extends NativeComponent
{
    public string $email = '';

    public string $password = '';

    public string $errorMessage = '';

    public bool $isLoading = false;

    public function navTitle(): string
    {
        return 'Masuk Kasir UMKM by MaccaPOS';
    }

    public function shouldHideNavBar(): bool
    {
        return true;
    }

    public function mount(): void
    {
        if (Auth::check()) {
            $this->replace('/app');
        }
    }

    public function login(): void
    {
        $this->errorMessage = '';

        $email = mb_strtolower(trim($this->email));
        $password = trim($this->password);

        if ($email === '' || $password === '') {
            $this->errorMessage = 'Email dan password wajib diisi.';

            return;
        }

        $this->isLoading = true;

        if (! User::query()->exists()) {
            try {
                MobileDefaultData::ensure();
            } catch (Throwable $exception) {
                report($exception);
                $this->isLoading = false;
                $this->errorMessage = 'Gagal menyiapkan akun default: '.$exception->getMessage();

                return;
            }
        }

        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        if (! $user) {
            $this->isLoading = false;
            $this->errorMessage = 'Email tidak terdaftar di perangkat ini.';

            return;
        }

        if (! Hash::check($password, $user->password)) {
            $this->isLoading = false;
            $this->errorMessage = 'Kata sandi salah.';

            return;
        }

        Auth::login($user);
        session()->regenerate();
        $this->isLoading = false;
        $this->replace('/app');
    }

    public function render(): Element|View
    {
        return view('native.auth.login');
    }
}
