@php
    $received = $transfer->status === \App\Models\StockTransfer::STATUS_RECEIVED;
    $tz = $timezone ?? config('app.timezone');
    $fmtQty = fn ($value) => rtrim(rtrim(number_format((float) $value, 4, ',', '.'), '0'), ',');
    $docTitle = $received ? 'Bukti Terima' : 'Surat Jalan';
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $docTitle }} - {{ $transfer->transfer_number }}</title>
    <style>
        body { font-family: sans-serif; font-size: 12px; margin: 0; padding: 24px; color: #222; }
        h1 { font-size: 20px; margin: 0 0 4px; letter-spacing: 1px; }
        .muted { color: #666; }
        .head { width: 100%; border-bottom: 2px solid #111; padding-bottom: 10px; margin-bottom: 16px; }
        .head td { vertical-align: top; border: none; padding: 0; }
        .route { width: 100%; margin-bottom: 16px; border-collapse: collapse; }
        .route td { width: 50%; vertical-align: top; border: 1px solid #ccc; padding: 10px; }
        .label { font-size: 10px; text-transform: uppercase; letter-spacing: 1px; color: #666; margin-bottom: 4px; }
        .name { font-size: 14px; font-weight: bold; }
        table.items { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        table.items th, table.items td { padding: 7px 8px; border: 1px solid #ccc; text-align: left; }
        table.items th { background: #f3f4f6; font-size: 11px; }
        table.items .num { text-align: right; }
        .check { width: 150px; }
        .flag { font-size: 10px; font-weight: bold; }
        .sign .sig { height: 60px; margin-top: 4px; }
        .sign .sig img { max-height: 60px; max-width: 170px; }
        .sign .line.signed { margin-top: 0; }
        .sign { width: 100%; margin-top: 28px; border-collapse: collapse; }
        .sign td { width: 33%; text-align: center; vertical-align: top; padding: 0 10px; border: none; }
        .sign .line { margin-top: 64px; border-top: 1px solid #111; padding-top: 4px; }
        .note { border: 1px dashed #bbb; padding: 8px 10px; margin-bottom: 16px; }
    </style>
</head>
<body>
    <table class="head">
        <tr>
            <td>
                <h1>{{ $received ? 'BUKTI TERIMA BARANG' : 'SURAT JALAN' }}</h1>
                <div class="muted">Transfer Stok{{ $received ? ' · Surat Jalan yang sudah diterima' : '' }}</div>
            </td>
            <td style="text-align: right;">
                <div><strong>No:</strong> {{ $transfer->transfer_number }}</div>
                <div><strong>Tanggal kirim:</strong> {{ ($transfer->sent_at ?? $transfer->created_at)->copy()->setTimezone($timezone ?? config('app.timezone'))->format('d M Y H:i') }}</div>
                @if($received && $transfer->received_at)
                    <div><strong>Tanggal terima:</strong> {{ $transfer->received_at->copy()->setTimezone($tz)->format('d M Y H:i') }}</div>
                @endif
                <div><strong>Status:</strong> {{ $statusLabel }}</div>
            </td>
        </tr>
    </table>

    <table class="route">
        <tr>
            <td>
                <div class="label">Dari</div>
                <div class="name">{{ $transfer->fromStore->name ?? '-' }}</div>
                @if($transfer->fromStore?->address)<div class="muted">{{ $transfer->fromStore->address }}</div>@endif
                @if($transfer->fromStore?->phone)<div class="muted">Telp. {{ $transfer->fromStore->phone }}</div>@endif
            </td>
            <td>
                <div class="label">Kepada</div>
                <div class="name">{{ $transfer->toStore->name ?? '-' }}</div>
                @if($transfer->toStore?->address)<div class="muted">{{ $transfer->toStore->address }}</div>@endif
                @if($transfer->toStore?->phone)<div class="muted">Telp. {{ $transfer->toStore->phone }}</div>@endif
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th style="width: 32px;">No</th>
                <th>Nama barang</th>
                <th>SKU</th>
                <th class="num">Jumlah</th>
                <th>Satuan</th>
                <th class="check">Diterima</th>
            </tr>
        </thead>
        <tbody>
            @foreach($transfer->lines as $i => $line)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $line->fromProduct->name ?? $line->toProduct->name ?? '-' }}</td>
                    <td>{{ $line->fromProduct->sku ?? '' }}</td>
                    <td class="num">{{ rtrim(rtrim(number_format((float) $line->quantity, 4, ',', '.'), '0'), ',') }}</td>
                    <td>{{ $line->base_unit ?? $line->fromProduct->base_unit ?? 'pcs' }}</td>
                    <td class="check">
                        @if($received)
                            {{ $fmtQty($line->quantity_received ?? $line->quantity) }}
                            @if($line->discrepancy_type)
                                <div class="flag">{{ \App\Models\StockTransferLine::DISCREPANCY_LABELS[$line->discrepancy_type] ?? 'Selisih' }} {{ $fmtQty($line->discrepancyQuantity()) }}</div>
                            @endif
                            @if($line->discrepancy_reason)<div class="muted" style="font-size: 10px;">{{ $line->discrepancy_reason }}</div>@endif
                            @if($line->discrepancy_type)
                                <div class="muted" style="font-size: 10px;">{{ $line->resolution ? (\App\Models\StockTransferLine::RESOLUTION_LABELS[$line->resolution] ?? $line->resolution) : 'Menunggu keputusan pengirim' }}</div>
                            @endif
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if($transfer->notes)
        <div class="note"><strong>Catatan:</strong> {{ $transfer->notes }}</div>
    @endif

    <table class="sign">
        <tr>
            <td>
                Pengirim
                <div class="line">{{ $transfer->sentBy->name ?? $transfer->user->name ?? '' }}</div>
            </td>
            <td>
                Pengantar
                <div class="line">&nbsp;</div>
            </td>
            <td>
                Penerima
                @if($received && $transfer->receiver_signature)
                    <div class="sig"><img src="{{ $transfer->receiver_signature }}" alt="Tanda tangan penerima"></div>
                    <div class="line signed">{{ $transfer->receiver_name ?: ($transfer->receivedBy->name ?? '') }}</div>
                    @if($transfer->receiver_name && $transfer->receivedBy && $transfer->receivedBy->name !== $transfer->receiver_name)
                        <div class="muted" style="font-size: 10px;">Dicatat oleh {{ $transfer->receivedBy->name }}</div>
                    @endif
                @else
                    <div class="line">{{ $transfer->receiver_name ?: ($transfer->receivedBy->name ?? '') }}&nbsp;</div>
                @endif
            </td>
        </tr>
    </table>

    <p class="muted" style="margin-top: 24px; font-size: 10px;">
        Dicetak {{ now($tz)->format('d M Y H:i') }}.
        @if($received && $transfer->receiver_signature)
            Ditandatangani di layar oleh penerima saat barang diterima.
        @else
            Periksa jumlah barang saat diterima dan catat bila ada selisih.
        @endif
    </p>
</body>
</html>
