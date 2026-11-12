<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { ClipboardList, ListChecks, CookingPot, PackageCheck, Printer } from 'lucide-vue-next';
import { usePrinter } from '@/composables/usePrinter';
import { buildKotPlainLines } from '@/lib/receiptPlainText';
import { receiptLinesToEscPos } from '@/lib/escPosEncode';
import { toastError, toastSuccess } from '@/lib/swal';

interface OrderItem {
    product_name: string;
    quantity: number | string;
    total_price: number;
}

interface Order {
    id: number;
    transaction_number: string;
    status: string;
    payment_status: string;
    table_reference: string | null;
    payment_method: string | null;
    total_amount: number;
    notes: string | null;
    created_at: string | null;
    items: OrderItem[];
}

const props = defineProps<{
    orders: { data: Order[] };
    stats: { active: number; pending: number; accepted: number; serving: number; ready: number; completed: number; cancelled: number };
    statusFilter: string;
}>();

const filter = (status: string) => router.get('/table-orders', { status }, { preserveState: true, preserveScroll: true });
const { isConnected: isPrinterConnected, isPrinting: isPrinterPrinting, initialize: initializePrinter, printEscPosBytes } = usePrinter();
const printKot = async (order: Order) => {
    const lines = buildKotPlainLines({
        storeName: 'Kasir UMKM by MaccaPOS',
        transactionNumber: order.transaction_number,
        tableReference: order.table_reference,
        items: order.items.map((item) => ({ product_name: item.product_name, quantity: item.quantity })),
        paperWidthMm: 58,
    });
    const result = await printEscPosBytes(receiptLinesToEscPos(lines), 'kitchen');
    if (result.success) toastSuccess('KOT berhasil dicetak ke printer thermal.');
    else toastError(result.error || 'KOT gagal dicetak.');
};
const accept = (order: Order) => router.post(`/table-orders/${order.id}/accept`);
const reject = (order: Order) => router.post(`/table-orders/${order.id}/reject`);
const complete = (order: Order) => router.post(`/table-orders/${order.id}/complete`);
const pay = (order: Order) => {
    if (!order.payment_method) return;
    router.post(`/table-orders/${order.id}/pay`, { payment_method: order.payment_method });
};
const paymentLabel = (method: string | null) => ({
    cash: 'Tunai',
    qris: 'QRIS',
    non_cash: 'Non-tunai',
    card: 'Kartu',
    ewallet: 'E-Wallet',
    transfer: 'Transfer',
}[method || ''] || method || 'Belum dipilih');
const money = (value: number) => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(value);
initializePrinter();
</script>

<template>
    <Head title="Pesanan Masuk" />
    <AdminLayout title="Pesanan Masuk">
        <div class="space-y-6 p-4 md:p-6">
            <div class="flex items-center gap-3">
                <ClipboardList class="h-7 w-7 text-primary" />
                <div>
                    <h2 class="text-xl font-bold">Pesanan Masuk</h2>
                    <p class="text-sm text-slate-500">Pesanan pelanggan dari Scan Meja.</p>
                </div>
            </div>

            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <Card><CardContent class="flex items-center gap-3 p-4"><ListChecks class="h-8 w-8 text-blue-600" /><div><p class="text-xs font-semibold text-slate-500">Baru</p><p class="mt-1 text-2xl font-bold">{{ props.stats.pending }}</p><p class="text-xs text-slate-400">menunggu konfirmasi</p></div></CardContent></Card>
                <Card><CardContent class="flex items-center gap-3 p-4"><PackageCheck class="h-8 w-8 text-orange-600" /><div><p class="text-xs font-semibold text-slate-500">Diterima / Siap</p><p class="mt-1 text-2xl font-bold">{{ props.stats.accepted }}</p><p class="text-xs text-slate-400">menunggu diserahkan</p></div></CardContent></Card>
                <Card><CardContent class="flex items-center gap-3 p-4"><ClipboardList class="h-8 w-8 text-emerald-600" /><div><p class="text-xs font-semibold text-slate-500">Selesai</p><p class="mt-1 text-2xl font-bold">{{ props.stats.completed }}</p><p class="text-xs text-slate-400">pesanan diserahkan</p></div></CardContent></Card>
                <Card><CardContent class="flex items-center gap-3 p-4"><CookingPot class="h-8 w-8 text-red-600" /><div><p class="text-xs font-semibold text-slate-500">Ditolak</p><p class="mt-1 text-2xl font-bold">{{ props.stats.cancelled }}</p><p class="text-xs text-slate-400">pesanan dibatalkan</p></div></CardContent></Card>
            </div>

            <div class="flex flex-wrap gap-2">
                <Button :variant="props.statusFilter === 'pending' ? 'default' : 'outline'" @click="filter('pending')">Baru ({{ props.stats.pending }})</Button>
                <Button :variant="props.statusFilter === 'accepted' ? 'default' : 'outline'" @click="filter('accepted')">Diterima / Siap ({{ props.stats.accepted }})</Button>
                <Button :variant="props.statusFilter === 'completed' ? 'default' : 'outline'" @click="filter('completed')">Selesai ({{ props.stats.completed }})</Button>
                <Button :variant="props.statusFilter === 'cancelled' ? 'default' : 'outline'" @click="filter('cancelled')">Ditolak ({{ props.stats.cancelled }})</Button>
            </div>

            <div v-if="props.orders.data.length" class="grid gap-4 lg:grid-cols-2">
                <Card v-for="order in props.orders.data" :key="order.id">
                    <CardHeader class="flex-row items-start justify-between space-y-0">
                        <div>
                            <CardTitle class="text-base">{{ order.transaction_number }}</CardTitle>
                            <p class="text-sm text-slate-500">Meja {{ order.table_reference || '-' }} · Pembayaran {{ paymentLabel(order.payment_method) }}</p>
                        </div>
                        <span class="rounded-full bg-orange-100 px-2 py-1 text-xs font-semibold text-orange-700">{{ order.status }}</span>
                    </CardHeader>
                    <CardContent class="space-y-3">
                        <ul class="space-y-1 text-sm">
                            <li v-for="item in order.items" :key="item.product_name">{{ item.quantity }}x {{ item.product_name }}</li>
                        </ul>
                        <div class="font-bold">{{ money(order.total_amount) }}</div>
                        <p v-if="order.notes" class="text-sm text-slate-500">Catatan: {{ order.notes }}</p>
                        <div v-if="order.status === 'pending'" class="flex gap-2">
                            <Button @click="accept(order)">Terima</Button>
                            <Button variant="destructive" @click="reject(order)">Tolak</Button>
                        </div>
                        <div v-else-if="props.statusFilter === 'accepted'" class="flex flex-wrap gap-2">
                            <Button variant="outline" :disabled="isPrinterPrinting" @click="printKot(order)"><Printer class="mr-2 h-4 w-4" />Cetak KOT</Button>
                            <Button v-if="order.payment_status !== 'paid'" :disabled="!order.payment_method" @click="pay(order)">
                                Tandai Dibayar · {{ paymentLabel(order.payment_method) }}
                            </Button>
                            <Button v-if="order.status === 'ready' && order.payment_status === 'paid'" @click="complete(order)">Sudah Diserahkan</Button>
                            <span v-if="order.payment_status !== 'paid'" class="self-center text-xs text-slate-500">Metode dipilih pelanggan</span>
                        </div>
                    </CardContent>
                </Card>
            </div>
            <Card v-else><CardContent class="p-8 text-center text-slate-500">Tidak ada pesanan masuk.</CardContent></Card>
        </div>
    </AdminLayout>
</template>
