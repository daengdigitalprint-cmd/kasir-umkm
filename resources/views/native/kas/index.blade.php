@use('App\Icons\Android')
@use('App\Icons\Ios')

<native:column class="flex-1 bg-theme-background">
    {{-- Header: Statistik & Tombol Aksi --}}
    <native:column class="bg-theme-surface border-b border-theme-outline-variant shadow-sm p-4 gap-3">
        {{-- 3 Stat Card --}}
        <native:row class="gap-2">
            {{-- Kas Masuk --}}
            <native:column class="flex-1 bg-green-50 rounded-2xl p-3 gap-1 items-center">
                <native:text class="text-xs text-zinc-500 text-center">Kas Masuk</native:text>
                <native:text class="text-sm font-bold text-green-600 text-center">{{ $todayIn }}</native:text>
            </native:column>

            {{-- Kas Keluar --}}
            <native:column class="flex-1 bg-red-50 rounded-2xl p-3 gap-1 items-center">
                <native:text class="text-xs text-zinc-500 text-center">Kas Keluar</native:text>
                <native:text class="text-sm font-bold text-red-500 text-center">{{ $todayOut }}</native:text>
            </native:column>

            {{-- Saldo --}}
            <native:column class="flex-1 bg-theme-primary/10 rounded-2xl p-3 gap-1 items-center">
                <native:text class="text-xs text-zinc-500 text-center">Saldo</native:text>
                <native:text class="text-sm font-bold text-center {{ str_starts_with($balance, '+') ? 'text-green-600' : 'text-red-500' }}">{{ $balance }}</native:text>
            </native:column>
        </native:row>

        {{-- Tombol Aksi --}}
        <native:row class="gap-2">
            <native:button
                variant="primary"
                class="flex-1"
                @tap="openAddModal('in')"
            >
                + Kas Masuk
            </native:button>
            <native:button
                variant="destructive"
                class="flex-1"
                @tap="openAddModal('out')"
            >
                - Kas Keluar
            </native:button>
        </native:row>
    </native:column>

    {{-- Riwayat Hari Ini --}}
    <native:column class="flex-1 p-3 gap-2">
        <native:text class="text-xs font-semibold text-zinc-500 uppercase tracking-wide px-1">Riwayat Hari Ini</native:text>

        @if ($transactions->isEmpty())
            {{-- Empty State --}}
            <native:column class="flex-1 items-center justify-center gap-3 py-12">
                <native:row class="w-16 h-16 rounded-full bg-theme-primary/10 items-center justify-center">
                    <native:icon :ios="Ios::Banknote" :android="Android::Payments" class="text-theme-primary text-3xl" />
                </native:row>
                <native:text class="text-base font-bold text-theme-on-surface text-center">Belum Ada Transaksi Kas</native:text>
                <native:text class="text-xs text-zinc-500 text-center">Belum ada transaksi kas hari ini.</native:text>
            </native:column>
        @else
            <native:list class="rounded-2xl overflow-hidden border border-theme-outline-variant">
                @foreach ($transactions as $t)
                    <native:list-item
                        headline="{{ $t->description ?: ($t->type === 'in' ? 'Kas Masuk' : 'Kas Keluar') }}"
                        supporting="{{ $t->created_at->format('H:i') }}"
                        trailing-text="{{ ($t->type === 'in' ? '+' : '-') }} Rp {{ number_format($t->amount, 0, ',', '.') }}"
                        class="{{ $t->type === 'in' ? 'text-green-600' : 'text-red-500' }}"
                    />
                @endforeach
            </native:list>
        @endif
    </native:column>

    {{-- Modal Tambah Transaksi --}}
    @if ($showAddModal)
        <native:modal>
            <native:column class="flex-1 bg-theme-background p-5 gap-4">
                {{-- Judul --}}
                <native:text class="text-lg font-bold text-theme-on-surface">
                    Tambah {{ $type === 'in' ? 'Kas Masuk' : 'Kas Keluar' }}
                </native:text>

                {{-- Input Nominal --}}
                <native:column class="gap-1">
                    <native:text class="text-xs font-medium text-zinc-500">Nominal (Rp)</native:text>
                    <native:outlined-text-input
                        native:model.live="amountInput"
                        placeholder="Contoh: 50000"
                        keyboard-type="numeric"
                        size="sm"
                    />
                </native:column>

                {{-- Input Keterangan --}}
                <native:column class="gap-1">
                    <native:text class="text-xs font-medium text-zinc-500">Keterangan (opsional)</native:text>
                    <native:outlined-text-input
                        native:model.live="description"
                        placeholder="Contoh: Bayar listrik"
                        size="sm"
                    />
                </native:column>

                {{-- Pesan Error --}}
                @if ($formError)
                    <native:text class="text-xs text-red-500">{{ $formError }}</native:text>
                @endif

                {{-- Tombol Aksi --}}
                <native:row class="gap-2 mt-2">
                    <native:button
                        variant="secondary"
                        class="flex-1"
                        @tap="closeAddModal"
                    >
                        Batal
                    </native:button>
                    <native:button
                        variant="primary"
                        class="flex-1"
                        @tap="saveTransaction"
                    >
                        Simpan
                    </native:button>
                </native:row>
            </native:column>
        </native:modal>
    @endif
</native:column>
