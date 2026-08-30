<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ArrowLeft, Shirt } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { confirmDialog } from '@/lib/swal';

interface Line {
    id: number;
    product_id: number | null;
    service_name: string;
    unit: string | null;
    quantity: number;
    unit_price: number;
    line_total: number;
    notes: string | null;
}

interface Order {
    id: number;
    order_number: string;
    status: string;
    payment_status: string;
    customer_name?: string | null;
    customer_phone?: string | null;
    received_at: string | null;
    estimated_ready_at: string | null;
    ready_at: string | null;
    completed_at: string | null;
    total_amount: number;
    amount_paid: number;
    payment_method?: string | null;
    notes?: string | null;
    user_name?: string | null;
    created_at: string | null;
    customer?: { id: number; name: string; phone: string | null } | null;
    transaction?: { id: number; transaction_number: string } | null;
    lines: Line[];
}

const props = defineProps<{
    order: Order;
    statuses: string[];
    store: { id: number; name: string; currency: string };
}>();

const isClosed = computed(() => ['completed', 'cancelled'].includes(props.order.status));
const balance = computed(() => Math.max(0, props.order.total_amount - props.order.amount_paid));

const statusLabels: Record<string, string> = {
    received: 'Diterima',
    processing: 'Dicuci',
    ready: 'Siap Diambil',
    completed: 'Selesai (diambil)',
    cancelled: 'Dibatalkan',
};

function statusBadge(status: string) {
    switch (status) {
        case 'received':
            return { variant: 'secondary' as const, label: statusLabels[status] };
        case 'processing':
            return { variant: 'secondary' as const, label: statusLabels[status] };
        case 'ready':
            return { variant: 'default' as const, label: statusLabels[status] };
        case 'completed':
            return { variant: 'default' as const, label: statusLabels[status] };
        case 'cancelled':
            return { variant: 'outline' as const, label: statusLabels[status] };
        default:
            return { variant: 'outline' as const, label: status };
    }
}

function paymentBadge(status: string) {
    switch (status) {
        case 'paid':
            return { variant: 'default' as const, label: 'Lunas' };
        case 'partial':
            return { variant: 'secondary' as const, label: 'DP / Sebagian' };
        default:
            return { variant: 'outline' as const, label: 'Belum bayar' };
    }
}

function changeStatus(newStatus: string) {
    router.post(`/laundry-orders/${props.order.id}/status`, { status: newStatus }, { preserveScroll: true });
}

// Form pembayaran
const payAmount = ref<number>(balance.value);
const payMethod = ref<string>('cash');

// Isi cepat nominal DP berdasar persentase dari total order (dibulatkan, dibatasi sisa tagihan).
function setDpPercent(percent: number) {
    const dp = Math.round((props.order.total_amount * percent) / 100);
    payAmount.value = Math.min(dp, balance.value);
}

function setFullPayment() {
    payAmount.value = balance.value;
}

// Menentukan jenis pembayaran dari nominal yang diketik: lunas bila menutup sisa tagihan, selain itu DP.
const paymentKind = computed(() => {
    const amount = Number(payAmount.value) || 0;
    if (amount <= 0) return null;
    return amount + 0.009 >= balance.value ? 'full' : 'dp';
});

function recordPayment() {
    if (Number(payAmount.value) <= 0) return;
    router.post(
        `/laundry-orders/${props.order.id}/payment`,
        { amount: Number(payAmount.value), payment_method: payMethod.value },
        { preserveScroll: true },
    );
}

async function cancelOrder() {
    const ok = await confirmDialog('Batalkan order?', 'Order laundry ini akan ditandai dibatalkan. Tindakan ini tidak bisa dibatalkan.', {
        confirmText: 'Ya, batalkan',
        icon: 'warning',
        confirmColor: '#dc2626',
    });
    if (!ok) return;
    router.post(`/laundry-orders/${props.order.id}/cancel`, {}, { preserveScroll: true });
}

function formatMoney(n: number) {
    return new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(n);
}

