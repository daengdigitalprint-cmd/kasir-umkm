<script setup lang="ts">
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Separator } from '@/components/ui/separator';
import { Users, Phone, MapPin, Mail, Calendar, ArrowLeft, Edit, ShoppingBag, Gift, Star, CreditCard, Copy, Check, HandCoins, MessageCircle } from 'lucide-vue-next';
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { confirmDialog } from '@/lib/swal';
import ReceivablePaymentDialog from '@/components/receivables/ReceivablePaymentDialog.vue';
import { DUE_TONE_CLASS, dueStatus, reminderLink } from '@/composables/useReceivables';

const referralCopied = ref(false);

interface TransactionItem {
    id: number;
    product_name: string;
    quantity: number;
    unit_price: number;
    subtotal: number;
    product?: { name: string };
}

interface Transaction {
    id: number;
    sale_number: string;
    total_amount: number;
    payment_method: string;
    created_at: string;
    items?: TransactionItem[];
}

interface Receivable {
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

interface Customer {
    id: number;
    name: string;
    email: string | null;
    phone: string | null;
    address: string | null;
    created_at: string;
    referral_code?: string | null;
}

interface PointLedger {
    id: number;
    type: string;
    points: number;
    balance_after: number;
    description: string | null;
    expires_at: string | null;
    created_at: string;
}

interface LoyaltyReward {
    id: number;
    name: string;
    type: string;
    points_cost: number;
    voucher_value: string | null;
    stock: number | null;
    is_active: boolean;
}

interface Loyalty {
    enabled: boolean;
    points_balance: number;
    points_earned_total: number;
    points_redeemed_total: number;
    history: PointLedger[];
    rewards: LoyaltyReward[];
}

interface Props {
    customer: Customer;
    transactions: {
        data: Transaction[];
        links: any[];
        meta: any;
    };
    loyalty: Loyalty;
    receivables: Receivable[];
    totalOutstanding: number;
    store: {
        id: number;
        name: string;
        currency: string;
    };
}

const props = defineProps<Props>();

const copyReferralCode = async () => {
    if (! props.customer.referral_code) {
        return;
    }
    try {
        await navigator.clipboard.writeText(props.customer.referral_code);
        referralCopied.value = true;
        setTimeout(() => (referralCopied.value = false), 2000);
    } catch {
        // Clipboard tidak tersedia — abaikan.
    }
};

const formatCurrency = (amount: number) =>
    new Intl.NumberFormat('id-ID', { style: 'decimal', minimumFractionDigits: 0 }).format(amount);

const formatDate = (dateString: string) =>
    new Date(dateString).toLocaleDateString('id-ID', { year: 'numeric', month: 'long', day: 'numeric' });

const paymentMethodLabel = (method: string) => {
    const map: Record<string, string> = {
        cash: 'Tunai', qris: 'QRIS', transfer: 'Transfer', card: 'Kartu',
        ewallet: 'E-Wallet', other: 'Lainnya',
    };
    return map[method] ?? method;
};

/** Jumlah seluruh transaksi (paginator Laravel menaruh total di tingkat atas). */
const transactionCount = computed(() => (props.transactions as unknown as { total?: number }).total ?? props.transactions.data.length);
const totalSpent = () => Number((props.customer as { total_spent?: number | string }).total_spent ?? 0);

const ledgerTypeLabel = (type: string) => {
    const map: Record<string, string> = {
        earn: 'Perolehan',
        redeem: 'Penukaran',
        expire: 'Kadaluarsa',
        adjust: 'Penyesuaian',
        bonus_birthday: 'Bonus ulang tahun',
        bonus_referral: 'Bonus referral',
    };
    return map[type] ?? type;
};

const affordableRewards = computed(() =>
    props.loyalty.rewards.filter(
        (r) => r.is_active && (r.stock === null || r.stock > 0),
    ),
);

const redeemReward = async (reward: LoyaltyReward) => {
    const ok = await confirmDialog(
        'Tukar hadiah?',
        `Tukar "${reward.name}" seharga ${reward.points_cost} poin untuk ${props.customer.name}?`,
        { confirmText: 'Ya, tukar', icon: 'question' },
    );
    if (!ok) return;
    router.post(
        '/loyalty/redeem',
        { customer_id: props.customer.id, reward_id: reward.id },
        { preserveScroll: true },
    );
};

// ─── Pelunasan piutang (dialog yang sama dengan halaman Piutang) ──────────────
/** null = terima sekaligus (dibagi ke bon tertua); berisi bon = bayar bon itu saja. */
const payTarget = ref<Receivable | null>(null);
const payOpen = ref(false);
function openPayAll() {
    payTarget.value = null;
    payOpen.value = true;
}
function openPayBill(r: Receivable) {
    payTarget.value = r;
    payOpen.value = true;
}
const earliestDue = computed(() => props.receivables.find((r) => r.debt_due_at)?.debt_due_at ?? null);
const waReminder = computed(() =>
    props.totalOutstanding > 0
        ? reminderLink({
              phone: props.customer.phone,
              customerName: props.customer.name,
              storeName: props.store.name,
              amount: props.totalOutstanding,
              dueAt: earliestDue.value,
          })
        : null,
);
</script>

<template>
    <AdminLayout title="Detail Pelanggan">
        <Head title="Detail Pelanggan" />

