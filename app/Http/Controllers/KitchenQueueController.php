<?php

namespace App\Http\Controllers;

use App\Models\QueueEntry;
use App\Support\FnBFeatures;
use App\Support\ReceiptSettings;
use Inertia\Inertia;

class KitchenQueueController extends Controller
{
    /**
     * Antrian dapur: transaksi selesai hari ini (F&B saja).
     */
    public function index()
    {
        $user = auth()->user();
        $store = $user->store;

        if (! $store) {
            return redirect()->route('stores.create')
                ->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        if (! FnBFeatures::enabled($store)) {
            return redirect()->route('dashboard')
                ->with('error', 'Antrian dapur hanya untuk jenis usaha F&B (warung makan / kafe).');
        }

        $entries = QueueEntry::query()
            ->where('store_id', $store->id)
            ->whereNotNull('transaction_id')
            ->whereIn('status', ['waiting', 'serving', 'ready'])
            ->whereDate('created_at', today())
            ->with('transaction:id,transaction_number,order_type,table_reference')
            ->orderByRaw("FIELD(status, 'waiting', 'serving', 'ready')")
            ->orderBy('created_at')
            ->limit(100)
            ->get()
            ->map(fn (QueueEntry $entry) => [
                'id' => $entry->id,
                'number' => $entry->number,
                'status' => $entry->status,
                'order_type' => $entry->transaction?->order_type ?? $entry->order_type,
                'table_reference' => $entry->transaction?->table_reference ?? $entry->table_number,
                'transaction_number' => $entry->transaction?->transaction_number,
                'created_at' => $entry->created_at?->toIso8601String(),
                'called_at' => $entry->called_at?->toIso8601String(),
                'notes' => $entry->notes,
                'items' => $entry->items ?? [],
            ])->values()->all();

        return Inertia::render('Kitchen/Index', [
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'receipt' => ReceiptSettings::payload($store),
            ],
            'entries' => $entries,
        ]);
    }
}
