<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Head, usePage } from '@inertiajs/vue3';
import {
    Crown, Check, Clock, Calendar, Shield,
    AlertCircle, CheckCircle, XCircle, Loader2, FileText,
    Store, Plus, CalendarCheck,
} from 'lucide-vue-next';
import { ref, computed } from 'vue';
import AdminLayout from '@/layouts/AdminLayout.vue';

type BillingCycle = 'monthly' | 'yearly';

interface Plan {
    id: number;
    name: string;
    slug: string;
    description: string;
    price: number;
    duration_days: number;
    max_products: number | null;
    max_customers: number | null;
    features: string[] | null;
    is_active: boolean;
    is_popular: boolean;
    billing_cycle?: BillingCycle;
}

interface Payment {
    id: number;
    invoice_number: string;
    amount: number;
    duration_days: number;
    payment_method: string;
    status: string;
    notes: string | null;
    admin_notes: string | null;
    created_at: string;
    plan?: Plan;
}

interface OutletInfo {
    current_count: number;
    free_limit: number;
    extra_outlets: number;
    total_limit: number;
    can_buy_addon: boolean;
    price_per_outlet_monthly: number;
    has_pending_addon: boolean;
    is_unlimited?: boolean;
}

interface AttendancePayrollInfo {
    is_purchased: boolean;
    expires_at: string | null;
    pricing: {
        monthly_price: number;
        yearly_price: number;
    };
    has_pending_addon: boolean;
}

interface Props {
    store: {
        id: number;
        name: string;
        is_active: boolean;
        subscription_start: string | null;
        subscription_end: string | null;
        subscription_days: number;
        is_subscription_active: boolean;
    };
    plans: Plan[];
    yearlyDiscountPercentLabel?: string;
    latestPayment?: Payment;
    paymentHistory: Payment[];
    outletInfo?: OutletInfo;
    attendancePayrollInfo?: AttendancePayrollInfo;
}

const props = withDefaults(defineProps<Props>(), {
    yearlyDiscountPercentLabel: '16,7',
});

const { store, plans, latestPayment, paymentHistory, yearlyDiscountPercentLabel, outletInfo, attendancePayrollInfo } = props;

const page = usePage();
const sharedSubscription = computed(
    () => (page.props as { subscription?: { starter_free?: boolean } | null }).subscription,
);
const sharedPlanLimits = computed(
    () => (page.props as { plan_limits?: { plan_slug?: string | null } | null }).plan_limits,
);

const isStarterFreeUi = computed(() => {
    if (!store?.is_subscription_active) return false;
    if (sharedSubscription.value?.starter_free) return true;
    const slug = sharedPlanLimits.value?.plan_slug ?? null;
    return slug === 'starter' || slug === 'starter-tahunan';
});

const showHistory = ref(false);
const billingTab = ref<BillingCycle>('monthly');
const activeTab = ref<'subscription' | 'outlet' | 'attendance'>('subscription');
const showAllFeatures = ref<Record<number, boolean>>({});
const FEATURES_COLLAPSE_COUNT = 5;

const toggleFeatures = (planId: number) => {
    showAllFeatures.value[planId] = !showAllFeatures.value[planId];
};

const visibleFeatures = (plan: Plan) => {
    const features = plan.features || [];
    const featuresWithoutProduk = features.filter(f => !f.toLowerCase().includes('produk'));
    if (showAllFeatures.value[plan.id]) return featuresWithoutProduk;
    return featuresWithoutProduk.slice(0, FEATURES_COLLAPSE_COUNT);
};

const hasMoreFeatures = (plan: Plan) => {
    const features = (plan.features || []).filter(f => !f.toLowerCase().includes('produk'));
    return features.length > FEATURES_COLLAPSE_COUNT;
};

const goToOutletCheckout = () => {
    window.location.href = '/subscription/checkout-outlet';
};

const goToAttendancePayrollCheckout = () => {
    window.location.href = '/subscription/checkout-attendance-payroll';
};

const visiblePlans = computed(() =>
    plans.filter(
        (p) =>
            (p.billing_cycle ?? 'monthly') === billingTab.value &&
            !p.slug.startsWith('promo-onboarding'),
    ),
);

