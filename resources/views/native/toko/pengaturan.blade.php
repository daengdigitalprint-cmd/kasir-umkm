@use('App\Icons\Android')
@use('App\Icons\Ios')

<native:column class="flex-1 bg-theme-background">

    {{-- Berhasil disimpan --}}
    @if ($saveSuccess)
        <native:row class="bg-green-50 border-b border-green-200 px-4 py-3 items-center gap-2">
            <native:icon :ios="Ios::CheckmarkCircleFill" :android="Android::CheckCircle" class="text-green-500" />
            <native:text class="text-xs text-green-700">Pengaturan toko berhasil disimpan.</native:text>
        </native:row>
    @endif

    {{-- Kartu Profil Toko --}}
    <native:column class="bg-theme-surface border-b border-theme-outline-variant shadow-sm p-5 gap-3">
        <native:row class="items-center gap-4">
            <native:row class="w-14 h-14 rounded-2xl bg-theme-primary/10 items-center justify-center">
                <native:icon :ios="Ios::StorefrontFill" :android="Android::Store" class="text-theme-primary text-3xl" />
            </native:row>
            <native:column class="flex-1 gap-0">
                <native:text class="text-lg font-bold text-theme-on-surface">{{ $storeName ?: 'Nama Toko' }}</native:text>
                @if ($storePhone)
                    <native:text class="text-xs text-zinc-500">{{ $storePhone }}</native:text>
                @endif
                @if ($storeEmail)
                    <native:text class="text-xs text-zinc-400">{{ $storeEmail }}</native:text>
                @endif
            </native:column>
        </native:row>

        @if ($storeAddress)
            <native:row class="items-start gap-2">
                <native:icon :ios="Ios::MappinCircleFill" :android="Android::LocationOn" class="text-zinc-400 text-sm mt-0.5" />
                <native:text class="flex-1 text-xs text-zinc-500">{{ $storeAddress }}</native:text>
            </native:row>
        @endif

        <native:button variant="secondary" class="w-full" @tap="openEditModal">
            Edit Info Toko
        </native:button>
    </native:column>

    {{-- Section Langganan --}}
    <native:column class="p-4 gap-2">
        <native:text class="text-xs font-semibold text-zinc-500 uppercase tracking-wide">Langganan</native:text>

        <native:column class="bg-theme-surface rounded-2xl border border-theme-outline-variant overflow-hidden">
            <native:list-item
                headline="Paket Aktif"
                supporting="{{ $subscriptionLabel }}"
                trailing-text="{{ $subscriptionActive ? 'Aktif' : 'Tidak Aktif' }}"
            />
            <native:list-item
                headline="Berakhir"
                supporting="{{ $subscriptionEnd }}"
                trailing-text="{{ $daysRemaining > 0 ? $daysRemaining . ' hari lagi' : ($subscriptionActive ? '∞' : 'Kadaluarsa') }}"
            />
        </native:column>

        @if ($daysRemaining <= 7 && $daysRemaining > 0)
            <native:row class="bg-orange-50 rounded-xl p-3 items-center gap-2">
                <native:icon :ios="Ios::ExclamationmarkTriangleFill" :android="Android::Warning" class="text-orange-400" />
                <native:text class="flex-1 text-xs text-orange-600">Langganan hampir berakhir. Perpanjang segera melalui web admin.</native:text>
            </native:row>
        @endif
    </native:column>

    {{-- Section Pajak --}}
    <native:column class="px-4 pb-4 gap-2">
        <native:text class="text-xs font-semibold text-zinc-500 uppercase tracking-wide">Pajak</native:text>

        <native:column class="bg-theme-surface rounded-2xl border border-theme-outline-variant overflow-hidden">
            <native:list-item
                headline="Pajak ({{ $taxName }})"
                supporting="{{ $taxEnabled ? 'Aktif' : 'Tidak aktif' }}"
                trailing-text="{{ $taxEnabled ? $taxRate . '%' : '-' }}"
            />
        </native:column>

        <native:text class="text-xs text-zinc-400 px-1">Pengaturan pajak dapat diubah melalui web admin.</native:text>
    </native:column>

    {{-- Modal Edit Toko --}}
    @if ($showEditModal)
        <native:modal>
            <native:column class="flex-1 bg-theme-background p-5 gap-4">
                <native:text class="text-base font-bold text-theme-on-surface">Edit Info Toko</native:text>

                {{-- Error --}}
                @if ($editError)
                    <native:column class="bg-red-50 rounded-xl p-3">
                        <native:text class="text-xs text-red-500">{{ $editError }}</native:text>
                    </native:column>
                @endif

                <native:column class="gap-3">
                    <native:column class="gap-1">
                        <native:text class="text-xs text-zinc-500">Nama Toko *</native:text>
                        <native:outlined-text-input
                            placeholder="Nama toko"
                            native:model.live="editName"
                        />
                    </native:column>

                    <native:column class="gap-1">
                        <native:text class="text-xs text-zinc-500">No. Telepon</native:text>
                        <native:outlined-text-input
                            placeholder="Contoh: 08123456789"
                            native:model.live="editPhone"
                            keyboard-type="phone-pad"
                        />
                    </native:column>

                    <native:column class="gap-1">
                        <native:text class="text-xs text-zinc-500">Email</native:text>
                        <native:outlined-text-input
                            placeholder="email@toko.com"
                            native:model.live="editEmail"
                            keyboard-type="email-address"
                        />
                    </native:column>

                    <native:column class="gap-1">
                        <native:text class="text-xs text-zinc-500">Alamat</native:text>
                        <native:outlined-text-input
                            placeholder="Alamat lengkap toko"
                            native:model.live="editAddress"
                            multiline
                        />
                    </native:column>
                </native:column>

                <native:row class="gap-3">
                    <native:button variant="secondary" class="flex-1" @tap="closeEditModal">
                        Batal
                    </native:button>
                    <native:button variant="primary" class="flex-1" @tap="saveStore">
                        Simpan
                    </native:button>
                </native:row>
            </native:column>
        </native:modal>
    @endif

</native:column>
