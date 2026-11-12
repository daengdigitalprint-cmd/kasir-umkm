<?php

namespace App\NativeComponents\Lainnya;

use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\NativeComponent;

class Index extends NativeComponent
{
    public string $userName = 'Pengguna';

    public string $userRole = 'Kasir';

    public string $storeName = 'Kasir UMKM by MaccaPOS';

    public function navTitle(): string
    {
        return 'Menu Lainnya';
    }

    public function mount(): void
    {
        $user = Auth::user();

        if ($user) {
            $this->userName = $user->name ?? 'Pengguna';
            $this->userRole = ucfirst($user->role ?? 'Kasir');
        }
    }

    public function logout(): void
    {
        Auth::logout();
        session()->invalidate();
        session()->regenerateToken();

        $this->replace('/mobile/login');
    }

    public function render(): Element|View
    {
        return view('native.lainnya.index');
    }
}
