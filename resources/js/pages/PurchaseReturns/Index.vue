<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Undo2, Plus, ArrowRight } from 'lucide-vue-next';

interface ReturnRow {
    id: number;
    return_number: string;
    returned_at: string | null;
    notes: string | null;
    lines_count: number;
    supplier_name?: string | null;
    user_name?: string | null;
    purchase_id?: number | null;
    purchase_number?: string | null;
}

defineProps<{
    returns: ReturnRow[];
    store: { id: number; name: string; currency: string };
}>();

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
    <Head title="Retur ke supplier" />

    <AdminLayout title="Retur ke supplier">
        <div class="mx-auto space-y-6 pb-8" style="max-width: 90rem">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-black text-slate-900">Retur ke pemasok</h1>
                    <p class="text-sm text-slate-600 mt-1">
                        Catat pengembalian barang ke supplier — stok berkurang dan tercatat sebagai retur pembelian (Fase E3).
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button variant="outline" as-child class="rounded-xl">
                        <Link href="/purchases" class="inline-flex items-center gap-2"> Pembelian </Link>
                    </Button>
                    <Button as-child class="rounded-xl">
                        <Link href="/purchase-returns/create" class="inline-flex items-center gap-2">
                            <Plus class="h-4 w-4" />
                            Retur baru
                            <ArrowRight class="h-4 w-4 opacity-80" />
                        </Link>
                    </Button>
                </div>
            </div>

            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <div class="flex items-center gap-2">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-orange-500/10">
                            <Undo2 class="h-5 w-5 text-orange-800" />
                        </div>
                        <div>
                            <CardTitle>Riwayat retur</CardTitle>
                            <CardDescription>100 entri terakhir</CardDescription>
                        </div>
                    </div>
                </CardHeader>
                <CardContent>
                    <div v-if="returns.length === 0" class="text-center py-12 text-slate-500">
                        <p>Belum ada retur ke supplier.</p>
                        <Button as-child variant="outline" class="mt-4 rounded-xl">
                            <Link href="/purchase-returns/create">Catat retur pertama</Link>
                        </Button>
                    </div>
                    <div v-else class="overflow-x-auto rounded-xl border border-slate-100">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b bg-slate-50 text-left text-xs font-bold text-slate-600 uppercase tracking-wide">
                                    <th class="p-3">Nomor</th>
                                    <th class="p-3">Tanggal</th>
                                    <th class="p-3">Supplier</th>
                                    <th class="p-3">Pembelian</th>
                                    <th class="p-3">Baris</th>
                                    <th class="p-3">Oleh</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="r in returns"
                                    :key="r.id"
                                    class="border-b border-slate-100 last:border-0 hover:bg-slate-50/80"
                                >
                                    <td class="p-3 font-mono font-semibold text-slate-900">
                                        <Link
                                            :href="`/purchase-returns/${r.id}`"
                                            class="text-orange-800 hover:underline"
                                        >
                                            {{ r.return_number }}
                                        </Link>
                                    </td>
                                    <td class="p-3 text-slate-600">{{ formatDateTime(r.returned_at) }}</td>
                                    <td class="p-3">{{ r.supplier_name || '—' }}</td>
                                    <td class="p-3 font-mono text-xs">
                                        <Link
                                            v-if="r.purchase_id && r.purchase_number"
                                            :href="`/purchases/${r.purchase_id}`"
                                            class="text-teal-700 hover:underline"
                                        >
                                            {{ r.purchase_number }}
                                        </Link>
                                        <span v-else>—</span>
                                    </td>
                                    <td class="p-3">{{ r.lines_count }}</td>
                                    <td class="p-3 text-slate-600">{{ r.user_name || '—' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AdminLayout>
</template>
