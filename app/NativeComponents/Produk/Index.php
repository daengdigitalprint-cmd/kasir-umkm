<?php

namespace App\NativeComponents\Produk;

use App\Models\Product;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\NativeComponent;

class Index extends NativeComponent
{
    public int $totalProducts = 0;

    public int $lowStockCount = 0;

    public string $search = '';

    public function navTitle(): string
    {
        return 'Katalog Produk';
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

        $this->totalProducts = Product::where('store_id', $storeId)
            ->where('is_active', true)
            ->count();

        $this->lowStockCount = Product::where('store_id', $storeId)
            ->where('is_active', true)
            ->whereColumn('stock_quantity', '<=', 'min_stock_level')
            ->count();
    }

    public function render(): Element|View
    {
        $user = Auth::user();
        $products = [];
        if ($user) {
            $products = Product::where('store_id', $user->store_id)
                ->where('is_active', true)
                ->when($this->search !== '', function ($q) {
                    $q->where('name', 'like', '%'.$this->search.'%')
                        ->orWhere('sku', 'like', '%'.$this->search.'%');
                })
                ->orderBy('name')
                ->limit(20)
                ->get();
        }

        return view('native.produk.index', [
            'products' => $products,
        ]);
    }
}
