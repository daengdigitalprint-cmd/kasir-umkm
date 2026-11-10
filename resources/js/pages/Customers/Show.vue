<script setup lang="ts">
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { useForm } from '@inertiajs/vue3';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Separator } from '@/components/ui/separator';
import { Users, Phone, MapPin, Mail, Calendar, ArrowLeft, Edit, ShoppingBag, Gift, Star, CreditCard, AlertTriangle, CheckCircle2, Copy, Check } from 'lucide-vue-next';
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { confirmDialog } from '@/lib/swal';

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

const totalSpent = () =>
    props.transactions.data.reduce((sum, t) => sum + Number(t.total_amount), 0);

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

// ─── Pelunasan piutang ────────────────────────────────────────────────────────
const selectedReceivable = ref<Receivable | null>(null);
const paymentFormOpen = ref(false);

const paymentForm = useForm({
    transaction_id: 0,
    amount: 0,
    payment_method: 'cash' as 'cash' | 'card' | 'ewallet' | 'transfer' | 'other',
    received_at: new Date().toISOString().slice(0, 10),
    reference_number: '',
    notes: '',
    idempotency_key: '',
});

function openPaymentForm(receivable: Receivable) {
    selectedReceivable.value = receivable;
    paymentForm.transaction_id = receivable.id;
    paymentForm.amount = receivable.outstanding;
    paymentForm.payment_method = 'cash';
    paymentForm.received_at = new Date().toISOString().slice(0, 10);
    paymentForm.reference_number = '';
    paymentForm.notes = '';
    paymentForm.idempotency_key = `rp-${receivable.id}-${Date.now()}`;
    paymentFormOpen.value = true;
}

