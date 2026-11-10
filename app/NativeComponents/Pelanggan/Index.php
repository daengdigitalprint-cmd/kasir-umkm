<?php

namespace App\NativeComponents\Pelanggan;

use App\Models\Customer;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\NativeComponent;

class Index extends NativeComponent
{
    public int $totalCustomers = 0;

    public int $newThisMonth = 0;

    public string $search = '';

    public bool $showAddModal = false;

    public string $newName = '';

    public string $newPhone = '';

    public string $newEmail = '';

    public string $addError = '';

    public function navTitle(): string
    {
        return 'Pelanggan';
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

        $this->totalCustomers = Customer::where('store_id', $storeId)
            ->where('is_active', true)
            ->count();

        $this->newThisMonth = Customer::where('store_id', $storeId)
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();
    }

    public function openAddModal(): void
    {
        $this->showAddModal = true;
        $this->newName = '';
        $this->newPhone = '';
        $this->newEmail = '';
        $this->addError = '';
    }

    public function closeAddModal(): void
    {
        $this->showAddModal = false;
    }

    public function saveCustomer(): void
    {
        if (trim($this->newName) === '') {
            $this->addError = 'Nama pelanggan wajib diisi.';

            return;
        }

        try {
            $user = Auth::user();

            Customer::create([
                'store_id' => $user->store_id,
                'name' => $this->newName,
                'phone' => $this->newPhone,
                'email' => $this->newEmail,
                'is_active' => true,
            ]);

            $this->loadStats();
            $this->showAddModal = false;
            $this->newName = '';
            $this->newPhone = '';
            $this->newEmail = '';
            $this->addError = '';
        } catch (\Throwable $e) {
            $this->addError = 'Gagal menyimpan pelanggan. Silakan coba lagi.';
        }
    }

    public function render(): Element|View
    {
        $user = Auth::user();
        $customers = collect();

        if ($user) {
            $search = $this->search;
            $customers = Customer::where('store_id', $user->store_id)
                ->where('is_active', true)
                ->when($search !== '', fn ($q) => $q->where(fn ($q2) => $q2->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")))
                ->orderByDesc('total_spent')
                ->limit(25)
                ->get();
        }

        return view('native.pelanggan.index', [
            'customers' => $customers,
        ]);
    }
}
