<script setup lang="ts">
import SuperAdminLayout from '@/layouts/SuperAdminLayout.vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import {
    Banknote,
    CheckCircle,
    ChevronDown,
    Clock,
    ExternalLink,
    Handshake,
    Hourglass,
    MessageCircle,
    Search,
    Settings2,
    Store,
    Trophy,
    UserPlus,
    Users,
    Wallet,
} from 'lucide-vue-next';
import { ref } from 'vue';

interface AffiliateRow {
    id: number;
    name: string;
    email: string | null;
    whatsapp: string | null;
    code: string;
    link: string;
    is_active: boolean;
    percent: number;
    custom_percent: number | null;
    clicks: number;
    signups: number;
    paying: number;
    held: number;
    ready: number;
    paid: number;
    payout_provider: string | null;
    payout_account_number: string | null;
    payout_account_holder: string | null;
    has_payout_account: boolean;
    joined_at: string | null;
}

interface CommissionRow {
    id: number;
    affiliate_name: string;
    affiliate_code: string | null;
    referred: string;
    invoice_number: string | null;
    payment_type: string | null;
    base_amount: number;
    percent: number;
    amount: number;
    state: 'held' | 'ready' | 'processing' | 'paid' | 'cancelled';
    created_at: string | null;
    available_at: string | null;
    paid_at: string | null;
    paid_note: string | null;
    cancel_reason: string | null;
}

interface PayoutRow {
    id: number;
    affiliate_name: string;
    affiliate_code: string | null;
    whatsapp: string | null;
    amount: number;
    status: 'requested' | 'paid' | 'rejected';
    type: 'transfer' | 'subscription';
    payout_provider: string | null;
    payout_account_number: string | null;
    payout_account_holder: string | null;
    requested_at: string | null;
    processed_at: string | null;
    proof_url: string | null;
    note: string | null;
    reject_reason: string | null;
}

type SettingKey = 'commission_percent' | 'commission_months' | 'cookie_days' | 'hold_days' | 'min_payout' | 'referred_trial_days';

interface Paginated<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    total: number;
}

const props = defineProps<{
    affiliates: Paginated<AffiliateRow>;
    commissions: Paginated<CommissionRow>;
    payouts: Paginated<PayoutRow>;
    stats: {
        affiliates: number;
        signups: number;
        paying: number;
        held: number;
        ready: number;
        paid: number;
        pending_payouts: number;
        pending_payouts_amount: number;
    };
    rules: { percent: number; months: number; once: boolean; cookie_days: number; hold_days: number; min_payout: number };
    settings: Record<SettingKey, { value: number; default: number; custom: boolean }>;
    leaderboard: { period: string; rows: { rank: number; name: string; paying: number; earned: number }[] };
    filters: { q: string | null; state: string | null };
}>();

