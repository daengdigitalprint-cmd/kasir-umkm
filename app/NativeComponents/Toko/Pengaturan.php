<?php

namespace App\NativeComponents\Toko;

use App\Models\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\NativeComponent;

class Pengaturan extends NativeComponent
{
    // Info toko
    public string $storeName = '';

    public string $storeAddress = '';

    public string $storePhone = '';

    public string $storeEmail = '';

    // Pajak
    public bool $taxEnabled = false;

    public string $taxRate = '0';

    public string $taxName = 'PPN';

    // Langganan
    public string $subscriptionLabel = '-';

    public string $subscriptionEnd = '-';

    public bool $subscriptionActive = false;

    public int $daysRemaining = 0;

    // Edit mode
    public bool $showEditModal = false;

    public string $editName = '';

    public string $editAddress = '';

    public string $editPhone = '';

    public string $editEmail = '';

    public string $editError = '';

    public bool $saveSuccess = false;

    public function navTitle(): string
    {
        return 'Pengaturan Toko';
    }

    public function mount(): void
    {
        $this->loadStore();
    }

    public function loadStore(): void
    {
        $user = Auth::user();
        if (! $user || ! $user->store_id) {
            return;
        }

        $store = Store::find($user->store_id);
        if (! $store) {
            return;
        }

        $this->storeName = $store->name;
        $this->storeAddress = $store->address ?? '';
        $this->storePhone = $store->phone ?? '';
        $this->storeEmail = $store->email ?? '';

        $this->taxEnabled = (bool) $store->tax_enabled;
        $this->taxRate = (string) ($store->tax_rate ?? 0);
        $this->taxName = $store->tax_name ?? 'PPN';

        $this->subscriptionActive = $store->isSubscriptionActive();
        $this->daysRemaining = $store->getDaysRemaining();
        $this->subscriptionLabel = $store->subscriptionPlan?->name ?? 'Starter';
        $this->subscriptionEnd = $store->subscription_end
            ? $store->subscription_end->format('d M Y')
            : 'Tidak terbatas';
    }

    public function openEditModal(): void
    {
        $this->editName = $this->storeName;
        $this->editAddress = $this->storeAddress;
        $this->editPhone = $this->storePhone;
        $this->editEmail = $this->storeEmail;
        $this->editError = '';
        $this->saveSuccess = false;
        $this->showEditModal = true;
    }

    public function closeEditModal(): void
    {
        $this->showEditModal = false;
    }

    public function saveStore(): void
    {
        $user = Auth::user();
        if (! $user || ! $user->store_id) {
            return;
        }

        $name = trim($this->editName);
        if ($name === '') {
            $this->editError = 'Nama toko tidak boleh kosong.';

            return;
        }

        $store = Store::find($user->store_id);
        if (! $store) {
            $this->editError = 'Toko tidak ditemukan.';

            return;
        }

        $store->update([
            'name' => $name,
            'address' => trim($this->editAddress),
            'phone' => trim($this->editPhone),
            'email' => trim($this->editEmail),
        ]);

        $this->loadStore();
        $this->saveSuccess = true;
        $this->showEditModal = false;
    }

    public function render(): Element|View
    {
        return view('native.toko.pengaturan');
    }
}
