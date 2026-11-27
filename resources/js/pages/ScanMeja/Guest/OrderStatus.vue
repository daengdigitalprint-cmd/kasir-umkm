<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, Bell, Bike, Check, CheckCircle2, ChefHat, Clock, Copy, MapPin, MessageCircle, Plus, Send, Utensils, XCircle } from 'lucide-vue-next';
import { accentStyle } from '@/lib/accentPalette';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

interface OrderItem {
    name: string;
    quantity: number;
    price: number;
    total: number;
    note?: string | null;
}

interface TransactionData {
    id: number;
    status_token: string;
    number: string;
    status: string;
    payment_status: string;
    payment_method: string;
    subtotal: number;
    delivery_fee?: number;
    total_amount: number;
    notes: string | null;
    reject_reason?: string | null;
    created_at: string;
    items: OrderItem[];
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
    /** Meja asal pesanan (Scan Meja). Null pada pesanan antar. */
    table: { id: number; name: string; slug: string; capacity: number | null } | null;
    /** Pesanan antar (kurir toko): status mengikuti pengantaran, bukan meja. */
    delivery?: { slug: string; fee: number; recipient_name?: string | null; address?: string | null } | null;
    store: { id: number; name: string; currency: string; phone?: string | null; logo_url?: string | null; cover_url?: string | null; accent_color?: string | null };
    transaction: TransactionData;
    paymentMethod?: PaymentMethod | null;
}>();

const current = ref<TransactionData>(props.transaction);
const lastUpdated = ref(new Date());
let timer: ReturnType<typeof setInterval> | null = null;

const formatPrice = (price: number) =>
    new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: props.store.currency === 'IDR' ? 'IDR' : props.store.currency,
        minimumFractionDigits: 0,
    }).format(Number(price));
const qty = (q: number) => Number(q).toLocaleString('id-ID');
const clock = (d: Date | string) => new Date(d).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });

const storeInitials = computed(() =>
    props.store.name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((w) => w[0]?.toUpperCase())
        .join(''),
);
const isDelivery = computed(() => !!props.delivery);
/** Halaman menu untuk kembali memesan: tautan pesan antar atau menu meja. */
const menuHref = computed(() => (props.delivery ? `/antar/${props.delivery.slug}` : `/m/${props.table?.slug}`));
const placeLabel = computed(() => (isDelivery.value ? 'Pesan antar' : (props.table?.name ?? '')));

const phoneDigits = computed(() => (props.store.phone ?? '').replace(/\D/g, ''));
const waLink = computed(() => {
    if (!phoneDigits.value) return null;
    const n = phoneDigits.value.startsWith('0') ? `62${phoneDigits.value.slice(1)}` : phoneDigits.value;
    const from = isDelivery.value ? 'saya memesan antar' : `saya dari ${props.table?.name}`;
    const text = encodeURIComponent(`Halo ${props.store.name}, ${from} dengan pesanan ${current.value.number}.`);
    return `https://wa.me/${n}?text=${text}`;
});

// ── Status & langkah ──
const isCancelled = computed(() => current.value.status === 'cancelled');
const isCompleted = computed(() => current.value.status === 'completed');
const isFinished = computed(() => isCancelled.value || isCompleted.value);
const isPaid = computed(() => current.value.payment_status === 'paid');

// 0 = dikirim, 1 = diterima kasir, 2 = disiapkan, 3 = selesai
// Pesanan antar: 0 = dikirim, 1 = disiapkan, 2 = diantar, 3 = selesai
const stage = computed(() => {
    if (isDelivery.value) {
        return { pending: 0, accepted: 1, on_the_way: 2, completed: 3 }[current.value.status] ?? 0;
    }
    switch (current.value.status) {
        case 'pending':
            return 0;
        case 'accepted':
        case 'processing':
            return 2;
        case 'serving':
        case 'ready':
            return 2;
        case 'completed':
            return 3;
        default:
            return 0;
    }
});
const steps = computed(() =>
    isDelivery.value
        ? [
              { label: 'Dikirim', icon: Send },
              { label: 'Disiapkan', icon: ChefHat },
              { label: 'Diantar', icon: Bike },
              { label: 'Selesai', icon: CheckCircle2 },
          ]
        : [
              { label: 'Dikirim', icon: Send },
              { label: 'Diterima', icon: Check },
              { label: 'Disiapkan', icon: ChefHat },
              { label: 'Selesai', icon: CheckCircle2 },
          ],
);

