{{--
    Kartu harga: paket berbayar ditonjolkan di kartu hijau, paket gratis jadi
    pembanding. Semua angka dari database (LandingPricing), tidak ada harga mati.
--}}
@php
    $paid = $pricing['paid'];
    $free = $pricing['free'];
    $batas = array_filter([
        $free['max_products'] ? $free['max_products'].' produk' : null,
        $free['max_customers'] ? $free['max_customers'].' pelanggan' : null,
    ]);
    $hemat = $pricing['yearly_saving_months'] ?? null;
@endphp
<div class="kt-plans">
    <div class="kt-feature">
        @include('kota.partials.shapes', ['variant' => 'hijau'])
        <div class="kt-feature-body">
            <span class="kt-badge">Paket {{ $paid['name'] }} · fitur lengkap</span>
            <p class="kt-plan-name">{{ $paid['description'] }}</p>
            @if ($paid['monthly_label'] !== '')
                <p class="kt-price">{{ $paid['monthly_label'] }}<small> / bulan</small></p>
            @endif
            @if ($paid['yearly_label'] !== '')
                <div class="kt-inner">
                    <p class="k">Bayar tahunan</p>
                    <p class="v">{{ $paid['yearly_label'] }}</p>
                    <p class="d">
                        Sekali bayar untuk satu tahun{{ $hemat ? ', hemat '.$hemat.' bulan dibanding bayar bulanan' : '' }}.
                    </p>
                </div>
            @endif
        </div>
    </div>
    <div class="kt-second">
        <span class="kt-badge plain">Paket {{ $free['name'] }}</span>
        <p class="kt-plan-name">Untuk mulai mencoba</p>
        <p class="kt-price">{{ $free['price_label'] }}</p>
        <p class="d">
            Transaksi tanpa batas untuk {{ $free['max_stores'] }} outlet{{ $batas ? ', hingga '.implode(' dan ', $batas) : '' }}.
            Bisa dipakai selamanya tanpa kartu kredit.
        </p>
    </div>
</div>