const planCycleLabel = (plan: Plan) => ((plan.billing_cycle ?? 'monthly') === 'yearly' ? '/tahun' : '/bulan');

const bisnisMonthlyPrice = computed(() => {
    const p = plans.find((x) => x.slug === 'bisnis' && (x.billing_cycle ?? 'monthly') === 'monthly');
    if (p && p.price > 0) return p.price;
    const anyBisnis = plans.find((x) => x.slug === 'bisnis');
    return anyBisnis && anyBisnis.price > 0 ? anyBisnis.price : 99000;
});

const isPromoOnboardingPlan = (plan: Plan) => plan.slug.startsWith('promo-onboarding');

const isStarterPlanSlug = (slug: string) => slug === 'starter' || slug === 'starter-tahunan';

const promoNormalMonthlyPrice = 99000;
const promoTotalMonths = 12;
const promoPayMonths = 8;
const promoNormalYearPrice = promoNormalMonthlyPrice * promoTotalMonths;
const promoPayTotalPrice = promoNormalMonthlyPrice * promoPayMonths;
const promoSavings = promoNormalYearPrice - promoPayTotalPrice;

const yearlySavingsVsMonthly12 = (plan: Plan): number | null => {
    if (plan.slug !== 'bisnis-tahunan' || plan.price <= 0) return null;
    const fullYearMonthly = bisnisMonthlyPrice.value * 12;
    const save = Math.round(fullYearMonthly - plan.price);
    return save > 0 ? save : null;
};

const formatCurrency = (amount: number | string | null | undefined) => {
    const n = typeof amount === 'number' ? amount : Number.parseFloat(String(amount ?? ''));
    return new Intl.NumberFormat('id-ID').format(Number.isFinite(n) ? n : 0);
};
const formatDate = (date: string) => new Date(date).toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric' });

const getStatusBadge = (status: string) => {
    const map: Record<string, { text: string; class: string }> = {
        pending: { text: 'Menunggu', class: 'border-yellow-400 text-yellow-700 bg-yellow-50' },
        confirmed: { text: 'Dikonfirmasi', class: 'bg-green-100 text-green-800' },
        rejected: { text: 'Ditolak', class: 'bg-red-100 text-red-800' },
        expired: { text: 'Kedaluwarsa', class: 'bg-slate-100 text-slate-600' },
    };
    return map[status] || { text: status, class: 'bg-slate-100 text-slate-600' };
};

const getMethodLabel = (method: string) => {
    const map: Record<string, string> = { bank_transfer: 'Rekening', qris: 'QRIS', e_wallet: 'E-Wallet', cash: 'Tunai' };
    return map[method] || method;
};

const goToCheckout = (plan: Plan) => {
    window.location.href = `/subscription/checkout/${plan.id}`;
};

const daysRemaining = computed(() => {
    if (!store?.subscription_end) return 0;
    const now = new Date();
    const endDate = new Date(store.subscription_end);
    return Math.max(0, Math.ceil((endDate.getTime() - now.getTime()) / (1000 * 60 * 60 * 24)));
});

const subscriptionStatus = computed(() => {
    if (!store?.is_subscription_active) return 'inactive';
    if (isStarterFreeUi.value) return 'active';
    if (!store?.subscription_end) return 'unlimited';
    if (daysRemaining.value <= 0) return 'expired';
    if (daysRemaining.value <= 7) return 'expiring';
    return 'active';
});

const hasPendingPayment = computed(() => latestPayment?.status === 'pending');
</script>

