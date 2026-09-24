<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Store;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

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
        $message = (string) ($this->alert['message'] ?? '');

        $mail = (new MailMessage)
            ->subject("[{$this->store->name}] {$title}")
            ->line($message);

        $data = (array) ($this->alert['data'] ?? []);
        if ($data !== []) {
            $mail->line('Detail: '.json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return $mail->line('— '.$this->store->name.' ('.config('app.name').')');
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
