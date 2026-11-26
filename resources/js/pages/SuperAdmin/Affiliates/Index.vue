<script setup lang="ts">
import SuperAdminLayout from '@/layouts/SuperAdminLayout.vue';
import { Link, router } from '@inertiajs/vue3';
import { Banknote, Clock, ExternalLink, Handshake, MessageCircle, Search, Store, UserPlus, Users, Wallet } from 'lucide-vue-next';
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
    state: 'held' | 'ready' | 'paid' | 'cancelled';
    created_at: string | null;
    available_at: string | null;
    paid_at: string | null;
    paid_note: string | null;
    cancel_reason: string | null;
}

interface Paginated<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    total: number;
}

const props = defineProps<{
    affiliates: Paginated<AffiliateRow>;
    commissions: Paginated<CommissionRow>;
    stats: { affiliates: number; signups: number; paying: number; held: number; ready: number; paid: number };
    rules: { percent: number; months: number; cookie_days: number; hold_days: number; min_payout: number };
    filters: { q: string | null; state: string | null };
}>();

const rupiah = (n: number) => 'Rp' + Math.round(n).toLocaleString('id-ID');
const formatDate = (d: string | null) => (d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—');

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
const processing = ref(false);

const openPay = (row: AffiliateRow) => {
    payingId.value = payingId.value === row.id ? null : row.id;
    payNote.value = '';
};

const submitPay = (row: AffiliateRow) => {
    processing.value = true;
    router.post(
        `/superadmin/affiliates/${row.id}/pay`,
        { note: payNote.value || null },
        {
            preserveScroll: true,
            onSuccess: () => (payingId.value = null),
            onFinish: () => (processing.value = false),
        },
    );
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
                        Komisi {{ rules.percent.toLocaleString('id-ID') }}% dari pembayaran yang dikonfirmasi, selama {{ rules.months }} bulan. Ditahan
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
                                        <p class="mt-1 text-xs text-slate-400 tabular-nums">{{ row.percent.toLocaleString('id-ID') }}%</p>
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