<template>
    <Head title="Subscription" />
    <AdminLayout title="Subscription">
        <div class="pb-6 space-y-6 max-w-5xl mx-auto">

            <!-- Status Banner -->
            <div :class="[
                'relative overflow-hidden rounded-2xl p-6',
                subscriptionStatus === 'active' ? 'bg-gradient-to-br from-emerald-500 via-emerald-600 to-teal-700' :
                subscriptionStatus === 'expiring' ? 'bg-gradient-to-br from-amber-500 via-orange-500 to-orange-600' :
                subscriptionStatus === 'expired' ? 'bg-gradient-to-br from-red-500 via-red-600 to-rose-700' :
                'bg-gradient-to-br from-slate-600 via-slate-700 to-slate-800'
            ]">
                <div class="absolute top-0 right-0 w-64 h-64 bg-white/5 rounded-full -translate-y-32 translate-x-32"></div>
                <div class="absolute bottom-0 left-0 w-48 h-48 bg-white/5 rounded-full translate-y-24 -translate-x-24"></div>

                <div class="relative z-10 flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-3 mb-2">
                            <div class="p-2.5 bg-white/20 backdrop-blur-sm rounded-xl">
                                <Crown class="h-5 w-5 text-white" />
                            </div>
                            <h2 class="text-xl md:text-2xl font-bold text-white">Status Subscription</h2>
                        </div>
                        <p class="text-white/80 text-sm">{{ store.name }}</p>

                        <div class="flex flex-wrap items-center gap-3 mt-3">
                            <Badge class="px-3 py-1 text-xs font-bold rounded-full bg-white text-slate-800">
                                <component
                                    :is="subscriptionStatus === 'active' ? Shield :
                                         subscriptionStatus === 'expiring' ? AlertCircle :
                                         subscriptionStatus === 'expired' ? XCircle : AlertCircle"
                                    class="h-3.5 w-3.5 mr-1"
                                />
                                {{ subscriptionStatus === 'active' ? 'Aktif' :
                                   subscriptionStatus === 'expiring' ? 'Akan Berakhir' :
                                   subscriptionStatus === 'expired' ? 'Expired' : 'Nonaktif' }}
                            </Badge>
                            <span
                                v-if="store.subscription_end && daysRemaining > 0 && !isStarterFreeUi"
                                class="text-white/90 text-sm flex items-center gap-1.5"
                            >
                                <Clock class="h-3.5 w-3.5" /> {{ daysRemaining }} hari tersisa
                            </span>
                            <span
                                v-if="store.subscription_end && !isStarterFreeUi"
                                class="text-white/70 text-sm flex items-center gap-1.5"
                            >
                                <Calendar class="h-3.5 w-3.5" /> Berakhir: {{ formatDate(store.subscription_end) }}
                            </span>
                        </div>
                    </div>
                    <Button
                        v-if="paymentHistory.length > 0"
                        @click="showHistory = !showHistory"
                        variant="ghost"
                        class="text-white hover:bg-white/20 border border-white/30 rounded-xl px-4 py-2 text-xs font-semibold"
                    >
                        <FileText class="h-3.5 w-3.5 mr-1.5" />
                        Riwayat
                    </Button>
                </div>
            </div>

            <!-- Pending Payment Alert -->
            <div v-if="hasPendingPayment" class="bg-amber-50 border border-amber-200 rounded-2xl p-5 flex items-start gap-4">
                <div class="p-2 bg-amber-100 rounded-xl flex-shrink-0">
                    <Loader2 class="h-5 w-5 text-amber-600 animate-spin" />
                </div>
                <div>
                    <h3 class="font-bold text-amber-900">Pembayaran Sedang Diproses</h3>
                    <p class="text-sm text-amber-700 mt-1">
                        Invoice <span class="font-mono font-bold">{{ latestPayment?.invoice_number }}</span> sedang menunggu konfirmasi admin.
                    </p>
                </div>
            </div>

            <!-- Tab Navigation -->
            <div class="flex gap-2">
                <button
                    @click="activeTab = 'subscription'"
                    class="flex-1 rounded-xl px-5 py-3 text-sm font-bold transition-all border-2"
                    :class="activeTab === 'subscription'
                        ? 'bg-violet-600 text-white border-violet-600 shadow-md'
                        : 'bg-white text-slate-600 border-slate-200 hover:border-violet-300 hover:text-violet-600'"
                >
                    <div class="flex items-center justify-center gap-2">
                        <Crown class="h-4 w-4" />
                        Paket Subscription
                    </div>
                </button>
                <button
                    v-if="outletInfo?.can_buy_addon"
                    @click="activeTab = 'outlet'"
                    class="flex-1 rounded-xl px-5 py-3 text-sm font-bold transition-all border-2"
                    :class="activeTab === 'outlet'
                        ? 'bg-violet-600 text-white border-violet-600 shadow-md'
                        : 'bg-white text-slate-600 border-slate-200 hover:border-violet-300 hover:text-violet-600'"
                >
                    <div class="flex items-center justify-center gap-2">
                        <Store class="h-4 w-4" />
                        Tambah Outlet
                    </div>
                </button>
                <button
                    @click="activeTab = 'attendance'"
                    class="flex-1 rounded-xl px-5 py-3 text-sm font-bold transition-all border-2"
                    :class="activeTab === 'attendance'
                        ? 'bg-violet-600 text-white border-violet-600 shadow-md'
                        : 'bg-white text-slate-600 border-slate-200 hover:border-violet-300 hover:text-violet-600'"
                >
                    <div class="flex items-center justify-center gap-2">
                        <CalendarCheck class="h-4 w-4" />
                        Absensi + Payroll
                    </div>
                </button>
            </div>

            <!-- Tab: Subscription -->
            <template v-if="activeTab === 'subscription'">
                <!-- Payment History -->
                <div v-if="showHistory && paymentHistory.length > 0" class="rounded-2xl border border-slate-200 bg-white p-6">
                    <h3 class="text-base font-bold text-slate-900 flex items-center gap-2 mb-4">
                        <FileText class="h-5 w-5 text-slate-400" /> Riwayat Pembayaran
                    </h3>
                    <div class="space-y-3">
                        <div v-for="payment in paymentHistory" :key="payment.id"
                             class="flex items-center justify-between rounded-xl border border-slate-100 p-4 hover:bg-slate-50 transition-colors">
                            <div class="flex items-center gap-3">
                                <div :class="[
                                    'p-2 rounded-lg',
                                    payment.status === 'confirmed' ? 'bg-green-100' :
                                    payment.status === 'pending' ? 'bg-yellow-100' :
                                    payment.status === 'rejected' ? 'bg-red-100' : 'bg-slate-100'
                                ]">
                                    <component
                                        :is="payment.status === 'confirmed' ? CheckCircle :
                                             payment.status === 'pending' ? Clock :
                                             payment.status === 'rejected' ? XCircle : AlertCircle"
                                        :class="[
                                            'h-4 w-4',
                                            payment.status === 'confirmed' ? 'text-green-600' :
                                            payment.status === 'pending' ? 'text-yellow-600' :
                                            payment.status === 'rejected' ? 'text-red-600' : 'text-slate-600'
                                        ]"
                                    />
                                </div>
                                <div>
                                    <p class="font-semibold text-sm text-slate-800">{{ payment.invoice_number }}</p>
                                    <p class="text-xs text-slate-400">
                                        {{ payment.plan?.name || 'Custom' }} · {{ getMethodLabel(payment.payment_method) }} · {{ formatDate(payment.created_at) }}
                                    </p>
                                </div>
                            </div>
                            <div class="text-right">
                                <p class="font-semibold text-sm text-slate-800">Rp {{ formatCurrency(payment.amount) }}</p>
                                <Badge :class="getStatusBadge(payment.status).class" class="mt-1 text-[11px]">
                                    {{ getStatusBadge(payment.status).text }}
                                </Badge>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Pricing Plans -->
                <div v-if="!hasPendingPayment">
                    <div class="text-center mb-8">
                        <h2 class="text-xl font-bold text-slate-900">Pilih Paket Subscription</h2>
                        <p class="mt-1 text-sm text-slate-500">
                            Perpanjang masa aktif toko Anda dengan pilihan paket terbaik
                        </p>
                    </div>

                    <!-- Billing tab switcher -->
                    <div class="flex gap-2 max-w-md mx-auto mb-8">
                        <button
                            type="button"
                            class="flex-1 rounded-xl px-4 py-2.5 text-sm font-bold transition-all border-2"
                            :class="billingTab === 'monthly'
                                ? 'bg-slate-900 text-white border-slate-900'
                                : 'bg-white text-slate-500 border-slate-200 hover:border-slate-300'"
                            @click="billingTab = 'monthly'"
                        >
                            Bulanan
                        </button>
                        <button
                            type="button"
                            class="flex-1 rounded-xl px-4 py-2.5 text-sm font-bold transition-all border-2 relative"
                            :class="billingTab === 'yearly'
                                ? 'bg-slate-900 text-white border-slate-900'
                                : 'bg-white text-slate-500 border-slate-200 hover:border-slate-300'"
                            @click="billingTab = 'yearly'"
                        >
                            Tahunan
                        </button>
                    </div>

                    <div
                        v-if="plans.length === 0"
                        class="rounded-2xl border border-amber-200 bg-amber-50 px-6 py-8 text-center"
                    >
                        <AlertCircle class="mx-auto h-8 w-8 text-amber-500" />
                        <h3 class="mt-2 font-bold text-amber-900">Belum ada paket aktif</h3>
                        <p class="mt-1 text-sm text-amber-700">
                            Administrator belum mempublikasikan paket subscription.
                        </p>
                    </div>

                    <div
                        v-else-if="visiblePlans.length === 0"
                        class="rounded-2xl border border-slate-200 bg-slate-50 px-6 py-8 text-center"
                    >
                        <p class="text-sm text-slate-500">
                            Tidak ada paket {{ billingTab === 'yearly' ? 'tahunan' : 'bulanan' }} yang aktif.
                        </p>
                    </div>

                    <div
                        v-else
                        class="grid gap-5"
                        :class="
                            visiblePlans.length === 1
                                ? 'mx-auto grid-cols-1 max-w-sm'
                                : visiblePlans.length === 2
                                  ? 'mx-auto grid-cols-1 max-w-2xl md:grid-cols-2'
                                  : 'grid-cols-1 md:grid-cols-2 lg:grid-cols-3'
                        "
                    >
                        <div
                            v-for="plan in visiblePlans"
                            :key="plan.id"
                            class="relative"
                        >
                            <!-- Badge populer / hemat -->
                            <span
                                v-if="plan.is_popular"
                                class="absolute -top-2.5 right-4 z-20 rounded-full bg-violet-100 px-3 py-0.5 text-[11px] font-bold text-violet-700 border border-violet-200"
                            >
                                Paling populer
                            </span>
                            <span
                                v-else-if="plan.slug === 'bisnis-tahunan'"
                                class="absolute -top-2.5 right-4 z-20 rounded-full bg-emerald-100 px-3 py-0.5 text-[11px] font-bold text-emerald-700 border border-emerald-200"
                            >
                                Hemat {{ yearlyDiscountPercentLabel }}%
                            </span>

                            <!-- Card -->
                            <div
                                class="rounded-2xl border bg-white p-6 flex flex-col h-full transition-all hover:shadow-md"
                                :class="
                                    plan.is_popular || plan.slug === 'bisnis-tahunan'
                                        ? 'border-violet-300 shadow-sm'
                                        : 'border-slate-200'
                                "
                            >
                                <div class="mb-4">
                                    <h3 class="text-lg font-bold text-slate-900">{{ plan.name }}</h3>
                                    <p class="mt-1 text-sm text-slate-400">{{ plan.description }}</p>
                                </div>

                                <!-- Harga -->
                                <div v-if="plan.price > 0" class="mb-5 pb-5 border-b border-slate-100">
                                    <div class="flex items-baseline gap-1">
                                        <span class="text-2xl font-black text-slate-900">
                                            Rp {{ formatCurrency(plan.price) }}
                                        </span>
                                        <span class="text-sm text-slate-400">{{ planCycleLabel(plan) }}</span>
                                    </div>
                                    <p
                                        v-if="yearlySavingsVsMonthly12(plan)"
                                        class="mt-1 text-xs font-semibold text-emerald-600"
                                    >
                                        Hemat Rp {{ formatCurrency(yearlySavingsVsMonthly12(plan)!) }} vs 12x bulanan
                                    </p>
                                </div>
                                <div v-else class="mb-5 pb-5 border-b border-slate-100">
                                    <span class="text-2xl font-black text-emerald-600">Gratis</span>
                                </div>

                                <!-- Features -->
                                <ul
                                    v-if="!isPromoOnboardingPlan(plan)"
                                    class="mb-6 flex flex-col gap-2.5 text-sm text-slate-600 flex-1"
                                >
                                    <li v-if="isStarterPlanSlug(plan.slug)" class="flex gap-2">
                                        <Check class="mt-0.5 h-4 w-4 shrink-0 text-violet-500" stroke-width="2.5" />
                                        <span>Gratis — tidak ada batas masa aktif</span>
                                    </li>
                                    <li v-else class="flex gap-2">
                                        <Check class="mt-0.5 h-4 w-4 shrink-0 text-violet-500" stroke-width="2.5" />
                                        <span>{{ plan.duration_days }} hari masa aktif</span>
                                    </li>
                                    <li class="flex gap-2">
                                        <Check class="mt-0.5 h-4 w-4 shrink-0 text-violet-500" stroke-width="2.5" />
                                        <span>{{ plan.max_products ? `Hingga ${plan.max_products} produk` : 'Produk unlimited' }}</span>
                                    </li>
                                    <li
                                        v-for="feature in visibleFeatures(plan)"
                                        :key="feature"
                                        class="flex gap-2"
                                    >
                                        <Check class="mt-0.5 h-4 w-4 shrink-0 text-violet-500" stroke-width="2.5" />
                                        <span>{{ feature }}</span>
                                    </li>
                                </ul>
                                <button
                                    v-if="!isPromoOnboardingPlan(plan) && hasMoreFeatures(plan)"
                                    type="button"
                                    class="mb-6 text-xs font-semibold text-violet-600 hover:text-violet-800 transition-colors"
                                    @click="toggleFeatures(plan.id)"
                                >
                                    {{ showAllFeatures[plan.id] ? 'Tampilkan lebih sedikit' : `Lihat semua (${(plan.features || []).filter(f => !f.toLowerCase().includes('produk')).length} fitur)` }}
                                </button>

                                <!-- Promo onboarding -->
                                <div v-if="isPromoOnboardingPlan(plan)" class="mb-6 flex-1">
                                    <div class="rounded-xl bg-violet-50 p-4 text-center">
                                        <p class="text-xs font-bold text-violet-500 uppercase tracking-wide">Total hemat</p>
                                        <p class="mt-1 text-2xl font-black text-violet-700">Rp {{ formatCurrency(promoSavings) }}</p>
                                        <p class="mt-1 text-xs text-slate-500">
                                            Normal <span class="line-through">Rp {{ formatCurrency(promoNormalYearPrice) }}</span>
                                            → Promo <span class="font-bold text-violet-700">Rp {{ formatCurrency(promoPayTotalPrice) }}</span>
                                        </p>
                                    </div>
                                </div>

                                <!-- CTA -->
                                <button
                                    v-if="plan.price > 0"
                                    type="button"
                                    class="w-full rounded-xl px-4 py-2.5 text-sm font-bold transition-all"
                                    :class="
                                        plan.is_popular || plan.slug === 'bisnis-tahunan'
                                            ? 'bg-violet-600 text-white hover:bg-violet-700'
                                            : 'border border-slate-200 bg-white text-slate-700 hover:border-violet-300 hover:text-violet-600'
                                    "
                                    @click="goToCheckout(plan)"
                                >
                                    Pilih Paket
                                </button>
                                <div
                                    v-else-if="isPromoOnboardingPlan(plan)"
                                    class="flex flex-col items-center justify-end pt-2"
                                >
                                    <img
                                        src="/maskot.webp"
                                        alt="Maskot UMKM Kasir"
                                        width="120"
                                        height="120"
                                        class="h-24 w-24 object-contain"
                                        decoding="async"
                                        loading="lazy"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </template>

            <!-- Tab: Outlet -->
            <template v-if="activeTab === 'outlet' && outletInfo?.can_buy_addon">
                <div class="rounded-2xl border border-slate-200 bg-white p-6">
                    <div class="flex items-start gap-4 mb-5">
                        <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-violet-100">
                            <Store class="h-6 w-6 text-violet-600" />
                        </div>
                        <div class="flex-1">
                            <h3 class="text-lg font-bold text-slate-900">Outlet Tambahan</h3>
                            <p class="text-sm text-slate-500">
                                Paket GrowUp termasuk <span class="font-bold text-violet-700">{{ outletInfo.free_limit ?? '∞' }} outlet</span>.
                                <span v-if="outletInfo.extra_outlets > 0" class="text-violet-600">
                                    +{{ outletInfo.extra_outlets }} add-on sudah dibeli.
                                </span>
                            </p>
                        </div>
                    </div>

                    <!-- Outlet usage bar -->
                    <div class="mb-5">
                        <div class="flex items-center justify-between text-sm mb-1.5">
                            <span class="text-slate-500">Outlet terpakai</span>
                            <span class="font-bold text-violet-700">{{ outletInfo.current_count }} / {{ outletInfo.is_unlimited ? '∞' : outletInfo.total_limit }}</span>
                        </div>
                        <div v-if="!outletInfo.is_unlimited" class="h-2 bg-violet-100 rounded-full overflow-hidden">
                            <div
                                class="h-full bg-violet-500 rounded-full transition-all"
                                :style="{ width: Math.min(100, (outletInfo.current_count / outletInfo.total_limit) * 100) + '%' }"
                            />
                        </div>
                    </div>

                    <p class="text-xs text-slate-400 mb-5">
                        Harga: Rp {{ outletInfo.price_per_outlet_monthly.toLocaleString('id-ID') }}/outlet/bulan
                    </p>

                    <p v-if="outletInfo.has_pending_addon" class="text-sm text-amber-600 mb-4 font-medium">
                        Ada pengajuan outlet tambahan yang sedang diproses admin.
                    </p>

                    <button
                        v-if="!outletInfo.has_pending_addon"
                        type="button"
                        class="inline-flex items-center gap-2 rounded-xl bg-violet-600 px-5 py-2.5 text-sm font-bold text-white hover:bg-violet-700 transition-colors"
                        @click="goToOutletCheckout"
                    >
                        <Plus class="h-4 w-4" />
                        Beli Outlet Tambahan
                    </button>
                </div>
            </template>

            <template v-if="activeTab === 'attendance'">
                <div class="rounded-2xl border border-slate-200 bg-white p-6">
                    <div class="flex items-start gap-4 mb-5">
                        <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-emerald-100">
                            <CalendarCheck class="h-6 w-6 text-emerald-600" />
                        </div>
                        <div class="flex-1">
                            <h3 class="text-lg font-bold text-slate-900">Paket Absensi + Payroll</h3>
                            <p class="text-sm text-slate-500">
                                Satu paket untuk mengaktifkan modul absensi (selfie + geolokasi) dan modul payroll sekaligus.
                            </p>
                        </div>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2 mb-5">
                        <div class="rounded-xl border border-slate-100 p-4">
                            <p class="text-sm text-slate-500">Bulanan</p>
                            <p class="text-xl font-bold text-slate-900">Rp {{ attendancePayrollInfo?.pricing.monthly_price.toLocaleString('id-ID') }}</p>
                        </div>
                        <div class="rounded-xl border border-slate-100 p-4">
                            <p class="text-sm text-slate-500">Tahunan</p>
                            <p class="text-xl font-bold text-slate-900">Rp {{ attendancePayrollInfo?.pricing.yearly_price.toLocaleString('id-ID') }}</p>
                        </div>
                    </div>

                    <p v-if="attendancePayrollInfo?.is_purchased" class="text-sm text-emerald-700 font-medium mb-4">
                        Paket aktif sampai {{ formatDate(attendancePayrollInfo.expires_at) }}.
                    </p>

                    <p v-else-if="attendancePayrollInfo?.has_pending_addon" class="text-sm text-amber-600 font-medium mb-4">
                        Pengajuan pembelian paket sedang diproses admin.
                    </p>

                    <button
                        v-if="!attendancePayrollInfo?.has_pending_addon"
                        type="button"
                        class="inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-bold text-white hover:bg-emerald-700 transition-colors"
                        @click="goToAttendancePayrollCheckout"
                    >
                        <Plus class="h-4 w-4" />
                        {{ attendancePayrollInfo?.is_purchased ? 'Perpanjang Paket' : 'Beli Paket' }}
                    </button>
                </div>
            </template>

        </div>
    </AdminLayout>
</template>
