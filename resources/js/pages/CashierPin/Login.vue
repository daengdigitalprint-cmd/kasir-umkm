<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Lock, User, Store as StoreIcon } from 'lucide-vue-next';
import { toastError, toastSuccess } from '@/lib/swal';

interface Cashier {
    id: number;
    name: string;
    position: string | null;
    has_pin: boolean;
}

const props = defineProps<{
    cashiers: Cashier[];
    store_name: string;
}>();

const page = usePage();

const selectedCashier = ref<Cashier | null>(null);
const pinInput = ref<HTMLInputElement | null>(null);
const pin = ref('');
const error = ref('');
const isSubmitting = ref(false);

const cashiersWithPin = computed(() => props.cashiers.filter(c => c.has_pin));

const flashError = computed(() => (page.props as { flash?: { error?: string } }).flash?.error);

watch(flashError, (e) => {
    if (e) void toastError(e);
});

function selectCashier(cashier: Cashier) {
    selectedCashier.value = cashier;
    pin.value = '';
    error.value = '';
    setTimeout(() => pinInput.value?.focus(), 100);
}

function backToSelection() {
    selectedCashier.value = null;
    pin.value = '';
    error.value = '';
}

function appendDigit(d: string) {
    if (pin.value.length < 6) {
        pin.value += d;
        error.value = '';
    }
}

function removeDigit() {
    pin.value = pin.value.slice(0, -1);
}

function submitPin() {
    if (!selectedCashier.value || pin.value.length < 4) {
        error.value = 'PIN minimal 4 digit';
        return;
    }

    isSubmitting.value = true;
    error.value = '';

    const form = useForm({
        employee_id: selectedCashier.value.id,
        pin: pin.value,
    });

    form.post('/cashier-pin/login', {
        onFinish: () => {
            isSubmitting.value = false;
        },
        onError: (errors) => {
            error.value = (errors.pin as string) || 'PIN salah';
            pin.value = '';
        },
    });
}

const pinDots = computed(() => {
    const dots = [];
    for (let i = 0; i < 6; i++) {
        dots.push(i < pin.value.length);
    }
    return dots;
});
</script>

<template>
    <Head title="Login Kasir" />

    <div class="min-h-screen bg-gradient-to-br from-slate-900 via-blue-900 to-slate-900 flex items-center justify-center p-4">
        <div class="w-full max-w-md">
            <div class="text-center mb-8">
                <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-white/10 backdrop-blur mb-4">
                    <StoreIcon class="h-8 w-8 text-white" />
                </div>
                <h1 class="text-2xl font-bold text-white">{{ store_name }}</h1>
                <p class="text-blue-200 text-sm mt-1">Login Kasir - Masukkan PIN Anda</p>
            </div>

            <Card v-if="!selectedCashier" class="border-0 shadow-2xl">
                <CardHeader>
                    <CardTitle class="flex items-center gap-2">
                        <User class="h-5 w-5 text-primary" />
                        Pilih Kasir
                    </CardTitle>
                    <CardDescription>Pilih nama Anda untuk masuk shift</CardDescription>
                </CardHeader>
                <CardContent>
                    <div v-if="cashiersWithPin.length === 0" class="text-center py-8">
                        <p class="text-slate-500">Belum ada kasir yang terdaftar.</p>
                        <p class="text-sm text-slate-400 mt-1">Hubungi admin untuk mengatur PIN.</p>
                    </div>
                    <div v-else class="grid gap-2">
                        <button
                            v-for="cashier in cashiersWithPin"
                            :key="cashier.id"
                            type="button"
                            class="flex items-center gap-3 p-4 rounded-xl border border-slate-200 hover:border-primary hover:bg-primary/5 transition-all text-left"
                            @click="selectCashier(cashier)"
                        >
                            <div class="w-10 h-10 rounded-full bg-primary/10 flex items-center justify-center">
                                <span class="text-primary font-bold text-lg">{{ cashier.name.charAt(0).toUpperCase() }}</span>
                            </div>
                            <div>
                                <p class="font-semibold text-slate-900">{{ cashier.name }}</p>
                                <p v-if="cashier.position" class="text-xs text-slate-500">{{ cashier.position }}</p>
                            </div>
                        </button>
                    </div>
                </CardContent>
            </Card>

            <Card v-else class="border-0 shadow-2xl">
                <CardHeader>
                    <div class="flex items-center justify-between">
                        <CardTitle class="text-lg">Halo, {{ selectedCashier.name }}</CardTitle>
                        <Button variant="ghost" size="sm" @click="backToSelection">Ganti</Button>
                    </div>
                    <CardDescription>Masukkan PIN Anda untuk memulai shift</CardDescription>
                </CardHeader>
                <CardContent>
                    <div class="space-y-6">
                        <div class="flex justify-center gap-3">
                            <div
                                v-for="(filled, i) in pinDots"
                                :key="i"
                                class="w-4 h-4 rounded-full transition-all"
                                :class="filled ? 'bg-primary scale-110' : 'bg-slate-200'"
                            />
                        </div>

                        <p v-if="error" class="text-center text-sm text-red-600">{{ error }}</p>

                        <div class="grid grid-cols-3 gap-3">
                            <Button
                                v-for="n in [1, 2, 3, 4, 5, 6, 7, 8, 9]"
                                :key="n"
                                variant="outline"
                                class="h-16 text-2xl font-bold rounded-xl"
                                @click="appendDigit(String(n))"
                            >
                                {{ n }}
                            </Button>
                            <div />
                            <Button
                                variant="outline"
                                class="h-16 text-2xl font-bold rounded-xl"
                                @click="appendDigit('0')"
                            >
                                0
                            </Button>
                            <Button
                                variant="ghost"
                                class="h-16 rounded-xl"
                                @click="removeDigit"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2M3 12l7-7 11 11H3z" />
                                </svg>
                            </Button>
                        </div>

                        <Button
                            class="w-full h-12 text-lg rounded-xl"
                            :disabled="pin.length < 4 || isSubmitting"
                            @click="submitPin"
                        >
                            <Lock class="h-5 w-5 mr-2" />
                            Masuk Shift
                        </Button>
                    </div>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
