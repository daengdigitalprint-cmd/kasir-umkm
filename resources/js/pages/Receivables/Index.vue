<script setup lang="ts">
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { CreditCard, AlertTriangle, ChevronRight, Search, User } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

interface CustomerSummary {
    customer: { id: number; name: string; phone?: string | null } | null;
    outstanding_total: number;
    transaction_count: number;
    earliest_due_at: string | null;
    overdue_count: number;
}

interface Props {
    customers: {
        data: CustomerSummary[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
        current_page: number;
        last_page: number;
        per_page: number;
        from: number | null;
        to: number | null;
    };
    filter: string;
    search: string;
    outstandingTotal: number;
    overdueCount: number;
    store: { id: number; name: string; currency: string };
}

const props = defineProps<Props>();

const page = usePage();
const receivablesEnabled = computed(() => Boolean((page.props as { receivables_enabled?: boolean }).receivables_enabled));

const formatCurrency = (amount: number) =>
    new Intl.NumberFormat('id-ID', { style: 'decimal', minimumFractionDigits: 0 }).format(amount);

const formatDate = (dateString: string | null) => {
    if (!dateString) return '-';
    return new Date(dateString).toLocaleDateString('id-ID', { year: 'numeric', month: 'short', day: 'numeric' });
};

const initials = (name: string) =>
    name.split(' ').filter(Boolean).slice(0, 2).map((w) => w[0]!.toUpperCase()).join('') || '?';

// ─── Filter & pencarian ─────────────────────────────────────────────────────
const searchInput = ref(props.search ?? '');

function setFilter(f: string) {
    router.get('/receivables', { status: f, search: searchInput.value || undefined }, { preserveScroll: true });
}

let searchTimer: ReturnType<typeof setTimeout> | null = null;
watch(searchInput, (value) => {
    if (searchTimer) clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        router.get('/receivables', { status: props.filter, search: value || undefined }, { preserveScroll: true, preserveState: true });
    }, 350);
});
</script>

