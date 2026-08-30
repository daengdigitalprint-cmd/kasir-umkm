<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Truck, Plus, ArrowRight, Undo2, Trash2 } from 'lucide-vue-next';
import { confirmDelete, toastSuccess } from '@/lib/swal';
import { ref } from 'vue';

interface PurchaseRow {
    id: number;
    purchase_number: string;
    purchased_at: string | null;
    notes: string | null;
    lines_count: number;
    supplier_name?: string | null;
    user_name?: string | null;
}

defineProps<{
    purchases: PurchaseRow[];
    store: { id: number; name: string; currency: string };
}>();

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
                        Catat pembelian barang — stok produk bertambah dan tercatat di riwayat stok (Fase B5).
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

            <Card class="rounded-2xl border-slate-200 shadow-sm">
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
                </CardHeader>
                <CardContent>
                    <div v-if="purchases.length === 0" class="text-center py-12 text-slate-500">
                        <p>Belum ada pembelian.</p>
                        <Button as-child variant="outline" class="mt-4 rounded-xl">
                            <Link href="/purchases/create">Tambah pembelian pertama</Link>
                        </Button>
                    </div>
                    <div v-else class="overflow-x-auto rounded-xl border border-slate-100">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b bg-slate-50 text-left text-xs font-bold text-slate-600 uppercase tracking-wide">
                                    <th class="p-3">Nomor</th>
                                    <th class="p-3">Tanggal</th>
                                    <th class="p-3">Supplier</th>
                                    <th class="p-3">Jumlah Produk</th>
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
