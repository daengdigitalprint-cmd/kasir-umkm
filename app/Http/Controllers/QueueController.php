<?php

namespace App\Http\Controllers;

use App\Events\KitchenQueueUpdated;
use App\Models\Queue;
use App\Models\QueueEntry;
use App\Models\Store;
use App\Models\User;
use App\Support\BarbershopFeatures;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class QueueController extends Controller
{
    private function guardStore(bool $requireModule = true): array
    {
        $store = auth()->user()->store;

        if (! $store) {
            return [null, redirect()->route('stores.create')
                ->with('error', 'Anda perlu membuat toko terlebih dahulu.')];
        }

        if ($requireModule && ! ($store->settings['queue_module_enabled'] ?? false)) {
            return [null, redirect()->route('dashboard')
                ->with('warning', 'Modul antrian belum diaktifkan. Aktifkan di Pengaturan → Tab Toko.')];
        }

        return [$store, null];
    }

    private function serializeQueue(Queue $q): array
    {
        return [
            'id' => $q->id,
            'name' => $q->name,
            'code' => $q->code,
            'is_active' => $q->is_active,
            'entries_count' => $q->entries_count ?? $q->entries()->count(),
            'waiting_count' => $q->waiting_count ?? $q->entries()->where('status', 'waiting')->count(),
            'serving_count' => $q->serving_count ?? $q->entries()->where('status', 'serving')->count(),
            'completed_today_count' => $q->completed_today_count ?? $q->entries()
                ->where('status', 'completed')
                ->whereBetween('completed_at', [today(), today()->endOfDay()])
                ->count(),
        ];
    }

    /** Prop tambahan untuk papan antrian: mode barbershop + daftar teknisi. */
    private function barbershopProps(Store $store): array
    {
        $enabled = BarbershopFeatures::enabled($store);

        return [
            'barbershopEnabled' => $enabled,
            'barbers' => $enabled
                ? User::barbersForStore($store)
                    ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])
                    ->values()
                    ->all()
                : [],
        ];
    }

    private function serializeEntry(QueueEntry $e): array
    {
        return [
            'id' => $e->id,
            'queue_id' => $e->queue_id,
            'queue_name' => $e->queue?->name,
            'queue_code' => $e->queue?->code,
            'number' => $e->number,
            'status' => $e->status,
            'order_type' => $e->order_type,
            'table_number' => $e->table_number,
            'customer_name' => $e->customer_name,
            'customer_phone' => $e->customer_phone,
            'priority' => $e->priority,
            'notes' => $e->notes,
            'items' => $e->items,
            'barber_user_id' => $e->barber_user_id,
            'barber_name' => $e->barber?->name,
            'called_at' => $e->called_at?->toIso8601String(),
            'completed_at' => $e->completed_at?->toIso8601String(),
            'created_at' => $e->created_at?->toIso8601String(),
            'transaction_id' => $e->transaction_id,
        ];
    }

    public function index()
    {
        [$store, $redirect] = $this->guardStore(false);
        if ($redirect) {
            return $redirect;
        }

        $queues = $store->queues()
            ->withCount(['entries', 'entries as waiting_count' => fn ($q) => $q->where('status', 'waiting'),
                'entries as serving_count' => fn ($q) => $q->where('status', 'serving'),
                'entries as completed_today_count' => fn ($q) => $q->where('status', 'completed')->whereBetween('completed_at', [today(), today()->endOfDay()]),
            ])
            ->orderBy('name')
            ->get()
            ->map(fn (Queue $q) => $this->serializeQueue($q));

        $entries = $store->queueEntries()
            ->with(['queue:id,name,code', 'barber:id,name'])
            ->whereNull('transaction_id')
            ->where('status', '!=', 'cancelled')
            ->whereBetween('created_at', [today(), today()->endOfDay()])
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn (QueueEntry $e) => $this->serializeEntry($e));

        return Inertia::render('Queues/Index', [
            'queues' => $queues,
            'entries' => $entries,
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
            ],
            ...$this->barbershopProps($store),
        ]);
    }

    public function show(Queue $queue)
    {
        [$store, $redirect] = $this->guardStore();
        if ($redirect) {
            return $redirect;
        }

        if ($queue->store_id !== $store->id) {
            abort(404);
        }

        $entries = $queue->entries()
            ->with('barber:id,name')
            ->latest()
            ->limit(200)
            ->get()
            ->map(fn (QueueEntry $e) => $this->serializeEntry($e));

        return Inertia::render('Queues/Show', [
            'queue' => [
                'id' => $queue->id,
                'name' => $queue->name,
                'code' => $queue->code,
                'is_active' => $queue->is_active,
            ],
            'entries' => $entries,
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
            ],
            ...$this->barbershopProps($store),
        ]);
    }

    public function store(Request $request)
    {
        [$store, $redirect] = $this->guardStore();
        if ($redirect) {
            return $redirect;
        }

        $validated = $request->validate([
            'name' => 'required|string|max:100',
        ]);

        $lastCode = $store->queues()->max('code');
        $nextNumber = $lastCode ? ((int) substr($lastCode, 1) + 1) : 1;
        $code = 'Q'.str_pad($nextNumber, 3, '0', STR_PAD_LEFT);

        $queue = $store->queues()->create([
            'name' => $validated['name'],
            'code' => $code,
            'is_active' => true,
        ]);

        return back()->with('success', "Antrian \"{$queue->name}\" berhasil dibuat.");
    }

    public function update(Request $request, Queue $queue)
    {
        [$store, $redirect] = $this->guardStore();
        if ($redirect) {
            return $redirect;
        }

        if ($queue->store_id !== $store->id) {
            abort(404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:100',
            'is_active' => 'sometimes|boolean',
        ]);

        $queue->update($validated);

        return back()->with('success', 'Antrian berhasil diperbarui.');
    }

    public function destroy(Queue $queue)
    {
        [$store, $redirect] = $this->guardStore();
        if ($redirect) {
            return $redirect;
        }

        if ($queue->store_id !== $store->id) {
            abort(404);
        }

        $queue->delete();

        return back()->with('success', 'Antrian berhasil dihapus.');
    }

    // --- Queue Entries ---

    public function storeEntry(Request $request, Queue $queue)
    {
        [$store, $redirect] = $this->guardStore();
        if ($redirect) {
            return $redirect;
        }

        if ($queue->store_id !== $store->id || ! $queue->is_active) {
            abort(404);
        }

        $validated = $request->validate([
            'order_type' => 'nullable|string|in:'.implode(',', QueueEntry::ORDER_TYPES),
            'table_number' => 'nullable|string|max:20',
            'customer_name' => 'nullable|string|max:255',
            'customer_phone' => 'nullable|string|max:40',
            'notes' => 'nullable|string|max:500',
            'priority' => 'nullable|integer|min:0|max:10',
            'barber_user_id' => 'nullable|integer|exists:users,id',
            'items' => 'nullable|array',
            'items.*.product_name' => 'required_with:items|string',
            'items.*.quantity' => 'required_with:items|numeric|min:1',
        ]);

        $barberUserId = null;

        if (BarbershopFeatures::enabled($store) && $request->filled('barber_user_id')) {
            $barberUserId = (int) $validated['barber_user_id'];

            if (! User::barbersForStore($store)->contains('id', $barberUserId)) {
                throw ValidationException::withMessages([
                    'barber_user_id' => 'Teknisi tidak valid atau bukan anggota toko.',
                ]);
            }
        }

        $entry = DB::transaction(function () use ($queue, $store, $validated, $barberUserId) {
            // Kunci baris queue agar penomoran antrian terserialisasi antar request.
            Queue::query()->whereKey($queue->id)->lockForUpdate()->first();

            $lastNumber = $queue->entries()->max('number');
            $nextNumber = $lastNumber ? ((int) $lastNumber + 1) : 1;

            return $queue->entries()->create([
                'store_id' => $store->id,
                'number' => $nextNumber,
                'status' => 'waiting',
                'order_type' => $validated['order_type'] ?? null,
                'table_number' => $validated['table_number'] ?? null,
                'customer_name' => $validated['customer_name'] ?? null,
                'customer_phone' => $validated['customer_phone'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'priority' => $validated['priority'] ?? 0,
                'barber_user_id' => $barberUserId,
                'items' => $validated['items'] ?? null,
            ]);
        });

        event(KitchenQueueUpdated::fromEntry($entry->load('transaction:id,transaction_number,order_type,table_reference')));

        return back()->with('success', "Nomor antrian #{$entry->number} berhasil dibuat.");
    }

    public function updateEntry(Request $request, QueueEntry $queueEntry)
    {
        [$store, $redirect] = $this->guardStore(false);
        if ($redirect) {
            return $redirect;
        }

        if ($queueEntry->store_id !== $store->id) {
            abort(404);
        }

        $validated = $request->validate([
            'status' => 'required|string|in:'.implode(',', QueueEntry::STATUSES),
        ]);

        $newStatus = $validated['status'];
        $allowedTransitions = [
            'waiting' => ['serving', 'cancelled'],
            'serving' => ['ready', 'completed'],
            'ready' => ['completed'],
        ];

        if (! in_array($newStatus, $allowedTransitions[$queueEntry->status] ?? [], true)) {
            abort(422, 'Perubahan status antrian tidak valid.');
        }

        $updates = ['status' => $newStatus];

        if ($newStatus === 'serving' && $queueEntry->status === 'waiting') {
            $updates['called_at'] = now();
        }

        if ($newStatus === 'completed' && $queueEntry->status !== 'completed') {
            $updates['completed_at'] = now();
        }
        DB::transaction(function () use ($queueEntry, $updates, $newStatus) {
            $queueEntry->update($updates);

            if ($queueEntry->transaction && $newStatus === 'ready') {
                $queueEntry->transaction->update(['order_status' => 'ready']);
            }

            if ($newStatus === 'completed' && $queueEntry->transaction) {
                $queueEntry->transaction->update([
                    'order_status' => 'completed',
                    'status' => 'completed',
                    'completed_at' => now(),
                ]);
            }
        });

        event(KitchenQueueUpdated::fromEntry($queueEntry->load('transaction:id,transaction_number,order_type,table_reference')));

        return response()->json([
            'success' => true,
            'message' => "Antrian #{$queueEntry->number} → {$newStatus}",
        ]);
    }

    public function destroyEntry(QueueEntry $queueEntry)
    {
        [$store, $redirect] = $this->guardStore();
        if ($redirect) {
            return $redirect;
        }

        if ($queueEntry->store_id !== $store->id) {
            abort(404);
        }

        $storeId = (int) $queueEntry->store_id;
        $entryId = (int) $queueEntry->id;
        $queueEntry->delete();

        event(KitchenQueueUpdated::deleted($storeId, $entryId));

        return response()->json([
            'success' => true,
            'message' => "Antrian #{$queueEntry->number} dihapus.",
        ]);
    }
}
