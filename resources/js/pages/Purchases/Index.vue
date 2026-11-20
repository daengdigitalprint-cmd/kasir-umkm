<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Truck, Plus, ArrowRight, Undo2, Trash2 } from 'lucide-vue-next';
import { confirmDelete, toastSuccess } from '@/lib/swal';
import { DUE_TONE_CLASS, dueStatus, rupiah } from '@/composables/useReceivables';
import { computed, ref } from 'vue';

interface PurchaseRow {
    id: number;
    purchase_number: string;
    purchased_at: string | null;
    notes: string | null;
    lines_count: number;
    supplier_name?: string | null;
    user_name?: string | null;
    total_amount: number;
    amount_paid: number;
    is_paid: boolean;
    due_date: string | null;
}

const props = defineProps<{
    purchases: PurchaseRow[];
    status?: string;
    unpaidSummary?: { count: number; total: number; overdue: number };
    store: { id: number; name: string; currency: string };
}>();

const showUnpaid = (on: boolean) => router.get('/purchases', on ? { status: 'unpaid' } : {}, { preserveScroll: true, replace: true });
const isUnpaidTab = computed(() => props.status === 'unpaid');

const isDeletingId = ref<number | null>(null);

async function destroyPurchase(p: PurchaseRow) {
    const confirmed = await confirmDelete(`Pembelian ${p.purchase_number}`, 'Seluruh data pembelian, stok yang ditambahkan, dan jurnal terkait akan dihapus permanen.');
    if (!confirmed) return;
    isDeletingId.value = p.id;
    router.delete(`/purchases/${p.id}`, {
        preserveScroll: true,
        onFinish: () => { isDeletingId.value = null; },
        onSuccess: () => toastSuccess('Pembelian berhasil dihapus.'),
    });
}

