<?php

namespace App\Jobs;

use App\Models\Store;
use App\Services\LoyaltyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class BroadcastLoyaltyPromo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(
        public readonly int $storeId,
        public readonly string $title,
        public readonly string $body,
    ) {}

    public function handle(LoyaltyService $loyalty): void
    {
        $store = Store::find($this->storeId);
        if (! $store) {
            return;
        }

        $loyalty->broadcastPromo($store, $this->title, $this->body);
    }
}
