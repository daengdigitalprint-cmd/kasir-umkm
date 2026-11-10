<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invoice - {{ $transaction->transaction_number }}</title>
    <style>
        body { font-family: sans-serif; font-size: 14px; margin: 0; padding: 20px; color: #333; }
        .header { text-align: center; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 1px solid #ddd; }
        .store-name { font-size: 24px; font-weight: bold; margin-bottom: 5px; }
        .store-info { font-size: 12px; color: #666; margin-bottom: 10px; }
        .invoice-details { margin-bottom: 20px; display: table; width: 100%; }
        .invoice-details div { display: table-cell; width: 50%; }
        .right-align { text-align: right; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th, td { padding: 10px; border-bottom: 1px solid #ddd; text-align: left; }
        th { background-color: #f8f9fa; font-weight: bold; color: #111; }
        .text-right { text-align: right; }
        .totals-table { width: 50%; float: right; border-collapse: collapse; }
        .totals-table td { padding: 5px 10px; border: none; }
        .totals-table .border-top { border-top: 1px solid #111; font-weight: bold; }
        .clearfix { clear: both; }
        .footer { text-align: center; margin-top: 40px; font-size: 12px; color: #777; border-top: 1px dashed #ccc; padding-top: 20px; }
        .badge { display: inline-block; padding: 2px 6px; font-size: 10px; background: #eee; border-radius: 4px; margin-left: 5px; }
    </style>
</head>
<body>
    <div class="header">
        @if(!empty($receipt['show_logo']) && !empty($receipt['logo_url']))
            <img src="{{ public_path(ltrim(parse_url($receipt['logo_url'], PHP_URL_PATH), '/')) }}" 
                 alt="Logo" 
                 style="max-height: 80px; max-width: 200px; margin-bottom: 10px;">
        @endif
        <div class="store-name">{{ $receipt['header_name'] ?? $store->name }}</div>
        @if(!empty($receipt['subtitle']))
            <div class="store-info">{{ $receipt['subtitle'] }}</div>
        @endif
        @if((!isset($receipt['show_address']) || $receipt['show_address']) && ($store->address || !empty($receipt['address'])))
        <div class="store-info" style="margin-bottom: 2px;">
            {{ !empty($receipt['address']) ? $receipt['address'] : $store->address }}
        </div>
        @endif
        @if((!isset($receipt['show_phone']) || $receipt['show_phone']) && ($store->phone || !empty($receipt['phone'])))
        <div class="store-info" style="margin-bottom: 10px;">
            Telp. {{ !empty($receipt['phone']) ? $receipt['phone'] : $store->phone }}
        </div>
        @endif
        <h2 style="margin-top: 15px; margin-bottom: 5px;">INVOICE</h2>
    </div>

    <div class="invoice-details">
        <div>
            <strong>No:</strong> {{ $transaction->transaction_number }}<br>
            <strong>Tgl:</strong> {{ $transaction->created_at->format('d M Y H:i') }}<br>
            <strong>Kasir:</strong> {{ $transaction->user->name ?? 'Kasir' }}<br>
            @if($transaction->barber)
                <strong>Teknisi:</strong> {{ $transaction->barber->name }}
            @endif
        </div>
        <div class="right-align">
            <strong>Pelanggan:</strong> {{ $transaction->customer->name ?? 'Umum' }}<br>
            <strong>Metode Bayar:</strong> {{ strtoupper($transaction->payment_method) }}
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Produk</th>
                <th class="text-right">Harga</th>
                <th class="text-right">Qty</th>
                <th class="text-right">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @foreach($transaction->items as $item)
            <tr>
                <td>
                    {{ $item->product_name }}
                    @if($item->variant_label)
                        <span class="badge">{{ $item->variant_label }}</span>
                    @endif
                    @if($item->modifiers && count($item->modifiers) > 0)
                        <div style="font-size: 11px; color: #666; margin-top: 2px;">
                            + {{ collect($item->modifiers)->pluck('label')->filter()->join(', ') }}
                        </div>
                    @endif
                </td>
                <td class="text-right">Rp {{ number_format($item->unit_price, 0, ',', '.') }}</td>
                <td class="text-right">{{ (float) $item->quantity }}</td>
                <td class="text-right">Rp {{ number_format($item->total_price, 0, ',', '.') }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals-table">
        <tr>
            <td>Subtotal</td>
            <td class="text-right">Rp {{ number_format($transaction->subtotal, 0, ',', '.') }}</td>
        </tr>
        @if($transaction->discount_amount > 0)
        <tr>
            <td>Diskon</td>
            <td class="text-right">- Rp {{ number_format($transaction->discount_amount, 0, ',', '.') }}</td>
        </tr>
        @endif
        @if($transaction->service_charge_amount > 0)
        <tr>
            <td>Service Charge</td>
            <td class="text-right">Rp {{ number_format($transaction->service_charge_amount, 0, ',', '.') }}</td>
        </tr>
        @endif
        @if($transaction->tax_amount > 0)
        <tr>
            <td>Pajak</td>
            <td class="text-right">Rp {{ number_format($transaction->tax_amount, 0, ',', '.') }}</td>
        </tr>
        @endif
        @if($transaction->tip_amount > 0)
        <tr>
            <td>Tip</td>
            <td class="text-right">Rp {{ number_format($transaction->tip_amount, 0, ',', '.') }}</td>
        </tr>
        @endif
        <tr class="border-top">
            <td>TOTAL</td>
            <td class="text-right">Rp {{ number_format($transaction->total_amount, 0, ',', '.') }}</td>
        </tr>
        @php
            $preOrderAudit = json_decode($transaction->payment_details, true)['pre_order'] ?? null;
        @endphp
        @if($preOrderAudit && !empty($preOrderAudit['order_number']))
        <tr>
            <td>Pre-order</td>
            <td class="text-right">{{ $preOrderAudit['order_number'] }}</td>
        </tr>
        <tr>
            <td>Deposit</td>
            <td class="text-right">Rp {{ number_format($preOrderAudit['deposit_applied'] ?? 0, 0, ',', '.') }}</td>
        </tr>
        @if(($preOrderAudit['balance_due'] ?? 0) > 0)
        <tr>
            <td>Sisa Tag</td>
            <td class="text-right">Rp {{ number_format($preOrderAudit['balance_due'], 0, ',', '.') }}</td>
        </tr>
        @endif
        @endif
        <tr>
            <td>Dibayar ({{ strtoupper($transaction->payment_method) }})</td>
            <td class="text-right">Rp {{ number_format($transaction->amount_paid, 0, ',', '.') }}</td>
        </tr>
        @if($transaction->change_amount > 0)
        <tr>
            <td>Kembalian</td>
            <td class="text-right">Rp {{ number_format($transaction->change_amount, 0, ',', '.') }}</td>
        </tr>
        @endif
    </table>
    
    <div class="clearfix"></div>

    <div class="footer">
        {{ $receipt['footer'] ?? 'Terima kasih atas kunjungan Anda.' }}<br>
        @if(!empty($receipt['footer_line2']))
            {{ $receipt['footer_line2'] }}<br>
        @endif
        Dicetak pada {{ now()->format('d M Y H:i') }}
    </div>
</body>
</html>