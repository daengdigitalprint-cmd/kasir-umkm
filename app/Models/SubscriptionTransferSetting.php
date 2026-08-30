<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class SubscriptionTransferSetting extends Model
{
    protected $fillable = [
        'bank_name',
        'account_holder',
        'account_number',
        'qris_path',
    ];

    /**
     * Satu baris pengaturan transfer untuk langganan (semua toko memakai yang sama).
     */
    public static function current(): self
    {
        $row = static::query()->first();
        if ($row !== null) {
            return $row;
        }

        return static::create([
            'bank_name' => null,
            'account_holder' => null,
            'account_number' => null,
            'qris_path' => null,
        ]);
    }

    /**
     * @return array{bank_name: string, account_holder: string, account_number: string, qris_url: string|null}
     */
    public function toCheckoutPayload(): array
    {
        return [
            'bank_name' => (string) ($this->bank_name ?? ''),
            'account_holder' => (string) ($this->account_holder ?? ''),
            'account_number' => (string) ($this->account_number ?? ''),
            'qris_url' => $this->qris_path
                ? Storage::disk('public')->url($this->qris_path)
                : null,
        ];
    }
}