const hero = computed(() => {
    if (isCancelled.value)
        return {
            icon: XCircle,
            tone: 'bg-red-50 text-red-600',
            title: 'Pesanan tidak dapat diproses',
            body: isDelivery.value
                ? 'Mohon maaf, pesanan Anda tidak dapat diproses oleh toko. Silakan hubungi toko atau pesan menu lain.'
                : 'Mohon maaf, pesanan Anda ditolak oleh kasir. Silakan hubungi staf atau pesan menu lain.',
        };
    if (isCompleted.value)
        return {
            icon: CheckCircle2,
            tone: 'bg-emerald-50 text-emerald-600',
            title: isDelivery.value ? 'Pesanan sudah sampai' : 'Pesanan selesai',
            body: 'Terima kasih! Selamat menikmati hidangan Anda.',
        };
    if (isDelivery.value && current.value.status === 'on_the_way')
        return {
            icon: Bike,
            tone: 'bg-violet-50 text-violet-600',
            title: 'Pesanan sedang diantar',
            body: 'Kurir toko sedang menuju alamat Anda. Pastikan nomor HP Anda aktif.',
        };
    if (isDelivery.value && current.value.status === 'accepted')
        return {
            icon: ChefHat,
            tone: 'bg-sky-50 text-sky-600',
            title: 'Pesanan sedang disiapkan',
            body: 'Toko sudah menerima pesanan dan sedang menyiapkannya untuk diantar.',
        };
    if (isDelivery.value)
        return {
            icon: Clock,
            tone: 'bg-amber-50 text-amber-600',
            title: 'Pesanan terkirim',
            body: 'Menunggu konfirmasi toko. Biasanya hanya beberapa saat.',
        };
    if (current.value.status === 'ready')
        return {
            icon: Bell,
            tone: 'bg-violet-50 text-violet-600',
            title: 'Pesanan siap diantar',
            body: 'Hidangan Anda akan segera diantar ke meja.',
        };
    if (stage.value === 2)
        return {
            icon: ChefHat,
            tone: 'bg-sky-50 text-sky-600',
            title: 'Pesanan sedang disiapkan',
            body: 'Kasir sudah menerima pesanan dan dapur sedang menyiapkannya.',
        };
    return {
        icon: Clock,
        tone: 'bg-amber-50 text-amber-600',
        title: 'Pesanan terkirim',
        body: 'Menunggu konfirmasi kasir. Biasanya hanya beberapa saat.',
    };
});

const paymentLabel = computed(
    () =>
        props.paymentMethod?.label ??
        ({ cash: 'Tunai', qris: 'QRIS', ewallet: 'E-Wallet', transfer: 'Transfer bank', bank_transfer: 'Transfer bank', card: 'Kartu' }[
            current.value.payment_method
        ] ||
            current.value.payment_method),
);
const paymentHint = computed(() => {
    if (isPaid.value) return 'Pembayaran sudah diterima.';
    if (current.value.payment_method === 'cash') return isDelivery.value ? 'Bayar tunai ke kurir saat pesanan tiba.' : 'Bayar tunai langsung ke kasir.';
    if (isDelivery.value) return props.paymentMethod?.instructions || 'Selesaikan pembayaran lalu kirim buktinya ke toko.';
    return props.paymentMethod?.instructions || 'Selesaikan pembayaran lalu tunjukkan bukti ke kasir.';
});
const showPaymentDetail = computed(
    () => !isPaid.value && !isCancelled.value && !!props.paymentMethod && (!!props.paymentMethod.image_url || !!props.paymentMethod.account_number),
);

const copied = ref(false);
const copyAccount = () => {
    const n = props.paymentMethod?.account_number;
    if (!n) return;
    navigator.clipboard
        ?.writeText(n)
        .then(() => {
            copied.value = true;
            setTimeout(() => (copied.value = false), 2000);
        })
        .catch(() => {});
};

// ── Pembaruan otomatis ──
const fetchStatus = async () => {
    try {
        const statusUrl = isDelivery.value
            ? `/api/guest/delivery-order/${current.value.status_token}/status`
            : `/api/guest/order/${current.value.status_token}/status`;
        const response = await fetch(statusUrl, {
            headers: { Accept: 'application/json' },
        });
        if (!response.ok) return;
        const data = await response.json();
        current.value = { ...current.value, ...data };
        lastUpdated.value = new Date();
        if (isFinished.value && timer) {
            clearInterval(timer);
            timer = null;
        }
    } catch {
        // jaringan terganggu, biarkan polling berikutnya mencoba lagi
    }
};

onMounted(() => {
    if (!isFinished.value) timer = setInterval(fetchStatus, 4000);
});
onBeforeUnmount(() => {
    if (timer) clearInterval(timer);
});
</script>