function formatDateTime(d: string | null) {
    if (!d) return '—';
    return new Date(d).toLocaleString('id-ID', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}
</script>

<template>
    <AdminLayout title="Detail Order Laundry">
        <Head :title="`Order ${order.order_number}`" />

        <div class="mb-6 flex items-center gap-3">
            <Link href="/laundry-orders" class="text-slate-500 hover:text-slate-800">
                <ArrowLeft class="h-5 w-5" />
            </Link>
            <div>
                <h1 class="text-2xl font-bold text-slate-900 flex items-center gap-2">
                    <Shirt class="h-7 w-7 text-cyan-600" />
                    {{ order.order_number }}
                </h1>
                <div class="mt-1 flex items-center gap-2">
                    <Badge :variant="statusBadge(order.status).variant">{{ statusBadge(order.status).label }}</Badge>
                    <Badge :variant="paymentBadge(order.payment_status).variant">{{ paymentBadge(order.payment_status).label }}</Badge>
                </div>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="lg:col-span-2 space-y-6">
                <Card class="border-0 shadow-lg">
                    <CardHeader>
                        <CardTitle>Rincian layanan</CardTitle>
                    </CardHeader>
                    <CardContent class="overflow-x-auto">
                        <table class="w-full text-sm text-left">
                            <thead>
                                <tr class="border-b text-slate-500">
                                    <th class="pb-3 pr-3 font-medium">Layanan</th>
                                    <th class="pb-3 pr-3 font-medium text-right">Jumlah</th>
                                    <th class="pb-3 pr-3 font-medium text-right">Harga</th>
                                    <th class="pb-3 font-medium text-right">Subtotal</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="l in order.lines" :key="l.id" class="border-b border-slate-100">
                                    <td class="py-3 pr-3">
                                        <div class="font-medium text-slate-800">{{ l.service_name }}</div>
                                        <div v-if="l.notes" class="text-xs text-slate-500">{{ l.notes }}</div>
                                    </td>
                                    <td class="py-3 pr-3 text-right tabular-nums">{{ l.quantity }} {{ l.unit || '' }}</td>
                                    <td class="py-3 pr-3 text-right tabular-nums">Rp {{ formatMoney(l.unit_price) }}</td>
                                    <td class="py-3 text-right tabular-nums font-semibold">Rp {{ formatMoney(l.line_total) }}</td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="3" class="pt-3 pr-3 text-right font-semibold text-slate-600">Total</td>
                                    <td class="pt-3 text-right tabular-nums font-bold text-slate-900">Rp {{ formatMoney(order.total_amount) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </CardContent>
                </Card>

                <Card v-if="!isClosed" class="border-0 shadow-lg">
                    <CardHeader>
                        <CardTitle>Ubah status pengerjaan</CardTitle>
                        <CardDescription>Alur: Diterima → Dicuci → Siap Diambil → Selesai.</CardDescription>
                    </CardHeader>
                    <CardContent class="flex flex-wrap gap-2">
                        <Button
                            v-for="s in ['received', 'processing', 'ready', 'completed']"
                            :key="s"
                            type="button"
                            :variant="order.status === s ? 'default' : 'outline'"
                            size="sm"
                            class="rounded-lg"
                            :disabled="order.status === s"
                            @click="changeStatus(s)"
                        >
                            {{ statusLabels[s] }}
                        </Button>
                    </CardContent>
                </Card>
            </div>

            <div class="space-y-6">
                <Card class="border-0 shadow-lg">
                    <CardHeader>
                        <CardTitle>Informasi</CardTitle>
                    </CardHeader>
                    <CardContent class="space-y-2 text-sm">
                        <div class="flex justify-between">
                            <span class="text-slate-500">Pelanggan</span>
                            <span class="font-medium text-slate-800">{{ order.customer_name || order.customer?.name || '—' }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-500">No. HP</span>
                            <span class="text-slate-800">{{ order.customer_phone || order.customer?.phone || '—' }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-500">Diterima</span>
                            <span class="text-slate-800">{{ formatDateTime(order.received_at) }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-500">Estimasi selesai</span>
                            <span class="text-slate-800">{{ formatDateTime(order.estimated_ready_at) }}</span>
                        </div>
                        <div v-if="order.ready_at" class="flex justify-between">
                            <span class="text-slate-500">Siap diambil</span>
                            <span class="text-slate-800">{{ formatDateTime(order.ready_at) }}</span>
                        </div>
                        <div v-if="order.completed_at" class="flex justify-between">
                            <span class="text-slate-500">Diambil</span>
                            <span class="text-slate-800">{{ formatDateTime(order.completed_at) }}</span>
                        </div>
                        <div v-if="order.transaction" class="flex justify-between">
                            <span class="text-slate-500">No. transaksi</span>
                            <span class="font-mono text-xs text-slate-800">{{ order.transaction.transaction_number }}</span>
                        </div>
                        <div v-if="order.notes" class="pt-2 text-xs text-slate-500">{{ order.notes }}</div>
                    </CardContent>
                </Card>

                <Card class="border-0 shadow-lg">
                    <CardHeader>
                        <CardTitle>Pembayaran</CardTitle>
                    </CardHeader>
                    <CardContent class="space-y-3 text-sm">
                        <div class="flex justify-between">
                            <span class="text-slate-500">Total</span>
                            <span class="tabular-nums font-semibold text-slate-900">Rp {{ formatMoney(order.total_amount) }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-500">Dibayar</span>
                            <span class="tabular-nums text-emerald-600">Rp {{ formatMoney(order.amount_paid) }}</span>
                        </div>
                        <div class="flex justify-between border-t pt-2">
                            <span class="text-slate-500">Sisa</span>
                            <span class="tabular-nums font-bold text-slate-900">Rp {{ formatMoney(balance) }}</span>
                        </div>

                        <div v-if="balance > 0 && order.status !== 'cancelled'" class="space-y-2 border-t pt-3">
                            <div>
                                <Label class="text-xs">Catat pembayaran</Label>
                                <div class="mt-1 flex flex-wrap gap-1.5">
                                    <Button type="button" variant="outline" size="sm" class="rounded-lg text-xs" @click="setDpPercent(30)">DP 30%</Button>
                                    <Button type="button" variant="outline" size="sm" class="rounded-lg text-xs" @click="setDpPercent(50)">DP 50%</Button>
                                    <Button type="button" variant="outline" size="sm" class="rounded-lg text-xs" @click="setFullPayment">Lunas</Button>
                                </div>
                                <Input v-model.number="payAmount" type="number" step="1" min="0" class="mt-2" />
                                <p v-if="paymentKind" class="mt-1.5 text-xs" :class="paymentKind === 'full' ? 'text-emerald-600' : 'text-amber-600'">
                                    <span v-if="paymentKind === 'full'">Pembayaran lunas — sisa tagihan Rp 0.</span>
                                    <span v-else>Pembayaran DP / sebagian — sisa Rp {{ formatMoney(Math.max(0, balance - (Number(payAmount) || 0))) }}.</span>
                                </p>
                            </div>
                            <select v-model="payMethod" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                <option value="cash">Tunai</option>
                                <option value="card">Kartu</option>
                                <option value="ewallet">E-Wallet</option>
                                <option value="transfer">Transfer</option>
                                <option value="other">Lainnya</option>
                            </select>
                            <Button type="button" class="w-full rounded-xl" @click="recordPayment">
                                {{ paymentKind === 'full' ? 'Bayar lunas' : paymentKind === 'dp' ? 'Bayar DP' : 'Simpan pembayaran' }}
                            </Button>
                        </div>
                    </CardContent>
                </Card>

                <Button
                    v-if="!isClosed"
                    type="button"
                    variant="outline"
                    class="w-full rounded-xl text-red-600 hover:bg-red-50"
                    @click="cancelOrder"
                >
                    Batalkan order
                </Button>
            </div>
        </div>
    </AdminLayout>
</template>
