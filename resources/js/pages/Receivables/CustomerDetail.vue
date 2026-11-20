<script setup lang="ts">
import ReceivablePaymentDialog from '@/components/receivables/ReceivablePaymentDialog.vue';
import { DUE_TONE_CLASS, dueStatus, formatDate, formatDateTime, paymentMethodLabel, reminderLink, rupiah } from '@/composables/useReceivables';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, ChevronLeft, ChevronRight, HandCoins, Mail, MapPin, MessageCircle, Phone, Receipt } from 'lucide-vue-next';
import { computed, ref } from 'vue';

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

interface ReceivablePaymentRow {
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

const props = defineProps<{
    customer: { id: number; name: string; phone: string | null; email: string | null; address: string | null };
    summary: { total_debt: number; total_paid: number; outstanding_total: number; active_count: number; overdue_count: number };
    transactions: ReceivableTransaction[];
    payments: {
        data: ReceivablePaymentRow[];
        current_page: number;
        last_page: number;
        from: number | null;
        to: number | null;
        total: number;
    };
    store: { id: number; name: string };
}>();

/** Bon belum lunas dalam urutan alokasi: jatuh tempo paling awal, lalu yang tertua. */
const openBills = computed(() =>
    props.transactions
        .filter((t) => t.payment_status !== 'paid' && t.outstanding > 0)
        .sort((a, b) => {
            if (a.debt_due_at && b.debt_due_at) return a.debt_due_at.localeCompare(b.debt_due_at) || a.created_at.localeCompare(b.created_at);
            if (a.debt_due_at) return -1;
            if (b.debt_due_at) return 1;
            return a.created_at.localeCompare(b.created_at);
        }),
);
const earliestDue = computed(() => openBills.value.find((b) => b.debt_due_at)?.debt_due_at ?? null);

const waLink = computed(() =>
    props.summary.outstanding_total > 0
        ? reminderLink({
              phone: props.customer.phone,
              customerName: props.customer.name,
              storeName: props.store.name,
              amount: props.summary.outstanding_total,
              dueAt: earliestDue.value,
          })
        : null,
);

const payOpen = ref(false);

function goPaymentsPage(page: number) {
    router.get(`/receivables/customers/${props.customer.id}`, { payments_page: page }, { preserveScroll: true, preserveState: true });
}
</script>

<template>
    <AdminLayout title="Piutang Pelanggan">
        <Head :title="`Piutang ${customer.name}`" />

        <div class="mx-auto max-w-4xl space-y-4">
            <!-- Kepala -->
            <div class="flex flex-wrap items-start gap-3">
                <Link href="/receivables" class="rounded-xl p-2 text-slate-500 hover:bg-slate-100" aria-label="Kembali ke daftar piutang">
                    <ArrowLeft class="h-5 w-5" />
                </Link>
                <div class="min-w-0 flex-1">
                    <h1 class="truncate text-xl font-bold text-slate-900">{{ customer.name }}</h1>
                    <p class="mt-0.5 flex flex-wrap gap-x-3 gap-y-1 text-xs text-slate-500">
                        <span v-if="customer.phone" class="flex items-center gap-1"><Phone class="h-3 w-3" /> {{ customer.phone }}</span>
                        <span v-if="customer.email" class="flex items-center gap-1"><Mail class="h-3 w-3" /> {{ customer.email }}</span>
                        <span v-if="customer.address" class="flex items-center gap-1"><MapPin class="h-3 w-3" /> {{ customer.address }}</span>
                        <Link :href="`/customers/${customer.id}`" class="font-semibold text-primary hover:underline">Profil pelanggan</Link>
                    </p>
                </div>
                <div class="flex w-full gap-2 sm:w-auto">
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
                        v-if="summary.outstanding_total > 0"
                        type="button"
                        class="inline-flex flex-1 items-center justify-center gap-1.5 rounded-xl bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90 sm:flex-none"
                        @click="payOpen = true"
                    >
                        <HandCoins class="h-4 w-4" /> Terima pembayaran
                    </button>
                </div>
            </div>

            <!-- Ringkasan -->
            <div class="grid grid-cols-3 gap-3">
                <div class="rounded-2xl border border-slate-200 bg-white p-4">
                    <p class="text-xs font-semibold text-slate-500">Sisa utang</p>
                    <p class="mt-1 text-base font-bold sm:text-lg" :class="summary.overdue_count > 0 ? 'text-red-600' : 'text-slate-900'">
                        {{ rupiah(summary.outstanding_total) }}
                    </p>
                    <p v-if="summary.overdue_count > 0" class="text-xs text-red-600">{{ summary.overdue_count }} bon lewat tempo</p>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-white p-4">
                    <p class="text-xs font-semibold text-slate-500">Sudah dibayar</p>
                    <p class="mt-1 text-base font-bold sm:text-lg text-slate-900">{{ rupiah(summary.total_paid) }}</p>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-white p-4">
                    <p class="text-xs font-semibold text-slate-500">Bon belum lunas</p>
                    <p class="mt-1 text-base font-bold sm:text-lg text-slate-900">{{ summary.active_count }}</p>
                </div>
            </div>

