<?php

namespace App\Services\Api\Sync;

use App\Models\SyncOutbox;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MobileSyncService
{
    /**
     * Memasukkan transaksi atau mutasi lokal ke antrian outbox untuk dikirim saat online.
     *
     * @param  array<string, mixed>  $payload
     */
    public function queue(string $entityType, string $clientUuid, array $payload, ?int $storeId = null): SyncOutbox
    {
        return SyncOutbox::updateOrCreate(
            ['client_uuid' => $clientUuid],
            [
                'store_id' => $storeId,
                'entity_type' => $entityType,
                'action' => 'create',
                'payload' => $payload,
                'status' => 'pending',
                'attempts' => 0,
            ]
        );
    }

    /**
     * Mengirim semua antrian pending di outbox ke server pusat.
     *
     * @return array{synced: int, failed: int}
     */
    public function flush(string $baseUrl, string $apiToken): array
    {
        $pendingItems = SyncOutbox::query()
            ->where('status', 'pending')
            ->orderBy('id')
            ->limit(20)
            ->get();

        $syncedCount = 0;
        $failedCount = 0;

        foreach ($pendingItems as $item) {
            $item->update(['status' => 'syncing']);

            try {
                $url = rtrim($baseUrl, '/').'/api/v1/sync/'.($item->entity_type === 'transaction' ? 'transactions' : $item->entity_type);

                $response = Http::withToken($apiToken)
                    ->acceptJson()
                    ->timeout(10)
                    ->post($url, $item->payload);

                if ($response->successful()) {
                    $item->update([
                        'status' => 'synced',
                        'synced_at' => now(),
                        'last_error' => null,
                    ]);
                    $syncedCount++;
                } else {
                    $item->increment('attempts');
                    $item->update([
                        'status' => 'failed',
                        'last_error' => 'HTTP '.$response->status().': '.$response->body(),
                    ]);
                    $failedCount++;
                }
            } catch (\Throwable $e) {
                Log::warning('Gagal sinkronisasi outbox item ID '.$item->id.': '.$e->getMessage());
                $item->increment('attempts');
                $item->update([
                    'status' => 'failed',
                    'last_error' => $e->getMessage(),
                ]);
                $failedCount++;
            }
        }

        return [
            'synced' => $syncedCount,
            'failed' => $failedCount,
        ];
    }
}
