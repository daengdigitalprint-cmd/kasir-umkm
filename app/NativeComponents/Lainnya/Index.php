<?php

namespace App\NativeComponents\Lainnya;

use App\Support\UiMode;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\NativeComponent;

class Index extends NativeComponent
{
    public string $userName = 'Pengguna';

    public string $userRole = 'Kasir';

    public string $storeName = 'Kasir UMKM by MaccaPOS';

    /** Mode Sederhana: menu lanjutan disembunyikan kecuali sudah dinyalakan (lihat UiMode). */
    public bool $simpleMode = false;

    public bool $showKas = true;

    public bool $showAbsensi = true;

    public bool $showGaji = true;

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

            $store = $user->store;
            if ($store && UiMode::isSimple($store)) {
                $visible = UiMode::visibleMenus($store);
                $this->simpleMode = true;
                $this->showKas = in_array('cash_transactions', $visible, true);
                $this->showAbsensi = in_array('attendance', $visible, true);
                $this->showGaji = in_array('payroll', $visible, true);
            }
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
