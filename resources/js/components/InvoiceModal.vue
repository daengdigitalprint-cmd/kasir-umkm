<script setup lang="ts">
import { ref, watch } from 'vue';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Badge } from '@/components/ui/badge';
import { Separator } from '@/components/ui/separator';
import { User, CreditCard, Package } from 'lucide-vue-next';

interface TransactionItem {
    id: number;
    product_name: string;
    product_sku: string;
    quantity: number;
    unit_price: number;
    total_price: number;
    modifiers?: Array<{ key?: string; label?: string; price?: number }> | null;
}

interface Transaction {
    id: number;
    transaction_number: string;
    status: string;
    payment_method: string;
    subtotal: number;
    tax_amount: number;
    discount_amount: number;
    service_charge_amount?: number;
    service_charge_percent?: number | null;
    tip_amount?: number;
    total_amount: number;
    amount_paid: number;
    change_amount: number;
    payments?: Array<{ id: number; method: string; amount: number; reference?: string | null }>;
    completed_at: string;
    items: TransactionItem[];
    store: { name: string; address?: string; phone?: string; email?: string };
    customer?: { name: string; phone?: string; email?: string };
    user: { name: string };
}

const props = defineProps<{
    open: boolean;
    transactionId: number | null;
}>();

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
}>();

const transaction = ref<Transaction | null>(null);
const loading = ref(false);

async function fetchInvoice(id: number) {
    loading.value = true;
    transaction.value = null;
    try {
        const res = await fetch(`/invoice/${id}/json`);
        if (!res.ok) throw new Error('Gagal memuat invoice');
        const data = await res.json();
        transaction.value = data.transaction;
    } catch {
        transaction.value = null;
    } finally {
        loading.value = false;
    }
}

watch(
    () => props.transactionId,
    (id) => {
        if (id && props.open) fetchInvoice(id);
    },
);

watch(
    () => props.open,
    (isOpen) => {
        if (isOpen && props.transactionId) fetchInvoice(props.transactionId);
    },
);

const formatCurrency = (amount: number) =>
    new Intl.NumberFormat('id-ID', { style: 'decimal', minimumFractionDigits: 0, maximumFractionDigits: 0 }).format(amount);

const formatQuantity = (qty: number | string) =>
    new Intl.NumberFormat('id-ID', { style: 'decimal', minimumFractionDigits: 0, maximumFractionDigits: 4 }).format(Number(qty));

