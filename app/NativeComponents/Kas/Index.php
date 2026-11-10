<?php

namespace App\NativeComponents\Kas;

use App\Models\CashTransaction;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\NativeComponent;

class Index extends NativeComponent
{
    public string $todayIn = 'Rp 0';

    public string $todayOut = 'Rp 0';

    public string $balance = 'Rp 0';

    public bool $showAddModal = false;

    public string $type = 'in';

    public string $amountInput = '';

    public string $description = '';

    public string $formError = '';

    public function navTitle(): string
    {
        return 'Kas Harian';
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

        $in = CashTransaction::where('store_id', $storeId)
            ->whereDate('created_at', today())
            ->where('type', 'in')
            ->sum('amount');

        $out = CashTransaction::where('store_id', $storeId)
            ->whereDate('created_at', today())
            ->where('type', 'out')
            ->sum('amount');

        $this->todayIn = 'Rp '.number_format((float) $in, 0, ',', '.');
        $this->todayOut = 'Rp '.number_format((float) $out, 0, ',', '.');

        $balance = (float) $in - (float) $out;
        $this->balance = ($balance >= 0 ? '+' : '-').' Rp '.number_format(abs($balance), 0, ',', '.');
    }

    public function openAddModal(string $type = 'in'): void
    {
        $this->showAddModal = true;
        $this->type = $type;
        $this->amountInput = '';
        $this->description = '';
        $this->formError = '';
    }

    public function closeAddModal(): void
    {
        $this->showAddModal = false;
    }

    public function saveTransaction(): void
    {
        $user = Auth::user();
        if (! $user) {
            return;
        }

        $cleanAmount = str_replace(',', '', $this->amountInput);

        if (! is_numeric($cleanAmount) || (float) $cleanAmount <= 0) {
            $this->formError = 'Nominal tidak valid.';

            return;
        }

        try {
            CashTransaction::create([
                'store_id' => $user->store_id,
                'user_id' => $user->id,
                'type' => $this->type,
                'amount' => (float) $cleanAmount,
                'description' => $this->description,
                'category' => $this->type === 'in' ? 'revenue' : 'expense',
            ]);

            $this->loadStats();

            $this->showAddModal = false;
            $this->amountInput = '';
            $this->description = '';
            $this->formError = '';
        } catch (\Throwable $e) {
            $this->formError = 'Gagal menyimpan transaksi. Silakan coba lagi.';
        }
    }

    public function render(): Element|View
    {
        $user = Auth::user();
        $storeId = $user?->store_id;

        $transactions = CashTransaction::where('store_id', $storeId)
            ->whereDate('created_at', today())
            ->latest()
            ->limit(10)
            ->get();

        return view('native.kas.index', compact('transactions'));
    }
}
