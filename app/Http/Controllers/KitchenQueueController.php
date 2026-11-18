<?php

namespace App\Http\Controllers;

use App\Models\QueueEntry;
use App\Support\FnBFeatures;
use App\Support\ReceiptSettings;
use Inertia\Inertia;

class KitchenQueueController extends Controller
{
    /** Batas waktu tombol "Kembalikan" (menit). */
    private const UNDO_MINUTES = 10;

    /**
     * Papan dapur: pesanan berjalan, riwayat selesai hari ini, dan ringkasan waktu (F&B saja).
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

        $base = fn () => QueueEntry::query()
            ->where('store_id', $store->id)
            ->whereNotNull('transaction_id')
            ->with('transaction:id,transaction_number,order_type,table_reference');

        $entries = $base()
            ->whereIn('status', ['waiting', 'serving', 'ready'])
            // Pesanan hari ini, plus pesanan kemarin yang belum selesai (mis. lewat tengah malam).
            ->where(fn ($q) => $q
                ->whereDate('created_at', today())
                ->orWhere(fn ($q2) => $q2
                    ->where('created_at', '>=', today()->subDay())
                    ->whereIn('status', ['waiting', 'serving'])))
            ->orderByRaw("CASE status WHEN 'waiting' THEN 1 WHEN 'serving' THEN 2 ELSE 3 END")
            ->orderBy('created_at')
            ->limit(100)
            ->get()
            ->map(fn (QueueEntry $e) => $this->payload($e))
            ->values()
            ->all();

        $completedToday = $base()
            ->where('status', 'completed')
            ->whereDate('completed_at', today())
            ->orderByDesc('completed_at')
            ->get();

        // Lama masak: masuk → siap (bila tidak ditandai siap, masuk → diserahkan).
        $prepMinutes = $completedToday
            ->map(fn (QueueEntry $e) => ($e->ready_at ?? $e->completed_at) && $e->created_at
                ? $e->created_at->diffInSeconds($e->ready_at ?? $e->completed_at) / 60
                : null)
            ->filter(fn ($m) => $m !== null && $m >= 0);

        return Inertia::render('Kitchen/Index', [
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'receipt' => ReceiptSettings::payload($store),
            ],
            'entries' => $entries,
            'history' => $completedToday->take(60)->map(fn (QueueEntry $e) => $this->payload($e))->values()->all(),
            'summary' => [
                'completed_today' => $completedToday->count(),
                'avg_prep_minutes' => $prepMinutes->isNotEmpty() ? round($prepMinutes->avg(), 1) : null,
            ],
            'undoMinutes' => self::UNDO_MINUTES,
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(QueueEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'number' => $entry->number,
            'status' => $entry->status,
            'order_type' => $entry->transaction?->order_type ?? $entry->order_type,
            'table_reference' => $entry->transaction?->table_reference ?? $entry->table_number,
            'transaction_number' => $entry->transaction?->transaction_number,
            'created_at' => $entry->created_at?->toIso8601String(),
            'called_at' => $entry->called_at?->toIso8601String(),
            'ready_at' => $entry->ready_at?->toIso8601String(),
            'completed_at' => $entry->completed_at?->toIso8601String(),
            'notes' => $entry->notes,
            'items' => $entry->items ?? [],
        ];
    }
}