const formatDate = (iso: string) =>
    new Date(iso).toLocaleDateString('id-ID', { year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit' });

const getPaymentMethodName = (method: string) => {
    const methods: Record<string, string> = {
        cash: 'Tunai',
        card: 'Kartu Kredit/Debit',
        ewallet: 'E-Wallet',
        transfer: 'Transfer Bank',
        bank_transfer: 'Transfer Bank',
        qris: 'QRIS',
        other: 'Lainnya',
    };
    return methods[method] || method;
};

const getStatusVariant = (status: string) => {
    const variants: Record<string, string> = { completed: 'default', pending: 'secondary', cancelled: 'destructive' };
    return variants[status] || 'default';
};

const getStatusName = (status: string) => {
    const statuses: Record<string, string> = { completed: 'Selesai', pending: 'Menunggu', cancelled: 'Dibatalkan' };
    return statuses[status] || status;
};
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent class="sm:max-w-lg max-h-[85vh] overflow-y-auto">
            <DialogHeader>
                <DialogTitle>Invoice</DialogTitle>
            </DialogHeader>

            <div v-if="loading" class="py-10 text-center text-sm text-slate-500">Memuat data invoice...</div>

            <div v-else-if="!transaction" class="py-10 text-center text-sm text-slate-500">Invoice tidak ditemukan.</div>

            <div v-else class="space-y-4 text-sm">
                <!-- Header -->
                <div class="flex items-center justify-between">
                    <div>
                        <p class="font-bold text-base text-slate-900">#{{ transaction.transaction_number }}</p>
                        <p class="text-xs text-slate-500">{{ formatDate(transaction.completed_at) }}</p>
                    </div>
                    <Badge :variant="getStatusVariant(transaction.status)">
                        {{ getStatusName(transaction.status) }}
                    </Badge>
                </div>

                <!-- Store -->
                <div class="rounded-lg bg-slate-50 p-3">
                    <p class="font-semibold text-slate-900">{{ transaction.store.name }}</p>
                    <p v-if="transaction.store.address" class="text-xs text-slate-600">{{ transaction.store.address }}</p>
                    <p v-if="transaction.store.phone" class="text-xs text-slate-600">Telp: {{ transaction.store.phone }}</p>
                </div>

                <!-- Customer -->
                <div v-if="transaction.customer" class="rounded-lg bg-slate-50 p-3">
                    <h4 class="font-semibold text-slate-900 mb-1 flex items-center gap-1.5">
                        <User class="h-3.5 w-3.5" />
                        Pelanggan
                    </h4>
                    <p class="text-slate-700">{{ transaction.customer.name }}</p>
                    <p v-if="transaction.customer.phone" class="text-xs text-slate-600">{{ transaction.customer.phone }}</p>
                </div>

                <!-- Items -->
                <div>
                    <h4 class="font-semibold text-slate-900 mb-2 flex items-center gap-1.5">
                        <Package class="h-3.5 w-3.5" />
                        Produk
                    </h4>
                    <div class="rounded-lg border border-slate-200 divide-y divide-slate-100">
                        <div v-for="item in transaction.items" :key="item.id" class="flex items-start justify-between gap-3 p-3">
                            <div class="min-w-0">
                                <p class="font-medium text-slate-900 truncate">{{ item.product_name }}</p>
                                <p class="text-xs text-slate-500">{{ formatQuantity(item.quantity) }} x {{ formatCurrency(item.unit_price) }}</p>
                                <ul v-if="item.modifiers && item.modifiers.length" class="text-xs text-violet-600 mt-0.5">
                                    <li v-for="(m, mi) in item.modifiers" :key="mi">+ {{ m.label }}</li>
                                </ul>
                            </div>
                            <span class="font-medium text-slate-900 tabular-nums whitespace-nowrap">{{ formatCurrency(item.total_price) }}</span>
                        </div>
                    </div>
                </div>

                <!-- Summary -->
                <div class="space-y-1.5 rounded-lg bg-slate-50 p-3">
                    <div class="flex justify-between text-sm">
                        <span class="text-slate-600">Subtotal</span>
                        <span class="font-medium">{{ formatCurrency(transaction.subtotal) }}</span>
                    </div>
                    <div v-if="transaction.discount_amount > 0" class="flex justify-between text-sm">
                        <span class="text-slate-600">Diskon</span>
                        <span class="font-medium text-red-600">-{{ formatCurrency(transaction.discount_amount) }}</span>
                    </div>
                    <div v-if="transaction.service_charge_amount && transaction.service_charge_amount > 0" class="flex justify-between text-sm">
                        <span class="text-slate-600">Biaya layanan<span v-if="transaction.service_charge_percent"> ({{ transaction.service_charge_percent }}%)</span></span>
                        <span class="font-medium">{{ formatCurrency(transaction.service_charge_amount) }}</span>
                    </div>
                    <div v-if="transaction.tax_amount > 0" class="flex justify-between text-sm">
                        <span class="text-slate-600">PPN</span>
                        <span class="font-medium">{{ formatCurrency(transaction.tax_amount) }}</span>
                    </div>
                    <div v-if="transaction.tip_amount && transaction.tip_amount > 0" class="flex justify-between text-sm">
                        <span class="text-slate-600">Tip</span>
                        <span class="font-medium">{{ formatCurrency(transaction.tip_amount) }}</span>
                    </div>
                    <Separator />
                    <div class="flex justify-between text-base font-bold">
                        <span class="text-slate-900">Total</span>
                        <span class="text-blue-600">{{ formatCurrency(transaction.total_amount) }}</span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-slate-600">Dibayar</span>
                        <span class="font-medium">{{ formatCurrency(transaction.amount_paid) }}</span>
                    </div>
                    <div v-if="transaction.change_amount > 0" class="flex justify-between text-sm">
                        <span class="text-slate-600">Kembalian</span>
                        <span class="font-medium text-green-600">{{ formatCurrency(transaction.change_amount) }}</span>
                    </div>
                    <div v-if="transaction.payments && transaction.payments.length > 1" class="space-y-1">
                        <span class="text-slate-600">Rincian pembayaran</span>
                        <div v-for="payment in transaction.payments" :key="payment.id" class="flex justify-between pl-3 text-sm">
                            <span class="text-slate-600">
                                {{ getPaymentMethodName(payment.method) }}<span v-if="payment.reference" class="text-xs text-slate-400"> ({{ payment.reference }})</span>
                            </span>
                            <span class="font-medium tabular-nums">{{ formatCurrency(payment.amount) }}</span>
                        </div>
                    </div>
                    <div v-else class="flex justify-between text-sm">
                        <span class="text-slate-600">Metode</span>
                        <span class="font-medium flex items-center gap-1">
                            <CreditCard class="h-3.5 w-3.5" />
                            {{ getPaymentMethodName(transaction.payment_method) }}
                        </span>
                    </div>
                </div>

                <!-- Footer -->
                <p class="text-center text-xs text-slate-400 pt-2">
                    Kasir: {{ transaction.user.name }}
                </p>
            </div>
        </DialogContent>
    </Dialog>
</template>
