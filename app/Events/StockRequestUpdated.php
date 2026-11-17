<?php

namespace App\Events;

use App\Models\StockRequest;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * F6 — Permintaan Stok berubah. Disiarkan ke kedua lokasi (peminta & pemasok)
 * supaya daftar di layar gudang/outlet langsung diperbarui. Isi sengaja ringkas;
 * halaman memuat ulang datanya sendiri lewat server.
 */
class StockRequestUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        public readonly int $requestId,
        public readonly string $requestNumber,
        public readonly string $status,
        public readonly string $action,
        public readonly int $fromStoreId,
        public readonly int $toStoreId,
    ) {}

    public static function dispatchFor(StockRequest $request, string $action): void
    {
        try {
            event(new self(
                requestId: $request->id,
                requestNumber: $request->request_number,
                status: $request->status,
                action: $action,
                fromStoreId: (int) $request->from_store_id,
                toStoreId: (int) $request->to_store_id,
            ));
        } catch (\Throwable $e) {
            // Siaran realtime gagal (mis. server WebSocket mati) tidak boleh menggagalkan alur stok.
            report($e);
        }
    }

    /** @return PrivateChannel[] */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("stock-requests.{$this->fromStoreId}"),
            new PrivateChannel("stock-requests.{$this->toStoreId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'stock-request.updated';
    }
}
