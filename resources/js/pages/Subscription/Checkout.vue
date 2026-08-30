<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    Crown,
    ArrowLeft,
    Upload,
    Receipt,
    Building2,
    QrCode,
    Loader2,
    CheckCircle,
    Shield,
    Copy,
    Check,
    Ticket,
    X,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
import AdminLayout from '@/layouts/AdminLayout.vue';

export interface TransferSettings {
    bank_name: string;
    account_holder: string;
    account_number: string;
    qris_url: string | null;
}

interface Plan {
    id: number;
    name: string;
    slug: string;
    description: string;
    price: number;
    duration_days: number;
    max_products: number | null;
    features: string[] | null;
    is_popular: boolean;
    billing_cycle?: 'monthly' | 'yearly';
}

interface AppliedVoucher {
    name: string;
    code: string;
    plan_price: number;
    amount_due: number;
    discount_amount: number;
}

interface Props {
    plan: Plan;
    storeName: string;
    transferSettings?: TransferSettings;
}

const props = defineProps<Props>();
const { plan, storeName } = props;

const selectedPaymentMethod = ref<string | null>(null);
const paymentProof = ref<File | null>(null);
const paymentNotes = ref('');
const processing = ref(false);
const showSuccess = ref(false);
const errorMessage = ref('');
const copiedAccount = ref<string | null>(null);
let copiedAccountTimer: ReturnType<typeof setTimeout> | null = null;

const voucherInput = ref('');
const appliedVoucher = ref<AppliedVoucher | null>(null);
const voucherApplying = ref(false);
const voucherError = ref('');

const formatAccountDisplay = (digits: string): string => {
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
    const bankName = t.bank_name?.trim() || '—';
    const holder = t.account_holder?.trim() || '—';
    return [
        {
            id: 'bank_transfer' as const,
            name: 'Transfer Rekening',
            description: 'Transfer ke rekening yang ditentukan admin',
            icon: Building2,
            bankInfo: {
                bankName,
                accountNumber: raw || '—',
                formattedNumber: raw ? formatAccountDisplay(raw) : '—',
                accountHolder: holder,
            },
        },
        {
            id: 'qris' as const,
            name: 'QRIS',
            description: t.qris_url
                ? 'Scan kode QR di bawah untuk bayar'
                : 'Hubungi admin jika QRIS belum diunggah',
            icon: QrCode,
            detail: 'Bayar dengan aplikasi e-wallet atau mobile banking',
            qrisUrl: t.qris_url,
        },
    ];
});

const planCycleLabel = computed(() =>
    (plan.billing_cycle ?? 'monthly') === 'yearly' ? '/tahun' : '/bulan',
);

const formatPriceRb = (amount: number): string => {
    if (!Number.isFinite(amount) || amount < 0) return '0 rb';
    const rb = Math.round(amount / 1000);
    return `${rb} rb`;
};

const formatCurrency = (amount: number) => new Intl.NumberFormat('id-ID').format(Math.round(amount));

const amountDue = computed(() => appliedVoucher.value?.amount_due ?? plan.price);
const discountAmount = computed(() => appliedVoucher.value?.discount_amount ?? 0);

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

const copyRekening = async (rawNumber: string) => {
    try {
        await navigator.clipboard.writeText(rawNumber);
        copiedAccount.value = rawNumber;
        if (copiedAccountTimer) clearTimeout(copiedAccountTimer);
        copiedAccountTimer = setTimeout(() => {
            copiedAccount.value = null;
        }, 2000);
    } catch {
        // clipboard tidak tersedia
    }
};

const handleFileUpload = (event: Event) => {
    const target = event.target as HTMLInputElement;
    if (target.files && target.files[0]) {
        paymentProof.value = target.files[0];
    }
};

const clearVoucher = () => {
    appliedVoucher.value = null;
    voucherError.value = '';
    voucherInput.value = '';
};

