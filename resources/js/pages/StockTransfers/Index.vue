<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { ArrowRightLeft, Plus, ArrowRight } from 'lucide-vue-next';

interface StoreTiny {
    id: number;
    name: string;
}

interface TransferRow {
    id: number;
    transfer_number: string;
    status: string;
    created_at: string;
    sent_at: string | null;
    received_at: string | null;
    cancelled_at: string | null;
    notes: string | null;
    lines_count: number;
    from_store: StoreTiny;
    to_store: StoreTiny;
    user: { id: number; name: string } | null;
}

interface Paginated<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    meta: Record<string, unknown>;
}

const props = withDefaults(
    defineProps<{
        transfers: Paginated<TransferRow>;
        currentStore: { id: number; name: string };
        canCreateTransfer?: boolean;
        statusFilter?: string | null;
    }>(),
    { canCreateTransfer: true, statusFilter: null },
);

const formatWhen = (iso: string) =>
    new Date(iso).toLocaleString('id-ID', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });

const roleLabel = (row: TransferRow) => {
    if (row.from_store.id === props.currentStore.id) return 'Keluar';
    if (row.to_store.id === props.currentStore.id) return 'Masuk';
    return '—';
};

const statusConfig: Record<string, { label: string; class: string }> = {
    draft: { label: 'Draft', class: 'bg-slate-100 text-slate-700' },
    sent: { label: 'Dikirim', class: 'bg-amber-100 text-amber-800' },
    received: { label: 'Diterima', class: 'bg-emerald-100 text-emerald-800' },
    cancelled: { label: 'Dibatalkan', class: 'bg-red-100 text-red-700' },
};

const statusFilters = [
    { value: '', label: 'Semua' },
    { value: 'draft', label: 'Draft' },
    { value: 'sent', label: 'Dikirim' },
    { value: 'received', label: 'Diterima' },
    { value: 'cancelled', label: 'Dibatalkan' },
];

function filterByStatus(status: string) {
    const params: Record<string, string> = {};
    if (status) params.status = status;
    router.get('/stock-transfers', params, { preserveState: true, replace: true });
}
</script>

<template>
    <Head title="Transfer stok" />

    <AdminLayout title="Transfer stok">
        <div class="mx-auto space-y-6 pb-8" style="max-width: 90rem">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-black text-slate-900">Transfer antar outlet</h1>
                    <p class="text-sm text-slate-600 mt-1">
                        Stok dikurangi saat dikirim dari <strong>{{ currentStore.name }}</strong>, dan bertambah saat diterima outlet tujuan.
                    </p>
                </div>
                <Button v-if="props.canCreateTransfer" as-child class="rounded-xl">
                    <Link href="/stock-transfers/create" class="inline-flex items-center gap-2">
                        <Plus class="h-4 w-4" />
                        Transfer baru
                        <ArrowRight class="h-4 w-4 opacity-80" />
                    </Link>
                </Button>
                <p
                    v-else
                    class="max-w-md rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900"
                >
                    Paket Starter tidak mencakup transfer antar outlet. Pilih paket GrowUp di
                    <Link href="/subscription" class="font-bold text-amber-950 underline">Langganan</Link>.
                </p>
            </div>

            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-violet-500/10">
                                <ArrowRightLeft class="h-5 w-5 text-violet-700" />
                            </div>
                            <div>
                                <CardTitle>Riwayat transfer</CardTitle>
                                <CardDescription>Transfer yang melibatkan toko aktif Anda</CardDescription>
                            </div>
                        </div>
                    </div>
                </CardHeader>
                <CardContent>
                    <!-- Filter status -->
                    <div class="flex flex-wrap gap-2 mb-4">
                        <button
                            v-for="f in statusFilters"
                            :key="f.value"
                            class="rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors"
                            :class="
                                statusFilter === f.value || (!statusFilter && f.value === '')
                                    ? 'bg-violet-100 text-violet-800'
                                    : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
                            "
                            @click="filterByStatus(f.value)"
                        >
                            {{ f.label }}
                        </button>
                    </div>

                    <div v-if="transfers.data.length === 0" class="text-center py-12 text-slate-500">
                        <p>Belum ada transfer.</p>
                        <Button as-child variant="outline" class="mt-4 rounded-xl">
                            <Link href="/stock-transfers/create">Buat transfer pertama</Link>
                        </Button>
                    </div>
                    <div v-else class="overflow-x-auto rounded-xl border border-slate-100">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b bg-slate-50 text-left text-xs font-bold text-slate-600 uppercase tracking-wide">
                                    <th class="p-3">Nomor</th>
                                    <th class="p-3">Waktu</th>
                                    <th class="p-3">Dari → Ke</th>
                                    <th class="p-3">Status</th>
                                    <th class="p-3">Peran</th>
                                    <th class="p-3">Baris</th>
                                    <th class="p-3">Oleh</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="t in transfers.data"
                                    :key="t.id"
                                    class="border-b border-slate-100 last:border-0 hover:bg-slate-50/80"
                                >
                                    <td class="p-3 font-mono font-semibold text-slate-900">
                                        <Link :href="`/stock-transfers/${t.id}`" class="text-violet-700 hover:underline">
                                            {{ t.transfer_number }}
                                        </Link>
                                    </td>
                                    <td class="p-3 text-slate-600 whitespace-nowrap">{{ formatWhen(t.created_at) }}</td>
                                    <td class="p-3">
                                        <span class="text-slate-800">{{ t.from_store.name }}</span>
                                        <span class="text-slate-400 mx-1">→</span>
                                        <span class="text-slate-800">{{ t.to_store.name }}</span>
                                    </td>
                                    <td class="p-3">
                                        <span
                                            class="inline-flex rounded-lg px-2 py-0.5 text-xs font-bold"
                                            :class="statusConfig[t.status]?.class ?? 'bg-slate-100 text-slate-600'"
                                        >
                                            {{ statusConfig[t.status]?.label ?? t.status }}
                                        </span>
                                    </td>
                                    <td class="p-3">
                                        <span
                                            class="inline-flex rounded-lg px-2 py-0.5 text-xs font-bold"
                                            :class="
                                                roleLabel(t) === 'Keluar'
                                                    ? 'bg-amber-100 text-amber-900'
                                                    : 'bg-emerald-100 text-emerald-900'
                                            "
                                        >
                                            {{ roleLabel(t) }}
                                        </span>
                                    </td>
                                    <td class="p-3">{{ t.lines_count }}</td>
                                    <td class="p-3 text-slate-600">{{ t.user?.name ?? '—' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-if="transfers.links.length > 3" class="flex flex-wrap justify-center gap-1 pt-4">
                        <Link
                            v-for="(l, i) in transfers.links"
                            :key="i"
                            :href="l.url || '#'"
                            class="min-w-9 rounded-lg px-3 py-1.5 text-sm"
                            :class="
                                l.active
                                    ? 'bg-primary text-white'
                                    : l.url
                                      ? 'bg-slate-100 text-slate-700 hover:bg-slate-200'
                                      : 'cursor-not-allowed text-slate-300'
                            "
                            preserve-state
                        >
                            <span v-html="l.label" />
                        </Link>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AdminLayout>
</template>
