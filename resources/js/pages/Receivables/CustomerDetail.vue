<script setup lang="ts">
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import {
    ArrowLeft, AlertTriangle, CheckCircle2, ChevronRight,
    User, Phone, Mail, MapPin, Clock, Receipt, CreditCard,
} from 'lucide-vue-next';

interface Customer {
    id: number;
    name: string;
    phone: string | null;
    email: string | null;
    address: string | null;
}

interface ReceivableTransaction {
    id: number;
    transaction_number: string;
    total_amount: number;
    amount_paid: number;
    outstanding: number;
    payment_status: string;
    debt_due_at: string | null;
    created_at: string;
    is_overdue: boolean;
}

interface ReceivablePayment {
    id: number;
    amount: number;
    payment_method: string;
    received_at: string;
    reference_number: string | null;
    notes: string | null;
    user_name: string | null;
    transaction_number: string | null;
    transaction_id: number | null;
}

interface Props {
    customer: Customer;
    summary: {
        total_debt: number;
        total_paid: number;
        outstanding_total: number;
        active_count: number;
        overdue_count: number;
    };
    transactions: ReceivableTransaction[];
    payments: {
        data: ReceivablePayment[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
        current_page: number;
        last_page: number;
        per_page: number;
        from: number | null;
        to: number | null;
    };
    store: { id: number; name: string; currency: string };
}

defineProps<Props>();

const formatCurrency = (amount: number) =>
    new Intl.NumberFormat('id-ID', { style: 'decimal', minimumFractionDigits: 0 }).format(amount);

const formatDate = (dateString: string | null) => {
    if (!dateString) return '-';
    return new Date(dateString).toLocaleDateString('id-ID', { year: 'numeric', month: 'short', day: 'numeric' });
};

const formatDateTime = (dateString: string) =>
    new Date(dateString).toLocaleString('id-ID', { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });

const paymentMethodLabel = (method: string) => {
    const map: Record<string, string> = { cash: 'Tunai', qris: 'QRIS', transfer: 'Transfer', card: 'Kartu', ewallet: 'E-Wallet', other: 'Lainnya' };
    return map[method] ?? method;
};

const statusLabel = (status: string) => {
    const map: Record<string, string> = { unpaid: 'Belum Bayar', partial: 'Sebagian', paid: 'Lunas' };
    return map[status] ?? status;
};

const initials = (name: string) =>
    name.split(' ').filter(Boolean).slice(0, 2).map((w) => w[0]!.toUpperCase()).join('') || '?';
</script>

<template>
    <AdminLayout title="Detail Piutang Pelanggan">
        <Head :title="`Piutang ${customer.name}`" />

        <div class="space-y-5 p-4">
            <!-- Header -->
            <div class="flex items-center gap-3">
                <Link href="/receivables">
                    <Button variant="ghost" size="icon" class="h-9 w-9 rounded-xl">
                        <ArrowLeft class="h-4 w-4" />
                    </Button>
                </Link>
                <div class="h-11 w-11 rounded-full bg-orange-100 text-orange-600 flex items-center justify-center text-sm font-black shrink-0">
                    {{ initials(customer.name) }}
                </div>
                <div class="min-w-0">
                    <h1 class="text-lg font-black text-slate-900 truncate">{{ customer.name }}</h1>
                    <Link :href="`/customers/${customer.id}`" class="text-xs text-primary hover:underline">Lihat profil pelanggan &rarr;</Link>
                </div>
                <Badge v-if="summary.overdue_count > 0" variant="destructive" class="text-xs ml-auto shrink-0">
                    <AlertTriangle class="h-2.5 w-2.5 mr-1" /> {{ summary.overdue_count }} lewat tempo
                </Badge>
            </div>

            <!-- Info kontak -->
            <div v-if="customer.phone || customer.email || customer.address" class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500 -mt-2">
                <span v-if="customer.phone" class="flex items-center gap-1"><Phone class="h-3 w-3" /> {{ customer.phone }}</span>
                <span v-if="customer.email" class="flex items-center gap-1"><Mail class="h-3 w-3" /> {{ customer.email }}</span>
                <span v-if="customer.address" class="flex items-center gap-1"><MapPin class="h-3 w-3" /> {{ customer.address }}</span>
            </div>

            <!-- Summary -->
            <div class="grid grid-cols-3 gap-3">
                <Card class="rounded-2xl border border-orange-200 bg-orange-50 shadow-sm">
                    <CardContent class="p-4 text-center">
                        <p class="text-xs text-orange-600 mb-1">Sisa Hutang</p>
                        <p class="text-base font-black text-orange-700">Rp {{ formatCurrency(summary.outstanding_total) }}</p>
                    </CardContent>
                </Card>
                <Card class="rounded-2xl border border-green-200 bg-green-50 shadow-sm">
                    <CardContent class="p-4 text-center">
                        <p class="text-xs text-green-600 mb-1">Sudah Dibayar</p>
                        <p class="text-base font-black text-green-700">Rp {{ formatCurrency(summary.total_paid) }}</p>
                    </CardContent>
                </Card>
                <Card class="rounded-2xl border border-slate-200 shadow-sm">
                    <CardContent class="p-4 text-center">
                        <p class="text-xs text-slate-500 mb-1">Utang Aktif</p>
                        <p class="text-base font-black text-slate-800">{{ summary.active_count }} transaksi</p>
                    </CardContent>
                </Card>
            </div>

