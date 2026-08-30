<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    Store,
    ArrowLeft,
    Upload,
    Receipt,
    Building2,
    QrCode,
    Loader2,
    CheckCircle,
    Copy,
    Check,
    Minus,
    Plus,
    Calendar,
    CalendarCheck,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { TransferSettings } from './Checkout.vue';

interface Props {
    outletInfo: {
        current_count: number;
        price_per_outlet_monthly: number;
    };
    storeName: string;
    transferSettings?: TransferSettings;
}

const props = defineProps<Props>();
const { storeName } = props;

const outlets = ref(1);
const billingCycle = ref<'monthly' | 'yearly'>('monthly');
const selectedPaymentMethod = ref<string | null>(null);
const paymentProof = ref<File | null>(null);
const paymentNotes = ref('');
const processing = ref(false);
const showSuccess = ref(false);
const errorMessage = ref('');
const copiedAccount = ref<string | null>(null);
let copiedAccountTimer: ReturnType<typeof setTimeout> | null = null;

const pricePerOutlet = computed(() => props.outletInfo.price_per_outlet_monthly);
const isYearly = computed(() => billingCycle.value === 'yearly');
const totalAmount = computed(() => {
    const base = pricePerOutlet.value * outlets.value;
    return isYearly.value ? base * 12 : base;
});
const cycleLabel = computed(() => isYearly.value ? '/tahun' : '/bulan');
const yearPrice = computed(() => pricePerOutlet.value * 12);

const formatCurrency = (amount: number) => new Intl.NumberFormat('id-ID').format(Math.round(amount));
const csrf = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

const accountDisplay = (digits: string): string => {
    const d = digits.replace(/\D/g, '');
    if (!d) return digits;
    return d.replace(/(\d{4})(?=.)/g, '$1-');
};

const paymentMethods = computed(() => {
    const t = props.transferSettings ?? {
        bank_name: '',
        account_holder: '',
        account_number: '',
        qris_url: null as string | null,
    };
    const raw = String(t.account_number ?? '').replace(/\s/g, '');
    return [
        {
            id: 'bank_transfer' as const,
            name: 'Transfer Rekening',
            description: 'Transfer ke rekening yang ditentukan admin',
            icon: Building2,
            bankInfo: {
                bankName: t.bank_name?.trim() || '—',
                accountNumber: raw || '—',
                formattedNumber: raw ? accountDisplay(raw) : '—',
                accountHolder: t.account_holder?.trim() || '—',
            },
        },
        {
            id: 'qris' as const,
            name: 'QRIS',
            description: t.qris_url ? 'Scan kode QR di bawah untuk bayar' : 'Hubungi admin jika QRIS belum diunggah',
            icon: QrCode,
            qrisUrl: t.qris_url,
        },
    ];
});

const handleFileUpload = (event: Event) => {
    const target = event.target as HTMLInputElement;
    if (target.files && target.files[0]) {
        paymentProof.value = target.files[0];
    }
};

const copyRekening = async (rawNumber: string) => {
    try {
        await navigator.clipboard.writeText(rawNumber);
        copiedAccount.value = rawNumber;
        if (copiedAccountTimer) clearTimeout(copiedAccountTimer);
        copiedAccountTimer = setTimeout(() => { copiedAccount.value = null; }, 2000);
    } catch {
        // clipboard tidak tersedia
    }
};

const submitPayment = async () => {
    if (!selectedPaymentMethod.value) return;

    processing.value = true;
    errorMessage.value = '';

    try {
        const formData = new FormData();
        formData.append('outlets', outlets.value.toString());
        formData.append('billing_cycle', billingCycle.value);
        formData.append('payment_method', selectedPaymentMethod.value);
        if (paymentNotes.value) formData.append('notes', paymentNotes.value);
        if (paymentProof.value) formData.append('payment_proof', paymentProof.value);

        const response = await fetch('/subscription/pay-outlet', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' },
            body: formData,
        });

        const result = await response.json();
        if (result.success) {
            showSuccess.value = true;
        } else {
            errorMessage.value = result.message || 'Terjadi kesalahan.';
        }
    } catch {
        errorMessage.value = 'Terjadi kesalahan saat mengirim pembayaran.';
    } finally {
        processing.value = false;
    }
};

const goBack = () => { window.location.href = '/subscription'; };
</script>