        <div class="max-w-3xl mx-auto space-y-5 p-4">
            <!-- Header -->
            <div class="flex items-center gap-3">
                <Link href="/customers">
                    <Button variant="ghost" size="sm" class="gap-1.5 rounded-xl">
                        <ArrowLeft class="h-4 w-4" />
                        Kembali
                    </Button>
                </Link>
                <h1 class="text-xl font-black text-slate-900 flex-1">Detail Pelanggan</h1>
                <Link :href="`/customers/${customer.id}/edit`">
                    <Button size="sm" class="gap-1.5 rounded-xl">
                        <Edit class="h-4 w-4" />
                        Edit
                    </Button>
                </Link>
            </div>

            <!-- Info pelanggan -->
            <Card class="rounded-2xl border border-slate-200 shadow-none">
                <CardContent class="p-5">
                    <div class="flex items-center gap-4 mb-4">
                        <div class="w-14 h-14 rounded-full bg-primary/10 flex items-center justify-center shrink-0">
                            <Users class="h-7 w-7 text-primary" />
                        </div>
                        <div>
                            <h2 class="text-lg font-black text-slate-900">{{ customer.name }}</h2>
                            <p class="text-xs text-slate-400">Bergabung {{ formatDate(customer.created_at) }}</p>
                        </div>
                    </div>
                    <Separator class="mb-4" />
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
                        <div class="flex items-center gap-2 text-slate-600">
                            <Mail class="h-4 w-4 text-slate-400 shrink-0" />
                            <span>{{ customer.email ?? '-' }}</span>
                        </div>
                        <div class="flex items-center gap-2 text-slate-600">
                            <Phone class="h-4 w-4 text-slate-400 shrink-0" />
                            <span>{{ customer.phone ?? '-' }}</span>
                        </div>
                        <div class="flex items-center gap-2 text-slate-600 sm:col-span-2">
                            <MapPin class="h-4 w-4 text-slate-400 shrink-0" />
                            <span>{{ customer.address ?? '-' }}</span>
                        </div>
                        <div
                            v-if="customer.referral_code"
                            class="flex items-center gap-2 text-slate-600 sm:col-span-2"
                        >
                            <Gift class="h-4 w-4 text-slate-400 shrink-0" />
                            <span class="font-mono uppercase">{{ customer.referral_code }}</span>
                            <button
                                type="button"
                                class="inline-flex items-center gap-1 text-xs font-semibold text-primary hover:underline"
                                @click="copyReferralCode"
                            >
                                <Check v-if="referralCopied" class="h-3.5 w-3.5" />
                                <Copy v-else class="h-3.5 w-3.5" />
                                {{ referralCopied ? 'Tersalin' : 'Salin' }}
                            </button>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- Ringkasan -->
            <div class="grid grid-cols-2 gap-3">
                <Card class="rounded-2xl border border-slate-200 shadow-none">
                    <CardContent class="p-4 text-center">
                        <p class="text-xs text-slate-400 mb-1">Total Transaksi</p>
                        <p class="text-2xl font-black text-slate-900">{{ transactionCount }}</p>
                    </CardContent>
                </Card>
                <Card class="rounded-2xl border border-slate-200 shadow-none">
                    <CardContent class="p-4 text-center">
                        <p class="text-xs text-slate-400 mb-1">Total Belanja</p>
                        <p class="text-lg font-black text-slate-900">Rp {{ formatCurrency(totalSpent()) }}</p>
                    </CardContent>
                </Card>
            </div>

