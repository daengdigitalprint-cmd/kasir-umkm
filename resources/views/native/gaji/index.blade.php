@use('App\Icons\Android')
@use('App\Icons\Ios')

<native:column class="flex-1 bg-theme-background">

    {{-- Header: Statistik Gaji --}}
    <native:column class="bg-theme-surface border-b border-theme-outline-variant shadow-sm p-4 gap-3">

        {{-- Periode + Status --}}
        <native:row class="items-center justify-between">
            <native:column class="gap-0">
                <native:text class="text-xs text-zinc-400">Periode</native:text>
                <native:text class="text-sm font-semibold text-theme-on-surface">{{ $periodLabel }}</native:text>
            </native:column>
            @php
                [$badgeBg, $badgeText, $badgeLabel] = match ($periodStatus) {
                    'paid' => ['bg-green-100', 'text-green-700', 'Dibayar'],
                    'approved' => ['bg-blue-100', 'text-blue-700', 'Disetujui'],
                    default => ['bg-orange-100', 'text-orange-600', 'Draft'],
                };
            @endphp
            <native:row class="px-3 py-1 rounded-full {{ $badgeBg }}">
                <native:text class="text-xs font-medium {{ $badgeText }}">
                    {{ $badgeLabel }}
                </native:text>
            </native:row>
        </native:row>

        {{-- 3 Stat Card --}}
        <native:row class="gap-2">
            {{-- Total Karyawan --}}
            <native:column class="flex-1 bg-theme-primary/10 rounded-2xl p-3 gap-1 items-center">
                <native:text class="text-xs text-zinc-500 text-center">Karyawan</native:text>
                <native:text class="text-xl font-bold text-theme-primary text-center">{{ $totalKaryawan }}</native:text>
            </native:column>

            {{-- Total Gaji --}}
            <native:column class="flex-2 bg-blue-50 rounded-2xl p-3 gap-1 items-center">
                <native:text class="text-xs text-zinc-500 text-center">Total Gaji</native:text>
                <native:text class="text-sm font-bold text-blue-700 text-center">{{ $totalGajiBulanIni }}</native:text>
            </native:column>

            {{-- Belum Dibayar --}}
            <native:column class="flex-1 bg-orange-50 rounded-2xl p-3 gap-1 items-center">
                <native:text class="text-xs text-zinc-500 text-center">Proses</native:text>
                <native:text class="text-xl font-bold text-orange-500 text-center">{{ $belumDibayar }}</native:text>
            </native:column>
        </native:row>
    </native:column>

    {{-- List Slip Gaji --}}
    <native:column class="flex-1 p-3 gap-2">
        <native:text class="text-xs font-semibold text-zinc-500 uppercase tracking-wide px-1">Slip Gaji Karyawan</native:text>

        @if ($runs->isEmpty())
            <native:column class="flex-1 items-center justify-center gap-3 py-12">
                <native:row class="w-16 h-16 rounded-full bg-theme-primary/10 items-center justify-center">
                    <native:icon :ios="Ios::DocText" :android="Android::Receipt" class="text-theme-primary text-3xl" />
                </native:row>
                <native:text class="text-base font-bold text-theme-on-surface text-center">Belum Ada Data Gaji</native:text>
                <native:text class="text-xs text-zinc-500 text-center">Proses penggajian dilakukan melalui web admin.</native:text>
            </native:column>
        @else
            <native:list class="rounded-2xl overflow-hidden border border-theme-outline-variant">
                @foreach ($runs as $run)
                    <native:list-item
                        headline="{{ $run->employee->name ?? '-' }}"
                        supporting="{{ $run->employee->position ?? 'Karyawan' }}"
                        trailing-text="Rp {{ number_format($run->net_salary, 0, ',', '.') }}"
                        @tap="openDetail({{ $run->id }})"
                    />
                @endforeach
            </native:list>
        @endif
    </native:column>

    {{-- Modal Detail Gaji --}}
    @if ($showDetailModal)
        <native:modal>
            <native:column class="flex-1 bg-theme-background p-5 gap-4">

                {{-- Header --}}
                <native:row class="items-center gap-3">
                    <native:row class="w-12 h-12 rounded-full bg-theme-primary/10 items-center justify-center">
                        <native:icon :ios="Ios::PersonCircleFill" :android="Android::AccountCircle" class="text-theme-primary text-2xl" />
                    </native:row>
                    <native:column class="flex-1 gap-0">
                        <native:text class="text-base font-bold text-theme-on-surface">{{ $detailNama }}</native:text>
                        <native:text class="text-xs text-zinc-500">{{ $detailPosisi }}</native:text>
                    </native:column>
                </native:row>

                {{-- Divider --}}
                <native:row class="h-px bg-theme-outline-variant" />

                {{-- Komponen Gaji --}}
                <native:column class="gap-3">
                    <native:text class="text-xs font-semibold text-zinc-500 uppercase tracking-wide">Rincian Gaji</native:text>

                    <native:row class="justify-between items-center">
                        <native:text class="text-sm text-zinc-600">Gaji Tetap</native:text>
                        <native:text class="text-sm font-medium text-theme-on-surface">{{ $detailGajiPokok }}</native:text>
                    </native:row>
                    <native:row class="justify-between items-center">
                        <native:text class="text-sm text-zinc-600">Lembur</native:text>
                        <native:text class="text-sm font-medium text-green-600">+ {{ $detailLembur }}</native:text>
                    </native:row>
                    <native:row class="justify-between items-center">
                        <native:text class="text-sm text-zinc-600">Potongan</native:text>
                        <native:text class="text-sm font-medium text-red-500">- {{ $detailPotongan }}</native:text>
                    </native:row>

                    {{-- Divider --}}
                    <native:row class="h-px bg-theme-outline-variant" />

                    <native:row class="justify-between items-center">
                        <native:text class="text-sm font-bold text-theme-on-surface">Gaji Bersih</native:text>
                        <native:text class="text-base font-bold text-theme-primary">{{ $detailGajiBersih }}</native:text>
                    </native:row>
                </native:column>

                {{-- Kehadiran --}}
                <native:column class="bg-zinc-50 rounded-2xl p-4 gap-2">
                    <native:text class="text-xs font-semibold text-zinc-500 uppercase tracking-wide">Rekap Kehadiran</native:text>
                    <native:row class="gap-3">
                        <native:column class="flex-1 items-center gap-1">
                            <native:text class="text-xl font-bold text-green-600">{{ $detailHadir }}</native:text>
                            <native:text class="text-xs text-zinc-500">Hadir</native:text>
                        </native:column>
                        <native:column class="flex-1 items-center gap-1">
                            <native:text class="text-xl font-bold text-red-500">{{ $detailAlpha }}</native:text>
                            <native:text class="text-xs text-zinc-500">Alpha</native:text>
                        </native:column>
                    </native:row>
                </native:column>

                {{-- Tombol Tutup --}}
                <native:button
                    variant="primary"
                    class="w-full"
                    @tap="closeDetail"
                >
                    Tutup
                </native:button>
            </native:column>
        </native:modal>
    @endif

</native:column>