<template>
    <Head title="Beli Outlet Tambahan" />
    <AdminLayout title="Beli Outlet Tambahan">
        <div class="pb-6 space-y-6 max-w-3xl mx-auto">

            <button
                type="button"
                class="inline-flex items-center gap-2 text-sm font-semibold text-slate-600 hover:text-slate-900 transition-colors"
                @click="goBack"
            >
                <ArrowLeft class="h-4 w-4" />
                Kembali ke subscription
            </button>

            <!-- Success -->
            <div v-if="showSuccess" class="rounded-2xl border border-slate-200 bg-white p-8 text-center">
                <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-emerald-100">
                    <CheckCircle class="h-8 w-8 text-emerald-600" />
                </div>
                <h2 class="text-xl font-bold text-slate-900">Pengajuan berhasil dikirim!</h2>
                <p class="mt-2 text-sm text-slate-500">
                    Admin akan mengonfirmasi dalam 1×24 jam. Outlet tambahan akan aktif setelah dikonfirmasi.
                </p>
                <div class="mt-6 flex justify-center gap-3">
                    <button type="button" class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50" @click="goBack">
                        <ArrowLeft class="h-4 w-4" />
                        Kembali
                    </button>
                    <a href="/dashboard" class="inline-flex items-center gap-2 rounded-xl bg-violet-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-violet-700">
                        Ke Dashboard
                    </a>
                </div>
            </div>

            <template v-else>
                <!-- Outlet info -->
                <div class="rounded-2xl border border-slate-200 bg-white p-6">
                    <div class="flex items-start gap-4">
                        <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-violet-100">
                            <Store class="h-6 w-6 text-violet-600" />
                        </div>
                        <div class="flex-1 min-w-0">
                            <h3 class="text-lg font-bold text-slate-900">Outlet Tambahan</h3>
                            <p class="text-sm text-slate-500">{{ storeName }}</p>
                            <p class="mt-1 text-sm text-slate-500">
                                Outlet terpakai: <span class="font-bold text-violet-700">{{ outletInfo.current_count }}</span>
                            </p>
                        </div>
                    </div>

                    <!-- Billing cycle -->
                    <div class="mt-5 border-t border-slate-100 pt-5">
                        <label class="text-sm font-bold text-slate-700 mb-3 block">Siklus penagihan</label>
                        <div class="grid grid-cols-2 gap-3">
                            <button
                                type="button"
                                @click="billingCycle = 'monthly'"
                                class="rounded-xl border-2 p-4 text-left transition-all"
                                :class="billingCycle === 'monthly'
                                    ? 'border-violet-500 bg-violet-50 shadow-sm'
                                    : 'border-slate-200 hover:border-violet-300'"
                            >
                                <div class="flex items-center gap-3">
                                    <div class="flex h-10 w-10 items-center justify-center rounded-lg"
                                        :class="billingCycle === 'monthly' ? 'bg-violet-600 text-white' : 'bg-slate-100 text-slate-500'">
                                        <Calendar class="h-5 w-5" />
                                    </div>
                                    <div>
                                        <p class="font-bold text-sm" :class="billingCycle === 'monthly' ? 'text-violet-700' : 'text-slate-700'">Bulanan</p>
                                        <p class="text-xs" :class="billingCycle === 'monthly' ? 'text-violet-600' : 'text-slate-400'">
                                            Rp {{ formatCurrency(pricePerOutlet) }}/outlet/bulan
                                        </p>
                                    </div>
                                </div>
                            </button>
                            <button
                                type="button"
                                @click="billingCycle = 'yearly'"
                                class="rounded-xl border-2 p-4 text-left transition-all relative"
                                :class="billingCycle === 'yearly'
                                    ? 'border-violet-500 bg-violet-50 shadow-sm'
                                    : 'border-slate-200 hover:border-violet-300'"
                            >
                                <span class="absolute -top-2.5 right-3 rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-bold text-emerald-700">Hemat 2 bln</span>
                                <div class="flex items-center gap-3">
                                    <div class="flex h-10 w-10 items-center justify-center rounded-lg"
                                        :class="billingCycle === 'yearly' ? 'bg-violet-600 text-white' : 'bg-slate-100 text-slate-500'">
                                        <CalendarCheck class="h-5 w-5" />
                                    </div>
                                    <div>
                                        <p class="font-bold text-sm" :class="billingCycle === 'yearly' ? 'text-violet-700' : 'text-slate-700'">Tahunan</p>
                                        <p class="text-xs" :class="billingCycle === 'yearly' ? 'text-violet-600' : 'text-slate-400'">
                                            Rp {{ formatCurrency(yearPrice) }}/outlet/tahun
                                        </p>
                                    </div>
                                </div>
                            </button>
                        </div>
                    </div>

                    <!-- Jumlah outlet -->
                    <div class="mt-5 border-t border-slate-100 pt-5">
                        <label class="text-sm font-bold text-slate-700 mb-3 block">Jumlah outlet tambahan</label>
                        <div class="flex items-center gap-3">
                            <button
                                type="button"
                                @click="outlets = Math.max(1, outlets - 1)"
                                class="h-10 w-10 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 flex items-center justify-center transition-colors"
                            >
                                <Minus class="h-4 w-4 text-slate-600" />
                            </button>
                            <input
                                v-model.number="outlets"
                                type="number"
                                min="1"
                                max="100"
                                class="h-10 w-20 text-center rounded-xl border border-slate-300 text-slate-900 font-bold text-lg focus:ring-2 focus:ring-violet-500 focus:border-violet-500"
                            />
                            <button
                                type="button"
                                @click="outlets++"
                                class="h-10 w-10 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 flex items-center justify-center transition-colors"
                            >
                                <Plus class="h-4 w-4 text-slate-600" />
                            </button>
                            <span class="text-sm text-slate-400">outlet</span>
                        </div>

                        <div class="mt-4 rounded-xl bg-violet-50 p-4 flex items-center justify-between">
                            <span class="text-sm font-semibold text-slate-700">Total biaya</span>
                            <span class="text-xl font-black text-violet-700">Rp {{ formatCurrency(totalAmount) }}</span>
                        </div>
                        <p class="mt-2 text-xs text-slate-400">
                            Rp {{ formatCurrency(pricePerOutlet) }}/outlet{{ cycleLabel }}
                        </p>
                    </div>
                </div>

                <!-- Error -->
                <div
                    v-if="errorMessage"
                    class="rounded-xl border border-red-200 bg-red-50 p-4 flex items-start gap-3"
                >
                    <p class="flex-1 text-sm font-medium text-red-700">{{ errorMessage }}</p>
                    <button type="button" class="text-red-400 hover:text-red-600" @click="errorMessage = ''">✕</button>
                </div>

                <!-- Metode pembayaran -->
                <div class="rounded-2xl border border-slate-200 bg-white p-6">
                    <h3 class="text-base font-bold text-slate-900 mb-4">Metode pembayaran</h3>
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <button
                            v-for="method in paymentMethods"
                            :key="method.id"
                            type="button"
                            class="rounded-xl border-2 p-4 text-left transition-all"
                            :class="
                                selectedPaymentMethod === method.id
                                    ? 'border-violet-500 bg-violet-50 shadow-sm'
                                    : 'border-slate-200 hover:border-violet-300'
                            "
                            @click="selectedPaymentMethod = method.id"
                        >
                            <div class="flex items-center gap-3">
                                <div class="flex h-10 w-10 items-center justify-center rounded-lg"
                                    :class="selectedPaymentMethod === method.id ? 'bg-violet-600 text-white' : 'bg-slate-100 text-slate-500'">
                                    <component :is="method.icon" class="h-5 w-5" />
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-bold text-slate-800">{{ method.name }}</p>
                                    <p class="text-xs text-slate-400 truncate">{{ method.description }}</p>
                                </div>
                                <div class="h-5 w-5 shrink-0 rounded-full border-2 flex items-center justify-center"
                                    :class="selectedPaymentMethod === method.id ? 'border-violet-600 bg-violet-600' : 'border-slate-300'">
                                    <div v-if="selectedPaymentMethod === method.id" class="h-2 w-2 rounded-full bg-white" />
                                </div>
                            </div>

                            <!-- Detail -->
                            <div v-if="selectedPaymentMethod === method.id" class="mt-4 border-t border-slate-100 pt-4 text-xs text-slate-500">
                                <div v-if="method.bankInfo" class="space-y-2">
                                    <div class="flex items-center justify-between">
                                        <span class="text-slate-400">Bank</span>
                                        <span class="font-semibold text-slate-700">{{ method.bankInfo.bankName }}</span>
                                    </div>
                                    <div class="flex items-center justify-between">
                                        <span class="text-slate-400">No. rekening</span>
                                        <div class="flex items-center gap-2">
                                            <span class="font-mono font-semibold text-slate-700">{{ method.bankInfo.formattedNumber }}</span>
                                            <button
                                                type="button"
                                                class="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-2 py-1 text-[11px] font-semibold text-slate-500 hover:bg-slate-50"
                                                @click.stop="copyRekening(method.bankInfo.accountNumber)"
                                            >
                                                <Check v-if="copiedAccount === method.bankInfo.accountNumber" class="h-3 w-3 text-emerald-600" />
                                                <Copy v-else class="h-3 w-3" />
                                                {{ copiedAccount === method.bankInfo.accountNumber ? 'Disalin' : 'Salin' }}
                                            </button>
                                        </div>
                                    </div>
                                    <div class="flex items-center justify-between">
                                        <span class="text-slate-400">a.n.</span>
                                        <span class="font-semibold text-slate-700">{{ method.bankInfo.accountHolder }}</span>
                                    </div>
                                </div>
                                <div v-else-if="'qrisUrl' in method && method.qrisUrl" class="flex flex-col items-center gap-3 pt-1">
                                    <img
                                        :src="method.qrisUrl"
                                        alt="QRIS pembayaran outlet"
                                        class="max-h-56 max-w-full rounded-xl border border-slate-200 bg-white object-contain"
                                    />
                                    <p class="text-[11px] text-slate-400">Scan dengan aplikasi bank / e-wallet Anda</p>
                                </div>
                                <p v-else class="text-slate-500">{{ method.description }}</p>
                            </div>
                        </button>
                    </div>
                </div>

                <!-- Bukti + Ringkasan -->
                <template v-if="selectedPaymentMethod">
                    <div class="rounded-2xl border border-slate-200 bg-white p-6">
                        <h3 class="flex items-center gap-2 text-base font-bold text-slate-900 mb-4">
                            <Upload class="h-5 w-5 text-slate-400" />
                            Bukti pembayaran
                        </h3>
                        <p class="text-xs text-slate-400 mb-4">Unggah bukti transfer atau screenshot (opsional); bisa menyusul setelah bayar.</p>
                        <label class="block cursor-pointer">
                            <div
                                class="rounded-xl border-2 border-dashed p-6 text-center transition-all"
                                :class="paymentProof ? 'border-violet-400 bg-violet-50' : 'border-slate-200 bg-slate-50 hover:border-violet-300'"
                            >
                                <input type="file" accept="image/*" class="hidden" @change="handleFileUpload" />
                                <Upload :class="['mx-auto mb-2 h-6 w-6', paymentProof ? 'text-violet-600' : 'text-slate-400']" />
                                <p v-if="paymentProof" class="text-sm font-semibold text-violet-600">{{ paymentProof.name }}</p>
                                <p v-else class="text-sm text-slate-500">Klik untuk pilih gambar</p>
                                <p class="mt-1 text-xs text-slate-400">Maks 5MB · JPG / PNG</p>
                            </div>
                        </label>
                        <div class="mt-4">
                            <label class="mb-1.5 block text-sm font-semibold text-slate-700">Catatan (opsional)</label>
                            <textarea
                                v-model="paymentNotes"
                                rows="2"
                                class="w-full resize-none rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-700 placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-violet-500 focus:border-violet-500"
                                placeholder="Contoh: Transfer dari BCA a.n. Budi"
                            />
                        </div>
                    </div>

                    <!-- Summary -->
                    <div class="rounded-2xl bg-slate-900 p-6 text-white">
                        <div class="flex items-center gap-2 mb-4">
                            <Receipt class="h-5 w-5 text-slate-400" />
                            <h3 class="text-base font-bold">Ringkasan pembayaran</h3>
                        </div>
                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between">
                                <span class="text-slate-400">Item</span>
                                <span class="font-semibold">{{ outlets }} outlet · {{ isYearly ? 'Tahunan' : 'Bulanan' }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-slate-400">Metode</span>
                                <span class="font-semibold">{{ selectedPaymentMethod === 'bank_transfer' ? 'Rekening' : 'QRIS' }}</span>
                            </div>
                            <div class="border-t border-white/10 pt-3 mt-3">
                                <div class="flex items-end justify-between">
                                    <span class="text-slate-300">Total transfer</span>
                                    <span class="text-2xl font-black">Rp {{ formatCurrency(totalAmount) }}</span>
                                </div>
                                <p class="mt-1 text-right text-xs text-slate-500">Nominal harus sesuai untuk verifikasi</p>
                            </div>
                        </div>
                        <button
                            type="button"
                            class="mt-5 w-full inline-flex items-center justify-center gap-2 rounded-xl bg-violet-600 px-5 py-3 text-sm font-bold text-white hover:bg-violet-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                            :disabled="processing"
                            @click="submitPayment"
                        >
                            <Loader2 v-if="processing" class="h-4 w-4 animate-spin" />
                            <span v-if="processing">Memproses…</span>
                            <span v-else>Kirim pengajuan pembayaran</span>
                        </button>
                        <p class="mt-3 text-center text-xs text-slate-500">Dikonfirmasi admin dalam 1×24 jam</p>
                    </div>
                </template>
            </template>
        </div>
    </AdminLayout>
</template>