<script setup lang="ts">
import ReceivablePaymentDialog from '@/components/receivables/ReceivablePaymentDialog.vue';
import { DUE_TONE_CLASS, dueStatus, formatDate, formatDateTime, paymentMethodLabel, reminderLink, rupiah } from '@/composables/useReceivables';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, HandCoins, MessageCircle, Phone, User } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface PaymentRow {
    id: number;
    amount: number;
    payment_method: string;
    received_at: string;
    reference_number: string | null;
    notes: string | null;
    user_name: string | null;
}

const props = defineProps<{
    transaction: {
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
        customer: { id: number; name: string; phone: string | null; email: string | null; address: string | null } | null;
        items: Array<{ product_name: string; quantity: number; unit_price: number; subtotal: number }>;
        payments: PaymentRow[];
    };
    store: { id: number; name: string };
}>();

const t = computed(() => props.transaction);
const isPaid = computed(() => t.value.payment_status === 'paid');
const due = computed(() => dueStatus(t.value.debt_due_at, isPaid.value));
const paidPercent = computed(() => (t.value.total_amount > 0 ? Math.min(100, Math.round((t.value.amount_paid / t.value.total_amount) * 100)) : 0));

const waLink = computed(() =>
    !isPaid.value && t.value.customer
        ? reminderLink({
              phone: t.value.customer.phone,
              customerName: t.value.customer.name,
              storeName: props.store.name,
              amount: t.value.outstanding,
              dueAt: t.value.debt_due_at,
          })
        : null,
);

const payOpen = ref(false);
const backHref = computed(() => (t.value.customer ? `/receivables/customers/${t.value.customer.id}` : '/receivables'));
</script>

