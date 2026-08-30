<script setup lang="ts">
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { useForm } from '@inertiajs/vue3';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import {
    ArrowLeft, CreditCard, AlertTriangle, CheckCircle2,
    User, Phone, Mail, MapPin, Clock, Receipt, Package,
} from 'lucide-vue-next';
import { ref } from 'vue';

interface Customer {
    id: number;
    name: string;
    phone: string | null;
    email: string | null;
    address: string | null;
}

interface TransactionItem {
    product_name: string;
    quantity: number;
    unit_price: number;
    subtotal: number;
}

interface ReceivablePayment {
    id: number;
    amount: number;
    payment_method: string;
    received_at: string;
    reference_number: string | null;
    notes: string | null;
    user_name: string | null;
}

interface Transaction {
    id: number;
    transaction_number: string;
    total_amount: number;
    amount_paid: number;
    outstanding: number;
    payment_status: string;
    payment_method: string;
    debt_due_at: string | null;
    created_at: string;
    is_overdue: boolean;
    customer: Customer | null;
    items: TransactionItem[];
    payments: ReceivablePayment[];
}

interface Props {
    transaction: Transaction;
    store: { id: number; name: string; currency: string };
}

const props = defineProps<Props>();

const formatCurrency = (amount: number) =>
    new Intl.NumberFormat('id-ID', { style: 'decimal', minimumFractionDigits: 0 }).format(amount);

const formatDate = (dateString: string | null) => {
    if (!dateString) return '-';
    return new Date(dateString).toLocaleDateString('id-ID', { year: 'numeric', month: 'short', day: 'numeric' });
};

const formatDateTime = (dateString: string) =>
    new Date(dateString).toLocaleString('id-ID', { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });

const paymentMethodLabel = (method: string) => {
    const map: Record<string, string> = {
        cash: 'Tunai', qris: 'QRIS', transfer: 'Transfer',
        card: 'Kartu', ewallet: 'E-Wallet', other: 'Lainnya',
    };
    return map[method] ?? method;
};

const statusLabel = (status: string) => {
    const map: Record<string, string> = { unpaid: 'Belum Bayar', partial: 'Sebagian', paid: 'Lunas' };
    return map[status] ?? status;
};

// ─── Form pelunasan ─────────────────────────────────────────────────────────
const paymentFormOpen = ref(false);

const paymentForm = useForm({
    transaction_id: props.transaction.id,
    amount: props.transaction.outstanding,
    payment_method: 'cash' as 'cash' | 'card' | 'ewallet' | 'transfer' | 'other',
    received_at: new Date().toISOString().slice(0, 10),
    reference_number: '',
    notes: '',
    idempotency_key: '',
});

function openPaymentForm() {
    paymentForm.transaction_id = props.transaction.id;
    paymentForm.amount = props.transaction.outstanding;
    paymentForm.payment_method = 'cash';
    paymentForm.received_at = new Date().toISOString().slice(0, 10);
    paymentForm.reference_number = '';
    paymentForm.notes = '';
    paymentForm.idempotency_key = `rp-${props.transaction.id}-${Date.now()}`;
    paymentFormOpen.value = true;
}

function submitPayment() {
    paymentForm.post('/receivable-payments', {
        preserveScroll: true,
        onSuccess: () => {
            paymentFormOpen.value = false;
        },
    });
}
</script>

