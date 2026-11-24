<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { ArrowDownLeft, ArrowLeft, ArrowUpRight } from 'lucide-vue-next';
import { computed, ref } from 'vue';

type Direction = 'income' | 'expense';

interface Row {
    id: number;
    request_number: string;
    status_label: string;
    store: string | null;
    lines_count: number;
    total_amount: number;
    payment_status: string;
    approved_at: string | null;
    paid_at: string | null;
}

const props = defineProps<{
    store: { id: number; name: string; is_central_warehouse: boolean };
    /** income = pemasukan dari lokasi yang membeli ke sini; expense = pengeluaran pembelian stok lokasi ini. */
    direction: Direction;
    filters: { from: string; to: string; store_id: number | null; payment: string | null };
    /** total_cost & gross_profit hanya ada di sisi pemasukan dan untuk pengelola yang boleh melihat HPP. */
    summary: { count: number; total: number; paid: number; unpaid: number; total_cost?: number; gross_profit?: number };
    byStore: { store_id: number; name: string; count: number; total: number; paid: number; unpaid: number }[];
    rows: { data: Row[]; links: { url: string | null; label: string; active: boolean }[] };
    stores: { id: number; name: string }[];
}>();

const isIncome = computed(() => props.direction === 'income');
const rupiah = (n: number) => 'Rp' + new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(n);
const day = (iso: string | null) => (iso ? new Date(iso).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—');

const from = ref(props.filters.from);
const to = ref(props.filters.to);
const storeId = ref<number | ''>(props.filters.store_id ?? '');
const payment = ref(props.filters.payment ?? '');

function apply(overrides: Record<string, unknown> = {}) {
    router.get(
        '/stock-requests/report',
        {
            direction: props.direction,
            from: from.value,
            to: to.value,
            store_id: storeId.value === '' ? undefined : storeId.value,
            payment: payment.value || undefined,
            ...overrides,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}
</script>

<template>
    <Head title="Laporan Jual-Beli Antar Lokasi" />

    <AdminLayout title="Laporan Jual-Beli Antar Lokasi">
        <div class="mx-auto max-w-6xl space-y-6 pb-8">
            <Button as-child variant="outline" size="sm" class="gap-2 rounded-xl">
                <Link href="/stock-requests">
                    <ArrowLeft class="h-4 w-4" />
                    Kembali
                </Link>
            </Button>

            <div>
                <h1 class="text-2xl font-black text-slate-900">Laporan jual-beli antar lokasi</h1>
                <p class="mt-1 text-sm text-slate-600">
                    Hanya permintaan berjenis "Beli ke gudang / CK" yang barangnya sudah dikirim. Transfer stok biasa tidak masuk laporan ini.
                </p>
            </div>

            <!-- Sisi laporan -->
            <div class="inline-flex flex-wrap rounded-xl bg-slate-100 p-1">
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold"
                    :class="isIncome ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600'"
                    @click="apply({ direction: 'income', store_id: undefined, page: undefined })"
                >
                    <ArrowDownLeft class="h-4 w-4 text-emerald-600" /> Pemasukan dari lokasi lain
                </button>
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold"
                    :class="!isIncome ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600'"
                    @click="apply({ direction: 'expense', store_id: undefined, page: undefined })"
                >
                    <ArrowUpRight class="h-4 w-4 text-red-600" /> Pengeluaran pembelian stok
                </button>
            </div>

            <!-- Saringan -->
            <div class="flex flex-wrap items-end gap-3 rounded-2xl border border-slate-200 bg-white p-4 text-sm">
                <label class="space-y-1 font-semibold text-slate-700">
                    Dari
                    <input v-model="from" type="date" class="block h-9 rounded-lg border border-slate-200 px-2 font-normal" />
                </label>
                <label class="space-y-1 font-semibold text-slate-700">
                    Sampai
                    <input v-model="to" type="date" class="block h-9 rounded-lg border border-slate-200 px-2 font-normal" />
                </label>
                <label class="space-y-1 font-semibold text-slate-700">
                    {{ isIncome ? 'Pembeli' : 'Dibeli dari' }}
                    <select v-model="storeId" class="block h-9 rounded-lg border border-slate-200 px-2 font-normal">
                        <option value="">Semua lokasi</option>
                        <option v-for="s in stores" :key="s.id" :value="s.id">{{ s.name }}</option>
                    </select>
                </label>
                <label class="space-y-1 font-semibold text-slate-700">
                    Pembayaran
                    <select v-model="payment" class="block h-9 rounded-lg border border-slate-200 px-2 font-normal">
                        <option value="">Semua</option>
                        <option value="unpaid">Belum dibayar</option>
                        <option value="paid">Lunas</option>
                    </select>
                </label>
                <Button class="rounded-xl" @click="apply({ page: undefined })">Terapkan</Button>
            </div>

            <!-- Ringkasan -->
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" data-testid="report-summary">
                <Card class="rounded-2xl border-slate-200 shadow-sm">
                    <CardContent class="pt-5">
                        <p class="text-xs font-semibold text-slate-500">{{ isIncome ? 'Total pemasukan' : 'Total pengeluaran' }}</p>
                        <p class="mt-1 text-2xl font-black tabular-nums" :class="isIncome ? 'text-emerald-700' : 'text-red-700'">{{ rupiah(summary.total) }}</p>
                        <p class="mt-0.5 text-xs text-slate-500">{{ summary.count }} dokumen</p>
                    </CardContent>
                </Card>
                <Card class="rounded-2xl border-slate-200 shadow-sm">
                    <CardContent class="pt-5">
                        <p class="text-xs font-semibold text-slate-500">{{ isIncome ? 'Sudah diterima' : 'Sudah dibayar' }}</p>
                        <p class="mt-1 text-2xl font-black tabular-nums text-slate-900">{{ rupiah(summary.paid) }}</p>
                    </CardContent>
                </Card>
                <Card class="rounded-2xl border-slate-200 shadow-sm">
                    <CardContent class="pt-5">
                        <p class="text-xs font-semibold text-slate-500">{{ isIncome ? 'Piutang (belum dibayar)' : 'Hutang (belum dibayar)' }}</p>
                        <p class="mt-1 text-2xl font-black tabular-nums text-amber-700">{{ rupiah(summary.unpaid) }}</p>
                    </CardContent>
                </Card>
                <Card v-if="summary.gross_profit !== undefined" class="rounded-2xl border-slate-200 shadow-sm">
                    <CardContent class="pt-5">
                        <p class="text-xs font-semibold text-slate-500">Laba kotor</p>
                        <p class="mt-1 text-2xl font-black tabular-nums text-slate-900">{{ rupiah(summary.gross_profit) }}</p>
                        <p class="mt-0.5 text-xs text-slate-500">HPP {{ rupiah(summary.total_cost ?? 0) }}</p>
                    </CardContent>
                </Card>
            </div>

            <!-- Per lokasi -->
            <Card v-if="byStore.length" class="rounded-2xl border-slate-200 shadow-sm">
                <CardContent class="pt-6">
                    <h2 class="mb-3 text-sm font-bold text-slate-900">{{ isIncome ? 'Per lokasi pembeli' : 'Per lokasi penjual' }}</h2>
                    <div class="overflow-x-auto rounded-xl border border-slate-100">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b bg-slate-50 text-left text-xs font-bold tracking-wide text-slate-600 uppercase">
                                    <th class="p-3">Lokasi</th>
                                    <th class="p-3 text-right">Dokumen</th>
                                    <th class="p-3 text-right">Total</th>
                                    <th class="p-3 text-right">Lunas</th>
                                    <th class="p-3 text-right">Belum dibayar</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="s in byStore" :key="s.store_id" class="border-b border-slate-100 last:border-0">
                                    <td class="p-3 font-medium text-slate-900">{{ s.name }}</td>
                                    <td class="p-3 text-right tabular-nums">{{ s.count }}</td>
                                    <td class="p-3 text-right font-semibold tabular-nums">{{ rupiah(s.total) }}</td>
                                    <td class="p-3 text-right tabular-nums text-emerald-700">{{ rupiah(s.paid) }}</td>
                                    <td class="p-3 text-right tabular-nums" :class="s.unpaid > 0 ? 'font-semibold text-amber-700' : 'text-slate-400'">{{ rupiah(s.unpaid) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </CardContent>
            </Card>

            <!-- Rincian dokumen -->
            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardContent class="pt-6">
                    <h2 class="mb-3 text-sm font-bold text-slate-900">Rincian</h2>
                    <div v-if="rows.data.length === 0" class="py-12 text-center text-sm text-slate-500">
                        {{ isIncome ? 'Belum ada lokasi yang membeli ke sini pada periode ini.' : 'Belum ada pembelian stok ke lokasi lain pada periode ini.' }}
                    </div>
                    <div v-else class="overflow-x-auto rounded-xl border border-slate-100">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b bg-slate-50 text-left text-xs font-bold tracking-wide text-slate-600 uppercase">
                                    <th class="p-3">Tanggal</th>
                                    <th class="p-3">Nomor</th>
                                    <th class="p-3">{{ isIncome ? 'Pembeli' : 'Dibeli dari' }}</th>
                                    <th class="p-3">Status barang</th>
                                    <th class="p-3 text-right">Total</th>
                                    <th class="p-3">Pembayaran</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="r in rows.data" :key="r.id" class="border-b border-slate-100 last:border-0 hover:bg-slate-50/80">
                                    <td class="p-3 text-slate-600">{{ day(r.approved_at) }}</td>
                                    <td class="p-3 font-mono font-semibold">
                                        <Link :href="`/stock-requests/${r.id}`" class="text-teal-700 hover:underline">{{ r.request_number }}</Link>
                                    </td>
                                    <td class="p-3">{{ r.store }}</td>
                                    <td class="p-3 text-slate-600">{{ r.status_label }} · {{ r.lines_count }} barang</td>
                                    <td class="p-3 text-right font-semibold tabular-nums">{{ rupiah(r.total_amount) }}</td>
                                    <td class="p-3">
                                        <span
                                            class="rounded-full px-2.5 py-1 text-xs font-semibold"
                                            :class="r.payment_status === 'paid' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800'"
                                        >
                                            {{ r.payment_status === 'paid' ? 'Lunas' : 'Belum dibayar' }}
                                        </span>
                                        <span v-if="r.paid_at" class="ml-1 text-xs text-slate-500">{{ day(r.paid_at) }}</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-if="rows.links.length > 3" class="mt-4 flex flex-wrap gap-1">
                        <Link
                            v-for="(link, i) in rows.links"
                            :key="i"
                            :href="link.url ?? ''"
                            class="rounded-lg px-3 py-1.5 text-xs"
                            :class="[link.active ? 'bg-teal-600 text-white' : 'bg-slate-100 text-slate-700', !link.url ? 'pointer-events-none opacity-40' : '']"
                            preserve-scroll
                        >
                            <span v-html="link.label" />
                        </Link>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AdminLayout>
</template>
