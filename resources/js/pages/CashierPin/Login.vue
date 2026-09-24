<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { toastError } from '@/lib/swal';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { Store as StoreIcon, User } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

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
const pin = ref('');
const error = ref('');
const isSubmitting = ref(false);

const cashiersWithPin = computed(() => props.cashiers.filter((c) => c.has_pin));

const flashError = computed(() => (page.props as { flash?: { error?: string } }).flash?.error);

watch(flashError, (e) => {
    if (e) void toastError(e);
});

function selectCashier(cashier: Cashier) {
    selectedCashier.value = cashier;
    pin.value = '';
    error.value = '';
}

function backToSelection() {
    selectedCashier.value = null;
    pin.value = '';
    error.value = '';
}

function appendDigit(d: string) {
    if (isSubmitting.value || pin.value.length >= 6) {
        return;
    }

    pin.value += d;
    error.value = '';

    if (pin.value.length === 6) {
        submitPin();
    }
}

function removeDigit() {
    if (!isSubmitting.value) {
        pin.value = pin.value.slice(0, -1);
    }
}

function submitPin() {
    if (isSubmitting.value) {
        return;
    }

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
            const message = (errors.pin as string) || 'PIN salah';
            error.value = message;
            pin.value = '';
            void toastError(message);
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

    <div class="flex min-h-screen items-center justify-center bg-gradient-to-br from-slate-900 via-blue-900 to-slate-900 p-4">
        <div class="w-full max-w-md">
            <div class="mb-8 text-center">
                <div class="mb-4 inline-flex h-16 w-16 items-center justify-center rounded-2xl bg-white/10 backdrop-blur">
                    <StoreIcon class="h-8 w-8 text-white" />
                </div>
                <h1 class="text-2xl font-bold text-white">{{ store_name }}</h1>
                <p class="mt-1 text-sm text-blue-200">Login Kasir - Masukkan PIN Anda</p>
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
                    <div v-if="cashiersWithPin.length === 0" class="py-8 text-center">
                        <p class="text-slate-500">Belum ada kasir yang terdaftar.</p>
                        <p class="mt-1 text-sm text-slate-400">Hubungi admin untuk mengatur PIN.</p>
                    </div>
                    <div v-else class="grid gap-2">
                        <button
                            v-for="cashier in cashiersWithPin"
                            :key="cashier.id"
                            type="button"
                            class="flex items-center gap-3 rounded-xl border border-slate-200 p-4 text-left transition-all hover:border-primary hover:bg-primary/5"
                            @click="selectCashier(cashier)"
                        >
                            <div class="flex h-10 w-10 items-center justify-center rounded-full bg-primary/10">
                                <span class="text-lg font-bold text-primary">{{ cashier.name.charAt(0).toUpperCase() }}</span>
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
                                class="h-4 w-4 rounded-full transition-all"
                                :class="filled ? 'scale-110 bg-primary' : 'bg-slate-200'"
                            />
                        </div>

                        <p v-if="error" class="text-center text-sm text-red-600">{{ error }}</p>

                        <div class="grid grid-cols-3 gap-3">
                            <Button
                                v-for="n in [1, 2, 3, 4, 5, 6, 7, 8, 9]"
                                :key="n"
                                variant="outline"
                                class="h-16 rounded-xl text-2xl font-bold"
                                :disabled="isSubmitting"
                                @click="appendDigit(String(n))"
                            >
                                {{ n }}
                            </Button>
                            <div />
                            <Button variant="outline" class="h-16 rounded-xl text-2xl font-bold" :disabled="isSubmitting" @click="appendDigit('0')">
                                0
                            </Button>
                            <Button variant="ghost" class="h-16 rounded-xl" :disabled="isSubmitting" @click="removeDigit">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M12 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2M3 12l7-7 11 11H3z"
                                    />
                                </svg>
                            </Button>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
