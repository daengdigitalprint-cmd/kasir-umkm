<script setup lang="ts">
import { Head, useForm, Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { ArrowLeft, ShoppingCart, ClipboardList } from 'lucide-vue-next';
import { computed } from 'vue';
import { confirmDelete } from '@/lib/swal';

interface Line {
    id: number;
    product_id: number;
    product_variant_id: number | null;
    quantity: number;
    unit_price: number;
    line_total: number;
    product_name: string;
}

const props = defineProps<{
    preOrder: {
        id: number;
        order_number: string;
        status: string;
        expected_pickup_at: string | null;
        deposit_required: number;
        deposit_received: number;
        estimated_total: number;
        notes: string | null;
        customer: { id: number; name: string } | null;
        user_name: string | null;
        created_at: string | null;
        lines: Line[];
        fulfilled_transaction: { id: number; transaction_number: string; total_amount: number } | null;
    };
    store: { id: number; name: string; currency: string };
}>();

const editForm = useForm({
    deposit_required: props.preOrder.deposit_required,
    deposit_received: props.preOrder.deposit_received,
    expected_pickup_at: props.preOrder.expected_pickup_at || '',
    notes: props.preOrder.notes || '',
});

const balanceHint = computed(() => {
    const est = props.preOrder.estimated_total;
    const rec = editForm.deposit_received;
    return Math.max(0, Math.round((est - rec) * 100) / 100);
});

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

function saveMeta() {
    editForm.put(`/pre-orders/${props.preOrder.id}`, { preserveScroll: true });
}

async function cancelOrder() {
    if (
        !(await confirmDelete(
            'Batalkan pre-order?',
            'Pesanan tidak bisa dilanjutkan ke kasir setelah dibatalkan.',
        ))
    ) {
        return;
    }
    router.post(`/pre-orders/${props.preOrder.id}/cancel`);
}
</script>

<template>
    <AdminLayout :title="preOrder.order_number">
        <Head :title="preOrder.order_number" />

        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <Button as="a" href="/pre-orders" variant="ghost" class="rounded-xl gap-2 mb-3 -ml-2">
                    <ArrowLeft class="h-4 w-4" />
                    Daftar
                </Button>
                <h1 class="text-2xl font-bold text-slate-900 flex flex-wrap items-center gap-2">
                    <ClipboardList class="h-7 w-7 text-amber-600" />
                    {{ preOrder.order_number }}
                    <Badge :variant="statusBadge(preOrder.status).variant">{{ statusBadge(preOrder.status).label }}</Badge>
                </h1>
                <p class="text-sm text-slate-600 mt-1">{{ store.name }}</p>
            </div>
            <div v-if="preOrder.status === 'open'" class="flex flex-wrap gap-2">
                <Button as="a" :href="`/pos?pre_order=${preOrder.id}`" class="rounded-xl gap-2 inline-flex items-center">
                    <ShoppingCart class="h-4 w-4" />
                    Buka di kasir
                </Button>
                <Button type="button" variant="destructive" class="rounded-xl" @click="cancelOrder">Batalkan</Button>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            <Card class="border-0 shadow-lg lg:col-span-2">
                <CardHeader>
                    <CardTitle>Item</CardTitle>
                    <CardDescription>Estimasi total saat pesanan dibuat: Rp {{ formatMoney(preOrder.estimated_total) }}</CardDescription>
                </CardHeader>
                <CardContent class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b text-slate-500 text-left">
                                <th class="pb-2 pr-2 font-medium">Produk</th>
                                <th class="pb-2 pr-2 font-medium text-right">Qty</th>
                                <th class="pb-2 pr-2 font-medium text-right">Harga</th>
                                <th class="pb-2 font-medium text-right">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="l in preOrder.lines" :key="l.id" class="border-b border-slate-100">
                                <td class="py-2 pr-2 font-medium">{{ l.product_name }}</td>
                                <td class="py-2 pr-2 text-right tabular-nums">{{ l.quantity }}</td>
                                <td class="py-2 pr-2 text-right tabular-nums">{{ formatMoney(l.unit_price) }}</td>
                                <td class="py-2 text-right tabular-nums">{{ formatMoney(l.line_total) }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-if="preOrder.fulfilled_transaction" class="mt-4 text-sm text-slate-600">
                        Transaksi selesai:
                        <Link
                            :href="`/invoice/${preOrder.fulfilled_transaction.id}`"
                            class="text-primary font-semibold hover:underline"
                        >
                            {{ preOrder.fulfilled_transaction.transaction_number }}
                        </Link>
                        (Rp {{ formatMoney(preOrder.fulfilled_transaction.total_amount) }})
                    </p>
                </CardContent>
            </Card>

            <Card class="border-0 shadow-lg">
                <CardHeader>
                    <CardTitle>DP &amp; jadwal</CardTitle>
                    <CardDescription>
                        Pelunasan dicatat saat transaksi kasir. Estimasi sisa referensi (acuan): Rp {{ formatMoney(balanceHint) }}
                    </CardDescription>
                </CardHeader>
                <CardContent class="space-y-4">
                    <template v-if="preOrder.status === 'open'">
                        <div class="space-y-2">
                            <Label>Tanggal ambil</Label>
                            <Input v-model="editForm.expected_pickup_at" type="date" class="h-11" />
                        </div>
                        <div class="space-y-2">
                            <Label>Target DP (Rp)</Label>
                            <Input v-model.number="editForm.deposit_required" type="number" min="0" step="1000" class="h-11" />
                        </div>
                        <div class="space-y-2">
                            <Label>DP diterima (Rp)</Label>
                            <Input v-model.number="editForm.deposit_received" type="number" min="0" step="1000" class="h-11" />
                        </div>
                        <div class="space-y-2">
                            <Label>Catatan</Label>
                            <Input v-model="editForm.notes" type="text" class="h-11" />
                        </div>
                        <Button type="button" class="w-full rounded-xl" :disabled="editForm.processing" @click="saveMeta">
                            {{ editForm.processing ? 'Menyimpan…' : 'Simpan perubahan' }}
                        </Button>
                    </template>
                    <template v-else>
                        <p class="text-sm text-slate-600">Ambil: {{ formatDate(preOrder.expected_pickup_at) }}</p>
                        <p class="text-sm text-slate-600">
                            DP: Rp {{ formatMoney(preOrder.deposit_received) }} / target Rp {{ formatMoney(preOrder.deposit_required) }}
                        </p>
                        <p v-if="preOrder.notes" class="text-sm text-slate-800">{{ preOrder.notes }}</p>
                    </template>
                    <p v-if="preOrder.customer" class="text-sm pt-2 border-t border-slate-100">
                        Pelanggan: <strong>{{ preOrder.customer.name }}</strong>
                    </p>
                    <p v-if="preOrder.user_name" class="text-xs text-slate-500">Dicatat oleh {{ preOrder.user_name }}</p>
                </CardContent>
            </Card>
        </div>
    </AdminLayout>
</template>
