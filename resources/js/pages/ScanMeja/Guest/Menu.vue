<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ref, computed, onMounted } from 'vue';
import { ShoppingCart, Plus, Minus, X, Check, Utensils, MapPin, Clock, Banknote, QrCode, Building, Flame, ExternalLink, Search, Phone, MessageCircle } from 'lucide-vue-next';

interface Product {
    id: number;
    name: string;
    price: number;
    description: string | null;
    image: string | null;
    has_variants: boolean;
    modifier_options: any[];
}

interface Category {
    id: number;
    name: string;
    products: Product[];
}

interface CartItem {
    product: Product;
    quantity: number;
    notes: string;
}

interface PaymentMethod {
    type: string;
    label: string;
    instructions: string | null;
    image_url: string | null;
    bank_name: string | null;
    account_number: string | null;
    account_name: string | null;
}

const props = defineProps<{
    table: { id: number; name: string; slug: string; capacity: number | null };
    store: { id: number; name: string; currency: string; address?: string | null; phone?: string | null; logo_url?: string | null; cover_url?: string | null };
    categories: Category[];
    bestSelling?: Product[];
    paymentMethods?: PaymentMethod[];
    /** F8: produk pemicu → produk yang ditawarkan (kosong bila modul Saran Menu mati). */
    upsellMap?: Record<string, number[]> | number[];
}>();

// ── F8 Saran tambahan menu ──
const upsellTriggerId = ref<number | null>(null);
const allProducts = computed(() => {
    const byId = new Map<number, Product>();
    for (const category of props.categories) {
        for (const product of category.products) byId.set(product.id, product);
    }
    return byId;
});
const upsellSuggestions = computed<Product[]>(() => {
    const map = props.upsellMap;
    if (upsellTriggerId.value === null || !map || Array.isArray(map)) return [];
    const inCart = new Set(cart.value.map((item) => item.product.id));
    return (map[String(upsellTriggerId.value)] ?? [])
        .map((id) => allProducts.value.get(id))
        .filter((p): p is Product => !!p && !inCart.has(p.id) && !p.has_variants);
});
const acceptUpsell = (product: Product) => {
    cart.value.push({ product, quantity: 1, notes: '' });
    upsellTriggerId.value = product.id;
};

const cart = ref<CartItem[]>([]);
const cartOpen = ref(false);
const selectedProduct = ref<Product | null>(null);
const selectedNotes = ref('');
const ordered = ref(false);
const orderNumber = ref('');
const orderTotal = ref('');
const statusToken = ref('');
const customerName = ref('');
const selectedPaymentMethod = ref('');

// Set default ke metode pertama yang aktif setelah props tersedia
const initPaymentMethod = () => {
    if (!selectedPaymentMethod.value && props.paymentMethods?.length) {
        selectedPaymentMethod.value = props.paymentMethods[0].type;
    } else if (!selectedPaymentMethod.value) {
        selectedPaymentMethod.value = 'cash';
    }
};
onMounted(initPaymentMethod);
const submitting = ref(false);

// Gunakan metode pembayaran dari toko, fallback ke default tunai
const availablePaymentMethods = computed(() => {
    if (props.paymentMethods && props.paymentMethods.length > 0) {
        return props.paymentMethods;
    }
    return [
        { type: 'cash', label: 'Tunai', instructions: 'Bayar langsung di kasir', image_url: null, bank_name: null, account_number: null, account_name: null },
    ];
});

const selectedMethod = computed(() =>
    availablePaymentMethods.value.find(m => m.type === selectedPaymentMethod.value) ?? availablePaymentMethods.value[0]
);

const formatPrice = (price: number) => {
    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: props.store.currency === 'IDR' ? 'IDR' : props.store.currency,
        minimumFractionDigits: 0,
    }).format(price);
};

