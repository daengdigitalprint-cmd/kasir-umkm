@use('App\Icons\Android')
@use('App\Icons\Ios')

<native:column class="flex-1 bg-theme-background">
    {{-- Header Bar: Info Shift & Kasir --}}
    <native:column class="bg-theme-surface p-4 border-b border-theme-outline-variant gap-2 shadow-sm">
        <native:row class="justify-between items-center">
            <native:column class="gap-0.5">
                <native:text class="text-xs text-zinc-500 font-medium">KASIR AKTIF</native:text>
                <native:text class="text-base font-bold text-theme-on-surface">{{ $cashierName }}</native:text>
            </native:column>

            <native:row class="items-center gap-2">
                @if ($isShiftOpen)
                    <native:button
                        variant="secondary"
                        size="sm"
                        :ios-icon="Ios::Receipt"
                        :android-icon="Android::PointOfSale"
                        @press="openShiftModal"
                    >
                        {{ $shiftStatusText }}
                    </native:button>
                @else
                    <native:button
                        variant="primary"
                        size="sm"
                        :ios-icon="Ios::Plus"
                        :android-icon="Android::Add"
                        @press="openShiftModal"
                    >
                        Buka Shift
                    </native:button>
                @endif
            </native:row>
        </native:row>

        @if ($errorMessage !== '')
            <native:row class="p-2.5 bg-red-50 rounded-xl border border-red-200 items-center gap-2">
                <native:icon :ios="Ios::ExclamationmarkTriangle" :android="Android::Warning" class="text-red-600 text-sm" />
                <native:text class="text-xs text-red-600 font-medium flex-1">{{ $errorMessage }}</native:text>
            </native:row>
        @endif
    </native:column>

    {{-- Pencarian & Filter Kategori --}}
    <native:column class="p-3 gap-2 bg-theme-surface border-b border-theme-outline-variant">
        <native:outlined-text-input
            placeholder="Cari produk atau SKU..."
            :leading-icon-ios="Ios::Magnifyingglass"
            :leading-icon-android="Android::Search"
            native:model.live.debounce.300ms="search"
            size="sm"
        />

        {{-- Baris Filter Kategori --}}
        <native:scroll-view horizontal class="gap-2 py-1">
            <native:chip
                label="Semua"
                :selected="$selectedCategoryId === null"
                @change="selectCategory"
            />
            @foreach ($categories as $category)
                <native:chip
                    label="{{ $category->name }}"
                    :selected="$selectedCategoryId === $category->id"
                    @change="selectCategory({{ $category->id }})"
                />
            @endforeach
        </native:scroll-view>
    </native:column>

    {{-- Area Utama: Pembagian Konten Katalog & Ringkasan Keranjang --}}
    <native:column class="flex-1 p-3 gap-3">
        {{-- Section Katalog Produk --}}
        <native:column class="flex-1 bg-theme-surface rounded-2xl border border-theme-outline-variant overflow-hidden">
            <native:row class="p-3 bg-zinc-50 border-b border-theme-outline-variant justify-between items-center">
                <native:text class="text-xs font-bold text-zinc-600 tracking-wider">KATALOG PRODUK ({{ count($products) }})</native:text>
                <native:text class="text-xs text-zinc-400">Ketuk untuk tambah</native:text>
            </native:row>

            @if ($products->isEmpty())
                <native:column class="flex-1 items-center justify-center p-6 gap-2">
                    <native:icon :ios="Ios::Tray" :android="Android::Inbox" class="text-zinc-400 text-3xl" />
                    <native:text class="text-sm font-semibold text-zinc-500">Tidak ada produk ditemukan</native:text>
                    <native:text class="text-xs text-zinc-400 text-center">Coba ubah kata kunci pencarian atau kategori filter.</native:text>
                </native:column>
            @else
                <native:list class="flex-1">
                    @foreach ($products as $prod)
                        <native:list-item
                            headline="{{ $prod->name }}"
                            supporting="Stok: {{ $prod->stock_quantity }} | Rp {{ number_format($prod->price, 0, ',', '.') }}"
                            :trailing-text="'Rp ' . number_format($prod->price, 0, ',', '.')"
                            :trailing-icon-ios="Ios::Plus"
                            :trailing-icon-android="Android::Add"
                            @press="addToCart({{ $prod->id }})"
                        />
                    @endforeach
                </native:list>
            @endif
        </native:column>

        {{-- Panel Ringkasan Keranjang Kasir --}}
        <native:column class="bg-theme-surface rounded-2xl border border-theme-outline-variant p-4 gap-3 shadow-sm">
            <native:row class="justify-between items-center">
                <native:row class="items-center gap-2">
                    <native:icon :ios="Ios::Cart" :android="Android::ShoppingCart" class="text-theme-primary text-base" />
                    <native:text class="text-sm font-bold text-theme-on-surface">Keranjang Belanja</native:text>
                    <native:text class="text-xs px-2 py-0.5 rounded-full bg-theme-primary/10 text-theme-primary font-bold">
                        {{ $cartCount }}
                    </native:text>
                </native:row>

                @if (!empty($cart))
                    <native:button
                        variant="ghost"
                        size="sm"
                        @press="clearCart"
                    >
                        Hapus Semua
                    </native:button>
                @endif
            </native:row>

            @if (!empty($cart))
                <native:scroll-view class="max-h-36 gap-2">
                    @foreach ($cart as $item)
                        <native:row class="justify-between items-center p-2 rounded-xl bg-zinc-50 border border-zinc-100">
                            <native:column class="flex-1 mr-2">
                                <native:text class="text-xs font-semibold text-zinc-800 line-clamp-1">{{ $item['name'] }}</native:text>
                                <native:text class="text-2xs text-zinc-500">
                                    {{ $item['qty'] }} x Rp {{ number_format($item['price'], 0, ',', '.') }}
                                </native:text>
                            </native:column>

                            <native:row class="items-center gap-2">
                                <native:button
                                    variant="secondary"
                                    size="sm"
                                    :ios-icon="Ios::Minus"
                                    :android-icon="Android::Remove"
                                    @press="decrementCart({{ $item['product_id'] }})"
                                />
                                <native:text class="text-xs font-bold text-zinc-800">{{ $item['qty'] }}</native:text>
                                <native:button
                                    variant="secondary"
                                    size="sm"
                                    :ios-icon="Ios::Plus"
                                    :android-icon="Android::Add"
                                    @press="incrementCart({{ $item['product_id'] }})"
                                />
                            </native:row>
                        </native:row>
                    @endforeach
                </native:scroll-view>
            @endif

            <native:divider class="bg-theme-outline-variant" />

            <native:row class="justify-between items-center">
                <native:column>
                    <native:text class="text-xs text-zinc-500">TOTAL TAGIHAN</native:text>
                    <native:text class="text-lg font-extrabold text-theme-primary">{{ $subtotalFormatted }}</native:text>
                </native:column>

                <native:button
                    variant="primary"
                    :disabled="empty($cart) || !$isShiftOpen"
                    :ios-icon="Ios::Creditcard"
                    :android-icon="Android::Payments"
                    @press="openCheckout"
                >
                    Bayar
                </native:button>
            </native:row>
        </native:column>
    </native:column>

    {{-- MODAL SHIFT KASIR (BUKA / TUTUP SHIFT) --}}
    <native:modal :visible="$showShiftModal" @dismiss="closeShiftModal">
        <native:column class="flex-1 bg-theme-background p-6 gap-4">
            <native:row class="justify-between items-center">
                <native:text class="text-lg font-bold text-theme-on-surface">
                    {{ $isShiftOpen ? 'Tutup Shift Kasir' : 'Buka Shift Baru' }}
                </native:text>
                <native:button variant="ghost" size="sm" @press="closeShiftModal">
                    Batal
                </native:button>
            </native:row>

            @if ($isShiftOpen)
                <native:column class="gap-3 bg-theme-surface p-4 rounded-2xl border border-theme-outline-variant">
                    <native:text class="text-xs text-zinc-500">
                        Shift Anda saat ini sedang aktif. Masukkan jumlah uang tunai di laci kasir saat ini untuk menyelesaikan shift.
                    </native:text>

                    <native:outlined-text-input
                        label="Kas Fisik di Laci (Rp)"
                        placeholder="Contoh: 500000"
                        keyboard="number"
                        native:model="closingCashInput"
                    />

                    <native:button variant="destructive" class="w-full mt-2" @press="endShift">
                        Selesaikan & Tutup Shift
                    </native:button>
                </native:column>
            @else
                <native:column class="gap-3 bg-theme-surface p-4 rounded-2xl border border-theme-outline-variant">
                    <native:text class="text-xs text-zinc-500">
                        Mulai sesi kasir baru dengan memasukkan modal kas awal di laci.
                    </native:text>

                    <native:outlined-text-input
                        label="Modal Kas Awal (Rp)"
                        placeholder="Contoh: 200000"
                        keyboard="number"
                        native:model="openingCashInput"
                    />

                    <native:button variant="primary" class="w-full mt-2" @press="startShift">
                        Buka Shift Sekarang
                    </native:button>
                </native:column>
            @endif
        </native:column>
    </native:modal>

    {{-- MODAL CHECKOUT & PEMBAYARAN --}}
    <native:modal :visible="$showCheckoutModal" @dismiss="closeCheckout">
        <native:column class="flex-1 bg-theme-background p-6 gap-4">
            <native:row class="justify-between items-center">
                <native:text class="text-lg font-bold text-theme-on-surface">Checkout Pembayaran</native:text>
                <native:button variant="ghost" size="sm" @press="closeCheckout">
                    Tutup
                </native:button>
            </native:row>

            <native:column class="bg-theme-surface p-4 rounded-2xl border border-theme-outline-variant gap-2 items-center">
                <native:text class="text-xs text-zinc-500 font-medium">TOTAL PEMBAYARAN</native:text>
                <native:text class="text-2xl font-black text-theme-primary">{{ $subtotalFormatted }}</native:text>
            </native:column>

            {{-- Pilihan Metode Pembayaran --}}
            <native:column class="gap-2">
                <native:text class="text-xs font-bold text-zinc-600">METODE PEMBAYARAN</native:text>
                <native:row class="gap-3">
                    <native:button
                        :variant="$paymentMethod === 'cash' ? 'primary' : 'secondary'"
                        class="flex-1"
                        :ios-icon="Ios::Banknote"
                        :android-icon="Android::Payments"
                        @press="setPaymentMethod('cash')"
                    >
                        Tunai
                    </native:button>
                    <native:button
                        :variant="$paymentMethod === 'qris' ? 'primary' : 'secondary'"
                        class="flex-1"
                        :ios-icon="Ios::Qrcode"
                        :android-icon="Android::QrCode"
                        @press="setPaymentMethod('qris')"
                    >
                        QRIS
                    </native:button>
                </native:row>
            </native:column>

            {{-- Input Uang Tunai / Tampilan QRIS --}}
            @if ($paymentMethod === 'cash')
                <native:column class="bg-theme-surface p-4 rounded-2xl border border-theme-outline-variant gap-3">
                    <native:outlined-text-input
                        label="Uang Diterima (Rp)"
                        placeholder="Nominal uang dari pelanggan"
                        keyboard="number"
                        native:model="cashReceivedInput"
                        @change="calculateChange"
                    />

                    <native:row class="justify-between items-center p-3 bg-zinc-50 rounded-xl">
                        <native:text class="text-xs text-zinc-600 font-semibold">Kembalian</native:text>
                        <native:text class="text-sm font-bold text-theme-success">
                            Rp {{ number_format($changeAmount, 0, ',', '.') }}
                        </native:text>
                    </native:row>
                </native:column>
            @else
                <native:column class="bg-theme-surface p-4 rounded-2xl border border-theme-outline-variant items-center justify-center gap-2">
                    <native:icon :ios="Ios::Qrcode" :android="Android::QrCode" class="text-theme-primary text-4xl" />
                    <native:text class="text-xs font-semibold text-zinc-600">Scan QRIS Dinamis / Statis</native:text>
                    <native:text class="text-2xs text-zinc-400 text-center">Pastikan dana telah masuk ke rekening toko sebelum menekan proses.</native:text>
                </native:column>
            @endif

            <native:button
                variant="primary"
                class="w-full mt-2"
                :ios-icon="Ios::Checkmark"
                :android-icon="Android::Check"
                @press="processCheckout"
            >
                Proses Pembayaran Selesai
            </native:button>
        </native:column>
    </native:modal>

    {{-- MODAL SUKSES TRANSAKSI --}}
    <native:modal :visible="$showSuccessModal" @dismiss="closeSuccessModal">
        <native:column class="flex-1 bg-theme-background p-6 items-center justify-center gap-4">
            <native:row class="w-16 h-16 rounded-full bg-theme-success/15 items-center justify-center">
                <native:icon :ios="Ios::Checkmark" :android="Android::Check" class="text-theme-success text-3xl" />
            </native:row>

            <native:text class="text-xl font-extrabold text-theme-on-surface">Transaksi Berhasil!</native:text>
            <native:text class="text-xs text-zinc-500 text-center">
                Nomor: <native:text class="font-bold text-zinc-700">{{ $lastTransactionNumber }}</native:text>
            </native:text>

            <native:column class="w-full bg-theme-surface p-4 rounded-2xl border border-theme-outline-variant gap-2 items-center">
                <native:text class="text-xs text-zinc-500">KEMBALIAN</native:text>
                <native:text class="text-2xl font-black text-theme-success">{{ $lastChangeFormatted }}</native:text>
                <native:text class="text-2xs text-zinc-400">Data tersimpan di SQLite lokal & antrian sinkronisasi</native:text>
            </native:column>

            <native:button
                variant="secondary"
                class="w-full mt-4"
                :ios-icon="Ios::Printer"
                :android-icon="Android::Print"
                @press="printReceipt('{{ $lastTransactionNumber }}')"
            >
                Cetak Struk
            </native:button>

            <native:button variant="primary" class="w-full mt-2" @press="closeSuccessModal">
                Selesai / Transaksi Baru
            </native:button>
        </native:column>
    </native:modal>
</native:column>
