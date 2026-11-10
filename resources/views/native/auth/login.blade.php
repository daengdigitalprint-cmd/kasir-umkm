@use('App\Icons\Android')
@use('App\Icons\Ios')

<native:scroll-view class="flex-1 bg-theme-background">
    <native:column class="flex-1 p-6 items-center justify-center gap-6">
        {{-- Header Logo & Branding --}}
        <native:column class="items-center gap-2 pt-8">
            <native:row class="w-20 h-20 rounded-2xl bg-theme-primary items-center justify-center shadow-lg">
                <native:icon :ios="Ios::Storefront" :android="Android::Storefront" class="text-white text-3xl" />
            </native:row>
            <native:text class="text-2xl font-extrabold text-theme-on-surface">UMKM Kasir</native:text>
            <native:text class="text-xs text-zinc-500 font-medium text-center">Aplikasi Kasir Native POS & Manajemen Usaha</native:text>
        </native:column>

        {{-- Form Login Card --}}
        <native:column class="w-full bg-theme-surface rounded-2xl p-6 border border-theme-outline-variant gap-4 shadow-sm">
            <native:text class="text-base font-bold text-theme-on-surface">Masuk ke Akun</native:text>

            @if ($errorMessage !== '')
                <native:row class="w-full p-3 bg-red-50 rounded-xl border border-red-200 items-center gap-2">
                    <native:icon :ios="Ios::ExclamationmarkTriangle" :android="Android::Warning" class="text-red-600" />
                    <native:text class="text-xs text-red-600 font-semibold flex-1">{{ $errorMessage }}</native:text>
                </native:row>
            @endif

            <native:column class="gap-3">
                <native:outlined-text-input
                    label="Email"
                    placeholder="nama@toko.com"
                    keyboard="email"
                    native:model="email"
                />

                <native:outlined-text-input
                    label="Kata Sandi"
                    placeholder="••••••••"
                    secure
                    revealable
                    native:model="password"
                />
            </native:column>

            <native:button
                variant="primary"
                class="w-full mt-2"
                :loading="$isLoading"
                @tap="login"
            >
                Masuk ke Kasir
            </native:button>

            <native:row class="justify-center items-center gap-1 pt-2">
                <native:icon :ios="Ios::Lock" :android="Android::Lock" class="text-zinc-400 text-xs" />
                <native:text class="text-xs text-zinc-400">Koneksi lokal SQLite & sinkronisasi aman</native:text>
            </native:row>
        </native:column>

        {{-- Footer Version --}}
        <native:column class="items-center pb-6">
            <native:text class="text-xs text-zinc-400 font-medium">v1.0.0 Native Mobile Edition</native:text>
        </native:column>
    </native:column>
</native:scroll-view>
