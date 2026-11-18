<?php

namespace App\Http\Controllers;

use App\Events\KitchenQueueUpdated;
use App\Models\ProductCategory;
use App\Models\QueueEntry;
use App\Support\FnBFeatures;
use App\Support\KitchenOrders;
use App\Support\ReceiptSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            // Pilihan stasiun (mis. bar = minuman, dapur = makanan), disimpan per perangkat.
            'categories' => ProductCategory::query()
                ->where('store_id', $store->id)
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    /**
     * Centang satu item pesanan. Semua item tercentang → pesanan otomatis "Siap";
     * centang dibuka lagi pada pesanan Siap → kembali "Sedang Dibuat".
     */
    public function toggleItem(Request $request, QueueEntry $queueEntry, int $index): JsonResponse
    {
        $store = $request->user()->store;
        abort_unless($store && (int) $queueEntry->store_id === (int) $store->id, 404);
        abort_unless(KitchenOrders::enabled($store) && $queueEntry->transaction_id, 422, 'Papan dapur tidak aktif.');
        abort_unless(in_array($queueEntry->status, ['waiting', 'serving', 'ready'], true), 422, 'Pesanan ini sudah selesai.');

        $done = $request->validate(['done' => ['required', 'boolean']])['done'];

        DB::transaction(function () use ($queueEntry, $index, $done) {
            $entry = QueueEntry::query()->whereKey($queueEntry->id)->lockForUpdate()->firstOrFail();
            $items = $entry->items ?? [];
            abort_unless(array_key_exists($index, $items), 404);

            $items[$index]['done'] = (bool) $done;
            $allDone = collect($items)->every(fn ($i) => ! empty($i['done']));

            $updates = ['items' => $items];
            if ($allDone && in_array($entry->status, ['waiting', 'serving'], true)) {
                $updates += ['status' => 'ready', 'called_at' => $entry->called_at ?? now(), 'ready_at' => now()];
            } elseif (! $allDone && $entry->status === 'ready') {
                $updates += ['status' => 'serving', 'ready_at' => null];
            } elseif ($done && $entry->status === 'waiting') {
                // Mulai mencentang = mulai dibuat.
                $updates += ['status' => 'serving', 'called_at' => now()];
            }

            $previousStatus = $entry->status;
            $entry->update($updates);

            if (isset($updates['status'])) {
                KitchenOrders::syncTransaction($entry, $previousStatus, $updates['status']);
            }

            $queueEntry->setRawAttributes($entry->getAttributes(), true);
        });

        $queueEntry->refresh()->load('transaction:id,transaction_number,order_type,table_reference');
        KitchenOrders::broadcast(KitchenQueueUpdated::fromEntry($queueEntry));

        return response()->json(['entry' => $this->payload($queueEntry)]);
    }

    /** Pesanan yang sudah Siap, untuk pemberitahuan di layar kasir. */
    public function ready(Request $request): JsonResponse
    {
        $store = $request->user()->store;
        if (! $store || ! KitchenOrders::enabled($store)) {
            return response()->json(['enabled' => false, 'entries' => []]);
        }

        $entries = QueueEntry::query()
            ->where('store_id', $store->id)
            ->whereNotNull('transaction_id')
            ->where('status', 'ready')
            ->where('created_at', '>=', today()->subDay())
            ->with('transaction:id,transaction_number,order_type,table_reference')
            ->orderBy('ready_at')
            ->limit(20)
            ->get()
            ->map(fn (QueueEntry $e) => $this->payload($e))
            ->values()
            ->all();

        return response()->json(['enabled' => true, 'entries' => $entries]);
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
