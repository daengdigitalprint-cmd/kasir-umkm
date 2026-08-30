<template>
    <Head title="Pembayaran - UMKM Kasir" />

    <div class="min-h-screen bg-gradient-to-br from-slate-50 to-slate-100">
        <!-- Header -->
        <div class="bg-white shadow-sm border-b">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex justify-between items-center py-6">
                    <div>
                        <h1 class="text-3xl font-bold text-gray-900">Aktifkan Toko</h1>
                        <p class="text-gray-600 mt-1">Pilih paket untuk mengaktifkan toko Anda</p>
                    </div>
                    <div class="flex items-center gap-4">
                        <div class="text-right">
                            <p class="text-sm font-medium text-gray-900">{{ storeName }}</p>
                            <p class="text-xs text-gray-500">Status: Dinonaktifkan</p>
                        </div>
                        <div class="w-10 h-10 bg-red-600 rounded-full flex items-center justify-center">
                            <span class="text-white text-sm font-medium">!</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <!-- Pricing Cards -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8 mb-12">
                <!-- Basic Plan -->
                <div class="bg-white rounded-xl shadow-lg border border-gray-200 p-8 relative">
                    <div class="text-center">
                        <h3 class="text-2xl font-bold text-gray-900 mb-2">Paket Basic</h3>
                        <p class="text-gray-600 mb-6">Cocok untuk toko kecil</p>
                        <div class="mb-6">
                            <span class="text-4xl font-bold text-gray-900">Rp 50.000</span>
                            <span class="text-gray-600">/bulan</span>
                        </div>
                        <ul class="space-y-3 mb-8 text-left">
                            <li class="flex items-center">
                                <Check class="h-5 w-5 text-green-500 mr-3" />
                                <span class="text-gray-700">30 hari masa aktif</span>
                            </li>
                            <li class="flex items-center">
                                <Check class="h-5 w-5 text-green-500 mr-3" />
                                <span class="text-gray-700">Hingga 30 produk</span>
                            </li>
                            <li class="flex items-center">
                                <Check class="h-5 w-5 text-green-500 mr-3" />
                                <span class="text-gray-700">Laporan penjualan</span>
                            </li>
                            <li class="flex items-center">
                                <Check class="h-5 w-5 text-green-500 mr-3" />
                                <span class="text-gray-700">Support email</span>
                            </li>
                        </ul>
                        <Button 
                            @click="selectPlan('basic')"
                            :class="[
                                'w-full py-3 px-6 rounded-lg font-semibold transition-colors',
                                selectedPlan === 'basic' 
                                    ? 'bg-blue-600 text-white hover:bg-blue-700' 
                                    : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
                            ]"
                        >
                            {{ selectedPlan === 'basic' ? 'Dipilih' : 'Pilih Paket' }}
                        </Button>
                    </div>
                </div>

                <!-- Premium Plan -->
                <div class="bg-white rounded-xl shadow-lg border-2 border-blue-500 p-8 relative">
                    <div class="absolute -top-4 left-1/2 transform -translate-x-1/2">
                        <span class="bg-blue-500 text-white px-4 py-1 rounded-full text-sm font-semibold">
                            Paling Populer
                        </span>
                    </div>
                    <div class="text-center">
                        <h3 class="text-2xl font-bold text-gray-900 mb-2">Paket Premium</h3>
                        <p class="text-gray-600 mb-6">Cocok untuk toko menengah</p>
                        <div class="mb-6">
                            <span class="text-4xl font-bold text-gray-900">Rp 100.000</span>
                            <span class="text-gray-600">/bulan</span>
                        </div>
                        <ul class="space-y-3 mb-8 text-left">
                            <li class="flex items-center">
                                <Check class="h-5 w-5 text-green-500 mr-3" />
                                <span class="text-gray-700">30 hari masa aktif</span>
                            </li>
                            <li class="flex items-center">
                                <Check class="h-5 w-5 text-green-500 mr-3" />
                                <span class="text-gray-700">Hingga 500 produk</span>
                            </li>
                            <li class="flex items-center">
                                <Check class="h-5 w-5 text-green-500 mr-3" />
                                <span class="text-gray-700">Laporan lengkap</span>
                            </li>
                            <li class="flex items-center">
                                <Check class="h-5 w-5 text-green-500 mr-3" />
                                <span class="text-gray-700">Export Excel/PDF</span>
                            </li>
                            <li class="flex items-center">
                                <Check class="h-5 w-5 text-green-500 mr-3" />
                                <span class="text-gray-700">Support prioritas</span>
                            </li>
                        </ul>
                        <Button 
                            @click="selectPlan('premium')"
                            :class="[
                                'w-full py-3 px-6 rounded-lg font-semibold transition-colors',
                                selectedPlan === 'premium' 
                                    ? 'bg-blue-600 text-white hover:bg-blue-700' 
                                    : 'bg-blue-100 text-blue-700 hover:bg-blue-200'
                            ]"
                        >
                            {{ selectedPlan === 'premium' ? 'Dipilih' : 'Pilih Paket' }}
                        </Button>
                    </div>
                </div>

                <!-- Enterprise Plan -->
                <div class="bg-white rounded-xl shadow-lg border border-gray-200 p-8 relative">
                    <div class="text-center">
                        <h3 class="text-2xl font-bold text-gray-900 mb-2">Paket Enterprise</h3>
                        <p class="text-gray-600 mb-6">Cocok untuk toko besar</p>
                        <div class="mb-6">
                            <span class="text-4xl font-bold text-gray-900">Rp 200.000</span>
                            <span class="text-gray-600">/bulan</span>
                        </div>
                        <ul class="space-y-3 mb-8 text-left">
                            <li class="flex items-center">
                                <Check class="h-5 w-5 text-green-500 mr-3" />
                                <span class="text-gray-700">30 hari masa aktif</span>
                            </li>
                            <li class="flex items-center">
                                <Check class="h-5 w-5 text-green-500 mr-3" />
                                <span class="text-gray-700">Produk unlimited</span>
                            </li>
                            <li class="flex items-center">
                                <Check class="h-5 w-5 text-green-500 mr-3" />
                                <span class="text-gray-700">Analytics mendalam</span>
                            </li>
                            <li class="flex items-center">
                                <Check class="h-5 w-5 text-green-500 mr-3" />
                                <span class="text-gray-700">API access</span>
                            </li>
                            <li class="flex items-center">
                                <Check class="h-5 w-5 text-green-500 mr-3" />
                                <span class="text-gray-700">Support 24/7</span>
                            </li>
                        </ul>
                        <Button 
                            @click="selectPlan('enterprise')"
                            :class="[
                                'w-full py-3 px-6 rounded-lg font-semibold transition-colors',
                                selectedPlan === 'enterprise' 
                                    ? 'bg-blue-600 text-white hover:bg-blue-700' 
                                    : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
                            ]"
                        >
                            {{ selectedPlan === 'enterprise' ? 'Dipilih' : 'Pilih Paket' }}
                        </Button>
                    </div>
                </div>
            </div>

            <!-- Payment Method -->
            <div v-if="selectedPlan" class="bg-white rounded-xl shadow-lg p-8 mb-8">
                <h3 class="text-2xl font-bold text-gray-900 mb-6">Metode Pembayaran</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div 
                        v-for="method in paymentMethods" 
                        :key="method.id"
                        @click="selectPaymentMethod(method.id)"
                        :class="[
                            'border-2 rounded-lg p-4 cursor-pointer transition-colors',
                            selectedPaymentMethod === method.id 
                                ? 'border-blue-500 bg-blue-50' 
                                : 'border-gray-200 hover:border-gray-300'
                        ]"
                    >
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-gray-100 rounded-lg flex items-center justify-center mr-4">
                                <component :is="method.icon" class="h-6 w-6 text-gray-600" />
                            </div>
                            <div>
                                <h4 class="font-semibold text-gray-900">{{ method.name }}</h4>
                                <p class="text-sm text-gray-600">{{ method.description }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Payment Summary -->
            <div v-if="selectedPlan && selectedPaymentMethod" class="bg-white rounded-xl shadow-lg p-8">
                <h3 class="text-2xl font-bold text-gray-900 mb-6">Ringkasan Pembayaran</h3>
                <div class="space-y-4 mb-6">
                    <div class="flex justify-between">
                        <span class="text-gray-600">Paket yang dipilih:</span>
                        <span class="font-semibold">{{ getPlanName(selectedPlan) }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">Durasi:</span>
                        <span class="font-semibold">30 hari</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">Metode pembayaran:</span>
                        <span class="font-semibold">{{ getPaymentMethodName(selectedPaymentMethod) }}</span>
                    </div>
                    <div class="border-t pt-4">
                        <div class="flex justify-between text-lg font-bold">
                            <span>Total:</span>
                            <span class="text-blue-600">Rp {{ formatCurrency(getPlanPrice(selectedPlan)) }}</span>
                        </div>
                    </div>
                </div>
                <div class="flex gap-4">
                    <Button 
                        @click="processPayment"
                        :disabled="processing"
                        class="flex-1 bg-blue-600 hover:bg-blue-700 text-white py-3 px-6 rounded-lg font-semibold"
                    >
                        <CreditCard class="h-5 w-5 mr-2" />
                        {{ processing ? 'Memproses...' : 'Bayar Sekarang' }}
                    </Button>
                    <Button 
                        @click="goBack"
                        variant="outline"
                        class="px-6 py-3 rounded-lg font-semibold"
                    >
                        Kembali
                    </Button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Check, CreditCard, Smartphone, Building2, Wallet } from 'lucide-vue-next';
import { ref } from 'vue';
import { toastError, toastSuccess } from '@/lib/swal';

interface Props {
    storeName: string;
}

const { storeName } = defineProps<Props>();

const selectedPlan = ref<string | null>(null);
const selectedPaymentMethod = ref<string | null>(null);
const processing = ref(false);

const paymentMethods = [
    {
        id: 'bank_transfer',
        name: 'Transfer Bank',
        description: 'BCA, Mandiri, BNI, BRI',
        icon: Building2
    },
    {
        id: 'e_wallet',
        name: 'E-Wallet',
        description: 'GoPay, OVO, DANA, ShopeePay',
        icon: Smartphone
    },
    {
        id: 'qris',
        name: 'QRIS',
        description: 'Scan QR untuk pembayaran',
        icon: CreditCard
    },
    {
        id: 'cash',
        name: 'Tunai',
        description: 'Bayar langsung ke admin',
        icon: Wallet
    }
];

const selectPlan = (plan: string) => {
    selectedPlan.value = plan;
};

const selectPaymentMethod = (method: string) => {
    selectedPaymentMethod.value = method;
};

const getPlanName = (plan: string) => {
    const names = {
        basic: 'Paket Basic',
        premium: 'Paket Premium',
        enterprise: 'Paket Enterprise'
    };
    return names[plan as keyof typeof names] || plan;
};

const getPlanPrice = (plan: string) => {
    const prices = {
        basic: 50000,
        premium: 100000,
        enterprise: 200000
    };
    return prices[plan as keyof typeof prices] || 0;
};

const getPaymentMethodName = (method: string) => {
    const methodObj = paymentMethods.find(m => m.id === method);
    return methodObj?.name || method;
};

const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('id-ID').format(amount);
};

const processPayment = async () => {
    if (!selectedPlan.value || !selectedPaymentMethod.value) return;
    
    processing.value = true;
    
    try {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        if (!csrfToken) {
            await toastError('CSRF token tidak ditemukan');
            return;
        }
        
        const response = await fetch('/payment/process', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json',
            },
            body: JSON.stringify({
                plan: selectedPlan.value,
                payment_method: selectedPaymentMethod.value,
            }),
        });
        
        const result = await response.json();
        
        if (result.success) {
            await toastSuccess(result.message || 'Pembayaran berhasil', 2000);
            window.location.href = result.redirect || '/dashboard';
        } else {
            await toastError(result.message || 'Terjadi kesalahan saat memproses pembayaran.');
        }
    } catch (error) {
        console.error('Payment error:', error);
        await toastError('Terjadi kesalahan saat memproses pembayaran. Silakan coba lagi.');
    } finally {
        processing.value = false;
    }
};

const goBack = () => {
    window.history.back();
};
</script>
