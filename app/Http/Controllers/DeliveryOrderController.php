<?php

namespace App\Http\Controllers;

use App\Models\DeliveryOrder;
use App\Support\CustomerSegments;
use App\Support\DeliveryFeatures;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * F10 — Pengantaran: daftar pesanan antar, ubah status (Disiapkan → Diantar →
 * Selesai), tetapkan kurir. Kurir bisa menyaring "pesanan saya".
 */
class DeliveryOrderController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $store = $user->store;
        $status = in_array($request->input('status'), ['active', ...array_keys(DeliveryOrder::LABELS)], true)
            ? $request->input('status')
            : 'active';
        $mine = $request->boolean('mine');

        $query = DeliveryOrder::query()
            ->where('store_id', $store->id)
            ->with(['transaction:id,transaction_number,total_amount,payment_status,created_at', 'courier:id,name'])
            ->when($status === 'active', fn ($q) => $q->whereIn('status', [DeliveryOrder::STATUS_PREPARING, DeliveryOrder::STATUS_ON_THE_WAY]))
            ->when($status !== 'active', fn ($q) => $q->where('status', $status))
            ->when($mine, fn ($q) => $q->where('courier_user_id', $user->id))
            ->orderByRaw('CASE status WHEN ? THEN 0 WHEN ? THEN 1 ELSE 2 END', [DeliveryOrder::STATUS_PREPARING, DeliveryOrder::STATUS_ON_THE_WAY])
            ->latest();

        $counts = DeliveryOrder::query()
            ->where('store_id', $store->id)
            ->when($mine, fn ($q) => $q->where('courier_user_id', $user->id))
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return Inertia::render('Deliveries/Index', [
            'orders' => $query->paginate(30)->withQueryString()->through(fn (DeliveryOrder $d) => [
                'id' => $d->id,
                'status' => $d->status,
                'status_label' => DeliveryOrder::LABELS[$d->status] ?? $d->status,
                'next_statuses' => DeliveryOrder::TRANSITIONS[$d->status] ?? [],
                'recipient_name' => $d->recipient_name,
                'phone' => $d->phone,
                'whatsapp' => CustomerSegments::whatsappNumber($d->phone),
                'address' => $d->address,
                'notes' => $d->notes,
                'delivery_fee' => (float) $d->delivery_fee,
                'courier' => $d->courier?->only(['id', 'name']),
                'transaction' => $d->transaction ? [
                    'id' => $d->transaction->id,
                    'number' => $d->transaction->transaction_number,
                    'total' => (float) $d->transaction->total_amount,
                    'payment_status' => $d->transaction->payment_status,
                ] : null,
                'created_at' => $d->created_at?->toIso8601String(),
                'dispatched_at' => $d->dispatched_at?->toIso8601String(),
                'delivered_at' => $d->delivered_at?->toIso8601String(),
            ]),
            'filters' => ['status' => $status, 'mine' => $mine],
            'counts' => [
                'active' => (int) (($counts[DeliveryOrder::STATUS_PREPARING] ?? 0) + ($counts[DeliveryOrder::STATUS_ON_THE_WAY] ?? 0)),
                ...collect(DeliveryOrder::LABELS)->keys()->mapWithKeys(fn ($k) => [$k => (int) ($counts[$k] ?? 0)])->all(),
            ],
            'labels' => DeliveryOrder::LABELS,
            'couriers' => DeliveryFeatures::couriers($store),
            'enabled' => DeliveryFeatures::enabled($store),
            'defaultFee' => DeliveryFeatures::defaultFee($store),
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
        // Kurir yang menekan "Antar" tanpa kurir terpilih otomatis jadi kurirnya.
        if ($validated['status'] === DeliveryOrder::STATUS_ON_THE_WAY && $deliveryOrder->courier_user_id === null) {
            $changes['courier_user_id'] = $request->user()->id;
        }

        $deliveryOrder->update($changes);

        return back()->with('success', 'Status pengantaran: '.DeliveryOrder::LABELS[$validated['status']].'.');
    }

    public function assignCourier(Request $request, DeliveryOrder $deliveryOrder): RedirectResponse
    {
        $this->authorizeStore($request, $deliveryOrder);

        $validated = $request->validate(['courier_user_id' => ['nullable', 'integer']]);
        $courierId = $validated['courier_user_id'] ?? null;
        $store = $request->user()->store;

        if ($courierId !== null && ! DeliveryFeatures::isCourierOf($store, (int) $courierId)) {
            return back()->withErrors(['courier_user_id' => 'Kurir harus karyawan toko ini.']);
        }
        if (in_array($deliveryOrder->status, [DeliveryOrder::STATUS_DELIVERED, DeliveryOrder::STATUS_CANCELLED], true)) {
            return back()->withErrors(['courier_user_id' => 'Pengantaran sudah selesai/dibatalkan.']);
        }

        $deliveryOrder->update(['courier_user_id' => $courierId]);

        return back()->with('success', 'Kurir diperbarui.');
    }

    /** Saklar modul & ongkir bawaan (admin toko). */
    public function settings(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageStoreSettings(), 403);

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'default_fee' => ['required', 'numeric', 'min:0', 'max:10000000'],
        ]);

        $store = $request->user()->store;
        $settings = $store->settings ?? [];
        $settings['delivery_enabled'] = (bool) $validated['enabled'];
        $settings['delivery_default_fee'] = round((float) $validated['default_fee'], 2);
        $store->update(['settings' => $settings]);

        return back()->with('success', $validated['enabled'] ? 'Pesanan Antar aktif di kasir.' : 'Pesanan Antar dimatikan.');
    }

    private function authorizeStore(Request $request, DeliveryOrder $deliveryOrder): void
    {
        abort_unless((int) $deliveryOrder->store_id === (int) $request->user()->store?->id, 404);
    }
}