<template>
    <Head :title="`Status Pesanan #${current.number}`" />

    <div class="min-h-screen bg-slate-50" :style="accentStyle(store.accent_color)">
        <!-- Kepala halaman -->
        <header class="sticky top-0 z-20 border-b border-slate-200 bg-white">
            <div class="mx-auto flex max-w-md items-center gap-3 px-4 py-3">
                <Link
                    :href="menuHref"
                    class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl text-slate-600 hover:bg-slate-100"
                    aria-label="Kembali ke menu"
                >
                    <ArrowLeft class="h-5 w-5" />
                </Link>
                <div
                    class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-violet-50 text-xs font-black text-violet-700"
                >
                    <img v-if="store.logo_url" :src="store.logo_url" :alt="store.name" class="h-full w-full object-cover" />
                    <span v-else>{{ storeInitials }}</span>
                </div>
                <div class="min-w-0">
                    <h1 class="truncate text-base leading-tight font-black text-slate-900">{{ store.name }}</h1>
                    <p class="text-xs text-slate-500">{{ placeLabel }} · Status pesanan</p>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-md space-y-4 px-4 py-5 pb-28">
            <!-- Status utama -->
            <section class="rounded-2xl border border-slate-200 bg-white p-5">
                <div class="flex items-start gap-4">
                    <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl" :class="hero.tone">
                        <component :is="hero.icon" class="h-7 w-7" />
                    </div>
                    <div class="min-w-0">
                        <h2 class="text-lg leading-tight font-black text-slate-900">{{ hero.title }}</h2>
                        <p class="mt-1 text-sm text-slate-500">{{ hero.body }}</p>
                    </div>
                </div>

                <p v-if="isCancelled && current.reject_reason" class="mt-4 rounded-xl bg-red-50 px-3 py-2 text-sm text-red-700">
                    Alasan: <b>{{ current.reject_reason }}</b>
                </p>

                <!-- Langkah -->
                <ol v-if="!isCancelled" class="mt-5 grid grid-cols-4">
                    <li v-for="(s, i) in steps" :key="s.label" class="relative flex flex-col items-center text-center">
                        <span
                            v-if="i > 0"
                            class="absolute top-4 right-1/2 h-0.5 w-full -translate-y-1/2"
                            :class="i <= stage ? 'bg-violet-500' : 'bg-slate-200'"
                        />
                        <span
                            class="relative z-10 flex h-8 w-8 items-center justify-center rounded-full border-2"
                            :class="
                                i < stage || (i === stage && isCompleted)
                                    ? 'border-violet-600 bg-violet-600 text-white'
                                    : i === stage
                                      ? 'border-violet-600 bg-white text-violet-600'
                                      : 'border-slate-200 bg-white text-slate-300'
                            "
                        >
                            <component :is="s.icon" class="h-4 w-4" :class="{ 'animate-pulse': i === stage && !isFinished }" />
                        </span>
                        <span class="mt-1.5 text-[11px] font-semibold" :class="i <= stage ? 'text-slate-800' : 'text-slate-400'">{{ s.label }}</span>
                    </li>
                </ol>

                <div class="mt-5 flex flex-wrap items-center justify-between gap-x-3 gap-y-1 border-t border-slate-100 pt-3 text-xs whitespace-nowrap text-slate-500">
                    <span>
                        No. pesanan <b class="font-mono text-slate-700">{{ current.number }}</b>
                    </span>
                    <span v-if="!isFinished" class="inline-flex items-center gap-1.5">
                        <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-emerald-500" /> Diperbarui {{ clock(lastUpdated) }}
                    </span>
                    <span v-else>{{ clock(current.created_at) }}</span>
                </div>
            </section>

            <!-- Alamat pengantaran (pesan antar) -->
            <section v-if="isDelivery && delivery?.address" class="rounded-2xl border border-slate-200 bg-white p-5">
                <p class="text-xs text-slate-500">Diantar ke</p>
                <p v-if="delivery.recipient_name" class="font-bold text-slate-900">{{ delivery.recipient_name }}</p>
                <p class="mt-1 flex items-start gap-1.5 text-sm text-slate-600">
                    <MapPin class="mt-0.5 h-4 w-4 shrink-0 text-slate-400" /> {{ delivery.address }}
                </p>
            </section>

            <!-- Pembayaran -->
            <section class="rounded-2xl border border-slate-200 bg-white p-5">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <p class="text-xs text-slate-500">Pembayaran</p>
                        <p class="font-bold text-slate-900">{{ paymentLabel }}</p>
                    </div>
                    <span
                        class="rounded-full px-2.5 py-1 text-xs font-bold"
                        :class="
                            isPaid ? 'bg-emerald-50 text-emerald-700' : isCancelled ? 'bg-slate-100 text-slate-500' : 'bg-amber-50 text-amber-700'
                        "
                    >
                        {{ isPaid ? 'Lunas' : isCancelled ? 'Dibatalkan' : 'Belum dibayar' }}
                    </span>
                </div>
                <p v-if="!isCancelled" class="mt-2 text-sm text-slate-500">{{ paymentHint }}</p>

                <div v-if="showPaymentDetail" class="mt-4 rounded-xl border border-slate-200 p-4">
                    <img v-if="paymentMethod?.image_url" :src="paymentMethod.image_url" alt="QRIS" class="mx-auto h-52 w-52 object-contain" />
                    <div v-if="paymentMethod?.account_number" :class="{ 'mt-4 border-t border-slate-100 pt-4': paymentMethod?.image_url }">
                        <p class="text-xs text-slate-500">Transfer ke {{ paymentMethod.bank_name }}</p>
                        <div class="mt-1 flex items-center justify-between gap-2">
                            <p class="font-mono text-lg font-bold text-slate-900">{{ paymentMethod.account_number }}</p>
                            <button
                                type="button"
                                class="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-2.5 py-1 text-xs font-semibold text-slate-600"
                                @click="copyAccount"
                            >
                                <Copy class="h-3.5 w-3.5" /> {{ copied ? 'Tersalin' : 'Salin' }}
                            </button>
                        </div>
                        <p v-if="paymentMethod.account_name" class="text-xs text-slate-500">a.n. {{ paymentMethod.account_name }}</p>
                    </div>
                    <p class="mt-3 text-center text-sm">
                        Total bayar <b class="text-slate-900">{{ formatPrice(current.total_amount) }}</b>
                    </p>
                </div>
            </section>

            <!-- Rincian -->
            <section class="rounded-2xl border border-slate-200 bg-white p-5">
                <h2 class="text-sm font-bold text-slate-900">Rincian pesanan</h2>
                <ul class="mt-3 divide-y divide-slate-100">
                    <li v-for="(item, index) in current.items" :key="index" class="flex gap-3 py-2.5">
                        <span class="w-7 shrink-0 text-sm font-bold text-slate-900">{{ qty(item.quantity) }}×</span>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm text-slate-800">{{ item.name }}</p>
                            <p v-if="item.note" class="text-xs text-amber-700">{{ item.note }}</p>
                        </div>
                        <span class="shrink-0 text-sm text-slate-700">{{ formatPrice(item.total) }}</span>
                    </li>
                </ul>
                <div v-if="isDelivery" class="mt-2 flex items-center justify-between border-t border-dashed border-slate-200 pt-3 text-sm text-slate-500">
                    <span>Ongkir</span>
                    <span class="text-slate-700">{{ Number(current.delivery_fee ?? 0) > 0 ? formatPrice(Number(current.delivery_fee)) : 'Gratis' }}</span>
                </div>
                <div class="mt-2 flex items-center justify-between border-t border-dashed border-slate-200 pt-3">
                    <span class="text-sm font-semibold text-slate-500">Total</span>
                    <span class="text-lg font-black text-slate-900">{{ formatPrice(current.total_amount) }}</span>
                </div>
            </section>

            <p class="flex items-center justify-center gap-1.5 text-center text-xs text-slate-400">
                <Utensils class="h-3.5 w-3.5" /> Simpan halaman ini untuk memantau pesanan Anda.
            </p>
        </main>

        <!-- Aksi bawah -->
        <div class="fixed inset-x-0 bottom-0 z-20 border-t border-slate-200 bg-white px-4 py-3">
            <div class="mx-auto flex max-w-md gap-2">
                <a
                    v-if="waLink"
                    :href="waLink"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="flex items-center justify-center gap-1.5 rounded-full border border-slate-200 px-4 py-3 text-sm font-semibold text-slate-700"
                >
                    <MessageCircle class="h-4 w-4" /> Hubungi
                </a>
                <Link
                    :href="menuHref"
                    class="flex flex-1 items-center justify-center gap-1.5 rounded-full bg-violet-600 px-5 py-3 text-sm font-bold text-white"
                >
                    <Plus class="h-4 w-4" /> {{ isCancelled ? 'Pesan menu lain' : 'Pesan lagi' }}
                </Link>
            </div>
        </div>
    </div>
</template>
