<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\AiAlert;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AiAlertCreated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public readonly AiAlert $alert) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("App.Models.User.{$this->alert->user_id}")];
    }

    public function broadcastAs(): string
    {
        return 'ai.alert.created';
    }

    /**
     * Payload broadcast — field minimal agar frontend bisa langsung menampilkan toast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->alert->id,
            'type' => $this->alert->type,
            'title' => $this->alert->title,
            'message' => $this->alert->message,
            'store_id' => $this->alert->store_id,
            'created_at' => $this->alert->created_at?->toIso8601String(),
        ];
    }
}
