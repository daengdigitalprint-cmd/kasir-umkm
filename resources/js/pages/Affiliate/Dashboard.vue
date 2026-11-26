<script setup lang="ts">
import AffiliateLayout from '@/layouts/AffiliateLayout.vue';
import { router, useForm } from '@inertiajs/vue3';
import {
    ArrowDownToLine,
    Banknote,
    Bell,
    Check,
    CircleAlert,
    Clock,
    Copy,
    Crown,
    ExternalLink,
    Hourglass,
    Landmark,
    MessageCircle,
    MousePointerClick,
    Pencil,
    Share2,
    Store,
    Trophy,
    UserPlus,
    Wallet,
} from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

interface Referral {
    id: number;
    name: string;
    stage: 'registered' | 'store_created' | 'paid';
    registered_at: string | null;
    commission_until: string | null;
}

interface Commission {
    id: number;
    name: string;
    base_amount: number;
    percent: number;
    amount: number;
    state: 'held' | 'ready' | 'processing' | 'paid' | 'cancelled';
    created_at: string | null;
    available_at: string | null;
    paid_at: string | null;
}

interface Payout {
    id: number;
    amount: number;
    status: 'requested' | 'paid' | 'rejected';
    type: 'transfer' | 'subscription';
    account: string;
    requested_at: string | null;
    processed_at: string | null;
    proof_url: string | null;
    note: string | null;
    reject_reason: string | null;
}

interface Notice {
    id: number;
    type: string;
    title: string;
    body: string | null;
    created_at: string | null;
}

interface RedeemPlan {
    id: number;
    name: string;
    price: number;
    duration_days: number;
    cycle: string;
}

interface LeaderRow {
    rank: number;
    name: string;
    paying: number;
    earned: number;
    is_me: boolean;
}

interface PromoTarget {
    key: string;
    label: string;
    name: string | null;
    url: string;
}

const props = defineProps<{
    affiliate: {
        code: string;
        link: string;
        is_active: boolean;
        payout_provider: string | null;
        payout_account_number: string | null;
        payout_account_holder: string | null;
        has_payout_account: boolean;
    };
    rules: {
        percent: number;
        months: number;
        once: boolean;
        cookie_days: number;
        hold_days: number;
        min_payout: number;
        referred_trial_days: number;
        default_trial_days: number;
    };
    summary: { clicks: number; signups: number; paying: number; held: number; ready: number; processing: number; paid: number };
    referrals: Referral[];
    commissions: Commission[];
    payouts: Payout[];
    openPayout: { amount: number; requested_at: string | null } | null;
    promoTargets: PromoTarget[];
    notifications: Notice[];
    leaderboard: { period: string; rows: LeaderRow[]; me: { rank: number | null; paying: number; earned: number } | null };
    redeem: { store_name: string; has_pending_payment: boolean; plans: RedeemPlan[] } | null;
}>();

