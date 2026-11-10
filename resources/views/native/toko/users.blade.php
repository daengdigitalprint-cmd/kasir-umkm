@use('App\Icons\Android')
@use('App\Icons\Ios')

<native:column class="flex-1 bg-theme-background">

    {{-- Header Stat --}}
    <native:column class="bg-theme-surface border-b border-theme-outline-variant shadow-sm p-4 gap-3">
        <native:row class="gap-2">
            {{-- Total User --}}
            <native:column class="flex-1 bg-theme-primary/10 rounded-2xl p-3 gap-1 items-center">
                <native:text class="text-xs text-zinc-500 text-center">Total Staf</native:text>
                <native:text class="text-xl font-bold text-theme-primary text-center">{{ $totalUsers }}</native:text>
            </native:column>

            {{-- Aktif --}}
            <native:column class="flex-1 bg-green-50 rounded-2xl p-3 gap-1 items-center">
                <native:text class="text-xs text-zinc-500 text-center">Aktif</native:text>
                <native:text class="text-xl font-bold text-green-600 text-center">{{ $totalActive }}</native:text>
            </native:column>

            {{-- Kasir --}}
            <native:column class="flex-1 bg-blue-50 rounded-2xl p-3 gap-1 items-center">
                <native:text class="text-xs text-zinc-500 text-center">Kasir</native:text>
                <native:text class="text-xl font-bold text-blue-600 text-center">{{ $totalCashier }}</native:text>
            </native:column>
        </native:row>

        {{-- Tombol Tambah --}}
        <native:button variant="primary" class="w-full" @tap="openAddModal">
            + Tambah Staf Baru
        </native:button>
    </native:column>

    {{-- Daftar User --}}
    <native:column class="flex-1 p-3 gap-2">
        <native:text class="text-xs font-semibold text-zinc-500 uppercase tracking-wide px-1">Daftar Staf</native:text>

        @if ($users->isEmpty())
            <native:column class="flex-1 items-center justify-center gap-3 py-12">
                <native:row class="w-16 h-16 rounded-full bg-theme-primary/10 items-center justify-center">
                    <native:icon :ios="Ios::Person2" :android="Android::Group" class="text-theme-primary text-3xl" />
                </native:row>
                <native:text class="text-base font-bold text-theme-on-surface text-center">Belum Ada Staf</native:text>
                <native:text class="text-xs text-zinc-500 text-center">Tambahkan kasir atau staf pertama Anda.</native:text>
            </native:column>
        @else
            <native:list class="rounded-2xl overflow-hidden border border-theme-outline-variant">
                @foreach ($users as $u)
                    @php
                        $roleLabel = match ($u->role) {
                            'owner'     => 'Pemilik',
                            'admin'     => 'Admin',
                            'manager'   => 'Manajer',
                            'cashier'   => 'Kasir',
                            'warehouse' => 'Gudang',
                            'kitchen'   => 'Dapur',
                            'barber'    => 'Teknisi',
                            default     => ucfirst($u->role),
                        };
                        $statusColor = $u->is_active ? 'text-green-600' : 'text-zinc-400';
                        $statusLabel = $u->is_active ? 'Aktif' : 'Nonaktif';
                    @endphp
                    <native:list-item
                        headline="{{ $u->name }}"
                        supporting="{{ $roleLabel }} · {{ $statusLabel }}"
                        trailing-text="{{ $u->is_active ? 'Nonaktifkan' : 'Aktifkan' }}"
                        @tap="openToggleModal({{ $u->id }}, '{{ addslashes($u->name) }}', {{ $u->is_active ? 'true' : 'false' }})"
                    />
                @endforeach
            </native:list>
        @endif
    </native:column>

    {{-- Modal Tambah Staf --}}
    @if ($showAddModal)
        <native:modal>
            <native:column class="flex-1 bg-theme-background p-5 gap-4">
                <native:text class="text-base font-bold text-theme-on-surface">Tambah Staf Baru</native:text>

                @if ($addError)
                    <native:column class="bg-red-50 rounded-xl p-3">
                        <native:text class="text-xs text-red-500">{{ $addError }}</native:text>
                    </native:column>
                @endif

                <native:column class="gap-3">
                    <native:column class="gap-1">
                        <native:text class="text-xs text-zinc-500">Nama Lengkap *</native:text>
                        <native:outlined-text-input placeholder="Nama staf" native:model.live="newName" />
                    </native:column>

                    <native:column class="gap-1">
                        <native:text class="text-xs text-zinc-500">Email *</native:text>
                        <native:outlined-text-input
                            placeholder="email@toko.com"
                            native:model.live="newEmail"
                            keyboard-type="email-address"
                        />
                    </native:column>

                    <native:column class="gap-1">
                        <native:text class="text-xs text-zinc-500">Password *</native:text>
                        <native:outlined-text-input
                            placeholder="Min. 6 karakter"
                            native:model.live="newPassword"
                            secure-text-entry
                        />
                    </native:column>

                    <native:column class="gap-1">
                        <native:text class="text-xs text-zinc-500">Role</native:text>
                        <native:row class="gap-2">
                            @foreach(['cashier' => 'Kasir', 'manager' => 'Manajer', 'warehouse' => 'Gudang'] as $val => $label)
                                <native:pressable
                                    class="flex-1 py-2 rounded-xl border {{ $newRole === $val ? 'bg-theme-primary border-theme-primary' : 'border-theme-outline-variant' }}"
                                    @tap="newRole = '{{ $val }}'"
                                >
                                    <native:text class="text-xs text-center {{ $newRole === $val ? 'text-white font-bold' : 'text-zinc-600' }}">{{ $label }}</native:text>
                                </native:pressable>
                            @endforeach
                        </native:row>
                    </native:column>
                </native:column>

                <native:row class="gap-3">
                    <native:button variant="secondary" class="flex-1" @tap="closeAddModal">
                        Batal
                    </native:button>
                    <native:button variant="primary" class="flex-1" @tap="saveUser">
                        Simpan
                    </native:button>
                </native:row>
            </native:column>
        </native:modal>
    @endif

    {{-- Modal Konfirmasi Toggle Aktif --}}
    @if ($showToggleModal)
        <native:modal>
            <native:column class="flex-1 bg-theme-background p-6 gap-5 items-center">
                <native:row class="w-20 h-20 rounded-full {{ $toggleToActive ? 'bg-green-100' : 'bg-orange-100' }} items-center justify-center">
                    <native:icon
                        :ios="$toggleToActive ? Ios::CheckmarkCircleFill : Ios::MinusCircleFill"
                        :android="$toggleToActive ? Android::CheckCircle : Android::RemoveCircle"
                        class="{{ $toggleToActive ? 'text-green-600' : 'text-orange-500' }} text-4xl"
                    />
                </native:row>

                <native:column class="gap-1 items-center">
                    <native:text class="text-lg font-bold text-theme-on-surface text-center">
                        {{ $toggleToActive ? 'Aktifkan Staf?' : 'Nonaktifkan Staf?' }}
                    </native:text>
                    <native:text class="text-sm text-zinc-500 text-center">{{ $toggleUserName }}</native:text>
                    @if (!$toggleToActive)
                        <native:text class="text-xs text-orange-500 text-center mt-1">
                            Staf tidak akan bisa login setelah dinonaktifkan.
                        </native:text>
                    @endif
                </native:column>

                <native:row class="gap-3 w-full">
                    <native:button variant="secondary" class="flex-1" @tap="closeToggleModal">
                        Batal
                    </native:button>
                    <native:button
                        variant="{{ $toggleToActive ? 'primary' : 'secondary' }}"
                        class="flex-1"
                        @tap="confirmToggle"
                    >
                        {{ $toggleToActive ? 'Aktifkan' : 'Nonaktifkan' }}
                    </native:button>
                </native:row>
            </native:column>
        </native:modal>
    @endif

</native:column>