            <!-- Bon -->
            <section class="rounded-2xl border border-slate-200 bg-white">
                <h2 class="flex items-center gap-2 border-b border-slate-100 px-4 py-3 text-base font-bold text-slate-900">
                    <Receipt class="h-4 w-4 text-slate-400" /> Bon utang
                </h2>
                <p v-if="!transactions.length" class="px-4 py-10 text-center text-sm text-slate-500">Tidak ada bon utang.</p>
                <ul v-else class="divide-y divide-slate-100">
                    <li v-for="t in transactions" :key="t.id">
                        <Link :href="`/receivables/${t.id}`" class="flex items-center gap-3 px-4 py-3 hover:bg-slate-50">
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-semibold text-slate-900">{{ t.transaction_number }}</span>
                                <span class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-500">
                                    {{ formatDate(t.created_at) }}
                                    <span
                                        class="rounded-full px-2 py-0.5 font-semibold"
                                        :class="DUE_TONE_CLASS[dueStatus(t.debt_due_at, t.payment_status === 'paid').tone]"
                                    >
                                        {{ dueStatus(t.debt_due_at, t.payment_status === 'paid').label }}
                                    </span>
                                </span>
                            </span>
                            <span class="shrink-0 text-right">
                                <span
                                    v-if="t.payment_status !== 'paid'"
                                    class="block text-sm font-bold"
                                    :class="t.is_overdue ? 'text-red-600' : 'text-slate-900'"
                                >
                                    {{ rupiah(t.outstanding) }}
                                </span>
                                <span class="block text-xs text-slate-400">dari {{ rupiah(t.total_amount) }}</span>
                            </span>
                            <ChevronRight class="h-4 w-4 shrink-0 text-slate-300" />
                        </Link>
                    </li>
                </ul>
            </section>

            <!-- Riwayat pembayaran -->
            <section class="rounded-2xl border border-slate-200 bg-white">
                <h2 class="border-b border-slate-100 px-4 py-3 text-base font-bold text-slate-900">Riwayat pembayaran</h2>
                <p v-if="!payments.data.length" class="px-4 py-10 text-center text-sm text-slate-500">Belum ada pembayaran tercatat.</p>
                <ul v-else class="divide-y divide-slate-100">
                    <li v-for="p in payments.data" :key="p.id" class="flex items-start justify-between gap-3 px-4 py-3 text-sm">
                        <span class="min-w-0">
                            <span class="block font-semibold text-slate-800">
                                {{ paymentMethodLabel(p.payment_method) }}
                                <Link
                                    v-if="p.transaction_id"
                                    :href="`/receivables/${p.transaction_id}`"
                                    class="ml-1 text-xs font-normal text-primary hover:underline"
                                >
                                    {{ p.transaction_number }}
                                </Link>
                            </span>
                            <span class="block text-xs text-slate-500">
                                {{ formatDateTime(p.received_at) }}<template v-if="p.user_name"> · {{ p.user_name }}</template>
                            </span>
                            <span v-if="p.reference_number" class="block text-xs text-slate-500">Ref: {{ p.reference_number }}</span>
                            <span v-if="p.notes" class="block text-xs text-slate-500 italic">{{ p.notes }}</span>
                        </span>
                        <span class="shrink-0 font-bold text-emerald-600">+{{ rupiah(p.amount) }}</span>
                    </li>
                </ul>
                <div v-if="payments.last_page > 1" class="flex items-center justify-between border-t border-slate-100 px-4 py-3">
                    <p class="text-xs text-slate-500">{{ payments.from }}–{{ payments.to }} dari {{ payments.total }}</p>
                    <div class="flex gap-1">
                        <button
                            type="button"
                            class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 disabled:opacity-40"
                            :disabled="payments.current_page <= 1"
                            aria-label="Sebelumnya"
                            @click="goPaymentsPage(payments.current_page - 1)"
                        >
                            <ChevronLeft class="h-4 w-4" />
                        </button>
                        <button
                            type="button"
                            class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 disabled:opacity-40"
                            :disabled="payments.current_page >= payments.last_page"
                            aria-label="Berikutnya"
                            @click="goPaymentsPage(payments.current_page + 1)"
                        >
                            <ChevronRight class="h-4 w-4" />
                        </button>
                    </div>
                </div>
            </section>
        </div>

        <ReceivablePaymentDialog
            :open="payOpen"
            :title="`Terima pembayaran dari ${customer.name}`"
            :outstanding="summary.outstanding_total"
            :submit-url="`/receivables/customers/${customer.id}/payments`"
            :bills="openBills"
            @close="payOpen = false"
        />
    </AdminLayout>
</template>
