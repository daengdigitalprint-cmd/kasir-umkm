@use('App\Icons\Android')
@use('App\Icons\Ios')

<native:column class="flex-1 bg-theme-background">
    <native:column class="p-4 gap-4 bg-theme-surface border-b border-theme-outline-variant shadow-sm">
        <native:row class="gap-3">
            <native:column class="flex-1 bg-theme-background rounded-2xl p-4 border border-theme-outline-variant gap-1">
                <native:text class="text-xs text-zinc-500 font-medium">Total Pelanggan</native:text>
                <native:text class="text-xl font-extrabold text-theme-on-surface">{{ $totalCustomers }}</native:text>
                <native:text class="text-xs text-theme-primary">Terdaftar aktif</native:text>
            </native:column>
            <native:column class="flex-1 bg-theme-background rounded-2xl p-4 border border-theme-outline-variant gap-1">
                <native:text class="text-xs text-zinc-500 font-medium">Baru Bulan Ini</native:text>
                <native:text class="text-xl font-extrabold text-emerald-600">{{ $newThisMonth }}</native:text>
                <native:text class="text-xs text-zinc-500">Pelanggan baru</native:text>
            </native:column>
        </native:row>

        <native:outlined-text-input
            placeholder="Cari nama atau nomor HP..."
            :leading-icon-ios="Ios::Magnifyingglass"
            :leading-icon-android="Android::Search"
            native:model.live.debounce.300ms="search"
            size="sm"
        />

        <native:button variant="primary" @tap="openAddModal">
            Tambah Pelanggan
        </native:button>
    </native:column>

    <native:column class="flex-1 p-3">
        @if ($customers->isEmpty())
            <native:column class="flex-1 bg-theme-surface rounded-2xl p-6 items-center justify-center gap-3 border border-theme-outline-variant">
                <native:row class="w-16 h-16 rounded-full bg-theme-primary/10 items-center justify-center">
                    <native:icon :ios="Ios::Person" :android="Android::Person" class="text-theme-primary text-3xl" />
                </native:row>
                <native:text class="text-base font-bold text-theme-on-surface text-center">Belum Ada Pelanggan</native:text>
                <native:text class="text-xs text-zinc-500 text-center">Belum ada pelanggan yang terdaftar. Tambahkan pelanggan pertama Anda.</native:text>
                <native:button variant="primary" class="mt-2" @tap="openAddModal">
                    Tambah Pelanggan
                </native:button>
            </native:column>
        @else
            <native:list class="bg-theme-surface rounded-2xl border border-theme-outline-variant overflow-hidden">
                @foreach ($customers as $c)
                    <native:list-item
                        headline="{{ $c->name }}"
                        supporting="HP: {{ $c->phone ?? '-' }}"
                        :trailing-text="'Rp ' . number_format($c->total_spent ?? 0, 0, ',', '.')"
                        :trailing-icon-ios="Ios::ChevronRight"
                        :trailing-icon-android="Android::ChevronRight"
                    />
                @endforeach
            </native:list>
        @endif
    </native:column>

    @if ($showAddModal)
        <native:bottom-sheet>
            <native:column class="p-5 gap-4">
                <native:text class="text-lg font-bold text-theme-on-surface">Tambah Pelanggan</native:text>

                @if ($addError)
                    <native:column class="bg-red-50 border border-red-200 rounded-xl p-3">
                        <native:text class="text-xs text-red-600">{{ $addError }}</native:text>
                    </native:column>
                @endif

                <native:column class="gap-1">
                    <native:text class="text-xs font-semibold text-zinc-500">Nama Pelanggan <native:text class="text-red-500">*</native:text></native:text>
                    <native:outlined-text-input
                        placeholder="Masukkan nama pelanggan"
                        native:model.live="newName"
                        size="sm"
                    />
                </native:column>

                <native:column class="gap-1">
                    <native:text class="text-xs font-semibold text-zinc-500">Nomor HP</native:text>
                    <native:outlined-text-input
                        placeholder="Contoh: 08123456789"
                        native:model.live="newPhone"
                        size="sm"
                    />
                </native:column>

                <native:column class="gap-1">
                    <native:text class="text-xs font-semibold text-zinc-500">Email</native:text>
                    <native:outlined-text-input
                        placeholder="Contoh: pelanggan@email.com"
                        native:model.live="newEmail"
                        size="sm"
                    />
                </native:column>

                <native:row class="gap-3 mt-2">
                    <native:button variant="secondary" class="flex-1" @tap="closeAddModal">
                        Batal
                    </native:button>
                    <native:button variant="primary" class="flex-1" @tap="saveCustomer">
                        Simpan
                    </native:button>
                </native:row>
            </native:column>
        </native:bottom-sheet>
    @endif
</native:column>