            <!-- Daftar utang per transaksi -->
            <Card class="rounded-2xl border border-slate-200 shadow-sm">
                <CardHeader class="pb-2 pt-4 px-4">
                    <CardTitle class="text-sm font-semibold flex items-center gap-2">
                        <CreditCard class="h-4 w-4 text-slate-400" /> Daftar Utang
                    </CardTitle>
                </CardHeader>
                <CardContent class="p-0">
                    <div v-if="transactions.length === 0" class="text-center py-8 text-slate-400">
                        <CreditCard class="h-8 w-8 mx-auto mb-2 opacity-30" />
                        <p class="text-xs">Tidak ada transaksi utang</p>
                    </div>
                    <div v-else class="divide-y divide-slate-100">
                        <Link
                            v-for="t in transactions"
                            :key="t.id"
                            :href="`/receivables/${t.id}`"
                            class="flex items-center gap-3 px-4 py-3 hover:bg-slate-50 transition-colors"
                        >
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="text-sm font-bold text-primary truncate">{{ t.transaction_number }}</span>
                                    <Badge v-if="t.is_overdue" variant="destructive" class="text-xs shrink-0">
                                        <AlertTriangle class="h-2.5 w-2.5 mr-1" /> Lewat Tempo
                                    </Badge>
                                    <Badge
                                        :class="[
                                            'text-xs shrink-0',
                                            t.payment_status === 'paid' ? 'bg-green-100 text-green-700 border-green-200' :
                                            t.payment_status === 'partial' ? 'bg-yellow-100 text-yellow-700 border-yellow-200' :
                                            'bg-orange-100 text-orange-700 border-orange-200'
                                        ]"
                                    >
                                        {{ statusLabel(t.payment_status) }}
                                    </Badge>
                                </div>
                                <p class="text-xs text-slate-400 mt-0.5">
                                    {{ formatDate(t.created_at) }}
                                    <span v-if="t.debt_due_at"> · <Clock class="inline h-3 w-3 -mt-0.5" /> Tempo: {{ formatDate(t.debt_due_at) }}</span>
                                </p>
                            </div>
                            <div class="text-right shrink-0 flex items-center gap-1.5">
                                <div>
                                    <p v-if="t.payment_status !== 'paid'" class="text-sm font-black text-orange-600">Rp {{ formatCurrency(t.outstanding) }}</p>
                                    <p v-else class="text-sm font-black text-green-600">Lunas</p>
                                    <p class="text-xs text-slate-400">dari Rp {{ formatCurrency(t.total_amount) }}</p>
                                </div>
                                <ChevronRight class="h-4 w-4 text-slate-300" />
                            </div>
                        </Link>
                    </div>
                    <p class="px-4 py-2.5 border-t border-slate-100 text-xs text-slate-400">
                        Ketuk transaksi untuk melihat item &amp; mencatat pembayaran.
                    </p>
                </CardContent>
            </Card>

            <!-- Riwayat pembayaran -->
            <Card class="rounded-2xl border border-slate-200 shadow-sm">
                <CardHeader class="pb-2 pt-4 px-4">
                    <CardTitle class="text-sm font-semibold flex items-center gap-2">
                        <Receipt class="h-4 w-4 text-slate-400" /> Riwayat Pembayaran
                    </CardTitle>
                </CardHeader>
                <CardContent class="p-0">
                    <div v-if="payments.data.length === 0" class="text-center py-8 text-slate-400">
                        <Receipt class="h-8 w-8 mx-auto mb-2 opacity-30" />
                        <p class="text-xs">Belum ada pembayaran tercatat</p>
                    </div>
                    <div v-else class="divide-y divide-slate-100">
                        <div
                            v-for="payment in payments.data"
                            :key="payment.id"
                            class="flex items-start justify-between px-4 py-3 text-xs"
                        >
                            <div>
                                <p class="font-medium text-slate-800">
                                    {{ paymentMethodLabel(payment.payment_method) }}
                                    <Link
                                        v-if="payment.transaction_id"
                                        :href="`/receivables/${payment.transaction_id}`"
                                        class="text-primary hover:underline"
                                    >{{ payment.transaction_number }}</Link>
                                </p>
                                <p class="text-slate-400 mt-0.5">{{ formatDateTime(payment.received_at) }}</p>
                                <p v-if="payment.reference_number" class="text-slate-400">Ref: {{ payment.reference_number }}</p>
                                <p v-if="payment.notes" class="text-slate-400 italic">{{ payment.notes }}</p>
                                <p v-if="payment.user_name" class="text-slate-400">Oleh: {{ payment.user_name }}</p>
                            </div>
                            <p class="font-black text-green-600 shrink-0">+Rp {{ formatCurrency(payment.amount) }}</p>
                        </div>
                    </div>

                    <!-- Pagination riwayat -->
                    <div v-if="(payments?.last_page ?? 0) > 1" class="flex items-center justify-between px-4 py-3 border-t border-slate-100">
                        <p class="text-xs text-slate-400">
                            {{ payments.from }}–{{ payments.to }} dari {{ payments.total }}
                        </p>
                        <div class="flex gap-1">
                            <button
                                v-for="link in (payments.links ?? [])"
                                :key="link.label"
                                :disabled="!link.url"
                                type="button"
                                :class="['px-2.5 py-1 rounded-lg text-xs border transition-colors', link.active ? 'bg-primary text-white border-primary' : 'border-gray-200 text-gray-600 hover:bg-gray-50 disabled:opacity-40 disabled:cursor-not-allowed']"
                                @click="link.url && router.get(link.url)"
                                v-html="link.label"
                            ></button>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AdminLayout>
</template>
