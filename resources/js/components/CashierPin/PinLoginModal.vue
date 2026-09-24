<script setup lang="ts">
import { toastError } from '@/lib/swal';
import { useForm } from '@inertiajs/vue3';
import { ArrowLeft, Delete, Lock, X } from 'lucide-vue-next';
import { computed, onMounted, onUnmounted, ref } from 'vue';

interface Cashier {
    id: number;
    name: string;
    position: string | null;
    has_pin: boolean;
}

const props = defineProps<{
    cashiers: Cashier[];
    storeName: string;
    dismissible?: boolean;
}>();

const emit = defineEmits<{
    close: [];
    success: [];
}>();

const selectedCashier = ref<Cashier | null>(null);
const pin = ref('');
const error = ref('');
const isSubmitting = ref(false);
const pressedKey = ref<string | null>(null);

const cashiersWithPin = computed(() => props.cashiers.filter((c) => c.has_pin));

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
    pressedKey.value = d;
    setTimeout(() => {
        pressedKey.value = null;
    }, 100);

    if (pin.value.length === 6) {
        submitPin();
    }
}

function removeDigit() {
    if (!isSubmitting.value && pin.value.length > 0) {
        pin.value = pin.value.slice(0, -1);
        pressedKey.value = 'del';
        setTimeout(() => {
            pressedKey.value = null;
        }, 100);
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
        preserveScroll: true,
        onFinish: () => {
            isSubmitting.value = false;
        },
        onSuccess: () => {
            emit('success');
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

function handleKeydown(e: KeyboardEvent) {
    if (!selectedCashier.value) return;

    if (e.key >= '0' && e.key <= '9') {
        appendDigit(e.key);
    } else if (e.key === 'Backspace') {
        removeDigit();
    } else if (e.key === 'Enter' && pin.value.length >= 4 && !isSubmitting.value) {
        submitPin();
    } else if (e.key === 'Escape' && props.dismissible !== false) {
        if (selectedCashier.value) {
            backToSelection();
        } else {
            emit('close');
        }
    }
}

onMounted(() => {
    window.addEventListener('keydown', handleKeydown);
});

onUnmounted(() => {
    window.removeEventListener('keydown', handleKeydown);
});
</script>

<template>
    <!-- Mobile: full-screen | Desktop: centered modal with backdrop -->
    <div
        class="fixed inset-0 z-[150] flex items-end justify-center bg-slate-900/60 p-0 backdrop-blur-sm md:items-center md:p-6"
        role="dialog"
        aria-modal="true"
        aria-labelledby="pin-login-title"
        @click.self="dismissible && emit('close')"
    >
        <!-- Modal Container -->
        <div
            class="relative flex max-h-[85vh] w-full flex-col overflow-hidden rounded-t-3xl bg-white md:h-auto md:max-h-[90vh] md:max-w-md md:rounded-3xl md:border md:border-slate-200/50 md:shadow-2xl dark:bg-slate-950 dark:md:border-slate-800/50"
        >
            <!-- Header -->
            <div class="safe-top flex items-center justify-between border-b border-slate-100 px-4 py-3 md:rounded-t-3xl dark:border-slate-800">
                <!-- Mobile drag handle -->
                <div
                    v-if="dismissible"
                    class="absolute top-2 left-1/2 h-1 w-10 -translate-x-1/2 rounded-full bg-slate-300 md:hidden dark:bg-slate-600"
                />
                <button
                    v-if="selectedCashier"
                    type="button"
                    class="-ml-2 flex h-10 w-10 items-center justify-center rounded-full transition-colors hover:bg-slate-100 active:bg-slate-200 dark:hover:bg-slate-800 dark:active:bg-slate-700"
                    @click="backToSelection"
                >
                    <ArrowLeft class="h-5 w-5 text-slate-600 dark:text-slate-400" />
                </button>
                <button
                    v-else-if="dismissible"
                    type="button"
                    class="-ml-2 flex h-10 w-10 items-center justify-center rounded-full transition-colors hover:bg-slate-100 active:bg-slate-200 dark:hover:bg-slate-800 dark:active:bg-slate-700"
                    @click="emit('close')"
                >
                    <X class="h-5 w-5 text-slate-600 dark:text-slate-400" />
                </button>
                <div v-else class="w-10" />

                <h2 id="pin-login-title" class="text-base font-semibold text-slate-900 dark:text-white">
                    {{ selectedCashier ? 'Masukkan PIN' : 'Pilih Kasir' }}
                </h2>

                <div class="w-10" />
            </div>

            <!-- Content -->
            <div class="flex-1 overflow-y-auto overscroll-contain">
                <!-- State: Pilih Kasir -->
                <div v-if="!selectedCashier" class="flex min-h-0 flex-col md:min-h-[400px]">
                    <div class="px-5 pt-5 pb-2">
                        <p class="text-sm text-slate-500 dark:text-slate-400">{{ storeName }}</p>
                    </div>

                    <!-- Empty state -->
                    <div v-if="cashiersWithPin.length === 0" class="flex flex-1 flex-col items-center justify-center px-6 py-12">
                        <div class="mb-5 flex h-20 w-20 items-center justify-center rounded-full bg-slate-100 dark:bg-slate-800">
                            <Lock class="h-8 w-8 text-slate-300 dark:text-slate-600" />
                        </div>
                        <p class="text-center text-base font-medium text-slate-700 dark:text-slate-300">Belum ada kasir dengan PIN</p>
                        <p class="mt-2 text-center text-sm text-slate-400 dark:text-slate-500">Hubungi admin untuk mengatur PIN</p>
                    </div>

                    <!-- Cashier list -->
                    <div v-else class="px-5 pb-6">
                        <p class="mb-3 text-xs font-medium tracking-wider text-slate-400 uppercase dark:text-slate-500">Daftar Kasir</p>
                        <div class="space-y-2">
                            <button
                                v-for="cashier in cashiersWithPin"
                                :key="cashier.id"
                                type="button"
                                class="flex w-full items-center gap-3 rounded-xl border border-transparent bg-slate-50 p-3 text-left transition-all hover:border-slate-200 hover:bg-slate-100 active:border-primary/30 active:bg-slate-200 md:rounded-2xl md:p-4 dark:bg-slate-900 dark:hover:border-slate-700 dark:hover:bg-slate-800 dark:active:bg-slate-700"
                                @click="selectCashier(cashier)"
                            >
                                <div
                                    class="flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-primary/20 to-primary/10 ring-2 ring-primary/10 md:h-12 md:w-12"
                                >
                                    <span class="text-base font-bold text-primary md:text-lg">{{ cashier.name.charAt(0).toUpperCase() }}</span>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-semibold text-slate-900 md:text-[15px] dark:text-white">{{ cashier.name }}</p>
                                    <p v-if="cashier.position" class="mt-0.5 truncate text-xs text-slate-400 md:text-sm dark:text-slate-500">
                                        {{ cashier.position }}
                                    </p>
                                </div>
                                <div class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full bg-primary/10">
                                    <Lock class="h-4 w-4 text-primary" />
                                </div>
                            </button>
                        </div>
                    </div>

                    <!-- Tutup button -->
                    <div v-if="dismissible" class="safe-bottom px-5 pt-2 pb-6">
                        <button
                            type="button"
                            class="h-11 w-full rounded-xl border border-slate-200 text-sm font-medium text-slate-600 transition-colors hover:bg-slate-50 active:bg-slate-100 md:h-12 md:rounded-2xl dark:border-slate-700 dark:text-slate-400 dark:hover:bg-slate-800 dark:active:bg-slate-700"
                            @click="emit('close')"
                        >
                            Tutup
                        </button>
                    </div>
                </div>

                <!-- State: Input PIN -->
                <div v-else class="flex min-h-0 flex-col items-center px-6 pt-6 pb-8 md:min-h-[400px] md:pt-8">
                    <!-- Avatar -->
                    <div
                        class="mb-3 flex h-18 w-18 items-center justify-center rounded-full bg-gradient-to-br from-primary/20 to-primary/10 ring-3 ring-primary/10 md:mb-4 md:h-20 md:w-20"
                    >
                        <span class="text-2xl font-bold text-primary md:text-3xl">{{ selectedCashier.name.charAt(0).toUpperCase() }}</span>
                    </div>
                    <p class="text-base font-semibold text-slate-900 md:text-lg dark:text-white">{{ selectedCashier.name }}</p>
                    <p v-if="selectedCashier.position" class="mt-1 text-xs text-slate-400 md:text-sm dark:text-slate-500">
                        {{ selectedCashier.position }}
                    </p>

                    <!-- PIN Dots -->
                    <div class="my-8 flex justify-center gap-3 md:my-10 md:gap-4">
                        <div
                            v-for="(filled, i) in pinDots"
                            :key="i"
                            class="h-3.5 w-3.5 rounded-full transition-all duration-200 md:h-4 md:w-4"
                            :class="filled ? 'scale-110 bg-primary shadow-lg shadow-primary/30' : 'bg-slate-200 dark:bg-slate-700'"
                        />
                    </div>

                    <!-- Error -->
                    <p v-if="error" class="mb-3 min-h-[20px] text-center text-sm text-red-500 dark:text-red-400">{{ error }}</p>
                    <div v-else class="mb-3 min-h-[20px]" />

                    <!-- Keypad -->
                    <div class="grid w-full max-w-[260px] grid-cols-3 gap-2.5 md:max-w-[280px] md:gap-3">
                        <button
                            v-for="n in ['1', '2', '3', '4', '5', '6', '7', '8', '9']"
                            :key="n"
                            type="button"
                            class="h-14 rounded-xl text-xl font-semibold transition-all duration-100 select-none active:scale-95 md:h-16 md:rounded-2xl md:text-2xl"
                            :class="
                                pressedKey === n
                                    ? 'scale-95 bg-primary text-white shadow-lg shadow-primary/30'
                                    : 'bg-slate-50 text-slate-800 hover:bg-slate-100 active:bg-slate-200 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700 dark:active:bg-slate-600'
                            "
                            @click="appendDigit(n)"
                        >
                            {{ n }}
                        </button>

                        <!-- Spacer -->
                        <div />

                        <!-- 0 -->
                        <button
                            type="button"
                            class="h-14 rounded-xl text-xl font-semibold transition-all duration-100 select-none active:scale-95 md:h-16 md:rounded-2xl md:text-2xl"
                            :class="
                                pressedKey === '0'
                                    ? 'scale-95 bg-primary text-white shadow-lg shadow-primary/30'
                                    : 'bg-slate-50 text-slate-800 hover:bg-slate-100 active:bg-slate-200 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700 dark:active:bg-slate-600'
                            "
                            @click="appendDigit('0')"
                        >
                            0
                        </button>

                        <!-- Backspace -->
                        <button
                            type="button"
                            class="flex h-14 items-center justify-center rounded-xl transition-all duration-100 select-none active:scale-95 md:h-16 md:rounded-2xl"
                            :class="
                                pressedKey === 'del'
                                    ? 'scale-95 bg-red-50 text-red-500 dark:bg-red-950/30'
                                    : 'bg-slate-50 text-slate-400 hover:bg-slate-100 active:bg-slate-200 dark:bg-slate-800 dark:text-slate-500 dark:hover:bg-slate-700 dark:active:bg-slate-600'
                            "
                            :disabled="isSubmitting"
                            @click="removeDigit"
                        >
                            <Delete class="h-5 w-5 md:h-6 md:w-6" />
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.safe-top {
    padding-top: env(safe-area-inset-top, 0px);
}
.safe-bottom {
    padding-bottom: env(safe-area-inset-bottom, 0px);
}
</style>
