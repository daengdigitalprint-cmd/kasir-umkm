@php
    $expired = collect($batches)->where('expiry_status', 'expired')->count();
@endphp
<x-email.layout
    title="Peringatan Batch dan Kedaluwarsa"
    heading="Batch Perlu Perhatian"
    :name="'Pengelola '.$store->name"
    :preheader="count($batches).' batch di '.$store->name.' mendekati atau lewat tanggal kedaluwarsa'"
    :intro="'Batch berikut kedaluwarsa dalam '.$warnDays.' hari atau sudah lewat (sisa stok masih ada), sama seperti di dashboard Anda.'"
>
    <x-email.row label="Toko" :value="$store->name" />
    <x-email.row label="Jendela peringatan" :value="$warnDays.' hari'" />
    <x-email.row label="Sudah kedaluwarsa" :value="$expired.' batch'" :color="$expired > 0 ? '#b91c1c' : '#333333'" />
    <x-email.divider />
    @foreach ($batches as $b)
        @php($isExpired = $b['expiry_status'] === 'expired')
        <x-email.row
            :label="$b['product_name']"
            :note="($b['batch_code'] ? 'Batch '.$b['batch_code'] : 'Tanpa kode batch').' · sisa '.$b['quantity_remaining']"
            :value="($isExpired ? 'Kedaluwarsa ' : 'ED ').\Illuminate\Support\Carbon::parse($b['expiry_date'])->locale('id')->translatedFormat('d M Y')"
            :color="$isExpired ? '#b91c1c' : '#c2410c'"
        />
    @endforeach
    <x-email.total label="Total batch" :value="count($batches).' batch'" />

    <x-slot:closing>
        Email ini dikirim karena pemberitahuan batch aktif di pengaturan toko. Ubah jendela peringatan (hari) di menu Pengaturan &gt; Profil &gt; Toko.
    </x-slot:closing>
</x-email.layout>
