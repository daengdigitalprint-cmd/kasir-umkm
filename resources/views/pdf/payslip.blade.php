@php
    $rp = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.');
@endphp
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Slip Gaji {{ $employee?->name }} — {{ $period->name }}</title>
<style>
    @page { margin: 18px 22px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 9.5px; color: #1e293b; }
    h1 { font-size: 13px; margin: 0; }
    .muted { color: #64748b; }
    .head { border-bottom: 2px solid #4f46e5; padding-bottom: 6px; margin-bottom: 8px; }
    .head table, .info, .lines { width: 100%; border-collapse: collapse; }
    .info td { padding: 1px 0; vertical-align: top; }
    .section { margin-top: 8px; font-weight: bold; color: #4338ca; border-bottom: 1px solid #c7d2fe; padding-bottom: 2px; }
    .lines td { padding: 2px 0; }
    .lines td.amt { text-align: right; white-space: nowrap; }
    .total td { border-top: 1px solid #cbd5e1; font-weight: bold; padding-top: 3px; }
    .net { margin-top: 10px; background: #eef2ff; border: 1px solid #c7d2fe; padding: 7px 9px; }
    .net table { width: 100%; }
    .net .label { font-size: 11px; font-weight: bold; }
    .net .value { font-size: 14px; font-weight: bold; text-align: right; color: #3730a3; }
    .draft { margin-top: 6px; padding: 4px 6px; background: #fef3c7; color: #92400e; font-weight: bold; text-align: center; }
    .warn { margin-top: 6px; color: #b91c1c; }
    .foot { margin-top: 12px; font-size: 8px; color: #94a3b8; }
</style>
</head>
<body>
    <div class="head">
        <table>
            <tr>
                <td>
                    <h1>{{ $store->name }}</h1>
                    <div class="muted">Slip Gaji Karyawan</div>
                </td>
                <td style="text-align:right">
                    <strong>{{ $period->name }}</strong><br>
                    <span class="muted">{{ $period->period_start->format('d/m/Y') }} – {{ $period->period_end->format('d/m/Y') }}</span>
                </td>
            </tr>
        </table>
    </div>

    @unless ($isFinal)
        <div class="draft">DRAFT — belum disetujui, angka masih dapat berubah</div>
    @endunless

    <table class="info">
        <tr><td style="width:28%">Nama</td><td>: {{ $employee?->name ?? '-' }}</td></tr>
        <tr><td>Jabatan</td><td>: {{ $employee?->position ?? '-' }}</td></tr>
        <tr><td>Status PTKP</td><td>: {{ $employee?->tax_status ?? 'TK/0' }}</td></tr>
        <tr>
            <td>Kehadiran</td>
            <td>: {{ $summary['present_days'] ?? 0 }} hadir · {{ $summary['late_days'] ?? 0 }} telat ·
                {{ $summary['absent_days'] ?? 0 }} alpa · {{ $summary['leave_days'] ?? 0 }} cuti</td>
        </tr>
        @if ($period->payment_date)
            <tr><td>Dibayar</td><td>: {{ $period->payment_date->format('d/m/Y') }} ({{ $period->payment_method === 'bank' ? 'transfer bank' : 'tunai' }})</td></tr>
        @endif
    </table>

    <div class="section">Penghasilan</div>
    <table class="lines">
        @foreach ($earnings as $line)
            <tr><td>{{ $line['label'] }}</td><td class="amt">{{ $line['amount'] < 0 ? '−' . $rp(-$line['amount']) : $rp($line['amount']) }}</td></tr>
        @endforeach
        <tr class="total"><td>Total penghasilan (bruto)</td><td class="amt">{{ $rp($run->gross_salary) }}</td></tr>
    </table>

    <div class="section">Potongan</div>
    <table class="lines">
        @forelse ($deductions as $line)
            <tr><td>{{ $line['label'] }}</td><td class="amt">{{ $rp($line['amount']) }}</td></tr>
        @empty
            <tr><td class="muted">Tidak ada potongan</td><td></td></tr>
        @endforelse
        <tr class="total"><td>Total potongan</td><td class="amt">{{ $rp($run->deductions) }}</td></tr>
    </table>

    @if ($refund > 0)
        <table class="lines" style="margin-top:4px">
            <tr><td>Pengembalian kelebihan PPh 21</td><td class="amt">+{{ $rp($refund) }}</td></tr>
        </table>
    @endif

    <div class="net">
        <table>
            <tr>
                <td class="label">Gaji bersih diterima</td>
                <td class="value">{{ $rp($run->net_salary) }}</td>
            </tr>
        </table>
    </div>

    @if ($shortfall > 0)
        <div class="warn">Potongan melebihi penghasilan sebesar {{ $rp($shortfall) }}; sisanya diselesaikan terpisah.</div>
    @endif

    <div class="foot">
        Dicetak {{ now($store->timezone ?: 'UTC')->format('d/m/Y H:i') }} · Dokumen ini dibuat otomatis oleh Kasir UMKM by MaccaPOS dan bersifat rahasia.
    </div>
</body>
</html>
