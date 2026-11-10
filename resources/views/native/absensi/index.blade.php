@use('App\Icons\Android')
@use('App\Icons\Ios')

<native:column class="flex-1 bg-theme-background">

    {{-- Header: Stat Card --}}
    <native:column class="bg-theme-surface border-b border-theme-outline-variant shadow-sm p-4 gap-3">
        <native:row class="gap-2">
            {{-- Total Karyawan --}}
            <native:column class="flex-1 bg-theme-primary/10 rounded-2xl p-3 gap-1 items-center">
                <native:text class="text-xs text-zinc-500 text-center">Total Karyawan</native:text>
                <native:text class="text-xl font-bold text-theme-primary text-center">{{ $totalKaryawan }}</native:text>
            </native:column>

            {{-- Hadir Hari Ini --}}
            <native:column class="flex-1 bg-green-50 rounded-2xl p-3 gap-1 items-center">
                <native:text class="text-xs text-zinc-500 text-center">Hadir Hari Ini</native:text>
                <native:text class="text-xl font-bold text-green-600 text-center">{{ $hadirHariIni }}</native:text>
            </native:column>

            {{-- Belum Absen --}}
            <native:column class="flex-1 bg-orange-50 rounded-2xl p-3 gap-1 items-center">
                <native:text class="text-xs text-zinc-500 text-center">Belum Absen</native:text>
                <native:text class="text-xl font-bold text-orange-500 text-center">{{ $belumAbsen }}</native:text>
            </native:column>
        </native:row>

        <native:text class="text-xs text-zinc-400 text-center">
            {{ now()->translatedFormat('l, d F Y') }}
        </native:text>
    </native:column>

    {{-- Daftar Karyawan --}}
    <native:column class="flex-1 p-3 gap-2">
        <native:text class="text-xs font-semibold text-zinc-500 uppercase tracking-wide px-1">Daftar Karyawan</native:text>

        @if ($employees->isEmpty())
            <native:column class="flex-1 items-center justify-center gap-3 py-12">
                <native:row class="w-16 h-16 rounded-full bg-theme-primary/10 items-center justify-center">
                    <native:icon :ios="Ios::Person2" :android="Android::Group" class="text-theme-primary text-3xl" />
                </native:row>
                <native:text class="text-base font-bold text-theme-on-surface text-center">Belum Ada Karyawan</native:text>
                <native:text class="text-xs text-zinc-500 text-center">Tambah karyawan terlebih dahulu di web admin.</native:text>
            </native:column>
        @else
            <native:list class="rounded-2xl overflow-hidden border border-theme-outline-variant">
                @foreach ($employees as $emp)
                    @php
                        $todayAttendance = $emp->attendances->first();
                        $isClockIn  = $todayAttendance && $todayAttendance->clock_in;
                        $isClockOut = $todayAttendance && $todayAttendance->clock_out;
                        $statusLabel = 'Belum Absen';
                        $statusColor = 'text-orange-500';
                        if ($isClockOut) {
                            $statusLabel = 'Sudah Pulang · ' . $todayAttendance->clock_out->format('H:i');
                            $statusColor = 'text-zinc-400';
                        } elseif ($isClockIn) {
                            $statusLabel = 'Masuk · ' . $todayAttendance->clock_in->format('H:i');
                            $statusColor = 'text-green-600';
                        }
                    @endphp
                    @if (!$isClockIn)
                        <native:list-item
                            headline="{{ $emp->name }}"
                            supporting="{{ $emp->position ?? 'Karyawan' }} · {{ $statusLabel }}"
                            trailing-text="→ Masuk"
                            @tap="openClockModal({{ $emp->id }}, '{{ addslashes($emp->name) }}', 'in')"
                        />
                    @elseif (!$isClockOut)
                        <native:list-item
                            headline="{{ $emp->name }}"
                            supporting="{{ $emp->position ?? 'Karyawan' }} · {{ $statusLabel }}"
                            trailing-text="→ Pulang"
                            @tap="openClockModal({{ $emp->id }}, '{{ addslashes($emp->name) }}', 'out')"
                        />
                    @else
                        <native:list-item
                            headline="{{ $emp->name }}"
                            supporting="{{ $emp->position ?? 'Karyawan' }} · {{ $statusLabel }}"
                            trailing-text="✓"
                        />
                    @endif
                @endforeach
            </native:list>
        @endif
    </native:column>

    {{-- Modal Konfirmasi Clock --}}
    @if ($showClockModal)
        <native:modal>
            <native:column class="flex-1 bg-theme-background p-6 gap-5 items-center">
                {{-- Ikon --}}
                <native:row class="w-20 h-20 rounded-full {{ $clockType === 'in' ? 'bg-green-100' : 'bg-orange-100' }} items-center justify-center">
                    <native:icon
                        :ios="$clockType === 'in' ? Ios::ArrowRightCircleFill : Ios::ArrowLeftCircleFill"
                        :android="$clockType === 'in' ? Android::Login : Android::Logout"
                        class="{{ $clockType === 'in' ? 'text-green-600' : 'text-orange-500' }} text-4xl"
                    />
                </native:row>

                <native:column class="gap-1 items-center">
                    <native:text class="text-lg font-bold text-theme-on-surface text-center">
                        {{ $clockType === 'in' ? 'Clock In' : 'Clock Out' }}
                    </native:text>
                    <native:text class="text-sm text-zinc-500 text-center">
                        {{ $selectedEmployeeName }}
                    </native:text>
                    <native:text class="text-xs text-zinc-400 text-center">
                        {{ now()->format('H:i, d M Y') }}
                    </native:text>
                </native:column>

                {{-- Pesan Error --}}
                @if ($clockError)
                    <native:column class="bg-red-50 rounded-xl p-3 w-full">
                        <native:text class="text-xs text-red-500 text-center">{{ $clockError }}</native:text>
                    </native:column>
                @endif

                {{-- Tombol --}}
                <native:row class="gap-3 w-full mt-2">
                    <native:button
                        variant="secondary"
                        class="flex-1"
                        @tap="closeClockModal"
                    >
                        Batal
                    </native:button>
                    <native:button
                        variant="{{ $clockType === 'in' ? 'primary' : 'secondary' }}"
                        class="flex-1"
                        @tap="saveClock"
                    >
                        Konfirmasi
                    </native:button>
                </native:row>
            </native:column>
        </native:modal>
    @endif

</native:column>
