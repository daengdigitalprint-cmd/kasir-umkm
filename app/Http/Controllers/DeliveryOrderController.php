<?php

namespace App\Http\Controllers;

use App\Events\KitchenQueueUpdated;
use App\Models\CashShift;
use App\Models\DeliveryOrder;
use App\Models\QueueEntry;
use App\Models\Transaction;
use App\Support\CustomerSegments;
use App\Support\DeliveryFeatures;
use App\Support\KitchenOrders;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * F10 — Pengantaran: daftar pesanan antar, ubah status (Disiapkan → Diantar →
 * Selesai), tetapkan kurir. Kurir cukup berupa nama (tidak perlu akun pengguna).
 *
 * Pesanan dari tautan pesan antar (dibuat pelanggan sendiri) masuk sebagai
 * "Menunggu konfirmasi": kasir menerima/menolak, lalu mencatat pembayarannya di sini.
 */
class DeliveryOrderController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $store = $user->store;

        $counts = DeliveryOrder::query()
            ->where('store_id', $store->id)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');
        $pendingCount = DeliveryFeatures::pendingSelfOrderCount($store);

        // Tanpa pilihan tab: buka "Pesanan masuk" bila ada yang menunggu konfirmasi.
        $status = in_array($request->input('status'), ['active', ...array_keys(DeliveryOrder::LABELS)], true)
            ? $request->input('status')
            : ($pendingCount > 0 ? DeliveryOrder::STATUS_PENDING : 'active');

        $query = DeliveryOrder::query()
            ->where('store_id', $store->id)
            ->with([
                'transaction:id,transaction_number,total_amount,payment_status,payment_method,payment_details,created_at',
                'transaction.items:id,transaction_id,product_name,quantity,modifiers',
                'courier:id,name',
            ])
            ->when($status === 'active', fn ($q) => $q->whereIn('status', [DeliveryOrder::STATUS_PREPARING, DeliveryOrder::STATUS_ON_THE_WAY]))
            ->when($status !== 'active', fn ($q) => $q->where('status', $status))
            ->orderByRaw('CASE status WHEN ? THEN 0 WHEN ? THEN 1 ELSE 2 END', [DeliveryOrder::STATUS_PREPARING, DeliveryOrder::STATUS_ON_THE_WAY]);
        // Pesanan masuk: yang paling lama menunggu di atas.
        $status === DeliveryOrder::STATUS_PENDING ? $query->oldest() : $query->latest();

        return Inertia::render('Deliveries/Index', [
            'orders' => $query->paginate(30)->withQueryString()->through(fn (DeliveryOrder $d) => [
                'id' => $d->id,
                'status' => $d->status,
                'status_label' => DeliveryOrder::LABELS[$d->status] ?? $d->status,
                'next_statuses' => DeliveryOrder::TRANSITIONS[$d->status] ?? [],
                'is_self_order' => DeliveryFeatures::isSelfOrder($d->transaction),
                'recipient_name' => $d->recipient_name,
                'phone' => $d->phone,
                'whatsapp' => CustomerSegments::whatsappNumber($d->phone),
                'address' => $d->address,
                'notes' => $d->notes,
                'delivery_fee' => (float) $d->delivery_fee,
                'courier' => $d->courierLabel(),
                'transaction' => $d->transaction ? [
                    'id' => $d->transaction->id,
                    'number' => $d->transaction->transaction_number,
                    'total' => (float) $d->transaction->total_amount,
                    'payment_status' => $d->transaction->payment_status,
                    'payment_method' => $d->transaction->payment_method,
                ] : null,
                'items' => $d->transaction
                    ? $d->transaction->items->map(fn ($i) => [
                        'name' => $i->product_name,
                        'quantity' => (float) $i->quantity,
                        'note' => GuestMenuController::guestItem($i)['note'],
                    ])->values()->all()
                    : [],
                'created_at' => $d->created_at?->toIso8601String(),
                'dispatched_at' => $d->dispatched_at?->toIso8601String(),
                'delivered_at' => $d->delivered_at?->toIso8601String(),
            ]),
            'filters' => ['status' => $status],
            'counts' => [
                'active' => (int) (($counts[DeliveryOrder::STATUS_PREPARING] ?? 0) + ($counts[DeliveryOrder::STATUS_ON_THE_WAY] ?? 0)),
                ...collect(DeliveryOrder::LABELS)->keys()->mapWithKeys(fn ($k) => [$k => (int) ($counts[$k] ?? 0)])->all(),
                DeliveryOrder::STATUS_PENDING => $pendingCount,
            ],
            'labels' => DeliveryOrder::LABELS,
            // Nama-nama kurir toko (tanpa akun). Kosong = pengantaran hanya berketerangan "Kurir toko".
            'couriers' => DeliveryFeatures::courierNames($store),
            'enabled' => DeliveryFeatures::enabled($store),
            'defaultFee' => DeliveryFeatures::defaultFee($store),
            'selfOrder' => [
                'enabled' => (bool) ($store->settings['delivery_self_order_enabled'] ?? false),
                'url' => DeliveryFeatures::publicUrl($store),
            ],
            'canManage' => $user->canManageStoreSettings(),
            'currentUserId' => $user->id,
        ]);
    }

    public function updateStatus(Request $request, DeliveryOrder $deliveryOrder): RedirectResponse
    {
        $this->authorizeStore($request, $deliveryOrder);

        $validated = $request->validate([
            'status' => ['required', Rule::in(array_keys(DeliveryOrder::LABELS))],
        ]);

        if (! $deliveryOrder->canMoveTo($validated['status'])) {
            return back()->withErrors(['status' => 'Status "'.DeliveryOrder::LABELS[$deliveryOrder->status].'" tidak bisa diubah ke "'.DeliveryOrder::LABELS[$validated['status']].'".']);
        }

        $changes = ['status' => $validated['status']];
        match ($validated['status']) {
            DeliveryOrder::STATUS_ON_THE_WAY => $changes['dispatched_at'] = now(),
            DeliveryOrder::STATUS_DELIVERED => $changes['delivered_at'] = now(),
            DeliveryOrder::STATUS_CANCELLED => $changes['cancelled_at'] = now(),
            default => null,
        };
        $deliveryOrder->update($changes);

        // Pesanan dari tautan yang belum dibayar: membatalkan pengantaran berarti pesanannya batal.
        $transaction = $deliveryOrder->transaction;
        if ($validated['status'] === DeliveryOrder::STATUS_CANCELLED
            && DeliveryFeatures::isSelfOrder($transaction)
            && $transaction->payment_status !== 'paid') {
            $this->cancelTransaction($request, $transaction, 'Dibatalkan toko');
        }

        return back()->with('success', 'Status pengantaran: '.DeliveryOrder::LABELS[$validated['status']].'.');
    }

    public function assignCourier(Request $request, DeliveryOrder $deliveryOrder): RedirectResponse
    {
        $this->authorizeStore($request, $deliveryOrder);

        $validated = $request->validate(['courier_name' => ['nullable', 'string', 'max:120']]);

        if (in_array($deliveryOrder->status, [DeliveryOrder::STATUS_DELIVERED, DeliveryOrder::STATUS_CANCELLED], true)) {
            return back()->withErrors(['courier_name' => 'Pengantaran sudah selesai/dibatalkan.']);
        }

        // Kurir cukup nama. Tautan ke akun pengguna (data lama) dilepas agar tidak tampil ganda.
        $deliveryOrder->update([
            'courier_name' => DeliveryFeatures::cleanCourierName($validated['courier_name'] ?? null),
            'courier_user_id' => null,
        ]);

        return back()->with('success', 'Kurir diperbarui.');
    }

    /** Terima pesanan dari tautan pesan antar: mulai disiapkan dan diteruskan ke dapur. */
    public function accept(Request $request, DeliveryOrder $deliveryOrder): RedirectResponse
    {
        $this->authorizeStore($request, $deliveryOrder);

        if ($deliveryOrder->status !== DeliveryOrder::STATUS_PENDING) {
            return back()->with('error', 'Pesanan sudah diproses.');
        }

        $store = $request->user()->store;
        $transaction = $deliveryOrder->transaction;

        $deliveryOrder->update(['status' => DeliveryOrder::STATUS_PREPARING]);
        $transaction->update([
            'order_status' => 'accepted',
            'accepted_at' => now(),
            'user_id' => $request->user()->id,
        ]);

        if (KitchenOrders::shouldEnqueue($store)) {
            KitchenOrders::enqueue($store, $transaction, [
                'order_type' => 'delivery',
                'customer_name' => $deliveryOrder->recipient_name,
                'customer_phone' => $deliveryOrder->phone,
                'notes' => $deliveryOrder->notes,
            ]);

            return back()->with('success', 'Pesanan diterima dan diteruskan ke dapur.');
        }

        return back()->with('success', 'Pesanan diterima. Siapkan pesanannya lalu tetapkan kurir.');
    }

    /** Tolak pesanan dari tautan pesan antar; alasannya terlihat oleh pelanggan. */
    public function reject(Request $request, DeliveryOrder $deliveryOrder): RedirectResponse
    {
        $this->authorizeStore($request, $deliveryOrder);

        if ($deliveryOrder->status !== DeliveryOrder::STATUS_PENDING) {
            return back()->with('error', 'Pesanan sudah diproses.');
        }

        $validated = $request->validate(['reason' => 'nullable|string|max:500']);

        $deliveryOrder->update(['status' => DeliveryOrder::STATUS_CANCELLED, 'cancelled_at' => now()]);
        $this->cancelTransaction($request, $deliveryOrder->transaction, 'Ditolak kasir', $validated['reason'] ?? null);

        return back()->with('success', 'Pesanan ditolak.');
    }

    /** Catat pembayaran pesanan dari tautan pesan antar (bayar di tempat, transfer, atau QRIS). */
    public function pay(Request $request, DeliveryOrder $deliveryOrder): RedirectResponse
    {
        $this->authorizeStore($request, $deliveryOrder);

        $transaction = $deliveryOrder->transaction;
        if (! DeliveryFeatures::isSelfOrder($transaction)) {
            abort(404);
        }
        if ($transaction->payment_status === 'paid') {
            return back()->with('error', 'Pesanan sudah dibayar.');
        }
        if (in_array($deliveryOrder->status, [DeliveryOrder::STATUS_PENDING, DeliveryOrder::STATUS_CANCELLED], true)) {
            return back()->with('error', 'Terima pesanan terlebih dahulu sebelum mencatat pembayaran.');
        }

        $validated = $request->validate([
            'payment_method' => 'required|in:cash,qris,non_cash,card,ewallet,transfer,other,bank_transfer',
            'payment_reference' => 'nullable|string|max:255',
        ]);

        $user = $request->user();
        $shift = CashShift::query()
            ->where('store_id', $deliveryOrder->store_id)
            ->where('user_id', $user->id)
            ->whereNull('closed_at')
            ->first();
        if (! $shift) {
            return back()->with('error', 'Buka shift kasir terlebih dahulu.');
        }

        $details = $transaction->payment_details ?? [];
        $details['paid_by'] = $user->id;
        $details['paid_at'] = now()->toIso8601String();
        if ($validated['payment_reference'] ?? null) {
            $details['payment_reference'] = $validated['payment_reference'];
        }

        $transaction->update([
            'payment_method' => $validated['payment_method'],
            'payment_status' => 'paid',
            'status' => 'completed',
            'order_status' => 'completed',
            'amount_paid' => $transaction->total_amount,
            'change_amount' => 0,
            'cash_shift_id' => $shift->id,
            'payment_details' => $details,
            'completed_at' => now(),
            'user_id' => $user->id,
        ]);

        $this->syncQueueEntries($transaction, 'completed');

        return back()->with('success', 'Pembayaran pesanan '.($deliveryOrder->recipient_name ?: $transaction->transaction_number).' berhasil dicatat.');
    }

    /** Saklar modul & ongkir bawaan (admin toko). */
    public function settings(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageStoreSettings(), 403);

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'default_fee' => ['required', 'numeric', 'min:0', 'max:10000000'],
            'self_order_enabled' => ['sometimes', 'boolean'],
            'courier_names' => ['sometimes', 'array', 'max:'.DeliveryFeatures::MAX_COURIER_NAMES],
            'courier_names.*' => ['nullable', 'string', 'max:'.DeliveryFeatures::MAX_COURIER_NAME_LENGTH],
        ]);

        $store = $request->user()->store;
        $settings = $store->settings ?? [];
        $settings['delivery_enabled'] = (bool) $validated['enabled'];
        $settings['delivery_default_fee'] = round((float) $validated['default_fee'], 2);
        if (array_key_exists('self_order_enabled', $validated)) {
            $settings['delivery_self_order_enabled'] = (bool) $validated['self_order_enabled'];
        }
        // Kurir toko: daftar nama saja (tanpa akun). Kosong = pengantaran berketerangan "Kurir toko".
        if (array_key_exists('courier_names', $validated)) {
            $settings['delivery_courier_names'] = DeliveryFeatures::normalizeCourierNames($validated['courier_names']);
        }
        $store->update(['settings' => $settings]);

        return back()->with('success', $validated['enabled'] ? 'Pesanan Antar aktif di kasir.' : 'Pesanan Antar dimatikan.');
    }

    /** Batalkan transaksi pesanan tamu yang belum dibayar dan cabut dari papan dapur. */
    private function cancelTransaction(Request $request, Transaction $transaction, string $label, ?string $reason = null): void
    {
        $transaction->update([
            'order_status' => 'cancelled',
            'status' => 'cancelled',
            // Format "[Ditolak kasir: ...]" dibaca halaman status tamu (GuestMenuController::rejectReason).
            'notes' => trim($transaction->notes."\n[{$label}: ".($reason ?: 'Tidak ada alasan').']'),
            'user_id' => $request->user()->id,
        ]);

        $this->syncQueueEntries($transaction, 'cancelled');
    }

    private function syncQueueEntries(Transaction $transaction, string $status): void
    {
        foreach (QueueEntry::query()->where('transaction_id', $transaction->id)->get() as $entry) {
            $entry->update(['status' => $status]);
            KitchenOrders::broadcast(KitchenQueueUpdated::fromEntry($entry->load('transaction:id,transaction_number,order_type,table_reference')));
        }
    }

    private function authorizeStore(Request $request, DeliveryOrder $deliveryOrder): void
    {
        abort_unless((int) $deliveryOrder->store_id === (int) $request->user()->store?->id, 404);
    }
}
