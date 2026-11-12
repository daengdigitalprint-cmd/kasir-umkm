<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ref, computed, onMounted } from 'vue';
import { ShoppingCart, Plus, Minus, X, ArrowLeft, Check, Utensils, MapPin, Clock, Banknote, QrCode, Building, CreditCard, Flame, ExternalLink } from 'lucide-vue-next';

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
    store: { id: number; name: string; currency: string };
    categories: Category[];
    bestSelling?: Product[];
    paymentMethods?: PaymentMethod[];
}>();

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

const activeCategory = ref<number | null>(null);
const categoryRefs = new Map<number, HTMLElement>();

const scrollToCategory = (categoryId: number) => {
    activeCategory.value = categoryId;
    categoryRefs.get(categoryId)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
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

    <div class="min-h-screen bg-slate-50 flex flex-col">
        <!-- Header -->
        <header class="sticky top-0 z-30 rounded-b-3xl border-b border-slate-100 bg-white/85 shadow-sm backdrop-blur-md">
            <div class="px-4 py-3 flex items-center gap-3 max-w-3xl mx-auto w-full">
                <button
                    v-if="cartOpen"
                    @click="cartOpen = false"
                    class="flex h-10 w-10 items-center justify-center rounded-xl hover:bg-slate-100"
                >
                    <ArrowLeft class="h-5 w-5 text-slate-600" />
                </button>
                <div class="flex-1 min-w-0">
                    <h1 class="font-black text-slate-900 text-base truncate">{{ store.name }}</h1>
                    <div class="flex items-center gap-2 text-xs text-slate-500">
                        <MapPin class="h-3 w-3" />
                        {{ table.name }}
                        <span v-if="table.capacity" class="inline-flex items-center gap-0.5">
                            <span class="text-slate-300">·</span>
                            {{ table.capacity }} orang
                        </span>
                    </div>
                </div>
                <button
                    @click="cartOpen = !cartOpen; ordered = false"
                    class="relative flex h-10 w-10 items-center justify-center rounded-xl bg-violet-600 text-white"
                >
                    <ShoppingCart class="h-5 w-5" />
                    <span
                        v-if="cartCount > 0"
                        class="absolute -top-1.5 -right-1.5 flex h-5 min-w-5 items-center justify-center rounded-full bg-red-500 text-[10px] font-black px-1"
                    >
                        {{ cartCount }}
                    </span>
                </button>
            </div>
        </header>

        <!-- Main Content -->
        <main class="flex-1 max-w-3xl mx-auto w-full px-4 pb-32 pt-4">
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
                <div v-if="props.categories.length" class="-mx-4 mb-5 overflow-x-auto border-b border-slate-100 bg-slate-50 px-4 py-2">
                    <div class="flex min-w-max gap-2">
                        <button
                            v-for="category in props.categories"
                            :key="category.id"
                            @click="scrollToCategory(category.id)"
                            :class="[
                                'rounded-full border px-4 py-2 text-xs font-bold transition',
                                activeCategory === category.id
                                    ? 'border-violet-600 bg-violet-600 text-white'
                                    : 'border-slate-200 bg-white text-slate-600 hover:border-violet-300 hover:text-violet-700',
                            ]"
                        >
                            {{ category.name }}
                        </button>
                    </div>
                </div>

                <section v-if="props.bestSelling?.length" class="mb-7">
                    <h2 class="mb-3 flex items-center gap-2 text-base font-black text-slate-900">
                        <Flame class="h-4 w-4 text-orange-500" />
                        Produk Terlaris
                    </h2>
                    <div class="flex gap-3 overflow-x-auto pb-1">
                        <button
                            v-for="product in props.bestSelling"
                            :key="product.id"
                            @click="openProduct(product)"
                            class="relative w-36 shrink-0 overflow-hidden rounded-2xl border border-orange-100 bg-white text-left shadow-sm transition hover:shadow-md"
                        >
                            <span class="absolute left-2 top-2 z-10 rounded-full bg-orange-500 px-2 py-1 text-[10px] font-black text-white">
                                Terlaris
                            </span>
                            <div class="aspect-square w-full bg-slate-50 overflow-hidden">
                                <img v-if="product.image" :src="product.image" :alt="product.name" class="h-full w-full object-cover" />
                                <div v-else class="flex h-full w-full items-center justify-center"><Utensils class="h-8 w-8 text-slate-200" /></div>
                            </div>
                            <div class="p-2.5">
                                <h3 class="line-clamp-2 text-sm font-bold leading-tight text-slate-900">{{ product.name }}</h3>
                                <p class="mt-1.5 text-sm font-black text-violet-700">{{ formatPrice(product.price) }}</p>
                            </div>
                        </button>
                    </div>
                </section>

                <div v-for="category in props.categories" :key="category.id" :ref="(element) => element && categoryRefs.set(category.id, element as HTMLElement)" class="mb-6 scroll-mt-28">
                    <h2 class="font-black text-slate-900 text-base mb-3 flex items-center gap-2">
                        <Utensils class="h-4 w-4 text-violet-500" />
                        {{ category.name }}
                    </h2>

                    <div class="grid grid-cols-2 gap-3 md:grid-cols-3">
                        <button
                            v-for="product in category.products"
                            :key="product.id"
                            @click="openProduct(product)"
                            class="text-left rounded-2xl border border-slate-100 bg-white overflow-hidden hover:shadow-md transition group"
                        >
                            <div class="aspect-square w-full bg-slate-50 overflow-hidden">
                                <img
                                    v-if="product.image"
                                    :src="product.image"
                                    :alt="product.name"
                                    class="h-full w-full object-cover group-hover:scale-105 transition"
                                />
                                <div v-else class="h-full w-full flex items-center justify-center">
                                    <Utensils class="h-8 w-8 text-slate-200" />
                                </div>
                            </div>
                            <div class="p-2.5">
                                <h3 class="font-bold text-slate-900 text-sm leading-tight line-clamp-2">{{ product.name }}</h3>
                                <p class="text-xs text-slate-400 mt-1">
                                    {{ product.description ? product.description.slice(0, 40) + '…' : 'Menu tersedia' }}
                                </p>
                                <p class="font-black text-violet-700 text-sm mt-1.5">{{ formatPrice(product.price) }}</p>
                            </div>
                        </button>
                    </div>
                </div>

                <div v-if="props.categories.length === 0" class="text-center py-16">
                    <Utensils class="mx-auto h-12 w-12 text-slate-200 mb-3" />
                    <p class="text-sm font-bold text-slate-500">Menu belum tersedia</p>
                    <p class="text-xs text-slate-400 mt-1">Silakan hubungi staf</p>
                </div>
            </div>
        </main>

        <footer class="rounded-t-3xl border-t border-slate-100 bg-white px-4 py-8 text-center">
            <div class="mx-auto max-w-3xl">
                <div class="mx-auto mb-3 flex h-10 w-10 items-center justify-center rounded-xl bg-violet-50">
                    <Utensils class="h-5 w-5 text-violet-600" />
                </div>
                <p class="text-sm font-black text-slate-800">{{ store.name }}</p>
                <p class="mt-1 text-xs text-slate-500">Pesan langsung dari {{ table.name }}</p>
                <a
                    href="https://play.google.com/store/apps/details?id=com.kasirumkm.idea&hl=id"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="mt-3 inline-flex items-center gap-1 text-[11px] font-semibold text-slate-400 transition hover:text-violet-600"
                >
                    Powered by Kasir UMKM by MaccaPOS
                    <ExternalLink class="h-3 w-3" />
                </a>
            </div>
        </footer>

        <!-- Cart Bar (bottom) -->
        <div
            v-if="cartCount > 0 && !cartOpen && !ordered"
            class="fixed bottom-0 inset-x-0 z-30 bg-white border-t border-slate-100 shadow-lg p-4"
        >
            <div class="max-w-3xl mx-auto">
                <button
                    @click="cartOpen = true"
                    class="w-full flex items-center justify-between rounded-2xl bg-violet-600 px-5 py-4 text-white font-bold"
                >
                    <span class="flex items-center gap-2 text-sm">
                        <ShoppingCart class="h-5 w-5" />
                        {{ cartCount }} item
                    </span>
                    <span class="text-sm">Lihat Pesanan · {{ formatPrice(cartSubtotal) }}</span>
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