// ── Kepala halaman: inisial toko & kontak ──
const storeInitials = computed(() =>
    props.store.name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((w) => w[0]?.toUpperCase())
        .join(''),
);
const phoneDigits = computed(() => (props.store.phone ?? '').replace(/\D/g, ''));
const waNumber = computed(() => (phoneDigits.value.startsWith('0') ? `62${phoneDigits.value.slice(1)}` : phoneDigits.value));

// Foto sampul: dari pengaturan Tampilan menu digital; bila kosong pakai foto menu pertama.
const coverImage = computed(
    () =>
        props.store.cover_url ??
        [...(props.bestSelling ?? []), ...props.categories.flatMap((c) => c.products)].find((p) => p.image)?.image ??
        null,
);
const paymentSummary = computed(() => availablePaymentMethods.value.map((m) => m.label).join(' · '));
const qtyInCart = (productId: number) => cart.value.find((i) => i.product.id === productId)?.quantity ?? 0;

// Kolom cari muncul saat tombol "Cari menu" ditekan.
const searchOpen = ref(false);
const searchInput = ref<HTMLInputElement | null>(null);
const openSearch = () => {
    searchOpen.value = true;
    setTimeout(() => searchInput.value?.focus(), 50);
};
const closeSearch = () => {
    search.value = '';
    searchOpen.value = false;
};

// ── Cari menu ──
const search = ref('');
const searching = computed(() => search.value.trim().length > 0);
const visibleCategories = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (!q) return props.categories;
    return props.categories
        .map((c) => ({ ...c, products: c.products.filter((p) => p.name.toLowerCase().includes(q) || p.description?.toLowerCase().includes(q)) }))
        .filter((c) => c.products.length > 0);
});

const activeCategory = ref<number | null>(null);
const categoryRefs = new Map<number, HTMLElement>();

const scrollToCategory = (categoryId: number) => {
    activeCategory.value = categoryId;
    // Saat sedang mencari, kosongkan pencarian dulu agar semua kategori tampil kembali.
    const go = () => categoryRefs.get(categoryId)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    if (searching.value) {
        search.value = '';
        setTimeout(go, 50);
    } else {
        go();
    }
};

const cartCount = computed(() => cart.value.reduce((sum, item) => sum + item.quantity, 0));
const cartSubtotal = computed(() => cart.value.reduce((sum, item) => sum + item.product.price * item.quantity, 0));

const addToCart = (product: Product) => {
    const existing = cart.value.find((item) => item.product.id === product.id);
    if (existing) {
        existing.quantity += 1;
    } else {
        cart.value.push({ product, quantity: 1, notes: '' });
    }
    upsellTriggerId.value = product.id;
};

const removeFromCart = (productId: number) => {
    cart.value = cart.value.filter((item) => item.product.id !== productId);
};

const decrement = (productId: number) => {
    const index = cart.value.findIndex((item) => item.product.id === productId);
    if (index === -1) return;
    if (cart.value[index].quantity > 1) {
        cart.value[index].quantity -= 1;
    } else {
        cart.value.splice(index, 1);
    }
};

const increment = (productId: number) => {
    const item = cart.value.find((i) => i.product.id === productId);
    if (item) item.quantity += 1;
};

const openProduct = (product: Product) => {
    selectedProduct.value = product;
    selectedNotes.value = '';
};

const closeProduct = () => {
    selectedProduct.value = null;
    selectedNotes.value = '';
};

const confirmAddFromDetail = () => {
    if (!selectedProduct.value) return;
    const existing = cart.value.find((item) => item.product.id === selectedProduct.value!.id);
    if (existing) {
        existing.quantity += 1;
        if (selectedNotes.value) existing.notes = selectedNotes.value;
    } else {
        cart.value.push({
            product: selectedProduct.value,
            quantity: 1,
            notes: selectedNotes.value,
        });
    }
    upsellTriggerId.value = selectedProduct.value.id;
    closeProduct();
};

