<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

const props = defineProps<{
    store: { id: number; name: string; currency: string };
    return: {
        id: number;
        return_number: string;
        return_type: string;
        refund_method: string;
        returned_at: string | null;
        total_amount: number;
        subtotal: number;
        notes: string | null;
        transaction: { id: number; transaction_number: string; completed_at: string | null; payment_method: string } | null;
        customer: { id: number; name: string; phone?: string | null } | null;
        user_name: string | null;
        lines: Array<{
            id: number;
            product_name: string;
            variant_label: string | null;
            quantity: number;
            unit_price: number;
            refund_amount: number;
        }>;
    };
}>();

/** `return` adalah reserved keyword JS — tidak boleh dipakai langsung di template. */
const salesReturn = computed(() => props.return);

const fmt = (v: number) => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(v);
const fmtDate = (v: string | null) => (v ? new Date(v).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' }) : '-');
const refundLabel: Record<string, string> = { cash: 'Tunai', original: 'Metode asli', store_credit: 'Saldo toko' };
</script>

<template>
    <Head title="Detail retur penjualan" />
    <AdminLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-slate-900">Retur {{ salesReturn.return_number }}</h2>
                    <p class="text-sm text-slate-500">{{ fmtDate(salesReturn.returned_at) }} · oleh {{ salesReturn.user_name ?? '-' }}</p>
                </div>
                <Link href="/sales-returns"><Button variant="ghost" size="sm">Kembali</Button></Link>
            </div>
        </template>

        <div class="mt-4 grid gap-4 lg:grid-cols-3">
            <Card class="lg:col-span-2">
                <CardHeader><CardTitle>Item yang diretur</CardTitle></CardHeader>
                <CardContent>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                                    <th class="px-4 py-3">Produk</th>
                                    <th class="px-4 py-3">Varian</th>
                                    <th class="px-4 py-3">Qty</th>
                                    <th class="px-4 py-3">Harga satuan</th>
                                    <th class="px-4 py-3 text-right">Refund</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="l in salesReturn.lines" :key="l.id" class="border-b last:border-0">
                                    <td class="px-4 py-3 font-medium">{{ l.product_name }}</td>
                                    <td class="px-4 py-3">{{ l.variant_label ?? '-' }}</td>
                                    <td class="px-4 py-3">{{ l.quantity }}</td>
                                    <td class="px-4 py-3">{{ fmt(l.unit_price) }}</td>
                                    <td class="px-4 py-3 text-right font-semibold text-red-600">{{ fmt(l.refund_amount) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </CardContent>
            </Card>

            <div class="space-y-4">
                <Card>
                    <CardHeader><CardTitle>Ringkasan</CardTitle></CardHeader>
                    <CardContent class="space-y-2 text-sm">
                        <div class="flex justify-between"><span class="text-slate-500">Transaksi asli</span><span class="font-medium">{{ salesReturn.transaction?.transaction_number ?? '-' }}</span></div>
                        <div class="flex justify-between"><span class="text-slate-500">Tipe retur</span><span class="font-medium capitalize">{{ salesReturn.return_type }}</span></div>
                        <div class="flex justify-between"><span class="text-slate-500">Metode refund</span><span class="font-medium">{{ refundLabel[salesReturn.refund_method] ?? salesReturn.refund_method }}</span></div>
                        <div class="flex justify-between"><span class="text-slate-500">Pelanggan</span><span class="font-medium">{{ salesReturn.customer?.name ?? '-' }}</span></div>
                        <div class="flex justify-between border-t pt-2"><span class="text-slate-500">Total refund</span><span class="text-base font-bold text-red-600">{{ fmt(salesReturn.total_amount) }}</span></div>
                    </CardContent>
                </Card>

                <Card v-if="salesReturn.notes">
                    <CardHeader><CardTitle>Catatan</CardTitle></CardHeader>
                    <CardContent><p class="text-sm text-slate-600">{{ salesReturn.notes }}</p></CardContent>
                </Card>
            </div>
        </div>
    </AdminLayout>
</template>