function submitPayment() {
    paymentForm.post('/receivable-payments', {
        preserveScroll: true,
        onSuccess: () => {
            paymentFormOpen.value = false;
            selectedReceivable.value = null;
        },
    });
}
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
            <Card class="rounded-2xl border border-slate-200 shadow-sm">
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
                <Card class="rounded-2xl border border-slate-200 shadow-sm">
                    <CardContent class="p-4 text-center">
                        <p class="text-xs text-slate-400 mb-1">Total Transaksi</p>
                        <p class="text-2xl font-black text-slate-900">{{ transactions.meta?.total ?? transactions.data.length }}</p>
                    </CardContent>
                </Card>
                <Card class="rounded-2xl border border-slate-200 shadow-sm">
                    <CardContent class="p-4 text-center">
                        <p class="text-xs text-slate-400 mb-1">Total Belanja</p>
                        <p class="text-lg font-black text-slate-900">Rp {{ formatCurrency(totalSpent()) }}</p>
                    </CardContent>
                </Card>
            </div>

            <!-- Piutang -->
            <Card v-if="receivables.length > 0 || totalOutstanding > 0" class="rounded-2xl border border-orange-200 shadow-sm">
                <CardHeader class="px-5 pt-5 pb-3">
                    <CardTitle class="text-base font-black text-slate-900 flex items-center justify-between gap-2">
                        <span class="flex items-center gap-2">
                            <CreditCard class="h-4 w-4 text-orange-500" />
                            Piutang Aktif
                        </span>
                        <span v-if="totalOutstanding > 0" class="text-sm font-bold text-orange-600 bg-orange-50 px-2.5 py-1 rounded-full">
                            Rp {{ formatCurrency(totalOutstanding) }}
                        </span>
                    </CardTitle>
                </CardHeader>
                <CardContent class="px-5 pb-5">
                    <div v-if="receivables.length === 0" class="text-center py-4 text-slate-400 text-sm">
                        Tidak ada piutang aktif.
                    </div>
                    <div v-else class="space-y-3">
                        <div
                            v-for="r in receivables"
                            :key="r.id"
                            :class="['p-3 rounded-xl border', r.is_overdue ? 'border-red-200 bg-red-50' : 'border-orange-100 bg-orange-50/40']"
                        >
                            <div class="flex items-start justify-between gap-2 mb-2">
                                <div>
                                    <p class="text-sm font-bold text-slate-800">{{ r.transaction_number }}</p>
                                    <p class="text-xs text-slate-400 mt-0.5">{{ formatDate(r.created_at) }}</p>
                                </div>
                                <div class="text-right shrink-0">
                                    <p class="text-sm font-black text-orange-700">Rp {{ formatCurrency(r.outstanding) }}</p>
                                    <p class="text-xs text-slate-400">dari Rp {{ formatCurrency(r.total_amount) }}</p>
                                </div>
                            </div>
                            <div class="flex items-center justify-between gap-2">
                                <div class="flex items-center gap-2 text-xs">
                                    <span v-if="r.is_overdue" class="flex items-center gap-1 text-red-600 font-medium">
                                        <AlertTriangle class="h-3 w-3" /> Lewat jatuh tempo
                                    </span>
                                    <span v-else-if="r.debt_due_at" class="text-slate-500">
                                        Jatuh tempo: {{ formatDate(r.debt_due_at) }}
                                    </span>
                                    <Badge variant="secondary" class="text-xs">{{ r.payment_status === 'unpaid' ? 'Belum Bayar' : 'Sebagian' }}</Badge>
                                </div>
                                <Button size="sm" class="h-7 px-3 text-xs rounded-lg" @click="openPaymentForm(r)">
                                    Catat Bayar
                                </Button>
                            </div>
                        </div>
                    </div>

                    <!-- Link ke detail piutang masing-masing -->
                    <p class="mt-3 text-center text-xs text-slate-400">
                        <a :href="`/receivables?customer_id=${customer.id}`" class="text-primary hover:underline">Lihat semua riwayat piutang →</a>
                    </p>
                </CardContent>
            </Card>

            <!-- Modal form pelunasan -->
            <div v-if="paymentFormOpen" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-4 bg-black/40 backdrop-blur-sm">
                <div class="bg-white w-full max-w-md rounded-2xl shadow-2xl overflow-hidden">
                    <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100">
                        <h3 class="font-black text-slate-900">Catat Pembayaran Piutang</h3>
                        <button type="button" class="text-gray-400 hover:text-gray-600 p-1" @click="paymentFormOpen = false">✕</button>
                    </div>
                    <div class="px-5 py-4 space-y-4">
                        <div v-if="selectedReceivable" class="p-3 bg-orange-50 rounded-xl text-sm">
                            <p class="font-semibold text-slate-700">{{ selectedReceivable.transaction_number }}</p>
                            <p class="text-orange-700 font-bold mt-0.5">Sisa piutang: Rp {{ formatCurrency(selectedReceivable.outstanding) }}</p>
                        </div>

                        <div class="space-y-1.5">
                            <label class="text-sm font-medium text-gray-700">Jumlah Dibayar (Rp)</label>
                            <input
                                v-model.number="paymentForm.amount"
                                type="number"
                                :max="selectedReceivable?.outstanding ?? 0"
                                class="w-full h-10 px-3 rounded-lg border border-gray-300 text-sm focus:outline-none focus:ring-2 focus:ring-primary/30"
                            />
                            <p v-if="paymentForm.errors.amount" class="text-xs text-red-600">{{ paymentForm.errors.amount }}</p>
                        </div>

                        <div class="space-y-1.5">
                            <label class="text-sm font-medium text-gray-700">Metode Pembayaran</label>
                            <div class="grid grid-cols-3 gap-1.5">
                                <button
                                    v-for="m in [{ key: 'cash', label: 'Tunai' }, { key: 'transfer', label: 'Transfer' }, { key: 'ewallet', label: 'E-Wallet' }, { key: 'card', label: 'Kartu' }, { key: 'other', label: 'Lainnya' }]"
                                    :key="m.key"
                                    type="button"
                                    :class="['h-9 rounded-lg border text-xs font-medium transition-colors', paymentForm.payment_method === m.key ? 'bg-primary text-white border-primary' : 'border-gray-300 text-gray-700 hover:bg-gray-50']"
                                    @click="paymentForm.payment_method = m.key as any"
                                >{{ m.label }}</button>
                            </div>
                        </div>

                        <div class="space-y-1.5">
                            <label class="text-sm font-medium text-gray-700">Tanggal Diterima</label>
                            <input
                                v-model="paymentForm.received_at"
                                type="date"
                                class="w-full h-10 px-3 rounded-lg border border-gray-300 text-sm focus:outline-none focus:ring-2 focus:ring-primary/30"
                            />
                        </div>

                        <div class="space-y-1.5">
                            <label class="text-sm font-medium text-gray-700">No. Referensi <span class="font-normal text-gray-400">(Opsional)</span></label>
                            <input
                                v-model="paymentForm.reference_number"
                                type="text"
                                placeholder="Nomor bukti / referensi transfer"
                                class="w-full h-10 px-3 rounded-lg border border-gray-300 text-sm focus:outline-none focus:ring-2 focus:ring-primary/30"
                            />
                        </div>

                        <div class="space-y-1.5">
                            <label class="text-sm font-medium text-gray-700">Catatan <span class="font-normal text-gray-400">(Opsional)</span></label>
                            <textarea
                                v-model="paymentForm.notes"
                                rows="2"
                                class="w-full px-3 py-2 rounded-lg border border-gray-300 text-sm focus:outline-none focus:ring-2 focus:ring-primary/30 resize-none"
                            ></textarea>
                        </div>
                    </div>
                    <div class="flex gap-2 px-5 pb-5">
                        <Button variant="outline" class="flex-1" @click="paymentFormOpen = false">Batal</Button>
                        <Button class="flex-1" :disabled="paymentForm.processing || paymentForm.amount <= 0" @click="submitPayment">
                            <div v-if="paymentForm.processing" class="h-4 w-4 mr-2 border-2 border-white border-t-transparent rounded-full animate-spin"></div>
                            {{ paymentForm.processing ? 'Memproses...' : 'Catat Pembayaran' }}
                        </Button>
                    </div>
                </div>
            </div>

            <!-- Loyalitas & Poin -->
            <template v-if="loyalty.enabled">
                <div class="grid grid-cols-3 gap-3">
                    <Card class="rounded-2xl border border-amber-200 bg-amber-50 shadow-sm">
                        <CardContent class="p-4 text-center">
                            <p class="text-xs text-amber-600 mb-1 flex items-center justify-center gap-1">
                                <Star class="h-3 w-3" /> Saldo Poin
                            </p>
                            <p class="text-2xl font-black text-amber-700">{{ loyalty.points_balance }}</p>
                        </CardContent>
                    </Card>
                    <Card class="rounded-2xl border border-slate-200 shadow-sm">
                        <CardContent class="p-4 text-center">
                            <p class="text-xs text-slate-400 mb-1">Total Diperoleh</p>
                            <p class="text-lg font-black text-slate-900">{{ loyalty.points_earned_total }}</p>
                        </CardContent>
                    </Card>
                    <Card class="rounded-2xl border border-slate-200 shadow-sm">
                        <CardContent class="p-4 text-center">
                            <p class="text-xs text-slate-400 mb-1">Total Ditukar</p>
                            <p class="text-lg font-black text-slate-900">{{ loyalty.points_redeemed_total }}</p>
                        </CardContent>
                    </Card>
                </div>

                <!-- Tukar hadiah -->
                <Card class="rounded-2xl border border-slate-200 shadow-sm">
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
                <Card class="rounded-2xl border border-slate-200 shadow-sm">
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
            <Card class="rounded-2xl border border-slate-200 shadow-sm">
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
