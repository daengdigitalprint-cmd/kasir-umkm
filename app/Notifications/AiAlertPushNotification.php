<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Store;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Route;

/**
 * Notifikasi email push peringatan proaktif / digest harian.
 * Dikirim oleh AlertPushService — channel email saja; telegram/whatsapp
 * dikirim langsung via HTTP webhook.
 */
class AiAlertPushNotification extends Notification
{
    use Queueable;

    /**
     * @param  array{type?: string, title?: string, message?: string, data?: array<string, mixed>}  $alert
     */
    public function __construct(
        private readonly Store $store,
        private readonly array $alert,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $title = (string) ($this->alert['title'] ?? 'Peringatan');
        [$actionLabel, $actionRoute] = match ($this->alert['type'] ?? null) {
            'low_stock' => ['Lihat stok produk', 'products.index'],
            default => ['Buka dashboard', 'dashboard'],
        };

        return (new MailMessage)
            ->subject("[{$this->store->name}] {$title}")
            ->view('emails.ai-alert', [
                'store' => $this->store,
                'name' => $notifiable->name ?? null,
                'alert' => $this->alert,
                'actionLabel' => $actionLabel,
                'actionUrl' => Route::has($actionRoute) ? route($actionRoute) : null,
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'store_id' => $this->store->id,
            'type' => $this->alert['type'] ?? null,
            'title' => $this->alert['title'] ?? null,
            'message' => $this->alert['message'] ?? null,
        ];
    }
}
