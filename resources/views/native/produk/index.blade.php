@use('App\Icons\Android')
@use('App\Icons\Ios')

<native:column class="flex-1 bg-theme-background">
    <native:column class="p-4 gap-4 bg-theme-surface border-b border-theme-outline-variant shadow-sm">
        <native:row class="gap-3">
            <native:column class="flex-1 bg-theme-background rounded-2xl p-4 border border-theme-outline-variant gap-1">
                <native:text class="text-xs text-zinc-500 font-medium">Total Produk</native:text>
                <native:text class="text-xl font-extrabold text-theme-on-surface">{{ $totalProducts }}</native:text>
                <native:text class="text-xs text-theme-primary">Siap dijual</native:text>
            </native:column>
            <native:column class="flex-1 bg-theme-background rounded-2xl p-4 border border-theme-outline-variant gap-1">
                <native:text class="text-xs text-zinc-500 font-medium">Stok Menipis</native:text>
                <native:text class="text-xl font-extrabold text-amber-600">{{ $lowStockCount }}</native:text>
                <native:text class="text-xs text-zinc-500">Perlu restock</native:text>
            </native:column>
        </native:row>

        <native:outlined-text-input
            placeholder="Cari produk atau SKU..."
            :leading-icon-ios="Ios::Magnifyingglass"
            :leading-icon-android="Android::Search"
            native:model.live.debounce.300ms="search"
            size="sm"
        />
    </native:column>

    <native:column class="flex-1 p-3">
        @if ($products->isEmpty())
            <native:column class="flex-1 bg-theme-surface rounded-2xl p-6 items-center justify-center gap-3 border border-theme-outline-variant">
                <native:row class="w-16 h-16 rounded-full bg-theme-primary/10 items-center justify-center">
                    <native:icon :ios="Ios::Storefront" :android="Android::Inventory" class="text-theme-primary text-3xl" />
                </native:row>
                <native:text class="text-base font-bold text-theme-on-surface text-center">Daftar Produk Kosong</native:text>
                <native:text class="text-xs text-zinc-500 text-center">Tidak ada produk ditemukan. Coba sinkronisasi atau tambahkan produk.</native:text>
                <native:button variant="primary" class="mt-2">
                    Tambah Produk
                </native:button>
            </native:column>
        @else
            <native:list class="bg-theme-surface rounded-2xl border border-theme-outline-variant overflow-hidden">
                @foreach ($products as $prod)
                    <native:list-item
                        headline="{{ $prod->name }}"
                        supporting="SKU: {{ $prod->sku ?? '-' }} | Kategori: {{ $prod->category?->name ?? 'Tanpa Kategori' }}"
                        :trailing-text="'Rp ' . number_format($prod->price, 0, ',', '.')"
                        :trailing-icon-ios="Ios::ChevronRight"
                        :trailing-icon-android="Android::ChevronRight"
                    >
                        <x-slot:supporting-content>
                            <native:text class="text-xs mt-1 {{ $prod->stock_quantity <= $prod->min_stock_level ? 'text-amber-600 font-bold' : 'text-theme-success' }}">
                                Stok: {{ $prod->stock_quantity }}
                            </native:text>
                        </x-slot>
                    </native:list-item>
                @endforeach
            </native:list>
        @endif
    </native:column>
</native:column>
