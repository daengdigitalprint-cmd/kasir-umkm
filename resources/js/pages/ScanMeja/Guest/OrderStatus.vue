<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ref, onMounted, onBeforeUnmount, computed } from 'vue';
import { ArrowLeft, CheckCircle2, Clock, Loader2, XCircle, Utensils } from 'lucide-vue-next';

interface OrderItem {
    name: string;
    quantity: number;
    price: number;
    total: number;
}

interface TransactionData {
    id: number;
    status_token: string;
    number: string;
    status: string;
    payment_status: string;
    payment_method: string;
    subtotal: number;
    total_amount: number;
    notes: string | null;
    created_at: string;
    items: OrderItem[];
}

const props = defineProps<{
    table: { id: number; name: string; slug: string; capacity: number | null };
    store: { id: number; name: string; currency: string };
    transaction: TransactionData;
}>();

const current = ref<TransactionData>(props.transaction);
const pollingInterval = 3000;
let timer: ReturnType<typeof setInterval> | null = null;

const formatPrice = (price: number) => {
    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: props.store.currency === 'IDR' ? 'IDR' : props.store.currency,
        minimumFractionDigits: 0,
    }).format(price);
};

const statusLabel = computed(() => {
    const map: Record<string, string> = {
        pending: 'Menunggu Konfirmasi',
        accepted: 'Diterima Kasir',
        processing: 'Sedang Disiapkan',
        ready: 'Siap Diantar',
        completed: 'Selesai',
        cancelled: 'Dibatalkan',
    };
    return map[current.value.status] ?? current.value.status;
});

const statusColor = computed(() => {
    switch (current.value.status) {
        case 'completed':
            return 'text-emerald-600 bg-emerald-50';
        case 'cancelled':
            return 'text-red-600 bg-red-50';
        case 'pending':
            return 'text-amber-600 bg-amber-50';
        case 'accepted':
            return 'text-sky-600 bg-sky-50';
        case 'processing':
            return 'text-sky-600 bg-sky-50';
        case 'ready':
            return 'text-violet-600 bg-violet-50';
        default:
            return 'text-slate-600 bg-slate-100';
    }
});

const paymentStatusLabel = computed(() => ({
    unpaid: 'Belum Dibayar',
    pending_payment: 'Menunggu Pembayaran',
    paid: 'Sudah Dibayar',
    failed: 'Pembayaran Gagal',
    refunded: 'Dikembalikan',
}[current.value.payment_status] ?? current.value.payment_status));

const paymentLabel = computed(() => {
    const map: Record<string, string> = {
        cash: 'Tunai',
        qris: 'QRIS',
        ewallet: 'E-Wallet',
        transfer: 'Transfer Bank',
        unpaid: 'Belum Dibayar',
    };
    return map[current.value.payment_method] ?? current.value.payment_method;
});

const isFinished = computed(() => ['completed', 'cancelled'].includes(current.value.status));

const fetchStatus = async () => {
    try {
        const response = await fetch(`/api/guest/order/${current.value.status_token}/status`, {
            headers: { 'Accept': 'application/json' },
        });
        if (!response.ok) return;
        const data = await response.json();
        current.value = { ...current.value, ...data };
    } catch {
        // jaringan terganggu, biarkan polling berikutnya mencoba lagi
    }
};

onMounted(() => {
    if (!isFinished.value) {
        timer = setInterval(fetchStatus, pollingInterval);
    }
});

onBeforeUnmount(() => {
    if (timer) clearInterval(timer);
});
</script>