const applyVoucher = async () => {
    voucherError.value = '';
    const code = voucherInput.value.trim();
    if (!code) {
        voucherError.value = 'Masukkan kode voucher.';
        return;
    }

    voucherApplying.value = true;
    try {
        const response = await fetch('/subscription/apply-voucher', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrf(),
            },
            body: JSON.stringify({ plan_id: plan.id, code }),
        });

        const result = await response.json();

        if (result.success) {
            appliedVoucher.value = {
                name: result.voucher.name,
                code: result.voucher.code,
                plan_price: result.plan_price,
                amount_due: result.amount_due,
                discount_amount: result.discount_amount,
            };
            voucherError.value = '';
        } else {
            appliedVoucher.value = null;
            voucherError.value = result.message || 'Voucher tidak bisa dipakai.';
        }
    } catch {
        appliedVoucher.value = null;
        voucherError.value = 'Gagal memverifikasi voucher. Coba lagi.';
    } finally {
        voucherApplying.value = false;
    }
};

const submitPayment = async () => {
    if (!selectedPaymentMethod.value) return;

    processing.value = true;
    errorMessage.value = '';

    try {
        const formData = new FormData();
        formData.append('plan_id', plan.id.toString());
        formData.append('payment_method', selectedPaymentMethod.value);
        if (paymentNotes.value) formData.append('notes', paymentNotes.value);
        if (paymentProof.value) formData.append('payment_proof', paymentProof.value);
        if (appliedVoucher.value) {
            formData.append('voucher_code', appliedVoucher.value.code);
        }

        const response = await fetch('/subscription/pay', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': csrf(),
                Accept: 'application/json',
            },
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

const goBack = () => {
    window.location.href = '/subscription';
};

const orderCardShadow =
    plan.is_popular || plan.slug === 'bisnis-tahunan' ? 'landing-card-orange-shadow' : '';
</script>

<template>
    <Head>
        <link rel="preconnect" href="https://fonts.googleapis.com" />
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin="anonymous" />
        <link
            href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800;900&display=swap"
            rel="stylesheet"
        />
    </Head>
    <AdminLayout title="Pembayaran">
        <div class="landing-root pb-8">
            <div class="mx-auto max-w-3xl space-y-6 px-1 sm:px-0">
                <button
                    type="button"
                    class="landing-btn landing-btn-secondary !px-4 !py-2.5 !text-sm"
                    @click="goBack"
                >
                    <ArrowLeft class="h-4 w-4" />
                    Kembali ke pilihan paket
                </button>

                <div v-if="showSuccess" class="landing-card mx-auto max-w-lg px-8 py-12 text-center">
                    <div
                        class="mx-auto mb-6 flex h-20 w-20 items-center justify-center rounded-full border-[3px] border-[#3E1F00] bg-emerald-100 shadow-[4px_4px_0_0_#34d399]"
                    >
                        <CheckCircle class="h-10 w-10 text-emerald-700" />
                    </div>
                    <h2 class="font-display text-2xl font-extrabold text-[#3E1F00] md:text-3xl">
                        Pembayaran berhasil diajukan!
                    </h2>
                    <p class="mt-3 text-sm font-medium text-lp-muted">
                        Admin akan mengonfirmasi dalam 1×24 jam. Masa aktif toko bertambah setelah dikonfirmasi.
                    </p>
                    <div class="mt-8 flex flex-wrap justify-center gap-3">
                        <button type="button" class="landing-btn landing-btn-secondary" @click="goBack">
                            <ArrowLeft class="h-4 w-4" />
                            Kembali
                        </button>
                        <a href="/dashboard" class="landing-btn landing-btn-primary !no-underline"> Ke Dashboard </a>
                    </div>
                </div>

                <template v-else>
                    <!-- Ringkasan pesanan -->
                    <div :class="['landing-card relative overflow-hidden p-6 sm:p-8', orderCardShadow]">
                        <div class="flex flex-col gap-4 border-b border-[#3E1F00]/15 pb-6 sm:flex-row sm:items-center sm:justify-between">
                            <div class="flex items-center gap-3">
                                <div
                                    class="flex h-12 w-12 items-center justify-center rounded-2xl border-[3px] border-[#3E1F00] bg-[#7C3AED] text-white shadow-[3px_3px_0_0_#ddd6fe]"
                                >
                                    <Crown class="h-6 w-6" />
                                </div>
                                <div class="text-left">
                                    <h3 class="font-display text-xl font-extrabold text-[#7C3AED]">
                                        {{ plan.name }}
                                    </h3>
                                    <p class="mt-0.5 text-sm font-medium text-lp-muted">
                                        {{ storeName }} · {{ plan.duration_days }} hari
                                        <span v-if="(plan.billing_cycle ?? 'monthly') === 'yearly'">
                                            · Paket tahunan</span
                                        >
                                    </p>
                                </div>
                            </div>
                            <div class="text-left sm:text-right">
                                <p class="text-xs font-medium text-lp-muted">mulai dari</p>
                                <p class="landing-price text-3xl text-[#3E1F00]">
                                    Rp {{ formatPriceRb(plan.price) }}
                                </p>
                                <p class="text-xs font-medium text-lp-muted">{{ planCycleLabel }}</p>
                            </div>
                        </div>

                        <!-- Voucher -->
                        <div class="mt-6">
                            <div class="mb-3 flex items-center gap-2">
                                <Ticket class="h-5 w-5 text-[#7C3AED]" />
                                <h4 class="font-display text-base font-extrabold text-[#3E1F00]">Voucher</h4>
                            </div>
                            <p class="mb-4 text-xs font-medium text-lp-muted sm:text-sm">
                                Masukkan kode dari admin. Nominal
                                <strong class="text-[#3E1F00]">Harga</strong> pada voucher = nominal
                                <strong class="text-[#3E1F00]">potongan</strong> dari harga paket
                                (maksimal sampai total paket).
                            </p>

                            <div v-if="!appliedVoucher" class="flex flex-col gap-3 sm:flex-row sm:items-stretch">
                                <input
                                    v-model="voucherInput"
                                    type="text"
                                    autocomplete="off"
                                    class="min-h-[2.75rem] flex-1 rounded-full border-[3px] border-[#3E1F00] bg-[#fffef7] px-4 font-mono text-sm font-semibold uppercase text-[#3E1F00] shadow-[3px_3px_0_0_#ddd6fe] placeholder:normal-case placeholder:font-sans placeholder:font-medium placeholder:text-lp-muted/70 focus:outline-none focus:ring-2 focus:ring-[#7C3AED]/40"
                                    placeholder="contoh: PROMO2025"
                                    @keyup.enter="applyVoucher"
                                />
                                <button
                                    type="button"
                                    class="landing-btn landing-btn-secondary shrink-0 justify-center sm:!min-w-[8.5rem]"
                                    :disabled="voucherApplying"
                                    @click="applyVoucher"
                                >
                                    <Loader2 v-if="voucherApplying" class="h-4 w-4 animate-spin" />
                                    <span v-else>Terapkan</span>
                                </button>
                            </div>

                            <div
                                v-else
                                class="rounded-2xl border-[3px] border-[#3E1F00] bg-emerald-50/90 px-4 py-4 shadow-[4px_4px_0_0_#34d399]"
                            >
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <p class="font-display text-sm font-extrabold text-emerald-900">
                                            {{ appliedVoucher.name }}
                                        </p>
                                        <p class="mt-1 font-mono text-xs font-bold text-emerald-800">
                                            {{ appliedVoucher.code }}
                                        </p>
                                    </div>
                                    <button
                                        type="button"
                                        class="rounded-full border-2 border-[#3E1F00] bg-white p-1.5 text-[#3E1F00] shadow-sm transition hover:bg-[#fffef7]"
                                        title="Hapus voucher"
                                        @click="clearVoucher"
                                    >
                                        <X class="h-4 w-4" />
                                    </button>
                                </div>
                                <div class="mt-3 space-y-1 border-t border-emerald-200/80 pt-3 text-sm font-medium text-emerald-950">
                                    <div class="flex justify-between gap-2">
                                        <span class="text-emerald-800/90">Harga paket</span>
                                        <span>Rp {{ formatPriceRb(appliedVoucher.plan_price) }}</span>
                                    </div>
                                    <div class="flex justify-between gap-2 text-emerald-800">
                                        <span>Potongan voucher</span>
                                        <span>− Rp {{ formatPriceRb(appliedVoucher.discount_amount) }}</span>
                                    </div>
                                    <div
                                        class="flex justify-between gap-2 border-t border-emerald-200/80 pt-2 font-display font-extrabold text-emerald-950"
                                    >
                                        <span>Transfer</span>
                                        <span>Rp {{ formatPriceRb(appliedVoucher.amount_due) }}</span>
                                    </div>
                                </div>
                            </div>

                            <p v-if="voucherError" class="mt-2 text-sm font-semibold text-red-600">
                                {{ voucherError }}
                            </p>
                        </div>
                    </div>

                    <div
                        v-if="errorMessage"
                        class="flex items-start gap-3 rounded-2xl border-[3px] border-red-400 bg-red-50 px-4 py-3 shadow-[4px_4px_0_0_#fca5a5]"
                    >
                        <div class="mt-0.5 shrink-0 rounded-xl border-2 border-[#3E1F00] bg-white p-1.5">
                            <Shield class="h-4 w-4 text-red-600" />
                        </div>
                        <p class="flex-1 text-sm font-medium text-red-800">{{ errorMessage }}</p>
                        <button
                            type="button"
                            class="text-lg font-bold text-red-400 hover:text-red-600"
                            @click="errorMessage = ''"
                        >
                            ✕
                        </button>
                    </div>

                    <div>
                        <h3 class="font-display text-lg font-extrabold text-[#3E1F00]">Metode pembayaran</h3>
                        <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <button
                                v-for="method in paymentMethods"
                                :key="method.id"
                                type="button"
                                class="rounded-[1.25rem] border-[3px] bg-[#fffef7] p-5 text-left shadow-[5px_5px_0_0_rgb(221_214_254_/0.9)] transition hover:-translate-y-0.5"
                                :class="
                                    selectedPaymentMethod === method.id
                                        ? 'border-[#7C3AED] ring-2 ring-[#7C3AED]/30'
                                        : 'border-[#3E1F00] hover:border-[#7C3AED]/60'
                                "
                                @click="selectedPaymentMethod = method.id"
                            >
                                <div class="flex items-center gap-4">
                                    <div
                                        class="flex h-12 w-12 items-center justify-center rounded-xl border-[3px] border-[#3E1F00] bg-[#7C3AED] text-white shadow-[2px_2px_0_0_#ddd6fe]"
                                    >
                                        <component :is="method.icon" class="h-6 w-6" />
                                    </div>
                                    <div class="flex-1">
                                        <p class="font-display text-sm font-extrabold text-[#3E1F00]">
                                            {{ method.name }}
                                        </p>
                                        <p class="mt-0.5 text-xs font-medium text-lp-muted">
                                            {{ method.description }}
                                        </p>
                                    </div>
                                    <div
                                        class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full border-[3px] border-[#3E1F00]"
                                        :class="
                                            selectedPaymentMethod === method.id ? 'bg-[#7C3AED]' : 'bg-white'
                                        "
                                    >
                                        <div
                                            v-if="selectedPaymentMethod === method.id"
                                            class="h-2 w-2 rounded-full bg-white"
                                        />
                                    </div>
                                </div>
                                <div
                                    v-if="selectedPaymentMethod === method.id"
                                    class="mt-4 border-t border-[#3E1F00]/10 pt-4 text-xs font-medium text-lp-muted"
                                >
                                    <div v-if="method.bankInfo" class="space-y-2">
                                        <p>
                                            <span>Bank</span>
                                            <span class="font-bold text-[#3E1F00]">
                                                · {{ method.bankInfo.bankName }}</span
                                            >
                                        </p>
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="shrink-0">No. rekening</span>
                                            <span class="font-mono font-bold tabular-nums text-[#3E1F00]">{{
                                                method.bankInfo.formattedNumber
                                            }}</span>
                                            <button
                                                type="button"
                                                class="landing-btn !border-[#3E1F00] !px-2 !py-1 !text-[11px] !shadow-[2px_2px_0_0_#fb923c]"
                                                :class="
                                                    copiedAccount === method.bankInfo.accountNumber
                                                        ? 'landing-btn-secondary'
                                                        : 'landing-btn-secondary'
                                                "
                                                @click.stop="copyRekening(method.bankInfo.accountNumber)"
                                            >
                                                <Check
                                                    v-if="copiedAccount === method.bankInfo.accountNumber"
                                                    class="h-3.5 w-3.5 text-emerald-600"
                                                />
                                                <Copy v-else class="h-3.5 w-3.5" />
                                                {{
                                                    copiedAccount === method.bankInfo.accountNumber
                                                        ? 'Disalin'
                                                        : 'Salin'
                                                }}
                                            </button>
                                        </div>
                                        <p>
                                            <span>a.n.</span>
                                            <span class="font-semibold text-[#3E1F00]">
                                                {{ method.bankInfo.accountHolder }}</span
                                            >
                                        </p>
                                    </div>
                                    <div
                                        v-else-if="'qrisUrl' in method && method.qrisUrl"
                                        class="flex flex-col items-center gap-3 pt-1"
                                    >
                                        <img
                                            :src="method.qrisUrl"
                                            alt="QRIS pembayaran langganan"
                                            class="max-h-64 max-w-full rounded-xl border-[3px] border-[#3E1F00] bg-white object-contain shadow-[3px_3px_0_0_#ddd6fe]"
                                        />
                                        <p class="text-center text-[11px] font-medium text-lp-muted">
                                            Scan dengan aplikasi bank / e-wallet Anda
                                        </p>
                                    </div>
                                    <p v-else class="font-semibold text-[#3E1F00]/90">{{ method.detail }}</p>
                                </div>
                            </button>
                        </div>
                    </div>

                    <div v-if="selectedPaymentMethod" class="space-y-5">
                        <div class="landing-card p-6 sm:p-8">
                            <h3 class="font-display flex items-center gap-2 text-base font-extrabold text-[#3E1F00]">
                                <Upload class="h-5 w-5 text-[#7C3AED]" />
                                Bukti pembayaran
                            </h3>
                            <p class="mt-1 text-xs font-medium text-lp-muted sm:text-sm">
                                Unggah bukti transfer atau screenshot (opsional); bisa menyusul setelah bayar.
                            </p>
                            <label class="mt-4 block cursor-pointer">
                                <div
                                    class="rounded-2xl border-[3px] border-dashed border-[#3E1F00]/40 bg-[#fffef7] p-8 text-center transition hover:border-[#7C3AED]/50"
                                    :class="
                                        paymentProof
                                            ? 'border-[#7C3AED] bg-[rgba(124,58,237,0.06)]'
                                            : ''
                                    "
                                >
                                    <input type="file" accept="image/*" class="hidden" @change="handleFileUpload" />
                                    <Upload
                                        :class="[
                                            'mx-auto mb-2 h-7 w-7',
                                            paymentProof ? 'text-[#7C3AED]' : 'text-lp-muted',
                                        ]"
                                    />
                                    <p v-if="paymentProof" class="text-sm font-bold text-[#7C3AED]">
                                        {{ paymentProof.name }}
                                    </p>
                                    <p v-else class="text-sm font-medium text-lp-muted">Klik untuk pilih gambar</p>
                                    <p class="mt-1 text-xs text-lp-muted">Maks 5MB · JPG / PNG</p>
                                </div>
                            </label>

                            <div class="mt-5">
                                <label class="mb-1.5 block text-sm font-bold text-[#3E1F00]">Catatan (opsional)</label>
                                <textarea
                                    v-model="paymentNotes"
                                    rows="2"
                                    class="w-full resize-none rounded-2xl border-[3px] border-[#3E1F00] bg-[#fffef7] px-4 py-3 text-sm font-medium text-[#3E1F00] shadow-[3px_3px_0_0_#ddd6fe] placeholder:text-lp-muted/70 focus:outline-none focus:ring-2 focus:ring-[#7C3AED]/35"
                                    placeholder="Contoh: Transfer dari BCA a.n. Budi"
                                />
                            </div>
                        </div>

                        <div
                            class="checkout-payment-summary rounded-[1.25rem] border-[3px] border-[#3E1F00] bg-[#3E1F00] p-6 text-white shadow-[8px_8px_0_0_#7C3AED]"
                        >
                            <div class="mb-5 flex items-center gap-2">
                                <div
                                    class="flex h-10 w-10 items-center justify-center rounded-xl border-2 border-white/40 bg-white/10"
                                >
                                    <Receipt class="h-5 w-5 text-white" />
                                </div>
                                <h3 class="font-display text-lg font-extrabold !text-white">
                                    Ringkasan pembayaran
                                </h3>
                            </div>
                            <div class="space-y-2 text-sm font-medium !text-white">
                                <div class="flex justify-between gap-2">
                                    <span class="text-white/80">Paket</span>
                                    <span class="text-right font-bold text-white">{{ plan.name }}</span>
                                </div>
                                <div class="flex justify-between gap-2">
                                    <span class="text-white/80">Durasi</span>
                                    <span class="font-bold text-white">{{ plan.duration_days }} hari</span>
                                </div>
                                <div class="flex justify-between gap-2">
                                    <span class="text-white/80">Metode</span>
                                    <span class="font-bold text-white">{{
                                        selectedPaymentMethod === 'bank_transfer' ? 'Rekening' : 'QRIS'
                                    }}</span>
                                </div>
                                <div
                                    v-if="appliedVoucher && discountAmount > 0"
                                    class="flex justify-between gap-2 text-white/90"
                                >
                                    <span>Potongan voucher</span>
                                    <span>− Rp {{ formatCurrency(discountAmount) }}</span>
                                </div>
                                <div class="border-t border-white/20 pt-3">
                                    <div class="flex items-end justify-between gap-2">
                                        <span class="text-white/90">Total transfer</span>
                                        <span
                                            class="landing-price text-2xl !text-white sm:text-3xl"
                                        >
                                            Rp {{ formatPriceRb(amountDue) }}
                                        </span>
                                    </div>
                                    <p class="mt-1 text-right text-xs text-white/70">
                                        Nominal harus sesuai untuk verifikasi
                                    </p>
                                </div>
                            </div>

                            <button
                                type="button"
                                class="landing-btn landing-btn-primary mt-6 w-full justify-center !py-4 text-base"
                                :disabled="processing"
                                @click="submitPayment"
                            >
                                <Loader2 v-if="processing" class="h-5 w-5 animate-spin" />
                                <span v-if="processing">Memproses…</span>
                                <span v-else>Kirim pengajuan pembayaran</span>
                            </button>
                            <p class="mt-3 text-center text-xs text-white/55">
                                Dikonfirmasi admin dalam 1×24 jam
                            </p>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </AdminLayout>
</template>