const submitOrder = async () => {
    if (cart.value.length === 0) return;
    submitting.value = true;

    try {
        const response = await fetch('/api/guest/order', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '',
            },
            body: JSON.stringify({
                table_slug: props.table.slug,
                customer_name: customerName.value || undefined,
                payment_method: selectedPaymentMethod.value,
                items: cart.value.map((item) => ({
                    product_id: item.product.id,
                    quantity: item.quantity,
                    notes: item.notes || undefined,
                })),
            }),
        });

        const data = await response.json();

        if (!response.ok) {
            throw new Error(data.error || 'Gagal membuat pesanan.');
        }

        orderNumber.value = data.transaction?.number || 'Terima kasih';
        orderTotal.value = formatPrice(parseFloat(data.transaction?.total || '0'));
        statusToken.value = data.transaction?.status_token || '';
        cart.value = [];
        cartOpen.value = false;

        // Arahkan ke halaman status pesanan
        if (statusToken.value) {
            window.location.href = `/m/${props.table.slug}/status/${statusToken.value}`;
            return;
        }

        ordered.value = true;
    } catch (error: any) {
        alert(error.message || 'Gagal membuat pesanan. Silakan coba lagi.');
    } finally {
        submitting.value = false;
    }
};
</script>

<template>
    <Head :title="`${store.name} - Menu Digital`" />

    <div class="min-h-screen bg-white flex flex-col">
        <!-- Sampul + kartu toko -->
        <div class="relative">
            <div class="h-40 w-full overflow-hidden bg-violet-100 sm:h-52">
                <img v-if="coverImage" :src="coverImage" alt="" class="h-full w-full object-cover" />
                <div v-else class="flex h-full w-full items-center justify-center"><Utensils class="h-12 w-12 text-violet-200" /></div>
                <div class="absolute inset-0 bg-gradient-to-b from-black/30 via-transparent to-transparent" />
            </div>
            <span class="absolute top-3 right-4 inline-flex items-center gap-1.5 rounded-full bg-white/95 px-3 py-1.5 text-xs font-bold text-slate-800">
                <MapPin class="h-3.5 w-3.5 text-violet-600" /> {{ table.name }}
            </span>
        </div>

        <header class="relative z-10 mx-auto -mt-12 w-full max-w-3xl px-4">
            <div class="flex gap-3 rounded-2xl border border-slate-200 bg-white p-3">
                <div class="flex h-20 w-20 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-violet-50 text-xl font-black text-violet-700">
                    <img v-if="store.logo_url" :src="store.logo_url" :alt="store.name" class="h-full w-full object-cover" />
                    <span v-else>{{ storeInitials }}</span>
                </div>
                <div class="min-w-0 flex-1 py-0.5">
                    <span class="inline-flex items-center gap-1 rounded-md bg-violet-50 px-1.5 py-0.5 text-[11px] font-bold text-violet-700">
                        <Check class="h-3 w-3" /> Pesan dari meja
                    </span>
                    <h1 class="mt-1 truncate text-lg leading-tight font-black text-slate-900">{{ store.name }}</h1>
                    <p v-if="store.address" class="truncate text-xs text-slate-500">{{ store.address }}</p>
                    <p class="mt-1 text-xs text-slate-600">
                        {{ table.name }}<template v-if="table.capacity"> · {{ table.capacity }} orang</template>
                    </p>
                </div>
            </div>

            <!-- Tombol aksi -->
            <div class="mt-3 flex gap-2 overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                <button type="button" class="inline-flex shrink-0 items-center gap-1.5 rounded-full border border-violet-300 bg-white px-3.5 py-2 text-sm font-semibold text-violet-700" @click="openSearch">
                    <Search class="h-4 w-4" /> Cari menu
                </button>
                <a v-if="phoneDigits" :href="`https://wa.me/${waNumber}`" target="_blank" rel="noopener noreferrer" class="inline-flex shrink-0 items-center gap-1.5 rounded-full border border-slate-200 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700">
                    <MessageCircle class="h-4 w-4" /> Hubungi toko
                </a>
                <a v-if="phoneDigits" :href="`tel:${phoneDigits}`" class="inline-flex shrink-0 items-center gap-1.5 rounded-full border border-slate-200 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700">
                    <Phone class="h-4 w-4" /> Telepon
                </a>
            </div>

            <!-- Info singkat -->
            <div class="mt-3 grid grid-cols-2 gap-2">
                <div class="flex items-center gap-2.5 rounded-xl border border-slate-200 bg-white p-2.5">
                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-violet-50 text-violet-600"><Clock class="h-4 w-4" /></div>
                    <div class="min-w-0">
                        <p class="text-xs font-bold text-slate-800">Langsung ke kasir</p>
                        <p class="truncate text-[11px] text-slate-500">Tanpa antre</p>
                    </div>
                </div>
                <div class="flex items-center gap-2.5 rounded-xl border border-slate-200 bg-white p-2.5">
                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600"><Banknote class="h-4 w-4" /></div>
                    <div class="min-w-0">
                        <p class="text-xs font-bold text-slate-800">Pembayaran</p>
                        <p class="truncate text-[11px] text-slate-500">{{ paymentSummary }}</p>
                    </div>
                </div>
            </div>
        </header>

        <!-- Bilah lengket: cari / kategori -->
        <div class="sticky top-0 z-30 mt-4 border-b border-slate-200 bg-white">
            <div class="mx-auto max-w-3xl px-4">
                <div v-if="searchOpen" class="flex items-center gap-2 py-2">
                    <label class="flex flex-1 items-center gap-2 rounded-xl border border-violet-300 bg-white px-3 py-2">
                        <Search class="h-4 w-4 shrink-0 text-slate-400" />
                        <input ref="searchInput" v-model="search" type="text" inputmode="search" enterkeyhint="search" placeholder="Cari menu" class="w-full bg-transparent text-sm outline-none placeholder:text-slate-400" />
                    </label>
                    <button type="button" class="px-1 text-sm font-semibold text-violet-700" @click="closeSearch">Batal</button>
                </div>
                <div v-else class="flex items-center gap-1 overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                    <button type="button" class="shrink-0 py-3 pr-2 text-slate-500" aria-label="Cari menu" @click="openSearch"><Search class="h-4 w-4" /></button>
                    <button
                        v-for="category in props.categories"
                        :key="category.id"
                        type="button"
                        class="shrink-0 border-b-2 px-3 py-3 text-sm font-bold whitespace-nowrap transition"
                        :class="activeCategory === category.id ? 'border-violet-600 text-violet-700' : 'border-transparent text-slate-500 hover:text-slate-800'"
                        @click="scrollToCategory(category.id)"
                    >
                        {{ category.name }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <main class="flex-1 max-w-3xl mx-auto w-full px-4 pb-10 pt-5">
            <!-- Success state -->
            <div v-if="ordered" class="text-center py-16">
                <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-emerald-50">
                    <Check class="h-8 w-8 text-emerald-500" />
                </div>
                <h2 class="text-lg font-black text-slate-900">Pesanan Diterima!</h2>
                <p class="text-sm text-slate-500 mt-1">{{ orderNumber }}</p>
                <p class="text-sm text-slate-500">Total: {{ orderTotal }}</p>
                <p class="text-xs text-slate-400 mt-3 flex items-center justify-center gap-1">
                    <Clock class="h-3 w-3" />
                    Mohon tunggu, pesanan Anda sedang diproses
                </p>
                <button
                    @click="ordered = false"
                    class="mt-6 px-6 py-2.5 rounded-xl bg-violet-600 text-white text-sm font-bold"
                >
                    Pesan Lagi
                </button>
            </div>

            <!-- Menu List -->
            <div v-else>
                <p v-if="searching" class="mb-4 text-sm text-slate-500">
                    Hasil pencarian <b class="text-slate-800">"{{ search.trim() }}"</b>
                </p>

                <!-- Terlaris: kartu geser -->
                <section v-if="props.bestSelling?.length && !searching" class="mb-7">
                    <h2 class="mb-3 flex items-center gap-2 text-lg font-black text-slate-900">
                        <Flame class="h-5 w-5 text-orange-500" /> Terlaris
                    </h2>
                    <div class="-mx-4 flex gap-3 overflow-x-auto px-4 pb-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                        <div v-for="product in props.bestSelling" :key="product.id" class="w-36 shrink-0">
                            <button type="button" class="relative block aspect-square w-full overflow-hidden rounded-2xl bg-slate-100" @click="openProduct(product)">
                                <img v-if="product.image" :src="product.image" :alt="product.name" class="h-full w-full object-cover" />
                                <div v-else class="flex h-full w-full items-center justify-center"><Utensils class="h-8 w-8 text-slate-300" /></div>
                                <span v-if="qtyInCart(product.id)" class="absolute top-2 right-2 flex h-7 min-w-7 items-center justify-center rounded-full border-2 border-white bg-violet-600 px-1 text-xs font-black text-white">
                                    {{ qtyInCart(product.id) }}
                                </span>
                            </button>
                            <div class="mt-2 flex items-start justify-between gap-1">
                                <button type="button" class="min-w-0 text-left" @click="openProduct(product)">
                                    <p class="line-clamp-2 text-sm leading-snug font-semibold text-slate-800">{{ product.name }}</p>
                                    <p class="mt-0.5 text-sm font-bold text-violet-700">{{ formatPrice(product.price) }}</p>
                                </button>
                                <button type="button" class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full border border-violet-300 text-violet-700" :aria-label="`Tambah ${product.name}`" @click="addToCart(product)">
                                    <Plus class="h-4 w-4" />
                                </button>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Daftar per kategori: baris foto + teks + tombol tambah -->
                <section v-for="category in visibleCategories" :key="category.id" :ref="(element) => element && categoryRefs.set(category.id, element as HTMLElement)" class="mb-6 scroll-mt-16">
                    <h2 class="mb-1 text-lg font-black text-slate-900">{{ category.name }}</h2>
                    <div class="divide-y divide-slate-100">
                        <div v-for="product in category.products" :key="product.id" class="flex gap-3 py-3">
                            <button type="button" class="relative h-24 w-24 shrink-0 overflow-hidden rounded-xl bg-slate-100" @click="openProduct(product)">
                                <img v-if="product.image" :src="product.image" :alt="product.name" class="h-full w-full object-cover" />
                                <div v-else class="flex h-full w-full items-center justify-center"><Utensils class="h-7 w-7 text-slate-300" /></div>
                            </button>
                            <!-- Nama, keterangan, dan harga berdekatan; dipusatkan terhadap foto. -->
                            <div class="flex min-w-0 flex-1 flex-col justify-center">
                                <button type="button" class="text-left" @click="openProduct(product)">
                                    <h3 class="line-clamp-2 text-[15px] leading-snug font-semibold text-slate-800">{{ product.name }}</h3>
                                    <p v-if="product.description" class="mt-0.5 line-clamp-2 text-xs leading-snug text-slate-500">{{ product.description }}</p>
                                </button>
                                <div class="mt-1.5 flex items-center justify-between gap-2">
                                    <p class="text-[15px] font-bold text-violet-700">{{ formatPrice(product.price) }}</p>
                                    <div v-if="qtyInCart(product.id)" class="flex items-center gap-2">
                                        <button type="button" class="flex h-7 w-7 items-center justify-center rounded-full border border-slate-300 text-slate-600" :aria-label="`Kurangi ${product.name}`" @click="decrement(product.id)">
                                            <Minus class="h-3.5 w-3.5" />
                                        </button>
                                        <span class="w-4 text-center text-sm font-bold">{{ qtyInCart(product.id) }}</span>
                                        <button type="button" class="flex h-7 w-7 items-center justify-center rounded-full bg-violet-600 text-white" :aria-label="`Tambah ${product.name}`" @click="addToCart(product)">
                                            <Plus class="h-3.5 w-3.5" />
                                        </button>
                                    </div>
                                    <button v-else type="button" class="flex h-7 w-7 items-center justify-center rounded-full border border-violet-300 text-violet-700" :aria-label="`Tambah ${product.name}`" @click="addToCart(product)">
                                        <Plus class="h-4 w-4" />
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <div v-if="searching && visibleCategories.length === 0" class="py-16 text-center">
                    <Search class="mx-auto mb-3 h-10 w-10 text-slate-200" />
                    <p class="text-sm font-bold text-slate-500">Menu tidak ditemukan</p>
                    <button type="button" class="mt-3 text-sm font-semibold text-violet-600" @click="search = ''">Lihat semua menu</button>
                </div>

                <div v-if="props.categories.length === 0" class="text-center py-16">
                    <Utensils class="mx-auto h-12 w-12 text-slate-200 mb-3" />
                    <p class="text-sm font-bold text-slate-500">Menu belum tersedia</p>
                    <p class="text-xs text-slate-400 mt-1">Silakan hubungi staf</p>
                </div>
            </div>
        </main>

        <footer class="border-t border-slate-200 bg-white px-4 pt-6 text-center" :class="cartCount > 0 && !cartOpen && !ordered ? 'pb-32' : 'pb-6'">
            <div class="mx-auto max-w-3xl">
                <p class="text-sm font-bold text-slate-800">{{ store.name }}</p>
                <p v-if="store.address" class="mt-0.5 text-xs text-slate-500">{{ store.address }}</p>
                <div v-if="phoneDigits" class="mt-3 flex items-center justify-center gap-4 text-xs font-semibold">
                    <a :href="`tel:${phoneDigits}`" class="inline-flex items-center gap-1.5 text-slate-600 hover:text-violet-700">
                        <Phone class="h-3.5 w-3.5" /> Telepon
                    </a>
                    <span class="text-slate-300">·</span>
                    <a :href="`https://wa.me/${waNumber}`" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1.5 text-slate-600 hover:text-violet-700">
                        <MessageCircle class="h-3.5 w-3.5" /> WhatsApp
                    </a>
                </div>
                <a
                    href="https://play.google.com/store/apps/details?id=com.kasirumkm.idea&hl=id"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="mt-4 inline-flex items-center gap-1 text-[11px] font-medium text-slate-400 transition hover:text-violet-600"
                >
                    Powered by Kasir UMKM by MaccaPOS
                    <ExternalLink class="h-3 w-3" />
                </a>
            </div>
        </footer>

        <!-- Cart Bar (bottom) -->
        <div
            v-if="cartCount > 0 && !cartOpen && !ordered"
            class="fixed bottom-0 inset-x-0 z-30 rounded-t-3xl border-t border-slate-200 bg-white px-4 pt-3 pb-4"
        >
            <div class="max-w-3xl mx-auto">
                <!-- F8: saran tambahan menu -->
                <div v-if="upsellSuggestions.length > 0" class="mb-3 rounded-2xl border border-amber-200 bg-amber-50 p-3">
                    <div class="mb-2 flex items-center justify-between">
                        <p class="text-xs font-bold text-amber-900">Biasanya dipesan bersama</p>
                        <button type="button" class="text-amber-700" aria-label="Tutup saran" @click="upsellTriggerId = null">
                            <X class="h-4 w-4" />
                        </button>
                    </div>
                    <div class="flex gap-2 overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                        <button
                            v-for="s in upsellSuggestions"
                            :key="s.id"
                            type="button"
                            class="flex shrink-0 items-center gap-2 rounded-xl border border-amber-300 bg-white px-3 py-2 text-left"
                            @click="acceptUpsell(s)"
                        >
                            <span>
                                <span class="block text-sm font-semibold text-slate-900">{{ s.name }}</span>
                                <span class="block text-xs text-slate-500">{{ formatPrice(s.price) }}</span>
                            </span>
                            <Plus class="h-4 w-4 text-violet-600" />
                        </button>
                    </div>
                </div>
                <button
                    @click="cartOpen = true"
                    class="flex w-full items-center justify-between rounded-full bg-violet-600 px-6 py-4 font-bold text-white"
                >
                    <span class="flex items-center gap-2 text-sm">
                        <ShoppingCart class="h-5 w-5" />
                        Keranjang · {{ cartCount }} item
                    </span>
                    <span class="text-base">{{ formatPrice(cartSubtotal) }}</span>
                </button>
            </div>
        </div>

        <!-- Cart Drawer -->
        <div
            v-if="cartOpen"
            class="fixed inset-0 z-40 bg-black/40"
            @click.self="cartOpen = false"
        >
            <div class="absolute bottom-0 inset-x-0 bg-white rounded-t-3xl max-h-[85vh] flex flex-col">
                <div class="p-4 border-b border-slate-100 flex items-center justify-between">
                    <h2 class="font-black text-slate-900 text-base">Pesanan Anda</h2>
                    <button @click="cartOpen = false" class="flex h-9 w-9 items-center justify-center rounded-xl hover:bg-slate-100">
                        <X class="h-5 w-5 text-slate-500" />
                    </button>
                </div>

                <div class="flex-1 overflow-y-auto p-4 space-y-3">
                    <div v-if="cart.length === 0" class="text-center py-10">
                        <p class="text-sm text-slate-400">Keranjang kosong</p>
                    </div>
                    <div
                        v-for="item in cart"
                        :key="item.product.id"
                        class="flex items-center gap-3 rounded-2xl border border-slate-100 p-3"
                    >
                        <div class="h-14 w-14 rounded-xl bg-slate-50 overflow-hidden shrink-0">
                            <img v-if="item.product.image" :src="item.product.image" class="h-full w-full object-cover" />
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="font-bold text-slate-900 text-sm truncate">{{ item.product.name }}</p>
                            <p class="text-xs text-slate-400">{{ formatPrice(item.product.price) }}</p>
                            <div class="flex items-center gap-2 mt-1">
                                <button @click="decrement(item.product.id)" class="flex h-7 w-7 items-center justify-center rounded-lg bg-slate-100 hover:bg-slate-200">
                                    <Minus class="h-3.5 w-3.5" />
                                </button>
                                <span class="text-sm font-bold text-slate-900 w-6 text-center">{{ item.quantity }}</span>
                                <button @click="increment(item.product.id)" class="flex h-7 w-7 items-center justify-center rounded-lg bg-slate-100 hover:bg-slate-200">
                                    <Plus class="h-3.5 w-3.5" />
                                </button>
                            </div>
                        </div>
                        <p class="font-black text-violet-700 text-sm shrink-0">{{ formatPrice(item.product.price * item.quantity) }}</p>
                    </div>
                </div>

                <div v-if="cart.length > 0" class="p-4 border-t border-slate-100 space-y-3">
                    <div>
                        <label class="text-xs font-bold text-slate-600">Nama Pemesan (opsional)</label>
                        <input
                            v-model="customerName"
                            type="text"
                            placeholder="Nama Anda"
                            class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-violet-300"
                        />
                    </div>

                    <div>
                        <label class="text-xs font-bold text-slate-600">Metode Pembayaran</label>
                        <div class="mt-1 grid grid-cols-2 gap-2">
                            <button
                                v-for="method in availablePaymentMethods"
                                :key="method.type"
                                type="button"
                                @click="selectedPaymentMethod = method.type"
                                :class="[
                                    'flex items-center justify-center gap-1.5 rounded-xl border px-3 py-2.5 text-sm font-semibold transition',
                                    selectedPaymentMethod === method.type
                                        ? 'border-violet-600 bg-violet-50 text-violet-700'
                                        : 'border-slate-200 text-slate-600 hover:bg-slate-50',
                                ]"
                            >
                                <Banknote v-if="method.type === 'cash'" class="h-4 w-4" />
                                <QrCode v-else-if="method.type === 'qris'" class="h-4 w-4" />
                                <Building v-else class="h-4 w-4" />
                                {{ method.label }}
                            </button>
                        </div>

                        <!-- Detail metode yang dipilih -->
                        <div v-if="selectedMethod" class="mt-2 rounded-xl bg-slate-50 border border-slate-100 p-3 space-y-2">
                            <!-- QRIS: tampilkan gambar -->
                            <div v-if="selectedMethod.type === 'qris' && selectedMethod.image_url" class="flex justify-center">
                                <img :src="selectedMethod.image_url" alt="QRIS" class="h-40 w-40 object-contain rounded-lg border border-slate-200 bg-white p-1" />
                            </div>
                            <!-- Transfer Bank: tampilkan info rekening -->
                            <div v-if="selectedMethod.type === 'bank_transfer' && selectedMethod.bank_name" class="space-y-0.5">
                                <p class="text-xs text-slate-500">Transfer ke:</p>
                                <p class="text-sm font-bold text-slate-800">{{ selectedMethod.bank_name }}</p>
                                <p class="text-sm font-mono font-bold text-violet-700">{{ selectedMethod.account_number }}</p>
                                <p class="text-xs text-slate-500">a.n. {{ selectedMethod.account_name }}</p>
                            </div>
                            <!-- Instruksi -->
                            <p v-if="selectedMethod.instructions" class="text-xs text-slate-500">{{ selectedMethod.instructions }}</p>
                        </div>
                    </div>

                    <div class="flex items-center justify-between">
                        <span class="text-sm font-bold text-slate-500">Total</span>
                        <span class="font-black text-slate-900 text-lg">{{ formatPrice(cartSubtotal) }}</span>
                    </div>
                    <button
                        @click="submitOrder"
                        :disabled="submitting"
                        class="w-full rounded-2xl bg-violet-600 px-5 py-4 text-white font-bold text-sm disabled:opacity-50"
                    >
                        {{ submitting ? 'Memproses...' : 'Konfirmasi Pesanan' }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Product Detail Bottom Sheet -->
        <div
            v-if="selectedProduct"
            class="fixed inset-0 z-40 bg-black/40"
            @click.self="closeProduct"
        >
            <div class="absolute bottom-0 inset-x-0 bg-white rounded-t-3xl max-h-[85vh] overflow-y-auto">
                <div class="aspect-video w-full bg-slate-50 overflow-hidden">
                    <img
                        v-if="selectedProduct.image"
                        :src="selectedProduct.image"
                        class="h-full w-full object-cover"
                    />
                    <div v-else class="h-full w-full flex items-center justify-center">
                        <Utensils class="h-16 w-16 text-slate-200" />
                    </div>
                </div>
                <div class="p-5">
                    <h2 class="font-black text-slate-900 text-lg">{{ selectedProduct.name }}</h2>
                    <p class="text-xs text-slate-400 mt-1">{{ selectedProduct.description || 'Tidak ada deskripsi' }}</p>
                    <p class="font-black text-violet-700 text-xl mt-3">{{ formatPrice(selectedProduct.price) }}</p>

                    <div class="mt-4">
                        <label class="text-xs font-bold text-slate-600">Catatan (opsional)</label>
                        <input
                            v-model="selectedNotes"
                            type="text"
                            placeholder="Contoh: tidak pedas, tanpa bawang"
                            class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-violet-300"
                        />
                    </div>

                    <button
                        @click="confirmAddFromDetail"
                        class="mt-5 w-full rounded-2xl bg-violet-600 px-5 py-4 text-white font-bold text-sm flex items-center justify-center gap-2"
                    >
                        <Plus class="h-4 w-4" />
                        Tambah ke Pesanan · {{ formatPrice(selectedProduct.price) }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>