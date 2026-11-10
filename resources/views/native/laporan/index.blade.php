@use('App\Icons\Android')
@use('App\Icons\Ios')

<native:column class="flex-1 bg-theme-background">
    {{-- Header stats hari ini --}}
    <native:column class="p-4 gap-3 bg-theme-surface border-b border-theme-outline-variant shadow-sm">
        <native:text class="text-xs font-semibold text-zinc-500 uppercase tracking-wide">Ringkasan Hari Ini</native:text>
        <native:row class="gap-3">
            <native:column class="flex-1 bg-theme-primary/10 rounded-2xl p-4 gap-1">
                <native:text class="text-xs text-zinc-500">Omset</native:text>
                <native:text class="text-lg font-extrabold text-theme-primary">{{ $todayOmset }}</native:text>
            </native:column>
            <native:column class="flex-1 bg-theme-secondary/10 rounded-2xl p-4 gap-1">
                <native:text class="text-xs text-zinc-500">Transaksi</native:text>
                <native:text class="text-lg font-extrabold text-theme-on-surface">{{ $todayTransactions }} struk</native:text>
            </native:column>
        </native:row>
    </native:column>

    <native:column class="flex-1 p-4 gap-4">
        {{-- Minggu ini --}}
        <native:column class="bg-theme-surface rounded-2xl border border-theme-outline-variant overflow-hidden shadow-sm">
            <native:column class="p-3 border-b border-theme-outline-variant bg-gray-50">
                <native:text class="text-xs font-semibold text-zinc-500 uppercase tracking-wide">Minggu Ini</native:text>
            </native:column>
            <native:row class="p-4 justify-between items-center">
                <native:column class="gap-1">
                    <native:text class="text-xs text-zinc-500">Total Omset</native:text>
                    <native:text class="text-base font-bold text-theme-on-surface">{{ $weekOmset }}</native:text>
                </native:column>
                <native:column class="items-end gap-1">
                    <native:text class="text-xs text-zinc-500">Jumlah Struk</native:text>
                    <native:text class="text-base font-bold text-theme-on-surface">{{ $weekTransactions }}x</native:text>
                </native:column>
            </native:row>
        </native:column>

        {{-- Bulan ini --}}
        <native:column class="bg-theme-surface rounded-2xl border border-theme-outline-variant overflow-hidden shadow-sm">
            <native:column class="p-3 border-b border-theme-outline-variant bg-gray-50">
                <native:text class="text-xs font-semibold text-zinc-500 uppercase tracking-wide">Bulan Ini</native:text>
            </native:column>
            <native:row class="p-4 justify-between items-center">
                <native:column class="gap-1">
                    <native:text class="text-xs text-zinc-500">Total Omset</native:text>
                    <native:text class="text-base font-bold text-theme-on-surface">{{ $monthOmset }}</native:text>
                </native:column>
                <native:column class="items-end gap-1">
                    <native:text class="text-xs text-zinc-500">Periode</native:text>
                    <native:text class="text-base font-bold text-theme-on-surface">{{ now()->format('F Y') }}</native:text>
                </native:column>
            </native:row>
        </native:column>

        {{-- Placeholder grafik --}}
        @if ($todayTransactions === 0)
            <native:column class="flex-1 bg-theme-surface rounded-2xl p-6 items-center justify-center gap-3 border border-theme-outline-variant">
                <native:row class="w-16 h-16 rounded-full bg-theme-primary/10 items-center justify-center">
                    <native:icon :ios="Ios::ChartBar" :android="Android::QueryStats" class="text-theme-primary text-3xl" />
                </native:row>
                <native:text class="text-base font-bold text-theme-on-surface text-center">Belum Ada Transaksi Hari Ini</native:text>
                <native:text class="text-xs text-zinc-500 text-center">Statistik dan grafik akan muncul setelah ada penjualan selesai.</native:text>
            </native:column>
        @endif
    </native:column>
</native:column>
