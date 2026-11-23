@php
    $type = $alert['type'] ?? null;
    $data = (array) ($alert['data'] ?? []);
    $accent = match ($type) {
        'low_stock', 'transaction_anomaly' => '#dc2626',
        'margin_drop', 'churn_risk' => '#d97706',
        default => '#7c3aed',
    };
    $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, ',', '.'), '0'), ',');
    $items = $type === 'low_stock' ? (array) ($data['items'] ?? []) : [];
    $remaining = $type === 'low_stock' ? max(0, (int) ($data['count'] ?? 0) - count($items)) : 0;
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $alert['title'] ?? 'Peringatan' }}</title>
</head>
<body style="margin: 0; padding: 0; background: #ffffff; font-family: -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #1e293b; line-height: 1.5;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background: #ffffff;">
    <tr>
        <td align="center" style="padding: 24px 12px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width: 560px; background: #ffffff; border-radius: 8px; overflow: hidden; border: 1px solid #e5e7eb; box-shadow: none;">
                <tr>
                    <td style="height: 4px; background: {{ $accent }}; font-size: 0; line-height: 0;">&nbsp;</td>
                </tr>
                <tr>
                    <td style="padding: 24px 24px 8px;">
                        <p style="margin: 0 0 4px; font-size: 12px; letter-spacing: .04em; text-transform: uppercase; color: #64748b;">{{ $store->name }}</p>
                        <h1 style="margin: 0; font-size: 20px; line-height: 1.3; color: #0f172a;">{{ $alert['title'] ?? 'Peringatan' }}</h1>
                    </td>
                </tr>

                @if ($type === 'low_stock')
                    <tr>
                        <td style="padding: 8px 24px 16px; font-size: 15px;">
                            <strong>{{ (int) ($data['count'] ?? count($items)) }} produk</strong> sudah di bawah stok minimum atau habis. Segera restock sebelum kehabisan.
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 0 24px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse: collapse; font-size: 14px;">
                                <tr style="background: #f8fafc;">
                                    <th align="left" style="padding: 8px 10px; border-bottom: 1px solid #e2e8f0; color: #475569; font-weight: 600;">Produk</th>
                                    <th align="right" style="padding: 8px 10px; border-bottom: 1px solid #e2e8f0; color: #475569; font-weight: 600;">Sisa</th>
                                    <th align="right" style="padding: 8px 10px; border-bottom: 1px solid #e2e8f0; color: #475569; font-weight: 600;">Minimum</th>
                                </tr>
                                @foreach ($items as $item)
                                    @php
                                        $unit = trim((string) ($item['unit'] ?? ''));
                                        $empty = (float) ($item['stock_quantity'] ?? 0) <= 0;
                                    @endphp
                                    <tr>
                                        <td style="padding: 10px; border-bottom: 1px solid #f1f5f9;">
                                            {{ $item['name'] ?? '-' }}
                                            @if ($empty)
                                                <span style="display: inline-block; margin-left: 6px; padding: 1px 8px; border-radius: 999px; background: #fee2e2; color: #b91c1c; font-size: 11px; font-weight: 600;">HABIS</span>
                                            @endif
                                        </td>
                                        <td align="right" style="padding: 10px; border-bottom: 1px solid #f1f5f9; white-space: nowrap; font-weight: 600; color: {{ $empty ? '#b91c1c' : '#c2410c' }};">{{ $fmt($item['stock_quantity'] ?? 0) }} {{ $unit }}</td>
                                        <td align="right" style="padding: 10px; border-bottom: 1px solid #f1f5f9; white-space: nowrap; color: #64748b;">{{ $fmt($item['min_stock_level'] ?? 0) }} {{ $unit }}</td>
                                    </tr>
                                @endforeach
                            </table>
                            @if ($remaining > 0)
                                <p style="margin: 10px 0 0; font-size: 13px; color: #64748b;">+ {{ $remaining }} produk lain. Lihat daftar lengkap di aplikasi.</p>
                            @endif
                        </td>
                    </tr>
                @elseif ($type === 'margin_drop')
                    <tr>
                        <td style="padding: 8px 24px 16px; font-size: 15px;">{{ $alert['message'] ?? '' }}</td>
                    </tr>
                    <tr>
                        <td style="padding: 0 24px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse: collapse; font-size: 14px;">
                                <tr>
                                    <td style="padding: 10px; background: #f8fafc; border-radius: 8px 0 0 8px;">
                                        <div style="font-size: 12px; color: #64748b;">{{ (int) ($data['window_days'] ?? 7) }} hari sebelumnya</div>
                                        <div style="font-size: 20px; font-weight: 700;">{{ $fmt($data['previous_margin_pct'] ?? 0) }}%</div>
                                    </td>
                                    <td style="padding: 10px; background: #fef3c7; border-radius: 0 8px 8px 0;">
                                        <div style="font-size: 12px; color: #92400e;">{{ (int) ($data['window_days'] ?? 7) }} hari terakhir</div>
                                        <div style="font-size: 20px; font-weight: 700; color: #b45309;">{{ $fmt($data['recent_margin_pct'] ?? 0) }}%</div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                @else
                    <tr>
                        <td style="padding: 8px 24px 0; font-size: 15px;">{!! nl2br(e($alert['message'] ?? '')) !!}</td>
                    </tr>
                @endif

                @if ($actionUrl)
                    <tr>
                        <td style="padding: 24px 24px 8px;">
                            <a href="{{ $actionUrl }}" style="display: inline-block; padding: 11px 20px; border-radius: 8px; background: #7c3aed; color: #ffffff; font-size: 14px; font-weight: 600; text-decoration: none;">{{ $actionLabel }}</a>
                        </td>
                    </tr>
                @endif

                <tr>
                    <td style="padding: 20px 24px 24px; font-size: 12px; color: #94a3b8; border-top: 1px solid #f1f5f9;">
                        Email otomatis dari {{ config('app.name') }} untuk pengelola {{ $store->name }}.
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