const formatDateTime = (iso: string | null) => {
    if (!iso) return '—';
    return new Date(iso).toLocaleString('id-ID', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};
</script>

<template>
    <Head title="Pembelian" />

    <AdminLayout title="Pembelian">
        <div class="mx-auto space-y-6 pb-8" style="max-width: 90rem">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-black text-slate-900">Pembelian &amp; masuk stok</h1>
                    <p class="text-sm text-slate-600 mt-1">
                        Catat pembelian barang dari supplier. Stok bertambah otomatis, dan pembelian bayar tempo tercatat sebagai utang.
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button variant="outline" as-child class="rounded-xl">
                        <Link href="/suppliers" class="inline-flex items-center gap-2">
                            <Truck class="h-4 w-4" />
                            Kelola Supplier
                        </Link>
                    </Button>
                    <Button variant="outline" as-child class="rounded-xl">
                        <Link href="/purchase-returns" class="inline-flex items-center gap-2">
                            <Undo2 class="h-4 w-4" />
                            Retur supplier
                        </Link>
                    </Button>
                    <Button as-child class="rounded-xl">
                        <Link href="/purchases/create" class="inline-flex items-center gap-2">
                            <Plus class="h-4 w-4" />
                            Pembelian baru
                            <ArrowRight class="h-4 w-4 opacity-80" />
                        </Link>
                    </Button>
                </div>
            </div>

            <Card class="rounded-2xl border-slate-200 shadow-none">
                <CardHeader>
                    <div class="flex items-center gap-2">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-teal-500/10">
                            <Truck class="h-5 w-5 text-teal-700" />
                        </div>
                        <div>
                            <CardTitle>Riwayat pembelian</CardTitle>
                            <CardDescription>100 entri terakhir</CardDescription>
                        </div>
                    </div>
                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <button
                            type="button"
                            class="rounded-lg px-3 py-1.5 text-sm font-semibold"
                            :class="!isUnpaidTab ? 'bg-primary text-white' : 'text-slate-500 hover:bg-slate-100'"
                            @click="showUnpaid(false)"
                        >
                            Semua
                        </button>
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-semibold"
                            :class="isUnpaidTab ? 'bg-primary text-white' : 'text-slate-500 hover:bg-slate-100'"
                            @click="showUnpaid(true)"
                        >
                            Belum lunas
                            <span
                                v-if="unpaidSummary?.count"
                                class="rounded-full px-1.5 text-xs"
                                :class="isUnpaidTab ? 'bg-white/20' : 'bg-amber-100 text-amber-700'"
                                >{{ unpaidSummary.count }}</span
                            >
                        </button>
                        <p v-if="unpaidSummary?.count" class="text-sm text-slate-500">
                            Utang ke supplier <strong class="text-slate-800">{{ rupiah(unpaidSummary.total) }}</strong>
                            <span v-if="unpaidSummary.overdue" class="text-red-600"> · {{ unpaidSummary.overdue }} lewat tempo</span>
                            ·
                            <Link href="/reports/aging-payables" class="font-semibold text-primary hover:underline">Laporan utang usaha</Link>
                        </p>
                    </div>
                </CardHeader>
                <CardContent>
                    <div v-if="purchases.length === 0" class="text-center py-12 text-slate-500">
                        <p>{{ isUnpaidTab ? 'Tidak ada pembelian yang belum lunas.' : 'Belum ada pembelian.' }}</p>
                        <Button as-child variant="outline" class="mt-4 rounded-xl">
                            <Link href="/purchases/create">Tambah pembelian pertama</Link>
                        </Button>
                    </div>
                    <!-- HP: kartu per pembelian (tabel 8 kolom tidak muat di layar sempit) -->
                    <ul v-if="purchases.length > 0" class="space-y-2 md:hidden">
                        <li v-for="p in purchases" :key="'m-' + p.id" class="rounded-xl border border-slate-200 bg-white p-3">
                            <div class="flex items-start justify-between gap-3">
                                <Link :href="`/purchases/${p.id}`" class="min-w-0">
                                    <span class="block truncate font-mono text-sm font-semibold text-teal-700">{{ p.purchase_number }}</span>
                                    <span class="block truncate text-sm text-slate-800">{{ p.supplier_name || 'Tanpa supplier' }}</span>
                                </Link>
                                <span class="shrink-0 text-right">
                                    <span class="block text-sm font-bold text-slate-900">{{ rupiah(p.total_amount) }}</span>
                                    <span v-if="p.is_paid" class="mt-0.5 inline-block rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-700">Lunas</span>
                                    <span v-else class="mt-0.5 block text-[11px] font-semibold text-amber-700">Sisa {{ rupiah(p.total_amount - p.amount_paid) }}</span>
                                </span>
                            </div>
                            <div class="mt-2 flex items-center justify-between gap-2 text-xs text-slate-500">
                                <span class="min-w-0 truncate">
                                    {{ formatDateTime(p.purchased_at) }} · {{ p.lines_count }} barang<template v-if="p.user_name"> · {{ p.user_name }}</template>
                                </span>
                                <span v-if="!p.is_paid" class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="DUE_TONE_CLASS[dueStatus(p.due_date).tone]">
                                    {{ dueStatus(p.due_date).label }}
                                </span>
                            </div>
                        </li>
                    </ul>
                    <div v-if="purchases.length > 0" class="hidden overflow-x-auto rounded-xl border border-slate-100 md:block">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b bg-slate-50 text-left text-xs font-bold text-slate-600 uppercase tracking-wide">
                                    <th class="p-3">Nomor</th>
                                    <th class="p-3">Tanggal</th>
                                    <th class="p-3">Supplier</th>
                                    <th class="p-3">Jumlah Produk</th>
                                    <th class="p-3 text-right">Total</th>
                                    <th class="p-3">Pembayaran</th>
                                    <th class="p-3">Oleh</th>
                                    <th class="p-3 text-right">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="p in purchases"
                                    :key="p.id"
                                    class="border-b border-slate-100 last:border-0 hover:bg-slate-50/80"
                                >
                                    <td class="p-3 font-mono font-semibold text-slate-900">
                                        <Link :href="`/purchases/${p.id}`" class="text-teal-700 hover:underline">
                                            {{ p.purchase_number }}
                                        </Link>
                                    </td>
                                    <td class="p-3 text-slate-600">{{ formatDateTime(p.purchased_at) }}</td>
                                    <td class="p-3">{{ p.supplier_name || '—' }}</td>
                                    <td class="p-3">{{ p.lines_count }}</td>
                                    <td class="p-3 text-right font-semibold text-slate-900">{{ rupiah(p.total_amount) }}</td>
                                    <td class="p-3">
                                        <span v-if="p.is_paid" class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-700">Lunas</span>
                                        <span v-else class="flex flex-col gap-0.5">
                                            <span class="text-xs font-semibold text-amber-700">Sisa {{ rupiah(p.total_amount - p.amount_paid) }}</span>
                                            <span class="w-fit rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="DUE_TONE_CLASS[dueStatus(p.due_date).tone]">
                                                {{ dueStatus(p.due_date).label }}
                                            </span>
                                        </span>
                                    </td>
                                    <td class="p-3 text-slate-600">{{ p.user_name || '—' }}</td>
                                    <td class="p-3 text-right">
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            class="rounded-lg text-red-600 hover:bg-red-50 hover:text-red-700"
                                            :disabled="isDeletingId === p.id"
                                            @click.prevent.stop="destroyPurchase(p)"
                                        >
                                            <Trash2 class="h-4 w-4" />
                                            <span class="sr-only">Hapus pembelian {{ p.purchase_number }}</span>
                                        </Button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AdminLayout>
</template>
