<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { ClipboardList, Plus } from 'lucide-vue-next';

interface Row {
    id: number;
    order_number: string;
    status: string;
    expected_pickup_at: string | null;
    deposit_required: number;
    deposit_received: number;
    estimated_total: number;
    customer_name?: string | null;
    user_name?: string | null;
    lines_count: number;
    created_at: string | null;
}

defineProps<{
    preOrders: Row[];
    store: { id: number; name: string; currency: string };
}>();

function statusBadge(status: string) {
    switch (status) {
        case 'open':
            return { variant: 'secondary' as const, label: 'Terbuka' };
        case 'completed':
            return { variant: 'default' as const, label: 'Selesai' };
        case 'cancelled':
            return { variant: 'outline' as const, label: 'Batal' };
        default:
            return { variant: 'outline' as const, label: status };
    }
}

function formatMoney(n: number) {
    return new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(n);
}

function formatDate(d: string | null) {
    if (!d) return '—';
    return new Date(d + 'T12:00:00').toLocaleDateString('id-ID', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}
</script>

<template>
    <AdminLayout title="Pre-order">
        <Head title="Pre-order" />

        <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-slate-900 flex items-center gap-2">
                    <ClipboardList class="h-7 w-7 text-amber-600" />
                    Pre-order &amp; DP
                </h1>
                <p class="text-sm text-slate-600 mt-1">{{ store.name }} — pesanan terjadwal &amp; cake custom (C4)</p>
            </div>
            <Button as="a" href="/pre-orders/create" class="rounded-xl inline-flex items-center gap-2 w-fit">
                <Plus class="h-4 w-4" />
                Buat pre-order
            </Button>
        </div>

        <Card class="border-0 shadow-lg">
            <CardHeader>
                <CardTitle>Daftar</CardTitle>
                <CardDescription>Pre-order terbuka bisa dilanjutkan di kasir untuk pembayaran final &amp; pengurangan stok.</CardDescription>
            </CardHeader>
            <CardContent class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead>
                        <tr class="border-b text-slate-500">
                            <th class="pb-3 pr-3 font-medium">No. pesanan</th>
                            <th class="pb-3 pr-3 font-medium">Status</th>
                            <th class="pb-3 pr-3 font-medium">Ambil</th>
                            <th class="pb-3 pr-3 font-medium">Pelanggan</th>
                            <th class="pb-3 pr-3 font-medium text-right">Estimasi</th>
                            <th class="pb-3 pr-3 font-medium text-right">DP</th>
                            <th class="pb-3 font-medium text-right">Baris</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="o in preOrders" :key="o.id" class="border-b border-slate-100">
                            <td class="py-3 pr-3">
                                <Link :href="`/pre-orders/${o.id}`" class="font-mono text-xs text-primary font-semibold hover:underline">
                                    {{ o.order_number }}
                                </Link>
                            </td>
                            <td class="py-3 pr-3">
                                <Badge :variant="statusBadge(o.status).variant">
                                    {{ statusBadge(o.status).label }}
                                </Badge>
                            </td>
                            <td class="py-3 pr-3 whitespace-nowrap">{{ formatDate(o.expected_pickup_at) }}</td>
                            <td class="py-3 pr-3">{{ o.customer_name || '—' }}</td>
                            <td class="py-3 pr-3 text-right tabular-nums">Rp {{ formatMoney(o.estimated_total) }}</td>
                            <td class="py-3 pr-3 text-right text-xs">
                                <span class="tabular-nums">Rp {{ formatMoney(o.deposit_received) }}</span>
                                <span class="text-slate-400"> / </span>
                                <span class="tabular-nums">Rp {{ formatMoney(o.deposit_required) }}</span>
                            </td>
                            <td class="py-3 text-right">{{ o.lines_count }}</td>
                        </tr>
                        <tr v-if="preOrders.length === 0">
                            <td colspan="7" class="py-12 text-center text-slate-500">Belum ada pre-order.</td>
                        </tr>
                    </tbody>
                </table>
            </CardContent>
        </Card>
    </AdminLayout>
</template>