<template>
    <AdminLayout title="Piutang Pelanggan">
        <Head :title="`Piutang ${transaction.transaction_number}`" />

        <div class="mx-auto max-w-3xl space-y-4">
            <!-- Kepala -->
            <div class="flex flex-wrap items-start gap-3">
                <Link :href="backHref" class="rounded-xl p-2 text-slate-500 hover:bg-slate-100" aria-label="Kembali">
                    <ArrowLeft class="h-5 w-5" />
                </Link>
                <div class="min-w-0 flex-1">
                    <h1 class="truncate text-xl font-bold text-slate-900">{{ transaction.transaction_number }}</h1>
                    <p class="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                        {{ formatDateTime(transaction.created_at) }}
                        <span class="rounded-full px-2 py-0.5 font-semibold" :class="DUE_TONE_CLASS[due.tone]">{{ due.label }}</span>
                    </p>
                </div>
                <div v-if="!isPaid" class="flex w-full gap-2 sm:w-auto">
                    <a
                        v-if="waLink"
                        :href="waLink"
                        target="_blank"
                        rel="noopener"
                        class="inline-flex flex-1 items-center justify-center gap-1.5 rounded-xl border border-slate-200 px-3 py-2 text-sm font-semibold text-emerald-700 hover:bg-emerald-50 sm:flex-none"
                    >
                        <MessageCircle class="h-4 w-4" /> Ingatkan via WA
                    </a>
                    <button
                        type="button"
                        class="inline-flex flex-1 items-center justify-center gap-1.5 rounded-xl bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90 sm:flex-none"
                        @click="payOpen = true"
                    >
                        <HandCoins class="h-4 w-4" /> Catat pembayaran
                    </button>
                </div>
            </div>

            <!-- Ringkasan pembayaran -->
            <section class="rounded-2xl border border-slate-200 bg-white p-4">
                <div class="grid grid-cols-3 gap-3 text-sm">
                    <div>
                        <p class="text-xs font-semibold text-slate-500">Total bon</p>
                        <p class="mt-0.5 font-bold text-slate-900">{{ rupiah(transaction.total_amount) }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold text-slate-500">Sudah dibayar</p>
                        <p class="mt-0.5 font-bold text-slate-900">{{ rupiah(transaction.amount_paid) }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold text-slate-500">Sisa</p>
                        <p class="mt-0.5 font-bold" :class="isPaid ? 'text-emerald-600' : transaction.is_overdue ? 'text-red-600' : 'text-slate-900'">
                            {{ isPaid ? 'Lunas' : rupiah(transaction.outstanding) }}
                        </p>
                    </div>
                </div>
                <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-100">
                    <div class="h-full rounded-full" :class="isPaid ? 'bg-emerald-500' : 'bg-primary'" :style="{ width: paidPercent + '%' }" />
                </div>
                <p class="mt-1 text-right text-xs text-slate-500">{{ paidPercent }}% terbayar</p>
                <dl class="mt-3 grid grid-cols-2 gap-2 border-t border-slate-100 pt-3 text-xs">
                    <dt class="text-slate-500">Jatuh tempo</dt>
                    <dd class="text-right font-semibold text-slate-800">{{ formatDate(transaction.debt_due_at) }}</dd>
                    <dt class="text-slate-500">Dibayar di kasir dengan</dt>
                    <dd class="text-right font-semibold text-slate-800">{{ paymentMethodLabel(transaction.payment_method) }}</dd>
                </dl>
            </section>

            <!-- Pelanggan -->
            <section v-if="transaction.customer" class="rounded-2xl border border-slate-200 bg-white p-4">
                <Link :href="`/receivables/customers/${transaction.customer.id}`" class="flex items-center gap-3">
                    <span class="flex h-9 w-9 items-center justify-center rounded-full bg-slate-100 text-slate-500"><User class="h-4 w-4" /></span>
                    <span class="min-w-0">
                        <span class="block truncate text-sm font-semibold text-slate-900">{{ transaction.customer.name }}</span>
                        <span v-if="transaction.customer.phone" class="flex items-center gap-1 text-xs text-slate-500"
                            ><Phone class="h-3 w-3" /> {{ transaction.customer.phone }}</span
                        >
                    </span>
                    <span class="ml-auto text-xs font-semibold text-primary">Semua utang pelanggan →</span>
                </Link>
            </section>

            <!-- Barang -->
            <section class="rounded-2xl border border-slate-200 bg-white">
                <h2 class="border-b border-slate-100 px-4 py-3 text-base font-bold text-slate-900">Barang</h2>
                <ul class="divide-y divide-slate-100 text-sm">
                    <li v-for="(item, i) in transaction.items" :key="i" class="flex items-start justify-between gap-3 px-4 py-2.5">
                        <span class="min-w-0">
                            <span class="block font-semibold text-slate-800">{{ item.product_name }}</span>
                            <span class="block text-xs text-slate-500">{{ item.quantity }} × {{ rupiah(item.unit_price) }}</span>
                        </span>
                        <span class="shrink-0 font-semibold text-slate-800">{{ rupiah(item.subtotal) }}</span>
                    </li>
                </ul>
                <div class="flex justify-between border-t border-slate-100 px-4 py-3 text-sm font-bold text-slate-900">
                    <span>Total</span>
                    <span>{{ rupiah(transaction.total_amount) }}</span>
                </div>
            </section>

            <!-- Riwayat pembayaran -->
            <section class="rounded-2xl border border-slate-200 bg-white">
                <h2 class="border-b border-slate-100 px-4 py-3 text-base font-bold text-slate-900">Riwayat pembayaran</h2>
                <p v-if="!transaction.payments.length" class="px-4 py-8 text-center text-sm text-slate-500">Belum ada pembayaran.</p>
                <ul v-else class="divide-y divide-slate-100">
                    <li v-for="p in transaction.payments" :key="p.id" class="flex items-start justify-between gap-3 px-4 py-3 text-sm">
                        <span class="min-w-0">
                            <span class="block font-semibold text-slate-800">{{ paymentMethodLabel(p.payment_method) }}</span>
                            <span class="block text-xs text-slate-500"
                                >{{ formatDateTime(p.received_at) }}<template v-if="p.user_name"> · {{ p.user_name }}</template></span
                            >
                            <span v-if="p.reference_number" class="block text-xs text-slate-500">Ref: {{ p.reference_number }}</span>
                            <span v-if="p.notes" class="block text-xs text-slate-500 italic">{{ p.notes }}</span>
                        </span>
                        <span class="shrink-0 font-bold text-emerald-600">+{{ rupiah(p.amount) }}</span>
                    </li>
                </ul>
            </section>
        </div>

        <ReceivablePaymentDialog
            :open="payOpen"
            :title="`Catat pembayaran ${transaction.transaction_number}`"
            :outstanding="transaction.outstanding"
            submit-url="/receivable-payments"
            :extra="{ transaction_id: transaction.id }"
            @close="payOpen = false"
        />
    </AdminLayout>
</template>
