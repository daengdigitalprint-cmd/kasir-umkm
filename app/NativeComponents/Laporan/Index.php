<?php

namespace App\NativeComponents\Laporan;

use App\Models\Transaction;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\NativeComponent;

class Index extends NativeComponent
{
    public string $todayOmset = 'Rp 0';

    public int $todayTransactions = 0;

    public string $weekOmset = 'Rp 0';

    public int $weekTransactions = 0;

    public string $monthOmset = 'Rp 0';

    public string $selectedPeriod = 'today';

    public function navTitle(): string
    {
        return 'Laporan Ringkas';
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

        // Hari ini
        $todayTotal = Transaction::where('store_id', $storeId)
            ->whereDate('created_at', today())
            ->sum('total_amount');
        $this->todayTransactions = Transaction::where('store_id', $storeId)
            ->whereDate('created_at', today())
            ->count();
        $this->todayOmset = 'Rp '.number_format((float) $todayTotal, 0, ',', '.');

        // Minggu ini
        $weekTotal = Transaction::where('store_id', $storeId)
            ->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])
            ->sum('total_amount');
        $this->weekTransactions = Transaction::where('store_id', $storeId)
            ->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])
            ->count();
        $this->weekOmset = 'Rp '.number_format((float) $weekTotal, 0, ',', '.');

        // Bulan ini
        $monthTotal = Transaction::where('store_id', $storeId)
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->sum('total_amount');
        $this->monthOmset = 'Rp '.number_format((float) $monthTotal, 0, ',', '.');
    }

    public function setPeriod(string $period): void
    {
        $this->selectedPeriod = $period;
    }

    public function render(): Element|View
    {
        return view('native.laporan.index');
    }
}
