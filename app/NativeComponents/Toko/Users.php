<?php

namespace App\NativeComponents\Toko;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\NativeComponent;

class Users extends NativeComponent
{
    public int $totalUsers = 0;

    public int $totalActive = 0;

    public int $totalCashier = 0;

    // Modal tambah
    public bool $showAddModal = false;

    public string $newName = '';

    public string $newEmail = '';

    public string $newRole = 'cashier';

    public string $newPassword = '';

    public string $addError = '';

    // Modal nonaktifkan
    public bool $showToggleModal = false;

    public int $toggleUserId = 0;

    public string $toggleUserName = '';

    public bool $toggleToActive = false;

    public function navTitle(): string
    {
        return 'Manajemen Kasir';
    }

    public function mount(): void
    {
        $this->loadStats();
    }

    public function loadStats(): void
    {
        $user = Auth::user();
        if (! $user) {
            return;
        }

        $storeId = $user->store_id;

        $this->totalUsers = User::where('store_id', $storeId)
            ->where('id', '!=', $user->id)
            ->count();

        $this->totalActive = User::where('store_id', $storeId)
            ->where('id', '!=', $user->id)
            ->where('is_active', true)
            ->count();

        $this->totalCashier = User::where('store_id', $storeId)
            ->where('id', '!=', $user->id)
            ->where('role', 'cashier')
            ->where('is_active', true)
            ->count();
    }

    public function openAddModal(): void
    {
        $this->newName = '';
        $this->newEmail = '';
        $this->newRole = 'cashier';
        $this->newPassword = '';
        $this->addError = '';
        $this->showAddModal = true;
    }

    public function closeAddModal(): void
    {
        $this->showAddModal = false;
    }

    public function saveUser(): void
    {
        $user = Auth::user();
        if (! $user) {
            return;
        }

        $name = trim($this->newName);
        $email = trim($this->newEmail);
        $pass = trim($this->newPassword);

        if ($name === '') {
            $this->addError = 'Nama tidak boleh kosong.';

            return;
        }
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->addError = 'Email tidak valid.';

            return;
        }
        if (strlen($pass) < 6) {
            $this->addError = 'Password minimal 6 karakter.';

            return;
        }
        if (User::where('email', $email)->exists()) {
            $this->addError = 'Email sudah terdaftar.';

            return;
        }

        User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($pass),
            'role' => $this->newRole,
            'store_id' => $user->store_id,
            'is_active' => true,
        ]);

        $this->loadStats();
        $this->showAddModal = false;
        $this->addError = '';
    }

    public function openToggleModal(int $userId, string $name, bool $currentlyActive): void
    {
        $this->toggleUserId = $userId;
        $this->toggleUserName = $name;
        $this->toggleToActive = ! $currentlyActive;
        $this->showToggleModal = true;
    }

    public function closeToggleModal(): void
    {
        $this->showToggleModal = false;
    }

    public function confirmToggle(): void
    {
        $target = User::find($this->toggleUserId);
        if ($target) {
            $target->update(['is_active' => $this->toggleToActive]);
        }
        $this->loadStats();
        $this->showToggleModal = false;
    }

    public function render(): Element|View
    {
        $user = Auth::user();
        $storeId = $user?->store_id;

        $users = User::where('store_id', $storeId)
            ->where('id', '!=', $user?->id)
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        return view('native.toko.users', compact('users'));
    }
}
