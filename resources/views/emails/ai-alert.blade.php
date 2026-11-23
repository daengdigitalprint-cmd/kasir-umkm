@php
    $type = $alert['type'] ?? null;
    $data = (array) ($alert['data'] ?? []);
    $title = (string) ($alert['title'] ?? 'Peringatan');
    $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, ',', '.'), '0'), ',');
    $items = $type === 'low_stock' ? (array) ($data['items'] ?? []) : [];
    $count = (int) ($data['count'] ?? count($items));
    $remaining = $type === 'low_stock' ? max(0, $count - count($items)) : 0;
    $windowDays = (int) ($data['window_days'] ?? 7);
@endphp
<x-email.layout
    :title="$title"
    :name="$name ?? null"
    :preheader="$title.' — '.$store->name"
    :intro="'Berikut peringatan terbaru untuk toko '.$store->name.' dari Kasir UMKM.'"
>
    <x-email.row label="Toko" :value="$store->name" />
    <x-email.row label="Waktu" :value="\App\Services\AI\AiPeriod::now($store)->locale('id')->translatedFormat('d F Y, H:i').' '.\App\Services\AI\AiPeriod::timezoneLabel($store)" />

    @if ($type === 'low_stock')
        <x-email.divider />
        @foreach ($items as $item)
            @php
                $unit = trim((string) ($item['unit'] ?? ''));
                $empty = (float) ($item['stock_quantity'] ?? 0) <= 0;
            @endphp
            <x-email.row
                :label="$item['name'] ?? '-'"
                :note="'Minimum '.$fmt($item['min_stock_level'] ?? 0).' '.$unit"
                :value="$empty ? 'HABIS' : trim('Sisa '.$fmt($item['stock_quantity'] ?? 0).' '.$unit)"
                :color="$empty ? '#b91c1c' : '#c2410c'"
            />
        @endforeach
        @if ($remaining > 0)
            <x-email.text>+ {{ $remaining }} produk lain. Lihat daftar lengkap di aplikasi.</x-email.text>
        @endif
        <x-email.total label="Perlu restock" :value="$count.' produk'" />
    @elseif ($type === 'margin_drop')
        <x-email.divider />
        <x-email.row :label="$windowDays.' hari sebelumnya'" :value="$fmt($data['previous_margin_pct'] ?? 0).'%'" />
        <x-email.row :label="$windowDays.' hari terakhir'" :value="$fmt($data['recent_margin_pct'] ?? 0).'%'" color="#b45309" />
        <x-email.divider />
        <x-email.text>{{ $alert['message'] ?? '' }}</x-email.text>
        <x-email.total label="Margin turun" :value="$fmt($data['drop_points'] ?? 0).' poin'" />
    @elseif ($type === 'transaction_anomaly')
        <x-email.divider />
        <x-email.row label="Diskon tidak wajar" :value="count((array) ($data['discount_anomalies'] ?? [])).' transaksi'" />
        <x-email.row label="Void berulang" :value="count((array) ($data['void_patterns'] ?? [])).' kasir'" />
        <x-email.row label="Selisih kas shift" :value="count((array) ($data['cash_differences'] ?? [])).' shift'" />
        <x-email.divider />
        <x-email.text>{{ $alert['message'] ?? '' }}</x-email.text>
    @elseif ($type === 'churn_risk')
        <x-email.row label="Tidak belanja lebih dari" :value="(int) ($data['inactive_days'] ?? 0).' hari'" />
        <x-email.divider />
        <x-email.text>{{ $alert['message'] ?? '' }}</x-email.text>
        <x-email.total label="Pelanggan" :value="$count.' orang'" />
    @else
        <x-email.divider />
        <x-email.text>{!! nl2br(e($alert['message'] ?? '')) !!}</x-email.text>
    @endif

    @if ($actionUrl)
        <x-slot:after>
            <x-email.button :url="$actionUrl">{{ $actionLabel }}</x-email.button>
        </x-slot:after>
    @endif

    <x-slot:closing>
        Email ini dikirim karena Anda pengelola {{ $store->name }} dan notifikasi peringatan aktif. Bila ada pertanyaan, hubungi kami lewat WhatsApp atau email di bawah ini.
    </x-slot:closing>
</x-email.layout>