<template>
    <AdminLayout title="Detail Piutang">
        <Head :title="`Piutang ${transaction.transaction_number}`" />

        <div class="space-y-5 p-4">
            <!-- Header -->
            <div class="flex items-center gap-3">
                <Link href="/receivables">
                    <Button variant="ghost" size="icon" class="h-9 w-9 rounded-xl">
                        <ArrowLeft class="h-4 w-4" />
                    </Button>
                </Link>
                <div>
                    <h1 class="text-xl font-black text-slate-900">{{ transaction.transaction_number }}</h1>
                    <p class="text-xs text-slate-400 mt-0.5">{{ formatDateTime(transaction.created_at) }}</p>
                </div>
                <div class="ml-auto flex gap-2 items-center">
                    <Badge v-if="transaction.is_overdue" variant="destructive" class="text-xs">
                        <AlertTriangle class="h-2.5 w-2.5 mr-1" /> Lewat Tempo
                    </Badge>
                    <Badge
                        :class="[
                            'text-xs',
                            transaction.payment_status === 'paid'
                                ? 'bg-green-100 text-green-700 border-green-200'
                                : transaction.payment_status === 'partial'
                                    ? 'bg-yellow-100 text-yellow-700 border-yellow-200'
                                    : 'bg-orange-100 text-orange-700 border-orange-200',
                        ]"
                    >
                        {{ statusLabel(transaction.payment_status) }}
                    </Badge>
                </div>
            </div>

            <!-- Summary piutang -->
            <div class="grid grid-cols-3 gap-3">
                <Card class="rounded-2xl border border-slate-200 shadow-sm">
                    <CardContent class="p-4 text-center">
                        <p class="text-xs text-slate-500 mb-1">Total Transaksi</p>
                        <p class="text-base font-black text-slate-800">Rp {{ formatCurrency(transaction.total_amount) }}</p>
                    </CardContent>
                </Card>
                <Card class="rounded-2xl border border-green-200 bg-green-50 shadow-sm">
                    <CardContent class="p-4 text-center">
                        <p class="text-xs text-green-600 mb-1">Sudah Dibayar</p>
                        <p class="text-base font-black text-green-700">Rp {{ formatCurrency(transaction.amount_paid) }}</p>
                    </CardContent>
                </Card>
                <Card class="rounded-2xl border border-orange-200 bg-orange-50 shadow-sm">
                    <CardContent class="p-4 text-center">
                        <p class="text-xs text-orange-600 mb-1">Sisa Piutang</p>
                        <p class="text-base font-black text-orange-700">Rp {{ formatCurrency(transaction.outstanding) }}</p>
                    </CardContent>
                </Card>
            </div>

            <!-- Info pelanggan + jatuh tempo -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <!-- Pelanggan -->
                <Card class="rounded-2xl border border-slate-200 shadow-sm">
                    <CardHeader class="pb-2 pt-4 px-4">
                        <CardTitle class="text-sm font-semibold flex items-center gap-2">
                            <User class="h-4 w-4 text-slate-400" /> Pelanggan
                        </CardTitle>
                    </CardHeader>
                    <CardContent class="px-4 pb-4">
                        <div v-if="transaction.customer" class="space-y-1">
                            <Link
                                :href="`/customers/${transaction.customer.id}`"
                                class="font-bold text-primary hover:underline text-sm"
                            >
                                {{ transaction.customer.name }}
                            </Link>
                            <p v-if="transaction.customer.phone" class="text-xs text-slate-500 flex items-center gap-1">
                                <Phone class="h-3 w-3" /> {{ transaction.customer.phone }}
                            </p>
                            <p v-if="transaction.customer.email" class="text-xs text-slate-500 flex items-center gap-1">
                                <Mail class="h-3 w-3" /> {{ transaction.customer.email }}
                            </p>
                            <p v-if="transaction.customer.address" class="text-xs text-slate-500 flex items-center gap-1">
                                <MapPin class="h-3 w-3" /> {{ transaction.customer.address }}
                            </p>
                        </div>
                        <p v-else class="text-xs text-slate-400 italic">Pelanggan tidak diketahui</p>
                    </CardContent>
                </Card>

                <!-- Informasi pembayaran -->
                <Card class="rounded-2xl border border-slate-200 shadow-sm">
                    <CardHeader class="pb-2 pt-4 px-4">
                        <CardTitle class="text-sm font-semibold flex items-center gap-2">
                            <CreditCard class="h-4 w-4 text-slate-400" /> Info Pembayaran
                        </CardTitle>
                    </CardHeader>
                    <CardContent class="px-4 pb-4 space-y-2">
                        <div class="flex justify-between text-xs">
                            <span class="text-slate-500">Metode Awal</span>
                            <span class="font-medium">{{ paymentMethodLabel(transaction.payment_method) }}</span>
                        </div>
                        <div class="flex justify-between text-xs">
                            <span class="text-slate-500 flex items-center gap-1"><Clock class="h-3 w-3" /> Jatuh Tempo</span>
                            <span
                                :class="['font-medium', transaction.is_overdue ? 'text-red-600' : 'text-slate-800']"
                            >
                                {{ formatDate(transaction.debt_due_at) }}
                            </span>
                        </div>
                        <div class="flex justify-between text-xs">
                            <span class="text-slate-500">Tanggal Transaksi</span>
                            <span class="font-medium">{{ formatDate(transaction.created_at) }}</span>
                        </div>
                    </CardContent>
                </Card>
            </div>

            <!-- Item transaksi -->
            <Card class="rounded-2xl border border-slate-200 shadow-sm">
                <CardHeader class="pb-2 pt-4 px-4">
                    <CardTitle class="text-sm font-semibold flex items-center gap-2">
                        <Package class="h-4 w-4 text-slate-400" /> Item Transaksi
                    </CardTitle>
                </CardHeader>
                <CardContent class="p-0">
                    <div class="divide-y divide-slate-100">
                        <div
                            v-for="(item, idx) in transaction.items"
                            :key="idx"
                            class="flex items-center justify-between px-4 py-2.5 text-xs"
                        >
                            <div>
                                <p class="font-medium text-slate-800">{{ item.product_name }}</p>
                                <p class="text-slate-400">{{ item.quantity }} × Rp {{ formatCurrency(item.unit_price) }}</p>
                            </div>
                            <p class="font-bold text-slate-700">Rp {{ formatCurrency(item.subtotal) }}</p>
                        </div>
                    </div>
                    <div class="flex justify-between px-4 py-3 border-t border-slate-100 text-sm font-black">
                        <span>Total</span>
                        <span>Rp {{ formatCurrency(transaction.total_amount) }}</span>
                    </div>
                </CardContent>
            </Card>

            <!-- Riwayat pembayaran -->
            <Card class="rounded-2xl border border-slate-200 shadow-sm">
                <CardHeader class="pb-2 pt-4 px-4">
                    <div class="flex items-center justify-between">
                        <CardTitle class="text-sm font-semibold flex items-center gap-2">
                            <Receipt class="h-4 w-4 text-slate-400" /> Riwayat Pelunasan
                        </CardTitle>
                        <Button
                            v-if="transaction.payment_status !== 'paid'"
                            size="sm"
                            class="h-7 px-3 text-xs rounded-lg"
                            @click="openPaymentForm"
                        >
                            Catat Bayar
                        </Button>
                        <Badge v-else class="bg-green-100 text-green-700 border-green-200 text-xs">
                            <CheckCircle2 class="h-3 w-3 mr-1" /> Lunas
                        </Badge>
                    </div>
                </CardHeader>
                <CardContent class="p-0">
                    <div v-if="transaction.payments.length === 0" class="text-center py-8 text-slate-400">
                        <Receipt class="h-8 w-8 mx-auto mb-2 opacity-30" />
                        <p class="text-xs">Belum ada pembayaran tercatat</p>
                    </div>
                    <div v-else class="divide-y divide-slate-100">
                        <div
                            v-for="payment in transaction.payments"
                            :key="payment.id"
                            class="flex items-start justify-between px-4 py-3 text-xs"
                        >
                            <div>
                                <p class="font-medium text-slate-800">{{ paymentMethodLabel(payment.payment_method) }}</p>
                                <p class="text-slate-400 mt-0.5">{{ formatDateTime(payment.received_at) }}</p>
                                <p v-if="payment.reference_number" class="text-slate-400">Ref: {{ payment.reference_number }}</p>
                                <p v-if="payment.notes" class="text-slate-400 italic">{{ payment.notes }}</p>
                                <p v-if="payment.user_name" class="text-slate-400">Oleh: {{ payment.user_name }}</p>
                            </div>
                            <p class="font-black text-green-600">+Rp {{ formatCurrency(payment.amount) }}</p>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>

        <!-- Modal form pelunasan -->
        <Teleport to="body">
            <div
                v-if="paymentFormOpen"
                class="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-4 bg-black/40 backdrop-blur-sm"
                @click.self="paymentFormOpen = false"
            >
                <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md p-5 space-y-4">
                    <div class="flex items-center justify-between">
                        <h3 class="font-black text-slate-900">Catat Pembayaran</h3>
                        <p class="text-xs text-slate-500">Sisa: <span class="font-bold text-orange-600">Rp {{ formatCurrency(transaction.outstanding) }}</span></p>
                    </div>

                    <div class="space-y-3">
                        <!-- Jumlah bayar -->
                        <div>
                            <label class="text-xs font-medium text-slate-600 mb-1 block">Jumlah Bayar</label>
                            <div class="relative">
                                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400">Rp</span>
                                <input
                                    v-model.number="paymentForm.amount"
                                    type="number"
                                    min="1"
                                    :max="transaction.outstanding"
                                    class="w-full border border-gray-200 rounded-xl py-2.5 pl-9 pr-3 text-sm focus:outline-none focus:ring-2 focus:ring-primary/30"
                                />
                            </div>
                            <p v-if="paymentForm.errors.amount" class="text-xs text-red-500 mt-1">{{ paymentForm.errors.amount }}</p>
                        </div>

                        <!-- Metode -->
                        <div>
                            <label class="text-xs font-medium text-slate-600 mb-1 block">Metode Pembayaran</label>
                            <select
                                v-model="paymentForm.payment_method"
                                class="w-full border border-gray-200 rounded-xl py-2.5 px-3 text-sm focus:outline-none focus:ring-2 focus:ring-primary/30"
                            >
                                <option value="cash">Tunai</option>
                                <option value="transfer">Transfer</option>
                                <option value="qris">QRIS</option>
                                <option value="card">Kartu</option>
                                <option value="ewallet">E-Wallet</option>
                                <option value="other">Lainnya</option>
                            </select>
                        </div>

                        <!-- Tanggal diterima -->
                        <div>
                            <label class="text-xs font-medium text-slate-600 mb-1 block">Tanggal Diterima</label>
                            <input
                                v-model="paymentForm.received_at"
                                type="date"
                                class="w-full border border-gray-200 rounded-xl py-2.5 px-3 text-sm focus:outline-none focus:ring-2 focus:ring-primary/30"
                            />
                        </div>

                        <!-- No. referensi -->
                        <div>
                            <label class="text-xs font-medium text-slate-600 mb-1 block">No. Referensi <span class="text-slate-400">(opsional)</span></label>
                            <input
                                v-model="paymentForm.reference_number"
                                type="text"
                                placeholder="Nomor bukti transfer, dsb."
                                class="w-full border border-gray-200 rounded-xl py-2.5 px-3 text-sm focus:outline-none focus:ring-2 focus:ring-primary/30"
                            />
                        </div>

                        <!-- Catatan -->
                        <div>
                            <label class="text-xs font-medium text-slate-600 mb-1 block">Catatan <span class="text-slate-400">(opsional)</span></label>
                            <textarea
                                v-model="paymentForm.notes"
                                rows="2"
                                placeholder="Catatan tambahan..."
                                class="w-full border border-gray-200 rounded-xl py-2.5 px-3 text-sm focus:outline-none focus:ring-2 focus:ring-primary/30 resize-none"
                            />
                        </div>
                    </div>

                    <div class="flex gap-2 pt-1">
                        <Button
                            variant="outline"
                            class="flex-1 rounded-xl"
                            type="button"
                            @click="paymentFormOpen = false"
                        >
                            Batal
                        </Button>
                        <Button
                            class="flex-1 rounded-xl"
                            type="button"
                            :disabled="paymentForm.processing || paymentForm.amount <= 0"
                            @click="submitPayment"
                        >
                            <CheckCircle2 class="h-4 w-4 mr-1.5" />
                            Simpan Pembayaran
                        </Button>
                    </div>
                </div>
            </div>
        </Teleport>
    </AdminLayout>
</template>
