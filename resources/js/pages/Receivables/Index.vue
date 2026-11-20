<script setup lang="ts">
import { DUE_TONE_CLASS, dueStatus, reminderLink, rupiah } from '@/composables/useReceivables';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowDownUp,
    CalendarClock,
    ChevronLeft,
    ChevronRight,
    CreditCard,
    MessageCircle,
    Search,
    ShoppingCart,
    Users,
} from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

interface CustomerSummary {
    customer: { id: number; name: string; phone?: string | null } | null;
    outstanding_total: number;
    debt_total: number;
    transaction_count: number;
    earliest_due_at: string | null;
    overdue_count: number;
}

const props = defineProps<{
    customers: {
        data: CustomerSummary[];
        total: number;
        current_page: number;
        last_page: number;
        from: number | null;
        to: number | null;
    };
    filter: string;
    sort: string;
    search: string;
    summary: {
        outstanding_total: number;
        customer_count: number;
        overdue_total: number;
        overdue_customers: number;
        due_soon_total: number;
        due_soon_customers: number;
    };
    store: { id: number; name: string };
}>();

const page = usePage();
const receivablesEnabled = computed(() => Boolean((page.props as { receivables_enabled?: boolean }).receivables_enabled));

// ── Filter, urutan, pencarian ───────────────────────────────────────────
const searchInput = ref(props.search ?? '');
const sortKey = ref(props.sort ?? 'due');

function visit(params: { status?: string; sort?: string; search?: string; page?: number }) {
    router.get(
        '/receivables',
        {
            status: params.status ?? props.filter,
            sort: (params.sort ?? sortKey.value) === 'due' ? undefined : (params.sort ?? sortKey.value),
            search: (params.search ?? searchInput.value) || undefined,
            page: params.page && params.page > 1 ? params.page : undefined,
        },
        { preserveScroll: true, preserveState: true, replace: true },
    );
}

let searchTimer: ReturnType<typeof setTimeout> | null = null;
watch(searchInput, (value) => {
    if (searchTimer) clearTimeout(searchTimer);
    searchTimer = setTimeout(() => visit({ search: value }), 350);
});
watch(sortKey, (value) => visit({ sort: value }));

const TABS = [
    { key: 'outstanding', label: 'Belum lunas' },
    { key: 'overdue', label: 'Lewat tempo' },
    { key: 'due_soon', label: 'Jatuh tempo ≤ 7 hari' },
    { key: 'paid', label: 'Lunas' },
];

const rows = computed(() => props.customers?.data ?? []);
const isPaidTab = computed(() => props.filter === 'paid');

function waLink(row: CustomerSummary) {
    if (!row.customer || row.outstanding_total <= 0) return null;
    return reminderLink({
        phone: row.customer.phone,
        customerName: row.customer.name,
        storeName: props.store.name,
        amount: row.outstanding_total,
        dueAt: row.earliest_due_at,
    });
}
</script>

