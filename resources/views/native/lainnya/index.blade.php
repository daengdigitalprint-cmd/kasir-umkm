@use('App\Icons\Android')
@use('App\Icons\Ios')

<native:column class="flex-1 bg-theme-background p-4 gap-4">
    <native:column class="bg-theme-surface rounded-2xl p-4 border border-theme-outline-variant gap-3 shadow-sm">
        <native:row class="items-center gap-3">
            <native:row class="w-12 h-12 rounded-full bg-theme-primary/10 items-center justify-center">
                <native:icon :ios="Ios::Person" :android="Android::Person" class="text-theme-primary" />
            </native:row>
            <native:column class="flex-1 gap-0.5">
                <native:text class="text-base font-bold text-theme-on-surface">{{ $userName }}</native:text>
                <native:text class="text-xs text-zinc-500">{{ $userRole }} • {{ $storeName }}</native:text>
            </native:column>
        </native:row>
    </native:column>

    <native:column class="bg-theme-surface rounded-2xl p-2 border border-theme-outline-variant divide-y divide-theme-outline-variant shadow-sm">
        <native:pressable class="p-3" @tap="$navigate('/app/pelanggan')">
            <native:row class="justify-between items-center">
                <native:row class="items-center gap-3">
                    <native:icon :ios="Ios::Person2" :android="Android::Group" class="text-zinc-600" />
                    <native:text class="text-sm font-semibold text-theme-on-surface">Manajemen Pelanggan</native:text>
                </native:row>
                <native:icon :ios="Ios::ChevronRight" :android="Android::ChevronRight" class="text-zinc-400" />
            </native:row>
        </native:pressable>

        <native:pressable class="p-3" @tap="$navigate('/app/kas')">
            <native:row class="justify-between items-center">
                <native:row class="items-center gap-3">
                    <native:icon :ios="Ios::Banknote" :android="Android::AccountBalance" class="text-zinc-600" />
                    <native:text class="text-sm font-semibold text-theme-on-surface">Kas Masuk / Keluar</native:text>
                </native:row>
                <native:icon :ios="Ios::ChevronRight" :android="Android::ChevronRight" class="text-zinc-400" />
            </native:row>
        </native:pressable>

        <native:pressable class="p-3" @tap="$navigate('/app/absensi')">
            <native:row class="justify-between items-center">
                <native:row class="items-center gap-3">
                    <native:icon :ios="Ios::Clock" :android="Android::AccessTime" class="text-zinc-600" />
                    <native:text class="text-sm font-semibold text-theme-on-surface">Absensi Karyawan</native:text>
                </native:row>
                <native:icon :ios="Ios::ChevronRight" :android="Android::ChevronRight" class="text-zinc-400" />
            </native:row>
        </native:pressable>

        <native:pressable class="p-3" @tap="$navigate('/app/gaji')">
            <native:row class="justify-between items-center">
                <native:row class="items-center gap-3">
                    <native:icon :ios="Ios::Creditcard" :android="Android::Payments" class="text-zinc-600" />
                    <native:text class="text-sm font-semibold text-theme-on-surface">Penggajian</native:text>
                </native:row>
                <native:icon :ios="Ios::ChevronRight" :android="Android::ChevronRight" class="text-zinc-400" />
            </native:row>
        </native:pressable>

        <native:pressable class="p-3" @tap="$navigate('/app/toko/pengaturan')">
            <native:row class="justify-between items-center">
                <native:row class="items-center gap-3">
                    <native:icon :ios="Ios::Storefront" :android="Android::Store" class="text-zinc-600" />
                    <native:text class="text-sm font-semibold text-theme-on-surface">Pengaturan Toko</native:text>
                </native:row>
                <native:icon :ios="Ios::ChevronRight" :android="Android::ChevronRight" class="text-zinc-400" />
            </native:row>
        </native:pressable>

        <native:pressable class="p-3" @tap="$navigate('/app/toko/users')">
            <native:row class="justify-between items-center">
                <native:row class="items-center gap-3">
                    <native:icon :ios="Ios::PersonBadgePlus" :android="Android::PersonAdd" class="text-zinc-600" />
                    <native:text class="text-sm font-semibold text-theme-on-surface">Manajemen Kasir</native:text>
                </native:row>
                <native:icon :ios="Ios::ChevronRight" :android="Android::ChevronRight" class="text-zinc-400" />
            </native:row>
        </native:pressable>

        <native:pressable class="p-3" @tap="$navigate('/mobile/settings/printer')">
            <native:row class="justify-between items-center">
                <native:row class="items-center gap-3">
                    <native:icon :ios="Ios::Gearshape" :android="Android::Settings" class="text-zinc-600" />
                    <native:text class="text-sm font-semibold text-theme-on-surface">Pengaturan Printer & Bluetooth</native:text>
                </native:row>
                <native:icon :ios="Ios::ChevronRight" :android="Android::ChevronRight" class="text-zinc-400" />
            </native:row>
        </native:pressable>

        <native:pressable class="p-3">
            <native:row class="justify-between items-center">
                <native:row class="items-center gap-3">
                    <native:icon :ios="Ios::Doc" :android="Android::Receipt" class="text-zinc-600" />
                    <native:text class="text-sm font-semibold text-theme-on-surface">Format Struk Kasir</native:text>
                </native:row>
                <native:text class="text-xs text-zinc-400">Default 58mm</native:text>
            </native:row>
        </native:pressable>
    </native:column>

    <native:button variant="destructive" @tap="logout">
        Keluar dari Kasir
    </native:button>
</native:column>