            <!-- Utang pelanggan -->
            <Card v-if="receivables.length > 0" class="rounded-2xl border border-slate-200 shadow-none">
                <CardHeader class="px-5 pt-5 pb-3">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <CardTitle class="flex items-center gap-2 text-base font-bold text-slate-900">
                            <CreditCard class="h-4 w-4 text-slate-400" />
                            Utang belum lunas
                        </CardTitle>
                        <span class="text-lg font-bold text-red-600">Rp {{ formatCurrency(totalOutstanding) }}</span>
                    </div>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <Button size="sm" class="gap-1.5 rounded-xl" @click="openPayAll"><HandCoins class="h-4 w-4" /> Terima pembayaran</Button>
                        <a
                            v-if="waReminder"
                            :href="waReminder"
                            target="_blank"
                            rel="noopener"
                            class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 px-3 py-1.5 text-sm font-semibold text-emerald-700 hover:bg-emerald-50"
                        >
                            <MessageCircle class="h-4 w-4" /> Ingatkan via WA
                        </a>
                    </div>
                </CardHeader>
                <CardContent class="px-5 pb-5">
                    <ul class="divide-y divide-slate-100">
                        <li v-for="r in receivables" :key="r.id" class="flex items-center gap-3 py-2.5">
                            <Link :href="`/receivables/${r.id}`" class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-semibold text-slate-800">{{ r.transaction_number }}</span>
                                <span class="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                                    {{ formatDate(r.created_at) }}
                                    <span class="rounded-full px-2 py-0.5 font-semibold" :class="DUE_TONE_CLASS[dueStatus(r.debt_due_at).tone]">{{ dueStatus(r.debt_due_at).label }}</span>
                                </span>
                            </Link>
                            <span class="shrink-0 text-right">
                                <span class="block text-sm font-bold" :class="r.is_overdue ? 'text-red-600' : 'text-slate-900'">Rp {{ formatCurrency(r.outstanding) }}</span>
                                <span class="block text-xs text-slate-400">dari Rp {{ formatCurrency(r.total_amount) }}</span>
                            </span>
                            <Button size="sm" variant="outline" class="h-8 shrink-0 rounded-lg px-2.5 text-xs" @click="openPayBill(r)">Bayar</Button>
                        </li>
                    </ul>
                    <p class="mt-3 text-center text-xs">
                        <Link :href="`/receivables/customers/${customer.id}`" class="font-semibold text-primary hover:underline">Riwayat utang & pembayaran →</Link>
                    </p>
                </CardContent>
            </Card>

            <ReceivablePaymentDialog
                :open="payOpen"
                :title="payTarget ? `Bayar ${payTarget.transaction_number}` : `Terima pembayaran dari ${customer.name}`"
                :outstanding="payTarget ? payTarget.outstanding : totalOutstanding"
                :submit-url="payTarget ? '/receivable-payments' : `/receivables/customers/${customer.id}/payments`"
                :extra="payTarget ? { transaction_id: payTarget.id } : undefined"
                :bills="payTarget ? undefined : receivables"
                @close="payOpen = false"
            />

            <!-- Loyalitas & Poin -->
            <template v-if="loyalty.enabled">
                <div class="grid grid-cols-3 gap-3">
                    <Card class="rounded-2xl border border-amber-200 bg-amber-50 shadow-none">
                        <CardContent class="p-4 text-center">
                            <p class="text-xs text-amber-600 mb-1 flex items-center justify-center gap-1">
                                <Star class="h-3 w-3" /> Saldo Poin
                            </p>
                            <p class="text-2xl font-black text-amber-700">{{ loyalty.points_balance }}</p>
                        </CardContent>
                    </Card>
                    <Card class="rounded-2xl border border-slate-200 shadow-none">
                        <CardContent class="p-4 text-center">
                            <p class="text-xs text-slate-400 mb-1">Total Diperoleh</p>
                            <p class="text-lg font-black text-slate-900">{{ loyalty.points_earned_total }}</p>
                        </CardContent>
                    </Card>
                    <Card class="rounded-2xl border border-slate-200 shadow-none">
                        <CardContent class="p-4 text-center">
                            <p class="text-xs text-slate-400 mb-1">Total Ditukar</p>
                            <p class="text-lg font-black text-slate-900">{{ loyalty.points_redeemed_total }}</p>
                        </CardContent>
                    </Card>
                </div>