<template>
    <AdminLayout title="Manajemen Piutang">
        <Head title="Piutang Pelanggan" />

        <div class="space-y-5 p-4">
            <!-- Modul nonaktif -->
            <div v-if="!receivablesEnabled" class="text-center py-16">
                <CreditCard class="h-14 w-14 mx-auto mb-4 text-slate-300" />
                <h2 class="text-lg font-bold text-slate-700 mb-2">Modul Hutang Piutang Nonaktif</h2>
                <p class="text-sm text-slate-500 max-w-md mx-auto">Modul ini belum diaktifkan. Silakan aktifkan melalui halaman <Link href="/profile?tab=store" class="text-primary underline">Pengaturan &rarr; Modul Fitur</Link>.</p>
            </div>
            <template v-else>
            <!-- Header -->
            <div class="flex items-center justify-between gap-3">
                <div>
                    <h1 class="text-xl font-black text-slate-900">Piutang Pelanggan</h1>
                    <p class="text-sm text-slate-400 mt-0.5">Daftar pelanggan yang masih memiliki hutang</p>
                </div>
            </div>

            <!-- Summary cards -->
            <div class="grid grid-cols-2 sm:grid-cols-2 gap-3">
                <Card class="rounded-2xl border border-orange-200 bg-orange-50 shadow-sm">
                    <CardContent class="p-4">
                        <p class="text-xs text-orange-600 mb-1 flex items-center gap-1">
                            <CreditCard class="h-3.5 w-3.5" /> Total Piutang
                        </p>
                        <p class="text-xl font-black text-orange-700">Rp {{ formatCurrency(outstandingTotal) }}</p>
                    </CardContent>
                </Card>
                <Card class="rounded-2xl border border-red-200 bg-red-50 shadow-sm">
                    <CardContent class="p-4">
                        <p class="text-xs text-red-600 mb-1 flex items-center gap-1">
                            <AlertTriangle class="h-3.5 w-3.5" /> Lewat Jatuh Tempo
                        </p>
                        <p class="text-xl font-black text-red-700">{{ overdueCount }} transaksi</p>
                    </CardContent>
                </Card>
            </div>

            <!-- Pencarian -->
            <div class="relative">
                <Search class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-slate-400" />
                <input
                    v-model="searchInput"
                    type="text"
                    placeholder="Cari nama atau no. HP pelanggan..."
                    class="w-full border border-gray-200 rounded-xl py-2.5 pl-9 pr-3 text-sm focus:outline-none focus:ring-2 focus:ring-primary/30"
                />
            </div>

            <!-- Filter tabs -->
            <div class="flex gap-2 border-b border-gray-200">
                <button
                    v-for="tab in [{ key: 'outstanding', label: 'Aktif' }, { key: 'all', label: 'Semua' }]"
                    :key="tab.key"
                    type="button"
                    :class="['px-4 py-2.5 text-sm font-medium border-b-2 transition-colors -mb-px', filter === tab.key ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700']"
                    @click="setFilter(tab.key)"
                >{{ tab.label }}</button>
            </div>

            <!-- Daftar pelanggan berutang -->
            <Card class="rounded-2xl border border-slate-200 shadow-sm">
                <CardContent class="p-0">
                    <div v-if="!customers?.data?.length" class="text-center py-12 text-slate-400">
                        <User class="h-10 w-10 mx-auto mb-3 opacity-30" />
                        <p class="text-sm font-medium">{{ search ? 'Pelanggan tidak ditemukan' : 'Tidak ada piutang aktif' }}</p>
                    </div>
                    <div v-else class="divide-y divide-slate-100">
                        <Link
                            v-for="row in (customers?.data ?? [])"
                            :key="row.customer?.id ?? `unknown-${row.transaction_count}`"
                            :href="row.customer ? `/receivables/customers/${row.customer.id}` : '#'"
                            class="flex items-center gap-3 px-4 py-3.5 hover:bg-slate-50 transition-colors"
                        >
                            <!-- Avatar inisial -->
                            <div
                                :class="[
                                    'h-10 w-10 rounded-full flex items-center justify-center text-sm font-black shrink-0',
                                    row.overdue_count > 0 ? 'bg-red-100 text-red-600' : 'bg-orange-100 text-orange-600',
                                ]"
                            >
                                {{ row.customer ? initials(row.customer.name) : '?' }}
                            </div>

                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="text-sm font-bold text-slate-800 truncate">
                                        {{ row.customer?.name ?? 'Pelanggan tidak diketahui' }}
                                    </span>
                                    <Badge v-if="row.overdue_count > 0" variant="destructive" class="text-xs shrink-0">
                                        <AlertTriangle class="h-2.5 w-2.5 mr-1" /> {{ row.overdue_count }} lewat tempo
                                    </Badge>
                                </div>
                                <p class="text-xs text-slate-500 mt-0.5">
                                    <span v-if="row.customer?.phone">{{ row.customer.phone }} · </span>
                                    {{ row.transaction_count }} transaksi
                                    <span v-if="row.earliest_due_at"> · Tempo terdekat: {{ formatDate(row.earliest_due_at) }}</span>
                                </p>
                            </div>

                            <div class="text-right shrink-0 flex items-center gap-1.5">
                                <div>
                                    <p class="text-sm font-black text-orange-600">Rp {{ formatCurrency(row.outstanding_total) }}</p>
                                    <p class="text-xs text-slate-400">sisa hutang</p>
                                </div>
                                <ChevronRight class="h-4 w-4 text-slate-300" />
                            </div>
                        </Link>
                    </div>

                    <!-- Pagination -->
                    <div v-if="(customers?.last_page ?? 0) > 1" class="flex items-center justify-between px-4 py-3 border-t border-slate-100">
                        <p class="text-xs text-slate-400">
                            {{ customers.from }}–{{ customers.to }} dari {{ customers.total }}
                        </p>
                        <div class="flex gap-1">
                            <button
                                v-for="link in (customers.links ?? [])"
                                :key="link.label"
                                :disabled="!link.url"
                                type="button"
                                :class="['px-2.5 py-1 rounded-lg text-xs border transition-colors', link.active ? 'bg-primary text-white border-primary' : 'border-gray-200 text-gray-600 hover:bg-gray-50 disabled:opacity-40 disabled:cursor-not-allowed']"
                                @click="link.url && router.get(link.url)"
                                v-html="link.label"
                            ></button>
                        </div>
                    </div>
                </CardContent>
            </Card>
            </template>
        </div>
    </AdminLayout>
</template>