<template>
    <AdminLayout title="Piutang Pelanggan">
        <Head title="Piutang Pelanggan" />

        <!-- Modul nonaktif -->
        <div v-if="!receivablesEnabled" class="mx-auto max-w-md py-16 text-center">
            <CreditCard class="mx-auto mb-4 h-12 w-12 text-slate-300" />
            <h2 class="mb-2 text-lg font-bold text-slate-800">Fitur piutang belum aktif</h2>
            <p class="text-sm text-slate-500">Nyalakan "Piutang Pelanggan" agar kasir bisa mencatat penjualan yang dibayar belakangan.</p>
            <Link
                href="/settings/store/fitur"
                class="mt-4 inline-flex rounded-xl bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90"
            >
                Buka Pengaturan → Fitur
            </Link>
        </div>

        <div v-else class="mx-auto max-w-5xl space-y-4">
            <!-- Ringkasan yang bisa diklik -->
            <div class="grid gap-3 sm:grid-cols-3">
                <button
                    type="button"
                    class="rounded-2xl border bg-white p-4 text-left transition"
                    :class="filter === 'outstanding' ? 'border-primary' : 'border-slate-200 hover:border-slate-300'"
                    @click="visit({ status: 'outstanding', page: 1 })"
                >
                    <p class="flex items-center gap-1.5 text-xs font-semibold text-slate-500"><CreditCard class="h-3.5 w-3.5" /> Total piutang</p>
                    <p class="mt-1 text-xl font-bold text-slate-900">{{ rupiah(summary.outstanding_total) }}</p>
                    <p class="text-xs text-slate-500">{{ summary.customer_count }} pelanggan</p>
                </button>
                <button
                    type="button"
                    class="rounded-2xl border bg-white p-4 text-left transition"
                    :class="filter === 'overdue' ? 'border-red-400' : 'border-slate-200 hover:border-slate-300'"
                    @click="visit({ status: 'overdue', page: 1 })"
                >
                    <p class="flex items-center gap-1.5 text-xs font-semibold" :class="summary.overdue_total > 0 ? 'text-red-600' : 'text-slate-500'">
                        <AlertTriangle class="h-3.5 w-3.5" /> Lewat jatuh tempo
                    </p>
                    <p class="mt-1 text-xl font-bold" :class="summary.overdue_total > 0 ? 'text-red-600' : 'text-slate-900'">
                        {{ rupiah(summary.overdue_total) }}
                    </p>
                    <p class="text-xs text-slate-500">{{ summary.overdue_customers }} pelanggan</p>
                </button>
                <button
                    type="button"
                    class="rounded-2xl border bg-white p-4 text-left transition"
                    :class="filter === 'due_soon' ? 'border-amber-400' : 'border-slate-200 hover:border-slate-300'"
                    @click="visit({ status: 'due_soon', page: 1 })"
                >
                    <p class="flex items-center gap-1.5 text-xs font-semibold text-slate-500">
                        <CalendarClock class="h-3.5 w-3.5" /> Jatuh tempo 7 hari ke depan
                    </p>
                    <p class="mt-1 text-xl font-bold text-slate-900">{{ rupiah(summary.due_soon_total) }}</p>
                    <p class="text-xs text-slate-500">{{ summary.due_soon_customers }} pelanggan</p>
                </button>
            </div>

            <section class="rounded-2xl border border-slate-200 bg-white">
                <!-- Pencarian & urutan -->
                <div class="flex flex-col gap-2 border-b border-slate-100 p-3 sm:flex-row">
                    <div class="relative flex-1">
                        <Search class="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-slate-400" />
                        <input
                            v-model="searchInput"
                            type="search"
                            placeholder="Cari nama atau no. HP pelanggan"
                            class="h-10 w-full rounded-xl border border-slate-200 bg-white pr-3 pl-9 text-sm focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none"
                        />
                    </div>
                    <div class="relative sm:w-56">
                        <ArrowDownUp class="pointer-events-none absolute top-1/2 left-3 h-3.5 w-3.5 -translate-y-1/2 text-slate-400" />
                        <select
                            v-model="sortKey"
                            aria-label="Urutkan"
                            class="h-10 w-full rounded-xl border border-slate-200 bg-white pr-3 pl-8 text-sm text-slate-700 focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none"
                        >
                            <option value="due">Jatuh tempo terdekat</option>
                            <option value="amount">Sisa utang terbesar</option>
                            <option value="name">Nama A–Z</option>
                        </select>
                    </div>
                </div>

                <!-- Filter status -->
                <div class="flex gap-1 overflow-x-auto border-b border-slate-100 px-3 py-2 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                    <button
                        v-for="t in TABS"
                        :key="t.key"
                        type="button"
                        class="shrink-0 rounded-lg px-3 py-1.5 text-sm font-semibold whitespace-nowrap transition"
                        :class="filter === t.key ? 'bg-primary text-white' : 'text-slate-500 hover:bg-slate-100 hover:text-slate-800'"
                        @click="visit({ status: t.key, page: 1 })"
                    >
                        {{ t.label }}
                    </button>
                </div>

                <!-- Kosong -->
                <div v-if="!rows.length" class="px-6 py-14 text-center">
                    <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-slate-100">
                        <Users class="h-5 w-5 text-slate-400" />
                    </div>
                    <template v-if="search">
                        <p class="font-semibold text-slate-700">Pelanggan tidak ditemukan</p>
                        <button type="button" class="mt-2 text-sm font-semibold text-primary hover:underline" @click="searchInput = ''">
                            Hapus pencarian
                        </button>
                    </template>
                    <template v-else-if="filter === 'outstanding'">
                        <p class="font-semibold text-slate-700">Belum ada piutang</p>
                        <p class="mx-auto mt-1 max-w-sm text-sm text-slate-500">
                            Piutang tercatat saat kasir mencentang <strong>Penjualan Kredit / Piutang</strong> di layar bayar dan memilih
                            pelanggannya. Tanggal jatuh tempo bisa diisi saat itu juga.
                        </p>
                        <Link
                            href="/pos"
                            class="mt-4 inline-flex items-center gap-1.5 rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                        >
                            <ShoppingCart class="h-4 w-4" /> Buka kasir
                        </Link>
                    </template>
                    <p v-else class="font-semibold text-slate-700">Tidak ada pelanggan di daftar ini</p>
                </div>

                <!-- Daftar pelanggan -->
                <ul v-else class="divide-y divide-slate-100">
                    <li v-for="row in rows" :key="row.customer?.id ?? `x-${row.transaction_count}`" class="flex items-center gap-3 px-4 py-3">
                        <Link :href="row.customer ? `/receivables/customers/${row.customer.id}` : '#'" class="flex min-w-0 flex-1 items-center gap-3">
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-semibold text-slate-900">{{
                                    row.customer?.name ?? 'Pelanggan tidak diketahui'
                                }}</span>
                                <span class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-500">
                                    <span>{{ row.transaction_count }} bon</span>
                                    <span v-if="row.customer?.phone">· {{ row.customer.phone }}</span>
                                    <span
                                        class="rounded-full px-2 py-0.5 font-semibold"
                                        :class="DUE_TONE_CLASS[dueStatus(row.earliest_due_at, isPaidTab).tone]"
                                    >
                                        {{ dueStatus(row.earliest_due_at, isPaidTab).label }}
                                    </span>
                                </span>
                            </span>
                            <span class="shrink-0 text-right">
                                <span
                                    class="block text-sm font-bold"
                                    :class="row.overdue_count > 0 && !isPaidTab ? 'text-red-600' : 'text-slate-900'"
                                >
                                    {{ rupiah(isPaidTab ? row.debt_total : row.outstanding_total) }}
                                </span>
                                <span class="block text-xs text-slate-400">{{ isPaidTab ? 'total utang' : 'sisa utang' }}</span>
                            </span>
                        </Link>
                        <a
                            v-if="waLink(row)"
                            :href="waLink(row)!"
                            target="_blank"
                            rel="noopener"
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-slate-200 text-emerald-600 hover:bg-emerald-50"
                            :aria-label="`Ingatkan ${row.customer?.name} via WhatsApp`"
                            title="Ingatkan via WhatsApp"
                        >
                            <MessageCircle class="h-4 w-4" />
                        </a>
                        <span v-else class="h-9 w-9 shrink-0" aria-hidden="true" />
                    </li>
                </ul>

                <!-- Halaman -->
                <div v-if="customers.last_page > 1" class="flex items-center justify-between border-t border-slate-100 px-4 py-3">
                    <p class="text-xs text-slate-500">{{ customers.from }}–{{ customers.to }} dari {{ customers.total }} pelanggan</p>
                    <div class="flex gap-1">
                        <button
                            type="button"
                            class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 disabled:opacity-40"
                            :disabled="customers.current_page <= 1"
                            aria-label="Sebelumnya"
                            @click="visit({ page: customers.current_page - 1 })"
                        >
                            <ChevronLeft class="h-4 w-4" />
                        </button>
                        <button
                            type="button"
                            class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 disabled:opacity-40"
                            :disabled="customers.current_page >= customers.last_page"
                            aria-label="Berikutnya"
                            @click="visit({ page: customers.current_page + 1 })"
                        >
                            <ChevronRight class="h-4 w-4" />
                        </button>
                    </div>
                </div>
            </section>
        </div>
    </AdminLayout>
</template>