                <!-- Tukar hadiah -->
                <Card class="rounded-2xl border border-slate-200 shadow-none">
                    <CardHeader class="px-5 pt-5 pb-3">
                        <CardTitle class="text-base font-black text-slate-900 flex items-center gap-2">
                            <Gift class="h-4 w-4 text-primary" />
                            Tukar Hadiah
                        </CardTitle>
                    </CardHeader>
                    <CardContent class="px-5 pb-5">
                        <div v-if="affordableRewards.length === 0" class="text-center py-6 text-slate-400 text-sm">
                            Belum ada hadiah tersedia.
                        </div>
                        <div v-else class="space-y-2">
                            <div
                                v-for="reward in affordableRewards"
                                :key="reward.id"
                                class="flex items-center justify-between p-3 rounded-xl bg-slate-50 border border-slate-100"
                            >
                                <div>
                                    <p class="text-sm font-bold text-slate-800">{{ reward.name }}</p>
                                    <p class="text-xs text-slate-400">
                                        {{ reward.points_cost }} poin
                                        <span v-if="reward.stock !== null"> · stok {{ reward.stock }}</span>
                                    </p>
                                </div>
                                <Button
                                    size="sm"
                                    class="rounded-xl"
                                    :disabled="loyalty.points_balance < reward.points_cost"
                                    @click="redeemReward(reward)"
                                >
                                    Tukar
                                </Button>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <!-- Riwayat poin -->
                <Card class="rounded-2xl border border-slate-200 shadow-none">
                    <CardHeader class="px-5 pt-5 pb-3">
                        <CardTitle class="text-base font-black text-slate-900 flex items-center gap-2">
                            <Star class="h-4 w-4 text-primary" />
                            Riwayat Poin
                        </CardTitle>
                    </CardHeader>
                    <CardContent class="px-5 pb-5">
                        <div v-if="loyalty.history.length === 0" class="text-center py-6 text-slate-400 text-sm">
                            Belum ada aktivitas poin.
                        </div>
                        <div v-else class="space-y-2">
                            <div
                                v-for="row in loyalty.history"
                                :key="row.id"
                                class="flex items-center justify-between p-3 rounded-xl bg-slate-50 border border-slate-100"
                            >
                                <div>
                                    <p class="text-sm font-bold text-slate-800">{{ ledgerTypeLabel(row.type) }}</p>
                                    <p class="text-xs text-slate-400">{{ row.description ?? '-' }} · {{ formatDate(row.created_at) }}</p>
                                </div>
                                <div class="text-right">
                                    <p
                                        class="text-sm font-black"
                                        :class="row.points >= 0 ? 'text-emerald-600' : 'text-red-600'"
                                    >
                                        {{ row.points >= 0 ? '+' : '' }}{{ row.points }}
                                    </p>
                                    <p class="text-xs text-slate-400">Saldo: {{ row.balance_after }}</p>
                                </div>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </template>

            <!-- Riwayat transaksi -->
            <Card class="rounded-2xl border border-slate-200 shadow-none">
                <CardHeader class="px-5 pt-5 pb-3">
                    <CardTitle class="text-base font-black text-slate-900 flex items-center gap-2">
                        <ShoppingBag class="h-4 w-4 text-primary" />
                        Riwayat Transaksi
                    </CardTitle>
                </CardHeader>
                <CardContent class="px-5 pb-5">
                    <div v-if="transactions.data.length === 0" class="text-center py-8 text-slate-400 text-sm">
                        Belum ada transaksi.
                    </div>
                    <div v-else class="space-y-3">
                        <div
                            v-for="tx in transactions.data"
                            :key="tx.id"
                            class="flex items-center justify-between p-3 rounded-xl bg-slate-50 border border-slate-100"
                        >
                            <div>
                                <p class="text-sm font-bold text-slate-800">{{ tx.sale_number }}</p>
                                <p class="text-xs text-slate-400 flex items-center gap-1 mt-0.5">
                                    <Calendar class="h-3 w-3" />
                                    {{ formatDate(tx.created_at) }}
                                </p>
                            </div>
                            <div class="text-right">
                                <p class="text-sm font-black text-slate-900">Rp {{ formatCurrency(tx.total_amount) }}</p>
                                <Badge variant="secondary" class="text-xs mt-0.5">{{ paymentMethodLabel(tx.payment_method) }}</Badge>
                            </div>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AdminLayout>
</template>
