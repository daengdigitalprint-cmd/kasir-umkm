<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { ArrowLeft, Printer, Undo2 } from 'lucide-vue-next';

interface LineRow {
    id: number;
    product_name: string | null;
    sku: string | null;
    unit: string | null;
    variant_name: string | null;
    quantity: number | string;
    product_batch_id: number | null;
    batch_code: string | null;
    batch_expiry_date: string | null;
}

const props = defineProps<{
    purchaseReturn: {
        id: number;
        return_number: string;
        returned_at: string | null;
        notes: string | null;
        supplier: { id: number; name: string; phone: string | null } | null;
        user_name: string | null;
        purchase: {
            id: number;
            purchase_number: string;
            purchased_at: string | null;
        } | null;
        lines: LineRow[];
    };
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

function printPage() {
    window.print();
}
</script>

<template>
    <Head :title="`Retur ${purchaseReturn.return_number}`" />

    <AdminLayout :title="`Retur ${purchaseReturn.return_number}`">
        <div class="mx-auto space-y-6 pb-8 print:max-w-none" style="max-width: 90rem">
            <div class="flex flex-wrap items-center justify-between gap-3 print:hidden">
                <div class="flex flex-wrap items-center gap-2">
                    <Button variant="outline" size="sm" as-child class="rounded-xl">
                        <Link href="/purchase-returns" class="inline-flex items-center gap-2">
                            <ArrowLeft class="h-4 w-4" />
                            Daftar retur
                        </Link>
                    </Button>
                    <Button type="button" variant="outline" size="sm" class="rounded-xl" @click="printPage">
                        <Printer class="h-4 w-4 mr-1" />
                        Cetak
                    </Button>
                </div>
            </div>

            <div class="print:block">
                <p class="text-center text-sm text-slate-600 mb-2 hidden print:block">{{ store.name }}</p>
                <h1 class="text-center text-lg font-black text-slate-900 print:text-xl print:mb-4">
                    Retur ke supplier
                </h1>
            </div>

            <Card class="rounded-2xl border-slate-200 shadow-sm print:shadow-none print:border">
                <CardHeader>
                    <div class="flex items-start gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-orange-500/10 print:hidden">
                            <Undo2 class="h-5 w-5 text-orange-800" />
                        </div>
                        <div>
                            <CardTitle class="font-mono">{{ purchaseReturn.return_number }}</CardTitle>
                            <CardDescription>{{ formatDateTime(purchaseReturn.returned_at) }}</CardDescription>
                        </div>
                    </div>
                </CardHeader>
                <CardContent class="space-y-4 text-sm">
                    <div class="grid gap-2 sm:grid-cols-2">
                        <div>
                            <p class="text-xs font-bold text-slate-500 uppercase">Supplier</p>
                            <p class="text-slate-900">{{ purchaseReturn.supplier?.name || '—' }}</p>
                            <p v-if="purchaseReturn.supplier?.phone" class="text-slate-600">{{ purchaseReturn.supplier.phone }}</p>
                        </div>
                        <div>
                            <p class="text-xs font-bold text-slate-500 uppercase">Dicatat oleh</p>
                            <p class="text-slate-900">{{ purchaseReturn.user_name || '—' }}</p>
                        </div>
                    </div>
                    <div v-if="purchaseReturn.purchase" class="rounded-xl border border-amber-100 bg-amber-50/50 p-3 print:border-amber-200">
                        <p class="text-xs font-bold text-amber-900 uppercase mb-1">Pembelian sumber</p>
                        <p class="font-mono font-semibold text-amber-950">{{ purchaseReturn.purchase.purchase_number }}</p>
                        <p class="text-xs text-amber-900/90">
                            {{ formatDateTime(purchaseReturn.purchase.purchased_at) }}
                        </p>
                        <Button variant="link" class="h-auto p-0 text-amber-900 print:hidden" as-child>
                            <Link :href="`/purchases/${purchaseReturn.purchase.id}`">Buka detail pembelian</Link>
                        </Button>
                    </div>
                    <div v-if="purchaseReturn.notes" class="rounded-xl bg-slate-50 p-3 text-slate-700 print:border">
                        <p class="text-xs font-bold text-slate-500 uppercase mb-1">Catatan</p>
                        {{ purchaseReturn.notes }}
                    </div>
                </CardContent>
            </Card>

            <Card class="rounded-2xl border-slate-200 shadow-sm print:shadow-none print:border">
                <CardHeader>
                    <CardTitle class="text-base">Baris retur</CardTitle>
                    <CardDescription>Kuantitas yang dikeluarkan dari stok</CardDescription>
                </CardHeader>
                <CardContent class="overflow-x-auto rounded-xl border border-slate-100 print:border-0">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b bg-slate-50 text-left text-xs font-bold text-slate-600 uppercase print:bg-transparent">
                                <th class="p-3">Produk</th>
                                <th class="p-3">Varian</th>
                                <th class="p-3 w-24">Qty</th>
                                <th class="p-3">Batch / ED</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="line in purchaseReturn.lines"
                                :key="line.id"
                                class="border-b border-slate-100 last:border-0"
                            >
                                <td class="p-3">
                                    <span class="font-medium text-slate-900">{{ line.product_name || '—' }}</span>
                                    <span v-if="line.sku" class="block text-xs text-slate-500 font-mono">{{ line.sku }}</span>
                                </td>
                                <td class="p-3 text-slate-600">{{ line.variant_name || '—' }}</td>
                                <td class="p-3 tabular-nums">
                                    {{ line.quantity }}
                                    <span v-if="line.unit" class="text-slate-500 text-xs">{{ line.unit }}</span>
                                </td>
                                <td class="p-3 text-slate-600">
                                    <template v-if="line.product_batch_id">
                                        <span v-if="line.batch_code">#{{ line.batch_code }}</span>
                                        <span v-else class="font-mono text-xs">Batch #{{ line.product_batch_id }}</span>
                                        <span v-if="line.batch_expiry_date" class="block text-xs">ED {{ line.batch_expiry_date }}</span>
                                    </template>
                                    <span v-else>—</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </CardContent>
            </Card>
        </div>
    </AdminLayout>
</template>
