<?php

namespace App\Http\Controllers;

use App\Support\CentralKitchenModule;
use App\Support\ShoppingList;
use App\Support\StoreLocationType;
use App\Support\StorePlanLimits;
use Inertia\Inertia;

/**
 * F2 — Daftar Belanja: barang di bawah Stok Minimum + saran jumlah beli,
 * lalu "Jadikan Pembelian" membuka form Pembelian yang sudah terisi.
 */
class ShoppingListController extends Controller
{
    public function index()
    {
        $store = auth()->user()->store;

        if (! $store) {
            return redirect()->route('stores.create')
                ->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        if (! StorePlanLimits::allowsAdvancedRetailFeatures($store)) {
            return redirect()->route('dashboard')
                ->with('warning', 'Paket Starter tidak mencakup Daftar Belanja. Upgrade ke paket GrowUp di halaman langganan.');
        }

        // Outlet yang pembeliannya dialihkan ke Gudang Pusat tetap bisa melihat daftar,
        // tapi tidak bisa langsung membuat Pembelian ke supplier.
        $routedToWarehouse = StoreLocationType::mustRoutePurchaseThroughCentralWarehouse($store)
            && ! PurchaseController::allowsLocalPurchase($store);

        return Inertia::render('ShoppingList/Index', [
            'items' => ShoppingList::items($store),
            'canPurchase' => ! $routedToWarehouse,
            // F6: minta ke lokasi lain dalam grup (Gudang Pusat Pro).
            'canRequestStock' => CentralKitchenModule::enabled($store) && CentralKitchenModule::siblingStores($store)->isNotEmpty(),
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'currency' => $store->currency,
                'location_label' => StoreLocationType::label($store),
            ],
        ]);
    }
}
