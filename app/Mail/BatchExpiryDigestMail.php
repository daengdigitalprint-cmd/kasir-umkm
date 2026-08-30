<?php

namespace App\Mail;

use App\Models\Store;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BatchExpiryDigestMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  list<array{product_name: string, batch_code: string|null, quantity_remaining: float|string, expiry_date: string, expiry_status: string}>  $batches
     */
    public function __construct(
        public Store $store,
        public int $warnDays,
        public array $batches,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Peringatan batch & kedaluwarsa — '.$this->store->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.batch-expiry-digest',
        );
    }
}
