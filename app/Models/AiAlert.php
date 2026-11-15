<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiAlert extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'store_id',
        'type',
        'title',
        'message',
        'data',
        'alert_date',
        'is_read',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data' => 'array',
            'alert_date' => 'date',
            'is_read' => 'boolean',
        ];
    }

    /**
     * Halaman detail yang dibuka saat peringatan diklik, sudah terfilter
     * sesuai temuan (mis. rentang tanggal anomali).
     */
    public function detailUrl(): string
    {
        $data = (array) ($this->data ?? []);
        $day = $this->alert_date ?? $this->created_at ?? now();

        switch ($this->type) {
            case 'low_stock':
                return '/stock-overview?low_stock=1';

            case 'transaction_anomaly':
                // Detektor memeriksa 24 jam sebelum peringatan dibuat.
                $to = $this->created_at ?? $day;
                $from = $to->copy()->subDay();
                $onlyCashDifference = ! empty($data['cash_differences'])
                    && empty($data['discount_anomalies'])
                    && empty($data['void_patterns']);

                return $onlyCashDifference
                    ? '/reports/cash-shifts?'.http_build_query(['start_date' => $from->toDateString(), 'end_date' => $to->toDateString()])
                    : '/pos/transactions?'.http_build_query(['date_from' => $from->toDateString(), 'date_to' => $to->toDateString()]);

            case 'churn_risk':
                return '/customers';

            case 'margin_drop':
                // Jendela margin berakhir sehari sebelum tanggal peringatan.
                $windowDays = max(1, (int) ($data['window_days'] ?? 7));

                return '/reports/sales?'.http_build_query([
                    'start_date' => $day->copy()->subDays($windowDays)->toDateString(),
                    'end_date' => $day->copy()->subDay()->toDateString(),
                ]);

            default:
                return '/dashboard';
        }
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