<template>
    <Head :title="`Status Pesanan #${current.number}`" />

    <div class="min-h-screen bg-slate-50">
        <!-- Header -->
        <header class="sticky top-0 z-20 bg-white border-b border-slate-100 shadow-sm">
            <div class="mx-auto flex max-w-md items-center gap-3 px-4 py-4">
                <Link
                    :href="`/m/${table.slug}`"
                    class="flex h-11 w-11 items-center justify-center rounded-xl text-slate-600 hover:bg-slate-100"
                >
                    <ArrowLeft class="h-5 w-5" />
                </Link>
                <div class="min-w-0">
                    <p class="text-xs text-slate-500">{{ store.name }} · {{ table.name }}</p>
                    <h1 class="truncate text-lg font-bold text-slate-800">Status Pesanan</h1>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-md px-4 py-6">
            <!-- Card Status -->
            <div class="rounded-3xl bg-white p-6 shadow-sm border border-slate-100">
                <div class="flex items-center gap-4">
                    <div
                        class="flex h-16 w-16 shrink-0 items-center justify-center rounded-2xl"
                        :class="statusColor"
                    >
                        <CheckCircle2 v-if="current.status === 'completed'" class="h-8 w-8" />
                        <XCircle v-else-if="current.status === 'cancelled'" class="h-8 w-8" />
                        <Clock v-else-if="current.status === 'pending'" class="h-8 w-8" />
                        <Utensils v-else class="h-8 w-8" />
                    </div>
                    <div class="min-w-0">
                        <p class="text-xs font-medium text-slate-500">Nomor Pesanan</p>
                        <p class="text-sm font-bold text-slate-800">{{ current.number }}</p>
                        <span
                            class="mt-1 inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold"
                            :class="statusColor"
                        >
                            <Loader2 v-if="!isFinished" class="h-3 w-3 animate-spin" />
                            {{ statusLabel }}
                        </span>
                    </div>
                </div>

                <p v-if="!isFinished" class="mt-4 text-xs text-slate-500">
                    Halaman ini diperbarui otomatis. Silakan tunggu pesanan Anda disiapkan.
                </p>
            </div>

            <!-- Detail Pesanan -->
            <div class="mt-4 rounded-3xl bg-white p-6 shadow-sm border border-slate-100">
                <h2 class="text-sm font-bold text-slate-800">Detail Pesanan</h2>

                <ul class="mt-4 divide-y divide-slate-100">
                    <li
                        v-for="(item, index) in current.items"
                        :key="index"
                        class="flex items-center justify-between gap-3 py-3"
                    >
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-slate-700">{{ item.name }}</p>
                            <p class="text-xs text-slate-400">{{ item.quantity }}x {{ formatPrice(item.price) }}</p>
                        </div>
                        <span class="text-sm font-semibold text-slate-800">{{ formatPrice(item.total) }}</span>
                    </li>
                </ul>

                <div class="mt-4 space-y-2 border-t border-slate-100 pt-4">
                    <div class="flex items-center justify-between text-sm text-slate-500">
                        <span>Metode Pembayaran</span>
                        <span class="font-semibold text-slate-700">{{ paymentLabel }}</span>
                    </div>
                    <div class="flex items-center justify-between text-sm text-slate-500">
                        <span>Status Pembayaran</span>
                        <span class="font-semibold" :class="current.payment_status === 'paid' ? 'text-emerald-600' : 'text-amber-600'">{{ paymentStatusLabel }}</span>
                    </div>
                    <div class="flex items-center justify-between text-sm text-slate-500">
                        <span>Subtotal</span>
                        <span>{{ formatPrice(current.subtotal) }}</span>
                    </div>
                    <div class="flex items-center justify-between border-t border-slate-100 pt-2 text-base font-bold text-slate-800">
                        <span>Total</span>
                        <span>{{ formatPrice(current.total_amount) }}</span>
                    </div>
                </div>
            </div>

            <!-- Tombol kembali -->
            <Link
                :href="`/m/${table.slug}`"
                class="mt-6 flex w-full items-center justify-center gap-2 rounded-2xl bg-violet-600 px-5 py-4 text-white font-bold text-sm"
            >
                <ArrowLeft class="h-4 w-4" />
                Kembali ke Menu
            </Link>
        </main>
    </div>
</template>