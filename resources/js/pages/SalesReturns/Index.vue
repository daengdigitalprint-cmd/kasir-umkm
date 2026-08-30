<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import ReportTabs from '@/components/ReportTabs.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

defineProps<{
    store: { id: number; name: string; currency: string };
    returns: Array<{
        id: number;
        return_number: string;
        returned_at: string | null;
        transaction_number: string | null;
        customer_name: string | null;
        total_amount: number;
        refund_method: string;
        return_type: string;
        user_name: string | null;
        items_count: number;
    }>;
}>();

const fmt = (v: number) => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(v);
const fmtDate = (v: string | null) => (v ? new Date(v).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' }) : '-');

const refundLabel: Record<string, string> = {
    cash: 'Tunai',
    original: 'Metode asli',
    store_credit: 'Saldo toko',
};
</script>

<template>
    <Head title="Retur penjualan" />
    <AdminLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-semibold text-slate-900">Retur Penjualan</h2>
                <Link href="/sales-returns/create"><Button size="sm">Buat Retur</Button></Link>
            </div>
        </template>
        <ReportTabs active="ringkasan" />
        <div class="mt-4">
            <Card>
                <CardHeader><CardTitle>Riwayat retur penjualan</CardTitle></CardHeader>
                <CardContent>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                                    <th class="px-4 py-3">Nomor</th>
                                    <th class="px-4 py-3">Tanggal</th>
                                    <th class="px-4 py-3">Transaksi</th>
                                    <th class="px-4 py-3">Pelanggan</th>
                                    <th class="px-4 py-3">Item</th>
                                    <th class="px-4 py-3">Refund</th>
                                    <th class="px-4 py-3">Metode</th>
                                    <th class="px-4 py-3">Oleh</th>
                                    <th class="px-4 py-3" />
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="r in returns" :key="r.id" class="border-b last:border-0 hover:bg-slate-50">
                                    <td class="px-4 py-3 font-medium">{{ r.return_number }}</td>
                                    <td class="px-4 py-3">{{ fmtDate(r.returned_at) }}</td>
                                    <td class="px-4 py-3">{{ r.transaction_number ?? '-' }}</td>
                                    <td class="px-4 py-3">{{ r.customer_name ?? '-' }}</td>
                                    <td class="px-4 py-3">{{ r.items_count }}</td>
                                    <td class="px-4 py-3 font-semibold text-red-600">{{ fmt(r.total_amount) }}</td>
                                    <td class="px-4 py-3">{{ refundLabel[r.refund_method] ?? r.refund_method }}</td>
                                    <td class="px-4 py-3">{{ r.user_name ?? '-' }}</td>
                                    <td class="px-4 py-3 text-right">
                                        <Link :href="`/sales-returns/${r.id}`">
                                            <Button variant="ghost" size="sm">Detail</Button>
                                        </Link>
                                    </td>
                                </tr>
                                <tr v-if="!returns.length">
                                    <td colspan="9" class="px-4 py-8 text-center text-slate-500">Belum ada retur penjualan.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AdminLayout>
</template>