const rupiah = (n: number) => 'Rp' + Math.round(n).toLocaleString('id-ID');
const formatDate = (d: string | null) => (d ? new Date(d).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' }) : '—');
const percentLabel = computed(() => props.rules.percent.toLocaleString('id-ID'));

/* ───────── Materi promosi ───────── */
// Yang diajak mendapat trial lebih panjang: jadi alasan untuk memakai link afiliator.
const hasTrialPerk = computed(() => props.rules.referred_trial_days > props.rules.default_trial_days);

const buildMessage = (target: PromoTarget) => {
    const usaha = target.name ? target.name.toLowerCase() : null;
    const opening = usaha
        ? `Punya usaha ${usaha}? Coba Kasir UMKM, aplikasi kasir untuk ${usaha}: catat penjualan, stok, dan laporan langsung dari HP.`
        : 'Lagi cari aplikasi kasir untuk usaha? Coba Kasir UMKM: catat penjualan, stok, dan laporan langsung dari HP.';
    const perk = hasTrialPerk.value
        ? `Daftar lewat link ini, semua fitur bisa dicoba gratis ${props.rules.referred_trial_days} hari:`
        : 'Bisa dicoba gratis. Daftar di sini:';

    return `${opening}\n\n${perk}\n${target.url}`;
};

const targetKey = ref(props.promoTargets[0]?.key ?? 'umum');
const target = computed(() => props.promoTargets.find((t) => t.key === targetKey.value) ?? props.promoTargets[0]);
const message = ref(buildMessage(target.value));
// Ganti jenis usaha: pesan dan link ikut berganti (suntingan manual sebelumnya ditimpa).
watch(targetKey, () => (message.value = buildMessage(target.value)));

const copied = ref<'link' | 'message' | null>(null);
let copiedTimer: ReturnType<typeof setTimeout> | undefined;

const copy = async (text: string, what: 'link' | 'message') => {
    try {
        await navigator.clipboard.writeText(text);
    } catch {
        // Peramban lama / tanpa izin clipboard: salin lewat textarea sementara.
        const el = document.createElement('textarea');
        el.value = text;
        el.style.position = 'fixed';
        el.style.opacity = '0';
        document.body.appendChild(el);
        el.select();
        document.execCommand('copy');
        document.body.removeChild(el);
    }
    copied.value = what;
    clearTimeout(copiedTimer);
    copiedTimer = setTimeout(() => (copied.value = null), 2000);
};

const whatsappUrl = computed(() => `https://wa.me/?text=${encodeURIComponent(message.value)}`);

const canNativeShare = typeof navigator !== 'undefined' && typeof navigator.share === 'function';
const nativeShare = () => {
    navigator.share({ text: message.value }).catch(() => {});
};

/* ───────── Tarik komisi ───────── */
const canRequestPayout = computed(
    () => props.affiliate.is_active && !props.openPayout && props.affiliate.has_payout_account && props.summary.ready >= props.rules.min_payout,
);
const requesting = ref(false);
const payoutError = ref('');

const requestPayout = () => {
    payoutError.value = '';
    requesting.value = true;
    router.post(
        '/afiliasi/pencairan',
        {},
        {
            preserveScroll: true,
            onError: (errors) => (payoutError.value = errors.payout ?? 'Pencairan belum bisa diajukan. Coba lagi.'),
            onFinish: () => (requesting.value = false),
        },
    );
};

const payoutStatus = (p: Payout) =>
    p.type === 'subscription'
        ? { label: 'Dipakai untuk langganan', cls: 'bg-violet-50 text-violet-700 ring-violet-200' }
        : {
              requested: { label: 'Sedang diproses', cls: 'bg-amber-50 text-amber-700 ring-amber-200' },
              paid: { label: 'Sudah ditransfer', cls: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
              rejected: { label: 'Ditolak', cls: 'bg-red-50 text-red-700 ring-red-200' },
          }[p.status];

/* ───────── Pakai komisi untuk langganan toko sendiri ───────── */
const redeemingId = ref<number | null>(null);
const redeemError = ref('');

const redeemPlan = (plan: RedeemPlan) => {
    if (!props.redeem) return;
    const ok = window.confirm(
        `Pakai komisi ${rupiah(plan.price)} untuk paket ${plan.name} toko ${props.redeem.store_name}? Langganan langsung diperpanjang ${plan.duration_days} hari dan komisi yang dipakai tidak bisa ditarik lagi.`,
    );
    if (!ok) return;

    redeemError.value = '';
    redeemingId.value = plan.id;
    router.post(
        '/afiliasi/tukar-langganan',
        { plan_id: plan.id },
        {
            preserveScroll: true,
            onError: (errors) => (redeemError.value = errors.redeem ?? errors.plan_id ?? 'Belum bisa diproses. Coba lagi.'),
            onFinish: () => (redeemingId.value = null),
        },
    );
};

/* ───────── Kabar ───────── */
const markingRead = ref(false);
const markNotificationsRead = () => {
    markingRead.value = true;
    router.post('/afiliasi/notifikasi/baca', {}, { preserveScroll: true, onFinish: () => (markingRead.value = false) });
};

/* ───────── Rekening pencairan ───────── */
const editingPayout = ref(!props.affiliate.has_payout_account);
const payoutForm = useForm({
    payout_provider: props.affiliate.payout_provider ?? '',
    payout_account_number: props.affiliate.payout_account_number ?? '',
    payout_account_holder: props.affiliate.payout_account_holder ?? '',
});
const savePayout = () => {
    payoutForm.put('/afiliasi/rekening', {
        preserveScroll: true,
        onSuccess: () => (editingPayout.value = false),
    });
};

/* ───────── Label status ───────── */
const stageInfo = (stage: Referral['stage']) =>
    ({
        registered: { label: 'Baru daftar', cls: 'bg-slate-100 text-slate-600 ring-slate-200' },
        store_created: { label: 'Sudah buat toko', cls: 'bg-sky-50 text-sky-700 ring-sky-200' },
        paid: { label: 'Berlangganan', cls: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
    })[stage];

const stateInfo = (c: Commission) =>
    ({
        held: { label: `Ditahan s.d. ${formatDate(c.available_at)}`, cls: 'bg-amber-50 text-amber-700 ring-amber-200' },
        ready: { label: 'Bisa ditarik', cls: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
        processing: { label: 'Sedang dicairkan', cls: 'bg-violet-50 text-violet-700 ring-violet-200' },
        paid: { label: `Dibayar ${formatDate(c.paid_at)}`, cls: 'bg-sky-50 text-sky-700 ring-sky-200' },
        cancelled: { label: 'Dibatalkan', cls: 'bg-slate-100 text-slate-500 ring-slate-200' },
    })[c.state];

const untilMinimum = computed(() => Math.max(0, props.rules.min_payout - props.summary.ready));
const inputClass =
    'h-12 w-full rounded-xl border border-slate-200 bg-white px-4 text-base text-slate-900 placeholder:text-slate-400 focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none';
</script>

<template>
    <AffiliateLayout title="Program Afiliasi">
        <div class="space-y-6">
            <div
                v-if="!affiliate.is_active"
                role="alert"
                class="flex items-start gap-3 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"
            >
                <CircleAlert class="mt-0.5 h-5 w-5 shrink-0" />
                <p><strong>Akun afiliasi Anda sedang dibekukan.</strong> Link Anda tidak aktif untuk sementara. Hubungi tim Kasir UMKM untuk informasi lebih lanjut.</p>
            </div>

            <!-- Kabar yang belum dibaca -->
            <section v-if="notifications.length > 0" class="rounded-2xl border border-amber-200 bg-amber-50/70" aria-labelledby="judul-kabar">
                <div class="flex items-center justify-between gap-3 px-5 pt-4">
                    <h2 id="judul-kabar" class="flex items-center gap-2 text-base font-bold text-slate-900">
                        <Bell class="h-4 w-4 text-amber-600" /> Kabar terbaru
                        <span class="rounded-full bg-amber-500 px-2 py-0.5 text-[11px] leading-none font-bold text-white">{{ notifications.length }}</span>
                    </h2>
                    <button
                        type="button"
                        :disabled="markingRead"
                        class="inline-flex h-10 shrink-0 items-center rounded-xl px-3 text-sm font-semibold text-slate-700 hover:bg-amber-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400 disabled:opacity-60"
                        @click="markNotificationsRead"
                    >
                        Tandai dibaca
                    </button>
                </div>
                <ul class="divide-y divide-amber-200/70 px-5 pb-2">
                    <li v-for="n in notifications" :key="n.id" class="py-3">
                        <p class="text-sm font-semibold text-slate-900">{{ n.title }}</p>
                        <p v-if="n.body" class="mt-0.5 text-sm text-slate-700">{{ n.body }}</p>
                        <p class="mt-0.5 text-xs text-slate-500">{{ formatDate(n.created_at) }}</p>
                    </li>
                </ul>
            </section>

            <!-- Link + bagikan -->
            <section class="relative overflow-hidden rounded-2xl bg-[#d4ceff] p-5 text-slate-900 sm:p-7" aria-labelledby="judul-link">
                <!-- Latar sama dengan kartu fitur di beranda: sorot cahaya di atas, kotak-kotak di kiri-kanan bawah, pendar dari bawah -->
                <div class="pointer-events-none absolute inset-0" aria-hidden="true">
                    <div class="shape shape--1 absolute top-0 left-0 h-full max-h-[337px]">
                        <img width="195" height="337" src="/sastik-assets/img/shape/das_shape03.png" alt="" loading="lazy" decoding="async" class="h-full w-auto" />
                    </div>
                    <div class="shape shape--2 absolute top-0 right-0 h-full max-h-[337px]">
                        <img width="195" height="337" src="/sastik-assets/img/shape/das_shape04.png" alt="" loading="lazy" decoding="async" class="h-full w-auto" />
                    </div>
                    <div class="shape shape--3 absolute bottom-0 left-0 w-28 sm:w-44">
                        <img width="255" height="264" src="/sastik-assets/img/shape/das_shape01.png" alt="" loading="lazy" decoding="async" class="h-auto w-full" />
                    </div>
                    <div class="shape shape--4 absolute right-0 bottom-0 w-28 sm:w-44">
                        <img width="255" height="264" src="/sastik-assets/img/shape/das_shape02.png" alt="" loading="lazy" decoding="async" class="h-auto w-full" />
                    </div>
                    <div class="shape shape--5 absolute bottom-0 left-1/2 w-full -translate-x-1/2">
                        <img width="1328" height="388" src="/sastik-assets/img/shape/circle-blur.webp" alt="" loading="lazy" decoding="async" class="h-auto w-full" />
                    </div>
                </div>
                <div class="relative z-10">
                    <h1 id="judul-link" class="text-xl font-black tracking-tight text-slate-900 sm:text-2xl">Link afiliasi Anda</h1>
                    <p class="mt-1 text-sm text-slate-700">
                        <template v-if="rules.once"
                            >Bagikan link ini. Anda mendapat komisi {{ percentLabel }}% dari harga paket pertama yang dibeli toko yang mendaftar lewat link ini,
                            sekali per toko.</template
                        >
                        <template v-else
                            >Bagikan link ini. Anda mendapat komisi {{ percentLabel }}% setiap kali toko yang mendaftar lewat link ini membayar
                            langganan.</template
                        >
                    </p>

                    <div class="mt-4 flex flex-col gap-2 sm:flex-row">
                        <div class="flex min-w-0 flex-1 items-center rounded-xl bg-white px-4 ring-1 ring-violet-200 ring-inset">
                            <label for="link-afiliasi" class="sr-only">Link afiliasi</label>
                            <input
                                id="link-afiliasi"
                                :value="affiliate.link"
                                readonly
                                class="h-12 w-full min-w-0 bg-transparent font-mono text-sm font-semibold text-slate-900 focus:outline-none sm:text-base"
                                @focus="($event.target as HTMLInputElement).select()"
                            />
                        </div>
                        <button
                            type="button"
                            class="inline-flex h-12 shrink-0 items-center justify-center gap-2 rounded-xl bg-primary px-5 text-sm font-bold text-white shadow-lg shadow-primary/25 transition-colors hover:bg-primary/90 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/50 focus-visible:ring-offset-2"
                            @click="copy(affiliate.link, 'link')"
                        >
                            <Check v-if="copied === 'link'" class="h-4 w-4" />
                            <Copy v-else class="h-4 w-4" />
                            <span aria-live="polite">{{ copied === 'link' ? 'Tersalin' : 'Salin link' }}</span>
                        </button>
                    </div>

                    <p class="mt-3 text-sm text-slate-700">
                        Kode referal Anda:
                        <strong class="rounded-md bg-white/80 px-2 py-0.5 font-mono tracking-wider text-primary">{{ affiliate.code }}</strong>
                        <span class="hidden sm:inline"> — bisa diketik di form daftar bila link tidak terpakai.</span>
                    </p>
                    <p v-if="hasTrialPerk" class="mt-2 text-sm font-semibold text-slate-800">
                        Yang mendaftar lewat link Anda bisa mencoba semua fitur gratis {{ rules.referred_trial_days }} hari (biasanya
                        {{ rules.default_trial_days }} hari).
                    </p>
                </div>
            </section>

            <!-- Pesan siap kirim -->
            <section class="rounded-2xl border border-slate-200 bg-white p-5 sm:p-6" aria-labelledby="judul-pesan">
                <h2 id="judul-pesan" class="text-base font-bold text-slate-900">Pesan siap kirim</h2>
                <p class="mt-0.5 text-sm text-slate-600">Pilih jenis usaha teman Anda. Pesan dan link menyesuaikan, dan boleh diubah dulu sebelum dikirim.</p>

                <label for="jenis-usaha" class="mt-4 mb-1.5 block text-sm font-semibold text-slate-700">Untuk usaha apa?</label>
                <select
                    id="jenis-usaha"
                    v-model="targetKey"
                    class="h-12 w-full rounded-xl border border-slate-200 bg-white px-4 text-base text-slate-900 focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none sm:w-72"
                >
                    <option v-for="t in promoTargets" :key="t.key" :value="t.key">{{ t.label }}</option>
                </select>

                <label for="pesan" class="sr-only">Isi pesan</label>
                <textarea
                    id="pesan"
                    v-model="message"
                    rows="8"
                    class="mt-3 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm leading-relaxed text-slate-800 focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none"
                ></textarea>
                <div class="mt-3 flex flex-col gap-2 sm:flex-row">
                    <a
                        :href="whatsappUrl"
                        target="_blank"
                        rel="noopener"
                        class="inline-flex h-12 items-center justify-center gap-2 rounded-xl bg-emerald-600 px-5 text-base font-bold text-white transition-colors hover:bg-emerald-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-300"
                    >
                        <MessageCircle class="h-5 w-5" /> Bagikan ke WhatsApp
                    </a>
                    <button
                        type="button"
                        class="inline-flex h-12 items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-5 text-sm font-bold text-slate-700 transition-colors hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40"
                        @click="copy(message, 'message')"
                    >
                        <Check v-if="copied === 'message'" class="h-4 w-4 text-emerald-600" />
                        <Copy v-else class="h-4 w-4" />
                        <span aria-live="polite">{{ copied === 'message' ? 'Tersalin' : 'Salin pesan' }}</span>
                    </button>
                    <button
                        v-if="canNativeShare"
                        type="button"
                        class="inline-flex h-12 items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-5 text-sm font-bold text-slate-700 transition-colors hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40"
                        @click="nativeShare"
                    >
                        <Share2 class="h-4 w-4" /> Aplikasi lain
                    </button>
                </div>
            </section>

            <!-- Hasil link -->
            <section aria-labelledby="judul-hasil">
                <h2 id="judul-hasil" class="mb-3 text-base font-bold text-slate-900">Hasil link Anda</h2>
                <div class="grid grid-cols-3 gap-3">
                    <div class="rounded-2xl border border-slate-200 bg-white p-4">
                        <MousePointerClick class="h-5 w-5 text-slate-400" />
                        <p class="mt-2 text-2xl font-black text-slate-900 tabular-nums">{{ summary.clicks.toLocaleString('id-ID') }}</p>
                        <p class="text-xs font-medium text-slate-500 sm:text-sm">Klik link</p>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-white p-4">
                        <UserPlus class="h-5 w-5 text-sky-500" />
                        <p class="mt-2 text-2xl font-black text-slate-900 tabular-nums">{{ summary.signups.toLocaleString('id-ID') }}</p>
                        <p class="text-xs font-medium text-slate-500 sm:text-sm">Mendaftar</p>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-white p-4">
                        <Store class="h-5 w-5 text-emerald-500" />
                        <p class="mt-2 text-2xl font-black text-slate-900 tabular-nums">{{ summary.paying.toLocaleString('id-ID') }}</p>
                        <p class="text-xs font-medium text-slate-500 sm:text-sm">Berlangganan</p>
                    </div>
                </div>
            </section>

            <!-- Komisi -->
            <section aria-labelledby="judul-komisi">
                <h2 id="judul-komisi" class="mb-3 text-base font-bold text-slate-900">Komisi Anda</h2>
                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="rounded-2xl bg-emerald-50 p-4 ring-1 ring-emerald-200 ring-inset">
                        <div class="flex items-center gap-2 text-sm font-semibold text-emerald-800"><Wallet class="h-4 w-4" /> Bisa ditarik</div>
                        <p class="mt-1 text-2xl font-black text-emerald-700 tabular-nums">{{ rupiah(summary.ready) }}</p>

                        <p v-if="openPayout" class="mt-2 flex items-start gap-1.5 text-xs font-medium text-emerald-900">
                            <Hourglass class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                            <span>Pencairan {{ rupiah(openPayout.amount) }} sedang diproses sejak {{ formatDate(openPayout.requested_at) }}.</span>
                        </p>
                        <template v-else-if="summary.ready >= rules.min_payout">
                            <button
                                v-if="canRequestPayout"
                                type="button"
                                :disabled="requesting"
                                class="mt-3 inline-flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-emerald-600 px-4 text-sm font-bold text-white transition-colors hover:bg-emerald-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-400 focus-visible:ring-offset-2 disabled:opacity-60"
                                @click="requestPayout"
                            >
                                <ArrowDownToLine class="h-4 w-4" /> {{ requesting ? 'Mengajukan…' : 'Tarik komisi' }}
                            </button>
                            <p v-else-if="!affiliate.has_payout_account" class="mt-1 text-xs font-medium text-emerald-900">
                                Isi rekening pencairan di bawah dulu, lalu tombol tarik komisi muncul di sini.
                            </p>
                        </template>
                        <p v-else class="mt-1 text-xs text-emerald-800">Kurang {{ rupiah(untilMinimum) }} lagi untuk bisa ditarik (minimal {{ rupiah(rules.min_payout) }}).</p>
                        <p v-if="payoutError" class="mt-2 text-xs font-semibold text-red-700" role="alert">{{ payoutError }}</p>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-white p-4">
                        <div class="flex items-center gap-2 text-sm font-semibold text-slate-600"><Clock class="h-4 w-4 text-amber-500" /> Masih ditahan</div>
                        <p class="mt-1 text-2xl font-black text-slate-900 tabular-nums">{{ rupiah(summary.held) }}</p>
                        <p class="mt-1 text-xs text-slate-500">Bisa ditarik {{ rules.hold_days }} hari setelah pembayaran toko.</p>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-white p-4">
                        <div class="flex items-center gap-2 text-sm font-semibold text-slate-600"><Banknote class="h-4 w-4 text-sky-500" /> Sudah dibayar</div>
                        <p class="mt-1 text-2xl font-black text-slate-900 tabular-nums">{{ rupiah(summary.paid) }}</p>
                        <p class="mt-1 text-xs text-slate-500">Total yang sudah ditransfer ke Anda.</p>
                    </div>
                </div>
            </section>

            <!-- Pakai komisi untuk langganan toko sendiri -->
            <section v-if="redeem && redeem.plans.length > 0" class="rounded-2xl border border-slate-200 bg-white p-5 sm:p-6" aria-labelledby="judul-tukar">
                <div class="flex items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary"><Crown class="h-5 w-5" /></span>
                    <div>
                        <h2 id="judul-tukar" class="text-base font-bold text-slate-900">Pakai komisi untuk langganan</h2>
                        <p class="text-sm text-slate-600">
                            Bayar langganan toko <strong class="text-slate-900">{{ redeem.store_name }}</strong> dengan komisi yang bisa ditarik. Langsung aktif,
                            tanpa transfer.
                        </p>
                    </div>
                </div>

                <p v-if="redeem.has_pending_payment" class="mt-4 flex items-start gap-2 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800 ring-1 ring-amber-200 ring-inset">
                    <CircleAlert class="mt-0.5 h-4 w-4 shrink-0" /> Toko ini masih punya pembayaran yang menunggu konfirmasi. Tunggu sampai selesai dulu.
                </p>

                <ul class="mt-4 space-y-2">
                    <li
                        v-for="plan in redeem.plans"
                        :key="plan.id"
                        class="flex flex-col gap-3 rounded-xl border border-slate-200 px-4 py-3 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div>
                            <p class="text-sm font-bold text-slate-900">{{ plan.name }}</p>
                            <p class="text-sm text-slate-600">{{ rupiah(plan.price) }} / {{ plan.cycle }} · tambah {{ plan.duration_days }} hari</p>
                        </div>
                        <button
                            v-if="summary.ready >= plan.price && !redeem.has_pending_payment && affiliate.is_active"
                            type="button"
                            :disabled="redeemingId !== null"
                            class="inline-flex h-12 shrink-0 items-center justify-center rounded-xl bg-primary px-5 text-sm font-bold text-white transition-colors hover:bg-primary/90 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/50 focus-visible:ring-offset-2 disabled:opacity-60"
                            @click="redeemPlan(plan)"
                        >
                            {{ redeemingId === plan.id ? 'Memproses…' : 'Pakai komisi' }}
                        </button>
                        <p v-else-if="summary.ready < plan.price" class="shrink-0 text-sm font-medium text-slate-500">
                            Kurang {{ rupiah(plan.price - summary.ready) }}
                        </p>
                    </li>
                </ul>
                <p v-if="redeemError" class="mt-3 text-sm font-semibold text-red-700" role="alert">{{ redeemError }}</p>
            </section>

            <!-- Rekening pencairan -->
            <section class="rounded-2xl border border-slate-200 bg-white p-5 sm:p-6" aria-labelledby="judul-rekening">
                <div class="flex items-start justify-between gap-3">
                    <div class="flex items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary"><Landmark class="h-5 w-5" /></span>
                        <div>
                            <h2 id="judul-rekening" class="text-base font-bold text-slate-900">Rekening pencairan</h2>
                            <p class="text-sm text-slate-600">Komisi ditransfer ke rekening bank atau e-wallet ini.</p>
                        </div>
                    </div>
                    <button
                        v-if="!editingPayout"
                        type="button"
                        class="inline-flex h-10 shrink-0 items-center gap-1.5 rounded-xl border border-slate-200 px-3 text-sm font-semibold text-slate-700 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40"
                        @click="editingPayout = true"
                    >
                        <Pencil class="h-4 w-4" /> Ubah
                    </button>
                </div>

                <div v-if="!editingPayout" class="mt-4 rounded-xl bg-slate-50 px-4 py-3 text-sm">
                    <p class="font-bold text-slate-900">{{ affiliate.payout_provider }} · <span class="font-mono tabular-nums">{{ affiliate.payout_account_number }}</span></p>
                    <p class="text-slate-600">a.n. {{ affiliate.payout_account_holder }}</p>
                </div>

                <form v-else class="mt-4" novalidate @submit.prevent="savePayout">
                    <p
                        v-if="!affiliate.has_payout_account"
                        class="mb-4 flex items-start gap-2 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800 ring-1 ring-amber-200 ring-inset"
                    >
                        <CircleAlert class="mt-0.5 h-4 w-4 shrink-0" /> Isi dulu supaya komisi Anda bisa ditransfer.
                    </p>
                    <div class="grid gap-4 sm:grid-cols-3">
                        <div>
                            <label for="pay-provider" class="mb-1.5 block text-sm font-semibold text-slate-700">Bank / e-wallet</label>
                            <input
                                id="pay-provider"
                                v-model="payoutForm.payout_provider"
                                type="text"
                                maxlength="60"
                                placeholder="mis. BCA, BRI, DANA"
                                :class="inputClass"
                                :aria-invalid="!!payoutForm.errors.payout_provider"
                            />
                            <p v-if="payoutForm.errors.payout_provider" class="mt-1 text-sm font-medium text-red-600" role="alert">{{ payoutForm.errors.payout_provider }}</p>
                        </div>
                        <div>
                            <label for="pay-number" class="mb-1.5 block text-sm font-semibold text-slate-700">Nomor rekening</label>
                            <input
                                id="pay-number"
                                v-model="payoutForm.payout_account_number"
                                type="text"
                                inputmode="numeric"
                                maxlength="40"
                                placeholder="mis. 1234567890"
                                :class="inputClass"
                                :aria-invalid="!!payoutForm.errors.payout_account_number"
                            />
                            <p v-if="payoutForm.errors.payout_account_number" class="mt-1 text-sm font-medium text-red-600" role="alert">
                                {{ payoutForm.errors.payout_account_number }}
                            </p>
                        </div>
                        <div>
                            <label for="pay-holder" class="mb-1.5 block text-sm font-semibold text-slate-700">Nama pemilik</label>
                            <input
                                id="pay-holder"
                                v-model="payoutForm.payout_account_holder"
                                type="text"
                                maxlength="100"
                                placeholder="Sesuai buku tabungan"
                                :class="inputClass"
                                :aria-invalid="!!payoutForm.errors.payout_account_holder"
                            />
                            <p v-if="payoutForm.errors.payout_account_holder" class="mt-1 text-sm font-medium text-red-600" role="alert">
                                {{ payoutForm.errors.payout_account_holder }}
                            </p>
                        </div>
                    </div>
                    <div class="mt-4 flex items-center gap-2">
                        <button
                            type="submit"
                            :disabled="payoutForm.processing"
                            class="inline-flex h-12 items-center justify-center rounded-xl bg-primary px-6 text-sm font-bold text-white transition-colors hover:bg-primary/90 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40 disabled:opacity-60"
                        >
                            {{ payoutForm.processing ? 'Menyimpan…' : 'Simpan rekening' }}
                        </button>
                        <button
                            v-if="affiliate.has_payout_account"
                            type="button"
                            class="inline-flex h-12 items-center rounded-xl px-4 text-sm font-semibold text-slate-600 hover:bg-slate-100"
                            @click="editingPayout = false"
                        >
                            Batal
                        </button>
                    </div>
                </form>
            </section>

            <!-- Riwayat pencairan -->
            <section v-if="payouts.length > 0" class="rounded-2xl border border-slate-200 bg-white" aria-labelledby="judul-pencairan">
                <div class="border-b border-slate-100 px-5 py-4">
                    <h2 id="judul-pencairan" class="text-base font-bold text-slate-900">Riwayat pencairan</h2>
                </div>
                <ul class="divide-y divide-slate-100">
                    <li v-for="p in payouts" :key="p.id" class="flex items-start justify-between gap-3 px-5 py-3.5">
                        <div class="min-w-0">
                            <p class="text-sm font-black text-slate-900 tabular-nums">{{ rupiah(p.amount) }}</p>
                            <p class="text-xs text-slate-500">
                                {{ formatDate(p.requested_at) }}<template v-if="p.type === 'transfer' && p.account"> · ke {{ p.account }}</template>
                            </p>
                            <p v-if="p.status === 'rejected' && p.reject_reason" class="mt-1 text-xs text-red-700">Alasan: {{ p.reject_reason }}</p>
                            <p v-if="p.status === 'paid' && p.note" class="mt-1 text-xs text-slate-500">{{ p.note }}</p>
                        </div>
                        <div class="shrink-0 text-right">
                            <span :class="['inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset', payoutStatus(p).cls]">
                                {{ payoutStatus(p).label }}
                            </span>
                            <p v-if="p.status === 'paid' && p.type === 'transfer'" class="mt-1 text-xs text-slate-500">{{ formatDate(p.processed_at) }}</p>
                            <a
                                v-if="p.proof_url"
                                :href="p.proof_url"
                                target="_blank"
                                rel="noopener"
                                class="mt-1 inline-flex items-center gap-1 text-xs font-semibold text-primary underline underline-offset-2"
                            >
                                <ExternalLink class="h-3.5 w-3.5" /> Lihat bukti transfer
                            </a>
                        </div>
                    </li>
                </ul>
            </section>

            <!-- Orang yang diajak -->
            <section class="rounded-2xl border border-slate-200 bg-white" aria-labelledby="judul-rujukan">
                <div class="border-b border-slate-100 px-5 py-4">
                    <h2 id="judul-rujukan" class="text-base font-bold text-slate-900">Yang mendaftar lewat link Anda</h2>
                </div>
                <div v-if="referrals.length === 0" class="px-5 py-10 text-center">
                    <UserPlus class="mx-auto h-8 w-8 text-slate-300" />
                    <p class="mt-3 text-sm text-slate-500">Belum ada yang mendaftar. Bagikan link Anda untuk memulai.</p>
                </div>
                <ul v-else class="divide-y divide-slate-100">
                    <li v-for="r in referrals" :key="r.id" class="flex items-center justify-between gap-3 px-5 py-3.5">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold text-slate-900">{{ r.name }}</p>
                            <p class="text-xs text-slate-500">
                                Daftar {{ formatDate(r.registered_at) }}
                                <template v-if="r.commission_until"> · komisi s.d. {{ formatDate(r.commission_until) }}</template>
                            </p>
                        </div>
                        <span :class="['inline-flex shrink-0 items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset', stageInfo(r.stage).cls]">
                            {{ stageInfo(r.stage).label }}
                        </span>
                    </li>
                </ul>
            </section>

            <!-- Riwayat komisi -->
            <section class="rounded-2xl border border-slate-200 bg-white" aria-labelledby="judul-riwayat">
                <div class="border-b border-slate-100 px-5 py-4">
                    <h2 id="judul-riwayat" class="text-base font-bold text-slate-900">Riwayat komisi</h2>
                </div>
                <div v-if="commissions.length === 0" class="px-5 py-10 text-center">
                    <Wallet class="mx-auto h-8 w-8 text-slate-300" />
                    <p class="mt-3 text-sm text-slate-500">Belum ada komisi. Komisi muncul saat toko yang Anda ajak membayar langganan.</p>
                </div>
                <ul v-else class="divide-y divide-slate-100">
                    <li v-for="c in commissions" :key="c.id" class="flex items-center justify-between gap-3 px-5 py-3.5">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold text-slate-900">{{ c.name }}</p>
                            <p class="text-xs text-slate-500">
                                {{ formatDate(c.created_at) }} · {{ c.percent.toLocaleString('id-ID') }}% dari {{ rupiah(c.base_amount) }}
                            </p>
                        </div>
                        <div class="shrink-0 text-right">
                            <p :class="['text-sm font-black tabular-nums', c.state === 'cancelled' ? 'text-slate-400 line-through' : 'text-slate-900']">
                                {{ rupiah(c.amount) }}
                            </p>
                            <span :class="['mt-1 inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 ring-inset', stateInfo(c).cls]">
                                {{ stateInfo(c).label }}
                            </span>
                        </div>
                    </li>
                </ul>
            </section>

            <!-- Papan peringkat -->
            <section v-if="leaderboard.rows.length > 0" class="rounded-2xl border border-slate-200 bg-white" aria-labelledby="judul-peringkat">
                <div class="border-b border-slate-100 px-5 py-4">
                    <h2 id="judul-peringkat" class="flex items-center gap-2 text-base font-bold text-slate-900">
                        <Trophy class="h-4 w-4 text-amber-500" /> Papan peringkat {{ leaderboard.period }}
                    </h2>
                    <p class="mt-0.5 text-sm text-slate-600">Afiliator dengan komisi terbanyak bulan ini. Nama afiliator lain disamarkan.</p>
                </div>
                <ol class="divide-y divide-slate-100">
                    <li
                        v-for="row in leaderboard.rows"
                        :key="row.rank"
                        :class="['flex items-center gap-3 px-5 py-3.5', row.is_me ? 'bg-primary/5' : '']"
                    >
                        <span
                            :class="[
                                'flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm font-black tabular-nums',
                                row.rank === 1 ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-600',
                            ]"
                            >{{ row.rank }}</span
                        >
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-semibold text-slate-900">
                                {{ row.name }}
                                <span v-if="row.is_me" class="ml-1 rounded-full bg-primary px-2 py-0.5 text-[11px] font-bold text-white">Anda</span>
                            </p>
                            <p class="text-xs text-slate-500">{{ row.paying }} toko baru berlangganan</p>
                        </div>
                        <p class="shrink-0 text-sm font-black text-slate-900 tabular-nums">{{ rupiah(row.earned) }}</p>
                    </li>
                </ol>
                <p v-if="leaderboard.me && leaderboard.me.rank === null" class="border-t border-slate-100 px-5 py-3 text-sm text-slate-600">
                    Anda belum masuk peringkat bulan ini. Komisi pertama bulan ini langsung menempatkan Anda di papan.
                </p>
                <p
                    v-else-if="leaderboard.me && leaderboard.me.rank !== null && leaderboard.me.rank > leaderboard.rows.length"
                    class="border-t border-slate-100 px-5 py-3 text-sm text-slate-600"
                >
                    Posisi Anda: <strong class="text-slate-900">#{{ leaderboard.me.rank }}</strong> dengan komisi {{ rupiah(leaderboard.me.earned) }} bulan ini.
                </p>
            </section>
        </div>
    </AffiliateLayout>
</template>