const rupiah = (n: number) => 'Rp' + Math.round(n).toLocaleString('id-ID');
const formatDate = (d: string | null) => (d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—');

/* ───────── Aturan program ───────── */
const settingFields: { key: SettingKey; label: string; unit: string; help: string }[] = [
    { key: 'commission_percent', label: 'Komisi', unit: '%', help: 'Dari setiap pembayaran yang dikonfirmasi.' },
    { key: 'commission_months', label: 'Lama komisi', unit: 'bulan', help: '0 = sekali saja per toko (paket pertama). Lebih dari 0 = berulang selama sekian bulan.' },
    { key: 'cookie_days', label: 'Masa berlaku link', unit: 'hari', help: 'Link diingat sejak diklik.' },
    { key: 'hold_days', label: 'Masa tahan', unit: 'hari', help: 'Sebelum komisi bisa ditarik.' },
    { key: 'min_payout', label: 'Minimum pencairan', unit: 'Rp', help: 'Saldo minimal untuk menarik komisi.' },
    { key: 'referred_trial_days', label: 'Trial yang diajak', unit: 'hari', help: 'Isi 0 untuk mematikan keuntungan ini.' },
];

// Kolom kosong = pakai nilai bawaan; yang terisi hanya aturan yang memang diubah.
const settingsOpen = ref(false);
const settingsSaved = ref(false);
const initialSetting = (key: SettingKey) => (props.settings[key].custom ? String(props.settings[key].value) : '');
const settingsForm = useForm({
    commission_percent: initialSetting('commission_percent'),
    commission_months: initialSetting('commission_months'),
    cookie_days: initialSetting('cookie_days'),
    hold_days: initialSetting('hold_days'),
    min_payout: initialSetting('min_payout'),
    referred_trial_days: initialSetting('referred_trial_days'),
});

const saveSettings = () => {
    settingsSaved.value = false;
    settingsForm.put('/superadmin/affiliates/settings', {
        preserveScroll: true,
        onSuccess: () => (settingsSaved.value = true),
    });
};

/* ───────── Tarif khusus per afiliator ───────── */
const rateId = ref<number | null>(null);
const rateValue = ref('');
const rateError = ref('');

const openRate = (row: AffiliateRow) => {
    rateId.value = rateId.value === row.id ? null : row.id;
    rateValue.value = row.custom_percent !== null ? String(row.custom_percent) : '';
    rateError.value = '';
    payingId.value = null;
};

const submitRate = (row: AffiliateRow) => {
    router.post(
        `/superadmin/affiliates/${row.id}/rate`,
        { percent: rateValue.value === '' ? null : rateValue.value },
        {
            preserveScroll: true,
            onSuccess: () => (rateId.value = null),
            onError: (errors) => (rateError.value = errors.percent ?? 'Tarif tidak valid.'),
        },
    );
};

/* ───────── Saring ───────── */
const search = ref(props.filters.q ?? '');
const stateFilter = ref(props.filters.state ?? '');
const applyFilter = () => {
    router.get(
        '/superadmin/affiliates',
        { q: search.value || undefined, state: stateFilter.value || undefined },
        { preserveState: true, preserveScroll: true },
    );
};

/* ───────── Catat transfer komisi (satu baris terbuka pada satu waktu) ───────── */
const payingId = ref<number | null>(null);
const payNote = ref('');
const payProof = ref<File | null>(null);
const payError = ref('');
const processing = ref(false);

const pickProof = (event: Event) => {
    payProof.value = (event.target as HTMLInputElement).files?.[0] ?? null;
};

const resetTransferForm = () => {
    payNote.value = '';
    payProof.value = null;
    payError.value = '';
};

const openPay = (row: AffiliateRow) => {
    payingId.value = payingId.value === row.id ? null : row.id;
    payoutPayingId.value = null;
    resetTransferForm();
};

/** Kirim catatan + bukti transfer (bila ada) ke alamat yang diberikan. */
const sendTransfer = (url: string, onDone: () => void) => {
    processing.value = true;
    payError.value = '';
    router.post(
        url,
        { note: payNote.value || null, proof: payProof.value },
        {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: onDone,
            onError: (errors) => (payError.value = errors.proof ?? errors.note ?? 'Gagal menyimpan. Coba lagi.'),
            onFinish: () => (processing.value = false),
        },
    );
};

const submitPay = (row: AffiliateRow) => sendTransfer(`/superadmin/affiliates/${row.id}/pay`, () => (payingId.value = null));

/* ───────── Permintaan pencairan dari afiliator ───────── */
const payoutPayingId = ref<number | null>(null);
const payoutRejectingId = ref<number | null>(null);
const rejectReason = ref('');
const rejectError = ref('');

const openPayoutPay = (row: PayoutRow) => {
    payoutPayingId.value = payoutPayingId.value === row.id ? null : row.id;
    payoutRejectingId.value = null;
    payingId.value = null;
    resetTransferForm();
};

const submitPayoutPaid = (row: PayoutRow) => sendTransfer(`/superadmin/affiliate-payouts/${row.id}/paid`, () => (payoutPayingId.value = null));

const openPayoutReject = (row: PayoutRow) => {
    payoutRejectingId.value = payoutRejectingId.value === row.id ? null : row.id;
    payoutPayingId.value = null;
    rejectReason.value = '';
    rejectError.value = '';
};

const submitPayoutReject = (row: PayoutRow) => {
    if (!rejectReason.value.trim()) {
        rejectError.value = 'Tulis alasan penolakan.';
        return;
    }
    router.post(
        `/superadmin/affiliate-payouts/${row.id}/reject`,
        { reason: rejectReason.value },
        { preserveScroll: true, onSuccess: () => (payoutRejectingId.value = null) },
    );
};

const payoutBadge = (row: PayoutRow) =>
    row.type === 'subscription'
        ? { label: 'Ditukar langganan', cls: 'bg-violet-50 text-violet-700 ring-violet-200' }
        : {
              requested: { label: 'Menunggu transfer', cls: 'bg-amber-50 text-amber-700 ring-amber-200' },
              paid: { label: 'Sudah ditransfer', cls: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
              rejected: { label: 'Ditolak', cls: 'bg-slate-100 text-slate-500 ring-slate-200' },
          }[row.status];

const payoutWhatsapp = (row: PayoutRow) => {
    const firstName = row.affiliate_name.trim().split(/\s+/)[0];
    const text = `Halo ${firstName}, saya dari tim Kasir UMKM. Terkait permintaan pencairan komisi afiliasi Anda.`;
    return `https://wa.me/${row.whatsapp}?text=${encodeURIComponent(text)}`;
};

const toggleStatus = (row: AffiliateRow) => {
    const question = row.is_active
        ? `Bekukan ${row.name}? Linknya berhenti bekerja dan tidak ada komisi baru sampai diaktifkan lagi.`
        : `Aktifkan lagi ${row.name}?`;
    if (!window.confirm(question)) return;
    router.post(`/superadmin/affiliates/${row.id}/toggle`, {}, { preserveScroll: true });
};

const whatsappUrl = (row: AffiliateRow) => {
    const firstName = row.name.trim().split(/\s+/)[0];
    const text = row.has_payout_account
        ? `Halo ${firstName}, saya dari tim Kasir UMKM. Terkait program afiliasi Anda.`
        : `Halo ${firstName}, saya dari tim Kasir UMKM. Komisi afiliasi Anda sudah bisa ditransfer. Mohon isi rekening pencairan di halaman Program Afiliasi ya.`;
    return `https://wa.me/${row.whatsapp}?text=${encodeURIComponent(text)}`;
};

/* ───────── Batalkan komisi ───────── */
const cancellingId = ref<number | null>(null);
const cancelReason = ref('');
const cancelError = ref('');

const openCancel = (row: CommissionRow) => {
    cancellingId.value = cancellingId.value === row.id ? null : row.id;
    cancelReason.value = '';
    cancelError.value = '';
};

const submitCancel = (row: CommissionRow) => {
    if (!cancelReason.value.trim()) {
        cancelError.value = 'Tulis alasan pembatalan.';
        return;
    }
    router.post(
        `/superadmin/affiliate-commissions/${row.id}/cancel`,
        { reason: cancelReason.value },
        { preserveScroll: true, onSuccess: () => (cancellingId.value = null) },
    );
};

const stateBadge = (state: CommissionRow['state']) =>
    ({
        held: { label: 'Ditahan', cls: 'bg-amber-50 text-amber-700 ring-amber-200' },
        ready: { label: 'Siap dibayar', cls: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
        processing: { label: 'Diajukan cair', cls: 'bg-violet-50 text-violet-700 ring-violet-200' },
        paid: { label: 'Dibayar', cls: 'bg-sky-50 text-sky-700 ring-sky-200' },
        cancelled: { label: 'Dibatalkan', cls: 'bg-slate-100 text-slate-500 ring-slate-200' },
    })[state];

const typeLabel = (type: string | null) =>
    ({
        subscription: 'Paket',
        outlet_addon: 'Outlet tambahan',
        attendance_payroll_addon: 'Absensi + Payroll',
        central_kitchen_addon: 'Gudang Pusat Pro',
    })[type ?? 'subscription'] ?? 'Tambahan';
</script>

<template>
    <SuperAdminLayout title="Afiliasi" active-tab="affiliates">
        <div class="space-y-6">
            <header class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-slate-900">Afiliasi</h1>
                    <p class="text-sm text-slate-500">
                        Komisi {{ rules.percent.toLocaleString('id-ID') }}%
                        {{ rules.once ? 'dari paket pertama tiap toko rujukan, sekali per toko.' : `dari pembayaran yang dikonfirmasi, selama ${rules.months} bulan.` }}
                        Ditahan
                        {{ rules.hold_days }} hari, ditransfer setelah {{ rupiah(rules.min_payout) }}.
                    </p>
                </div>
                <a
                    href="/afiliasi"
                    target="_blank"
                    rel="noopener"
                    class="inline-flex shrink-0 items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
                >
                    <ExternalLink class="h-4 w-4" /> Lihat halaman program
                </a>
            </header>

            <!-- KPI -->
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-6">
                <div class="rounded-xl border border-slate-200 bg-white p-4">
                    <div class="flex items-center justify-between">
                        <p class="text-xs font-medium text-slate-500">Afiliator</p>
                        <Users class="h-4 w-4 text-slate-400" />
                    </div>
                    <p class="mt-2 text-2xl font-bold text-slate-900 tabular-nums">{{ stats.affiliates }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4">
                    <div class="flex items-center justify-between">
                        <p class="text-xs font-medium text-slate-500">Pendaftar rujukan</p>
                        <UserPlus class="h-4 w-4 text-sky-500" />
                    </div>
                    <p class="mt-2 text-2xl font-bold text-slate-900 tabular-nums">{{ stats.signups }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4">
                    <div class="flex items-center justify-between">
                        <p class="text-xs font-medium text-slate-500">Sudah berlangganan</p>
                        <Store class="h-4 w-4 text-emerald-500" />
                    </div>
                    <p class="mt-2 text-2xl font-bold text-slate-900 tabular-nums">{{ stats.paying }}</p>
                </div>
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4">
                    <div class="flex items-center justify-between">
                        <p class="text-xs font-medium text-emerald-800">Siap dibayar</p>
                        <Wallet class="h-4 w-4 text-emerald-600" />
                    </div>
                    <p class="mt-2 text-xl font-bold text-emerald-700 tabular-nums">{{ rupiah(stats.ready) }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4">
                    <div class="flex items-center justify-between">
                        <p class="text-xs font-medium text-slate-500">Masih ditahan</p>
                        <Clock class="h-4 w-4 text-amber-500" />
                    </div>
                    <p class="mt-2 text-xl font-bold text-slate-900 tabular-nums">{{ rupiah(stats.held) }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4">
                    <div class="flex items-center justify-between">
                        <p class="text-xs font-medium text-slate-500">Sudah dibayar</p>
                        <Banknote class="h-4 w-4 text-sky-500" />
                    </div>
                    <p class="mt-2 text-xl font-bold text-slate-900 tabular-nums">{{ rupiah(stats.paid) }}</p>
                </div>
            </div>

            <!-- Aturan program -->
            <section class="rounded-xl border border-slate-200 bg-white">
                <button
                    type="button"
                    class="flex w-full items-center justify-between gap-3 px-5 py-4 text-left"
                    :aria-expanded="settingsOpen"
                    aria-controls="aturan-program"
                    @click="settingsOpen = !settingsOpen"
                >
                    <span class="flex items-center gap-2.5">
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-violet-100">
                            <Settings2 class="h-4 w-4 text-violet-600" />
                        </span>
                        <span>
                            <span class="block text-base font-semibold text-slate-900">Aturan program</span>
                            <span class="block text-xs text-slate-500">
                                Komisi {{ settings.commission_percent.value.toLocaleString('id-ID') }}% ·
                                {{ settings.commission_months.value > 0 ? settings.commission_months.value + ' bulan' : 'sekali per toko' }} · tahan
                                {{ settings.hold_days.value }} hari · minimum {{ rupiah(settings.min_payout.value) }} · trial {{ settings.referred_trial_days.value }} hari
                            </span>
                        </span>
                    </span>
                    <ChevronDown :class="['h-4 w-4 shrink-0 text-slate-400 transition-transform', settingsOpen ? 'rotate-180' : '']" />
                </button>

                <form v-show="settingsOpen" id="aturan-program" class="border-t border-slate-100 p-5" @submit.prevent="saveSettings">
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <div v-for="field in settingFields" :key="field.key">
                            <label :for="`set-${field.key}`" class="mb-1 block text-xs font-semibold text-slate-600">{{ field.label }} ({{ field.unit }})</label>
                            <input
                                :id="`set-${field.key}`"
                                v-model="settingsForm[field.key]"
                                type="number"
                                min="0"
                                :step="field.key === 'commission_percent' ? '0.5' : '1'"
                                :placeholder="`Bawaan: ${settings[field.key].default.toLocaleString('id-ID')}`"
                                class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-violet-400 focus:ring-2 focus:ring-violet-100 focus:outline-none"
                                :aria-invalid="!!settingsForm.errors[field.key]"
                            />
                            <p v-if="settingsForm.errors[field.key]" class="mt-1 text-xs font-medium text-red-600" role="alert">{{ settingsForm.errors[field.key] }}</p>
                            <p v-else class="mt-1 text-xs text-slate-500">{{ field.help }}</p>
                        </div>
                    </div>
                    <p class="mt-4 text-xs text-slate-500">
                        Kosongkan kolom untuk memakai nilai bawaan. Perubahan berlaku untuk komisi dan pendaftaran berikutnya; komisi yang sudah tercatat tidak
                        berubah. Halaman /afiliasi otomatis menampilkan angka baru.
                    </p>
                    <div class="mt-4 flex items-center gap-3">
                        <button
                            type="submit"
                            :disabled="settingsForm.processing"
                            class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 disabled:opacity-60"
                        >
                            {{ settingsForm.processing ? 'Menyimpan…' : 'Simpan aturan' }}
                        </button>
                        <p v-if="settingsSaved" class="flex items-center gap-1.5 text-sm font-medium text-emerald-700" role="status">
                            <CheckCircle class="h-4 w-4" /> Tersimpan.
                        </p>
                    </div>
                </form>
            </section>

            <!-- Peringkat bulan ini -->
            <section v-if="leaderboard.rows.length > 0" class="rounded-xl border border-slate-200 bg-white">
                <div class="border-b border-slate-100 px-5 py-4">
                    <h2 class="flex items-center gap-2 text-base font-semibold text-slate-900">
                        <Trophy class="h-4 w-4 text-amber-500" /> Peringkat {{ leaderboard.period }}
                    </h2>
                </div>
                <ol class="divide-y divide-slate-100">
                    <li v-for="row in leaderboard.rows" :key="row.rank" class="flex items-center gap-3 px-5 py-3 text-sm">
                        <span
                            :class="[
                                'flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-bold tabular-nums',
                                row.rank === 1 ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-600',
                            ]"
                            >{{ row.rank }}</span
                        >
                        <span class="min-w-0 flex-1 truncate font-medium text-slate-900">{{ row.name }}</span>
                        <span class="shrink-0 text-xs text-slate-500">{{ row.paying }} toko baru</span>
                        <span class="w-28 shrink-0 text-right font-bold text-slate-900 tabular-nums">{{ rupiah(row.earned) }}</span>
                    </li>
                </ol>
            </section>

            <!-- Permintaan pencairan -->
            <section class="rounded-xl border border-slate-200 bg-white">
                <div class="flex flex-col gap-1 border-b border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <h2 class="text-base font-semibold text-slate-900">Pencairan ({{ payouts.total }})</h2>
                    <p
                        v-if="stats.pending_payouts > 0"
                        class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-800 ring-1 ring-amber-200 ring-inset"
                    >
                        <Hourglass class="h-3.5 w-3.5" /> {{ stats.pending_payouts }} menunggu transfer · {{ rupiah(stats.pending_payouts_amount) }}
                    </p>
                </div>

                <div v-if="payouts.data.length === 0" class="px-5 py-12 text-center">
                    <Banknote class="mx-auto h-8 w-8 text-slate-300" />
                    <p class="mt-3 text-sm text-slate-500">Belum ada pencairan. Permintaan dari afiliator muncul di sini.</p>
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-slate-50 text-xs font-medium tracking-wide text-slate-500 uppercase">
                            <tr>
                                <th scope="col" class="px-5 py-3">Diajukan</th>
                                <th scope="col" class="px-3 py-3">Afiliator</th>
                                <th scope="col" class="px-3 py-3 text-right">Jumlah</th>
                                <th scope="col" class="px-3 py-3">Rekening tujuan</th>
                                <th scope="col" class="px-3 py-3">Status</th>
                                <th scope="col" class="px-5 py-3 text-right"><span class="sr-only">Aksi</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <template v-for="row in payouts.data" :key="row.id">
                                <tr :class="['align-top hover:bg-slate-50/60', row.status === 'requested' ? 'bg-amber-50/40' : '']">
                                    <td class="px-5 py-3.5 text-xs whitespace-nowrap text-slate-600">
                                        {{ row.requested_at ? formatDate(row.requested_at) : row.type === 'subscription' ? formatDate(row.processed_at) : 'Dicatat admin' }}
                                    </td>
                                    <td class="px-3 py-3.5">
                                        <p class="font-medium text-slate-900">{{ row.affiliate_name }}</p>
                                        <p class="font-mono text-xs text-slate-400">{{ row.affiliate_code }}</p>
                                    </td>
                                    <td class="px-3 py-3.5 text-right font-bold whitespace-nowrap text-slate-900 tabular-nums">{{ rupiah(row.amount) }}</td>
                                    <td class="px-3 py-3.5 text-xs text-slate-600">
                                        <template v-if="row.payout_account_number">
                                            <p class="font-medium text-slate-800">{{ row.payout_provider }}</p>
                                            <p class="tabular-nums">{{ row.payout_account_number }}</p>
                                            <p class="text-slate-400">a.n. {{ row.payout_account_holder }}</p>
                                        </template>
                                        <span v-else class="text-slate-400">—</span>
                                    </td>
                                    <td class="px-3 py-3.5">
                                        <span :class="['inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap ring-1 ring-inset', payoutBadge(row).cls]">
                                            {{ payoutBadge(row).label }}
                                        </span>
                                        <p v-if="row.status !== 'requested'" class="mt-1 text-xs text-slate-400">{{ formatDate(row.processed_at) }}</p>
                                        <p v-if="row.status === 'paid' && row.note" class="mt-0.5 max-w-[220px] text-xs text-slate-400">{{ row.note }}</p>
                                        <p v-if="row.status === 'rejected' && row.reject_reason" class="mt-0.5 max-w-[220px] text-xs text-slate-400">{{ row.reject_reason }}</p>
                                    </td>
                                    <td class="px-5 py-3.5">
                                        <div class="flex flex-wrap items-center justify-end gap-1.5">
                                            <template v-if="row.status === 'requested'">
                                                <button
                                                    type="button"
                                                    class="inline-flex items-center gap-1.5 rounded-lg bg-slate-900 px-2.5 py-1.5 text-xs font-semibold whitespace-nowrap text-white hover:bg-slate-800"
                                                    :aria-expanded="payoutPayingId === row.id"
                                                    @click="openPayoutPay(row)"
                                                >
                                                    <Banknote class="h-3.5 w-3.5" /> Sudah ditransfer
                                                </button>
                                                <a
                                                    v-if="row.whatsapp"
                                                    :href="payoutWhatsapp(row)"
                                                    target="_blank"
                                                    rel="noopener"
                                                    class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-2.5 py-1.5 text-xs font-semibold whitespace-nowrap text-white hover:bg-emerald-700"
                                                >
                                                    <MessageCircle class="h-3.5 w-3.5" /> WhatsApp
                                                </a>
                                                <button
                                                    type="button"
                                                    class="rounded-lg px-2.5 py-1.5 text-xs font-medium whitespace-nowrap text-red-600 hover:bg-red-50"
                                                    :aria-expanded="payoutRejectingId === row.id"
                                                    @click="openPayoutReject(row)"
                                                >
                                                    Tolak
                                                </button>
                                            </template>
                                            <a
                                                v-else-if="row.proof_url"
                                                :href="row.proof_url"
                                                target="_blank"
                                                rel="noopener"
                                                class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-medium whitespace-nowrap text-slate-600 hover:bg-slate-100"
                                            >
                                                <ExternalLink class="h-3.5 w-3.5" /> Bukti transfer
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="payoutPayingId === row.id" class="bg-slate-50/60">
                                    <td colspan="6" class="px-5 py-4">
                                        <p class="text-sm font-semibold text-slate-900">Transfer {{ rupiah(row.amount) }} ke {{ row.affiliate_name }}</p>
                                        <p class="mt-0.5 text-xs text-slate-600">
                                            {{ row.payout_provider }} · <span class="tabular-nums">{{ row.payout_account_number }}</span> · a.n. {{ row.payout_account_holder }}
                                        </p>
                                        <label :for="`payout-note-${row.id}`" class="mt-3 mb-1 block text-xs font-semibold text-slate-600">Catatan transfer (opsional)</label>
                                        <input
                                            :id="`payout-note-${row.id}`"
                                            v-model="payNote"
                                            type="text"
                                            maxlength="500"
                                            placeholder="mis. Transfer BCA 26 Nov, ref 12345"
                                            class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm focus:border-slate-400 focus:outline-none"
                                        />
                                        <label :for="`payout-proof-${row.id}`" class="mt-3 mb-1 block text-xs font-semibold text-slate-600">
                                            Bukti transfer (opsional, gambar maks. 5 MB)
                                        </label>
                                        <input
                                            :id="`payout-proof-${row.id}`"
                                            type="file"
                                            accept="image/*"
                                            class="block w-full text-sm text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-slate-700 hover:file:bg-slate-200"
                                            @change="pickProof"
                                        />
                                        <p v-if="payError" class="mt-1 text-xs font-medium text-red-600" role="alert">{{ payError }}</p>
                                        <p class="mt-1 text-xs text-slate-500">Lakukan transfernya dulu, baru tandai di sini. Afiliator bisa melihat bukti yang Anda unggah.</p>
                                        <div class="mt-3 flex justify-end gap-2">
                                            <button type="button" class="rounded-lg px-3 py-1.5 text-xs font-medium text-slate-600 hover:bg-slate-100" @click="payoutPayingId = null">
                                                Batal
                                            </button>
                                            <button
                                                type="button"
                                                :disabled="processing"
                                                class="rounded-lg bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-slate-800 disabled:opacity-60"
                                                @click="submitPayoutPaid(row)"
                                            >
                                                {{ processing ? 'Menyimpan…' : 'Sudah saya transfer' }}
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="payoutRejectingId === row.id" class="bg-slate-50/60">
                                    <td colspan="6" class="px-5 py-3">
                                        <label :for="`payout-reject-${row.id}`" class="mb-1 block text-xs font-semibold text-slate-600">
                                            Alasan menolak pencairan {{ rupiah(row.amount) }} — {{ row.affiliate_name }}
                                        </label>
                                        <input
                                            :id="`payout-reject-${row.id}`"
                                            v-model="rejectReason"
                                            type="text"
                                            maxlength="500"
                                            placeholder="mis. nama pemilik rekening tidak cocok"
                                            class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm focus:border-slate-400 focus:outline-none"
                                            :aria-invalid="!!rejectError"
                                        />
                                        <p v-if="rejectError" class="mt-1 text-xs font-medium text-red-600" role="alert">{{ rejectError }}</p>
                                        <p class="mt-1 text-xs text-slate-500">Alasan ini terlihat oleh afiliator. Komisinya kembali bisa ditarik.</p>
                                        <div class="mt-2 flex justify-end gap-2">
                                            <button type="button" class="rounded-lg px-3 py-1.5 text-xs font-medium text-slate-600 hover:bg-slate-100" @click="payoutRejectingId = null">
                                                Tidak jadi
                                            </button>
                                            <button type="button" class="rounded-lg bg-red-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-red-700" @click="submitPayoutReject(row)">
                                                Tolak pencairan
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>

                <div v-if="payouts.links && payouts.links.length > 3" class="flex justify-center border-t border-slate-100 px-5 py-4">
                    <nav class="flex items-center gap-1" aria-label="Halaman daftar pencairan">
                        <Link
                            v-for="link in payouts.links"
                            :key="link.label"
                            :href="link.url || '#'"
                            preserve-scroll
                            :class="[
                                'rounded-lg px-3 py-1.5 text-sm font-medium transition-colors',
                                link.active ? 'bg-slate-900 text-white' : link.url ? 'text-slate-600 hover:bg-slate-100' : 'cursor-default text-slate-300',
                            ]"
                        >
                            <span v-html="link.label" />
                        </Link>
                    </nav>
                </div>
            </section>

            <!-- Afiliator -->
            <section class="rounded-xl border border-slate-200 bg-white">
                <div class="flex flex-col gap-3 border-b border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <h2 class="text-base font-semibold text-slate-900">Afiliator ({{ affiliates.total }})</h2>
                    <div class="relative">
                        <Search class="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-slate-400" />
                        <input
                            v-model="search"
                            type="search"
                            aria-label="Cari afiliator"
                            placeholder="Cari nama, kode, email, nomor…"
                            class="w-full rounded-lg border border-slate-200 py-2 pr-3 pl-9 text-sm focus:border-slate-400 focus:outline-none sm:w-64"
                            @keyup.enter="applyFilter"
                        />
                    </div>
                </div>

                <div v-if="affiliates.data.length === 0" class="px-5 py-16 text-center">
                    <Handshake class="mx-auto h-8 w-8 text-slate-300" />
                    <p class="mt-3 text-sm text-slate-500">Belum ada afiliator. Bagikan halaman /afiliasi untuk mengajak orang bergabung.</p>
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-slate-50 text-xs font-medium tracking-wide text-slate-500 uppercase">
                            <tr>
                                <th scope="col" class="px-5 py-3">Afiliator</th>
                                <th scope="col" class="px-3 py-3">Kode</th>
                                <th scope="col" class="px-3 py-3 text-right">Klik</th>
                                <th scope="col" class="px-3 py-3 text-right">Daftar</th>
                                <th scope="col" class="px-3 py-3 text-right">Bayar</th>
                                <th scope="col" class="px-3 py-3 text-right">Komisi</th>
                                <th scope="col" class="px-3 py-3">Rekening</th>
                                <th scope="col" class="px-5 py-3 text-right"><span class="sr-only">Aksi</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <template v-for="row in affiliates.data" :key="row.id">
                                <tr class="align-top hover:bg-slate-50/60">
                                    <td class="px-5 py-3.5">
                                        <p class="font-semibold text-slate-900">
                                            {{ row.name }}
                                            <span
                                                v-if="!row.is_active"
                                                class="ml-1 inline-flex items-center rounded-full bg-red-50 px-2 py-0.5 text-[11px] font-medium text-red-700 ring-1 ring-red-200 ring-inset"
                                                >Dibekukan</span
                                            >
                                        </p>
                                        <p class="text-xs text-slate-500">{{ row.email ?? '—' }}</p>
                                        <p class="mt-0.5 text-xs text-slate-400">Gabung {{ formatDate(row.joined_at) }}</p>
                                    </td>
                                    <td class="px-3 py-3.5">
                                        <code class="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-xs text-slate-700">{{ row.code }}</code>
                                        <button
                                            type="button"
                                            :class="[
                                                'mt-1 block rounded text-xs tabular-nums underline decoration-dotted underline-offset-2 hover:text-slate-700',
                                                row.custom_percent !== null ? 'font-semibold text-violet-700' : 'text-slate-400',
                                            ]"
                                            :title="row.custom_percent !== null ? 'Tarif khusus. Klik untuk mengubah.' : 'Tarif program. Klik untuk memberi tarif khusus.'"
                                            :aria-expanded="rateId === row.id"
                                            @click="openRate(row)"
                                        >
                                            {{ row.percent.toLocaleString('id-ID') }}%<template v-if="row.custom_percent !== null"> khusus</template>
                                        </button>
                                    </td>
                                    <td class="px-3 py-3.5 text-right text-slate-700 tabular-nums">{{ row.clicks }}</td>
                                    <td class="px-3 py-3.5 text-right text-slate-700 tabular-nums">{{ row.signups }}</td>
                                    <td class="px-3 py-3.5 text-right font-semibold text-slate-900 tabular-nums">{{ row.paying }}</td>
                                    <td class="px-3 py-3.5 text-right text-xs whitespace-nowrap tabular-nums">
                                        <p :class="row.ready > 0 ? 'font-bold text-emerald-700' : 'text-slate-500'">Siap {{ rupiah(row.ready) }}</p>
                                        <p class="text-slate-500">Ditahan {{ rupiah(row.held) }}</p>
                                        <p class="text-slate-400">Dibayar {{ rupiah(row.paid) }}</p>
                                    </td>
                                    <td class="px-3 py-3.5 text-xs text-slate-600">
                                        <template v-if="row.has_payout_account">
                                            <p class="font-medium text-slate-800">{{ row.payout_provider }}</p>
                                            <p class="tabular-nums">{{ row.payout_account_number }}</p>
                                            <p class="text-slate-400">a.n. {{ row.payout_account_holder }}</p>
                                        </template>
                                        <span v-else class="inline-flex items-center rounded-full bg-amber-50 px-2 py-0.5 font-medium text-amber-700 ring-1 ring-amber-200 ring-inset"
                                            >Belum diisi</span
                                        >
                                    </td>
                                    <td class="px-5 py-3.5">
                                        <div class="flex flex-wrap items-center justify-end gap-1.5">
                                            <button
                                                v-if="row.ready > 0"
                                                type="button"
                                                class="inline-flex items-center gap-1.5 rounded-lg bg-slate-900 px-2.5 py-1.5 text-xs font-semibold whitespace-nowrap text-white hover:bg-slate-800"
                                                :aria-expanded="payingId === row.id"
                                                @click="openPay(row)"
                                            >
                                                <Banknote class="h-3.5 w-3.5" /> Catat transfer
                                            </button>
                                            <a
                                                v-if="row.whatsapp"
                                                :href="whatsappUrl(row)"
                                                target="_blank"
                                                rel="noopener"
                                                class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-2.5 py-1.5 text-xs font-semibold whitespace-nowrap text-white hover:bg-emerald-700"
                                            >
                                                <MessageCircle class="h-3.5 w-3.5" /> WhatsApp
                                            </a>
                                            <button
                                                type="button"
                                                :class="[
                                                    'rounded-lg px-2.5 py-1.5 text-xs font-medium whitespace-nowrap',
                                                    row.is_active ? 'text-red-600 hover:bg-red-50' : 'text-emerald-700 hover:bg-emerald-50',
                                                ]"
                                                @click="toggleStatus(row)"
                                            >
                                                {{ row.is_active ? 'Bekukan' : 'Aktifkan' }}
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="rateId === row.id" class="bg-slate-50/60">
                                    <td colspan="8" class="px-5 py-3">
                                        <label :for="`rate-${row.id}`" class="mb-1 block text-xs font-semibold text-slate-600">
                                            Tarif komisi khusus untuk {{ row.name }} (%)
                                        </label>
                                        <div class="flex flex-wrap items-center gap-2">
                                            <input
                                                :id="`rate-${row.id}`"
                                                v-model="rateValue"
                                                type="number"
                                                min="0"
                                                max="100"
                                                step="0.5"
                                                :placeholder="`Kosong = tarif program ${rules.percent.toLocaleString('id-ID')}%`"
                                                class="w-64 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm focus:border-slate-400 focus:outline-none"
                                                :aria-invalid="!!rateError"
                                            />
                                            <button type="button" class="rounded-lg bg-slate-900 px-3 py-2 text-xs font-semibold text-white hover:bg-slate-800" @click="submitRate(row)">
                                                Simpan tarif
                                            </button>
                                            <button type="button" class="rounded-lg px-3 py-2 text-xs font-medium text-slate-600 hover:bg-slate-100" @click="rateId = null">
                                                Batal
                                            </button>
                                        </div>
                                        <p v-if="rateError" class="mt-1 text-xs font-medium text-red-600" role="alert">{{ rateError }}</p>
                                        <p class="mt-1 text-xs text-slate-500">Berlaku untuk komisi berikutnya. Komisi yang sudah tercatat tidak berubah.</p>
                                    </td>
                                </tr>
                                <tr v-if="payingId === row.id" class="bg-slate-50/60">
                                    <td colspan="8" class="px-5 py-4">
                                        <p class="text-sm font-semibold text-slate-900">
                                            Transfer {{ rupiah(row.ready) }} ke {{ row.name }}
                                            <span v-if="row.ready < rules.min_payout" class="ml-1 text-xs font-medium text-amber-700"
                                                >(di bawah minimum {{ rupiah(rules.min_payout) }})</span
                                            >
                                        </p>
                                        <p v-if="row.has_payout_account" class="mt-0.5 text-xs text-slate-600">
                                            {{ row.payout_provider }} · <span class="tabular-nums">{{ row.payout_account_number }}</span> · a.n. {{ row.payout_account_holder }}
                                        </p>
                                        <p v-else class="mt-0.5 text-xs font-medium text-amber-700">Afiliator belum mengisi rekening pencairan.</p>
                                        <label :for="`pay-note-${row.id}`" class="mt-3 mb-1 block text-xs font-semibold text-slate-600">
                                            Catatan transfer (opsional)
                                        </label>
                                        <input
                                            :id="`pay-note-${row.id}`"
                                            v-model="payNote"
                                            type="text"
                                            maxlength="500"
                                            placeholder="mis. Transfer BCA 26 Nov, ref 12345"
                                            class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm focus:border-slate-400 focus:outline-none"
                                        />
                                        <label :for="`pay-proof-${row.id}`" class="mt-3 mb-1 block text-xs font-semibold text-slate-600">
                                            Bukti transfer (opsional, gambar maks. 5 MB)
                                        </label>
                                        <input
                                            :id="`pay-proof-${row.id}`"
                                            type="file"
                                            accept="image/*"
                                            class="block w-full text-sm text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-slate-700 hover:file:bg-slate-200"
                                            @change="pickProof"
                                        />
                                        <p v-if="payError" class="mt-1 text-xs font-medium text-red-600" role="alert">{{ payError }}</p>
                                        <p class="mt-1 text-xs text-slate-500">Lakukan transfernya dulu, baru catat di sini. Semua komisi yang siap dibayar ditandai sudah dibayar.</p>
                                        <div class="mt-3 flex justify-end gap-2">
                                            <button type="button" class="rounded-lg px-3 py-1.5 text-xs font-medium text-slate-600 hover:bg-slate-100" @click="payingId = null">
                                                Batal
                                            </button>
                                            <button
                                                type="button"
                                                :disabled="processing"
                                                class="rounded-lg bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-slate-800 disabled:opacity-60"
                                                @click="submitPay(row)"
                                            >
                                                {{ processing ? 'Menyimpan…' : 'Sudah saya transfer' }}
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>

                <div v-if="affiliates.links && affiliates.links.length > 3" class="flex justify-center border-t border-slate-100 px-5 py-4">
                    <nav class="flex items-center gap-1" aria-label="Halaman daftar afiliator">
                        <Link
                            v-for="link in affiliates.links"
                            :key="link.label"
                            :href="link.url || '#'"
                            preserve-scroll
                            :class="[
                                'rounded-lg px-3 py-1.5 text-sm font-medium transition-colors',
                                link.active ? 'bg-slate-900 text-white' : link.url ? 'text-slate-600 hover:bg-slate-100' : 'cursor-default text-slate-300',
                            ]"
                        >
                            <span v-html="link.label" />
                        </Link>
                    </nav>
                </div>
            </section>

            <!-- Komisi -->
            <section class="rounded-xl border border-slate-200 bg-white">
                <div class="flex flex-col gap-3 border-b border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <h2 class="text-base font-semibold text-slate-900">Komisi ({{ commissions.total }})</h2>
                    <select
                        v-model="stateFilter"
                        aria-label="Saring status komisi"
                        class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm focus:outline-none"
                        @change="applyFilter"
                    >
                        <option value="">Semua status</option>
                        <option value="ready">Siap dibayar</option>
                        <option value="processing">Diajukan cair</option>
                        <option value="held">Ditahan</option>
                        <option value="paid">Dibayar</option>
                        <option value="cancelled">Dibatalkan</option>
                    </select>
                </div>

                <div v-if="commissions.data.length === 0" class="px-5 py-16 text-center">
                    <Wallet class="mx-auto h-8 w-8 text-slate-300" />
                    <p class="mt-3 text-sm text-slate-500">Belum ada komisi. Komisi tercatat otomatis saat pembayaran toko rujukan dikonfirmasi.</p>
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-slate-50 text-xs font-medium tracking-wide text-slate-500 uppercase">
                            <tr>
                                <th scope="col" class="px-5 py-3">Tanggal</th>
                                <th scope="col" class="px-3 py-3">Afiliator</th>
                                <th scope="col" class="px-3 py-3">Toko rujukan</th>
                                <th scope="col" class="px-3 py-3">Pembayaran</th>
                                <th scope="col" class="px-3 py-3 text-right">Komisi</th>
                                <th scope="col" class="px-3 py-3">Status</th>
                                <th scope="col" class="px-5 py-3 text-right"><span class="sr-only">Aksi</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <template v-for="row in commissions.data" :key="row.id">
                                <tr class="align-top hover:bg-slate-50/60">
                                    <td class="px-5 py-3.5 text-xs whitespace-nowrap text-slate-600">{{ formatDate(row.created_at) }}</td>
                                    <td class="px-3 py-3.5">
                                        <p class="font-medium text-slate-900">{{ row.affiliate_name }}</p>
                                        <p class="font-mono text-xs text-slate-400">{{ row.affiliate_code }}</p>
                                    </td>
                                    <td class="px-3 py-3.5 text-slate-700">{{ row.referred }}</td>
                                    <td class="px-3 py-3.5 text-xs text-slate-600">
                                        <p class="font-medium text-slate-800">{{ typeLabel(row.payment_type) }} · {{ rupiah(row.base_amount) }}</p>
                                        <p class="font-mono text-slate-400">{{ row.invoice_number ?? '—' }}</p>
                                    </td>
                                    <td class="px-3 py-3.5 text-right whitespace-nowrap tabular-nums">
                                        <p :class="['font-bold', row.state === 'cancelled' ? 'text-slate-400 line-through' : 'text-slate-900']">{{ rupiah(row.amount) }}</p>
                                        <p class="text-xs text-slate-400">{{ row.percent.toLocaleString('id-ID') }}%</p>
                                    </td>
                                    <td class="px-3 py-3.5">
                                        <span :class="['inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap ring-1 ring-inset', stateBadge(row.state).cls]">
                                            {{ stateBadge(row.state).label }}
                                        </span>
                                        <p v-if="row.state === 'held'" class="mt-1 text-xs text-slate-400">s.d. {{ formatDate(row.available_at) }}</p>
                                        <p v-if="row.state === 'paid'" class="mt-1 text-xs text-slate-400">{{ formatDate(row.paid_at) }}<template v-if="row.paid_note"> · {{ row.paid_note }}</template></p>
                                        <p v-if="row.state === 'cancelled' && row.cancel_reason" class="mt-1 max-w-[220px] text-xs text-slate-400">{{ row.cancel_reason }}</p>
                                    </td>
                                    <td class="px-5 py-3.5 text-right">
                                        <button
                                            v-if="row.state === 'held' || row.state === 'ready'"
                                            type="button"
                                            class="rounded-lg px-2.5 py-1.5 text-xs font-medium whitespace-nowrap text-red-600 hover:bg-red-50"
                                            :aria-expanded="cancellingId === row.id"
                                            @click="openCancel(row)"
                                        >
                                            Batalkan
                                        </button>
                                    </td>
                                </tr>
                                <tr v-if="cancellingId === row.id" class="bg-slate-50/60">
                                    <td colspan="7" class="px-5 py-3">
                                        <label :for="`cancel-${row.id}`" class="mb-1 block text-xs font-semibold text-slate-600">
                                            Alasan membatalkan komisi {{ rupiah(row.amount) }} — {{ row.affiliate_name }}
                                        </label>
                                        <input
                                            :id="`cancel-${row.id}`"
                                            v-model="cancelReason"
                                            type="text"
                                            maxlength="500"
                                            placeholder="mis. pembayaran di-refund"
                                            class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm focus:border-slate-400 focus:outline-none"
                                            :aria-invalid="!!cancelError"
                                        />
                                        <p v-if="cancelError" class="mt-1 text-xs font-medium text-red-600" role="alert">{{ cancelError }}</p>
                                        <div class="mt-2 flex justify-end gap-2">
                                            <button type="button" class="rounded-lg px-3 py-1.5 text-xs font-medium text-slate-600 hover:bg-slate-100" @click="cancellingId = null">
                                                Tidak jadi
                                            </button>
                                            <button type="button" class="rounded-lg bg-red-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-red-700" @click="submitCancel(row)">
                                                Batalkan komisi
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>

                <div v-if="commissions.links && commissions.links.length > 3" class="flex justify-center border-t border-slate-100 px-5 py-4">
                    <nav class="flex items-center gap-1" aria-label="Halaman daftar komisi">
                        <Link
                            v-for="link in commissions.links"
                            :key="link.label"
                            :href="link.url || '#'"
                            preserve-scroll
                            :class="[
                                'rounded-lg px-3 py-1.5 text-sm font-medium transition-colors',
                                link.active ? 'bg-slate-900 text-white' : link.url ? 'text-slate-600 hover:bg-slate-100' : 'cursor-default text-slate-300',
                            ]"
                        >
                            <span v-html="link.label" />
                        </Link>
                    </nav>
                </div>
            </section>
        </div>
    </SuperAdminLayout>
</template>
