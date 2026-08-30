<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref, onMounted, onUnmounted } from 'vue';
import { ArrowLeft, Lock, Delete, X } from 'lucide-vue-next';

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

const cashiersWithPin = computed(() => props.cashiers.filter(c => c.has_pin));

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
    if (pin.value.length < 6) {
        pin.value += d;
        error.value = '';
        pressedKey.value = d;
        setTimeout(() => { pressedKey.value = null; }, 100);
    }
}

function removeDigit() {
    if (pin.value.length > 0) {
        pin.value = pin.value.slice(0, -1);
        pressedKey.value = 'del';
        setTimeout(() => { pressedKey.value = null; }, 100);
    }
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
        preserveScroll: true,
        onFinish: () => {
            isSubmitting.value = false;
        },
        onSuccess: () => {
            emit('success');
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

function handleKeydown(e: KeyboardEvent) {
    if (!selectedCashier.value) return;

    if (e.key >= '0' && e.key <= '9') {
        appendDigit(e.key);
    } else if (e.key === 'Backspace') {
        removeDigit();
    } else if (e.key === 'Enter' && pin.value.length >= 4) {
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
        class="fixed inset-0 z-[150] bg-slate-900/60 backdrop-blur-sm
               flex items-end justify-center p-0 md:items-center md:p-6"
        role="dialog"
        aria-modal="true"
        aria-labelledby="pin-login-title"
        @click.self="dismissible && emit('close')"
    >
        <!-- Modal Container -->
        <div class="relative flex flex-col bg-white dark:bg-slate-950
                    w-full max-h-[85vh]
                    md:h-auto md:max-h-[90vh] md:max-w-md md:rounded-3xl md:shadow-2xl md:border md:border-slate-200/50 dark:md:border-slate-800/50
                    overflow-hidden rounded-t-3xl md:rounded-3xl">

            <!-- Header -->
            <div class="flex items-center justify-between px-4 py-3 border-b border-slate-100 dark:border-slate-800 safe-top md:rounded-t-3xl">
                <!-- Mobile drag handle -->
                <div v-if="dismissible" class="absolute top-2 left-1/2 -translate-x-1/2 w-10 h-1 rounded-full bg-slate-300 dark:bg-slate-600 md:hidden" />
                <button
                    v-if="selectedCashier"
                    type="button"
                    class="w-10 h-10 flex items-center justify-center rounded-full hover:bg-slate-100 dark:hover:bg-slate-800 active:bg-slate-200 dark:active:bg-slate-700 transition-colors -ml-2"
                    @click="backToSelection"
                >
                    <ArrowLeft class="h-5 w-5 text-slate-600 dark:text-slate-400" />
                </button>
                <button
                    v-else-if="dismissible"
                    type="button"
                    class="w-10 h-10 flex items-center justify-center rounded-full hover:bg-slate-100 dark:hover:bg-slate-800 active:bg-slate-200 dark:active:bg-slate-700 transition-colors -ml-2"
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
                <div v-if="!selectedCashier" class="flex flex-col min-h-0 md:min-h-[400px]">
                    <div class="px-5 pt-5 pb-2">
                        <p class="text-sm text-slate-500 dark:text-slate-400">{{ storeName }}</p>
                    </div>

                    <!-- Empty state -->
                    <div v-if="cashiersWithPin.length === 0" class="flex-1 flex flex-col items-center justify-center px-6 py-12">
                        <div class="w-20 h-20 rounded-full bg-slate-100 dark:bg-slate-800 flex items-center justify-center mb-5">
                            <Lock class="h-8 w-8 text-slate-300 dark:text-slate-600" />
                        </div>
                        <p class="text-base font-medium text-slate-700 dark:text-slate-300 text-center">Belum ada kasir dengan PIN</p>
                        <p class="text-sm text-slate-400 dark:text-slate-500 mt-2 text-center">Hubungi admin untuk mengatur PIN</p>
                    </div>

                    <!-- Cashier list -->
                    <div v-else class="px-5 pb-6">
                        <p class="text-xs font-medium text-slate-400 dark:text-slate-500 uppercase tracking-wider mb-3">Daftar Kasir</p>
                        <div class="space-y-2">
                            <button
                                v-for="cashier in cashiersWithPin"
                                :key="cashier.id"
                                type="button"
                                class="w-full flex items-center gap-3 p-3 md:p-4 rounded-xl md:rounded-2xl
                                       bg-slate-50 dark:bg-slate-900
                                       hover:bg-slate-100 dark:hover:bg-slate-800
                                       active:bg-slate-200 dark:active:bg-slate-700
                                       transition-all text-left
                                       border border-transparent hover:border-slate-200 dark:hover:border-slate-700
                                       active:border-primary/30"
                                @click="selectCashier(cashier)"
                            >
                                <div class="w-11 h-11 md:w-12 md:h-12 rounded-full bg-gradient-to-br from-primary/20 to-primary/10 flex items-center justify-center flex-shrink-0 ring-2 ring-primary/10">
                                    <span class="text-primary font-bold text-base md:text-lg">{{ cashier.name.charAt(0).toUpperCase() }}</span>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="font-semibold text-slate-900 dark:text-white text-sm md:text-[15px] truncate">{{ cashier.name }}</p>
                                    <p v-if="cashier.position" class="text-xs md:text-sm text-slate-400 dark:text-slate-500 mt-0.5 truncate">{{ cashier.position }}</p>
                                </div>
                                <div class="w-8 h-8 rounded-full bg-primary/10 flex items-center justify-center flex-shrink-0">
                                    <Lock class="h-4 w-4 text-primary" />
                                </div>
                            </button>
                        </div>
                    </div>

                    <!-- Tutup button -->
                    <div v-if="dismissible" class="px-5 pb-6 pt-2 safe-bottom">
                        <button
                            type="button"
                            class="w-full h-11 md:h-12 rounded-xl md:rounded-2xl border border-slate-200 dark:border-slate-700
                                   text-slate-600 dark:text-slate-400 font-medium text-sm
                                   hover:bg-slate-50 dark:hover:bg-slate-800
                                   active:bg-slate-100 dark:active:bg-slate-700
                                   transition-colors"
                            @click="emit('close')"
                        >
                            Tutup
                        </button>
                    </div>
                </div>

                <!-- State: Input PIN -->
                <div v-else class="flex flex-col items-center px-6 pt-6 md:pt-8 pb-8 min-h-0 md:min-h-[400px]">
                    <!-- Avatar -->
                    <div class="w-18 h-18 md:w-20 md:h-20 rounded-full bg-gradient-to-br from-primary/20 to-primary/10 flex items-center justify-center mb-3 md:mb-4 ring-3 ring-primary/10">
                        <span class="text-primary font-bold text-2xl md:text-3xl">{{ selectedCashier.name.charAt(0).toUpperCase() }}</span>
                    </div>
                    <p class="text-base md:text-lg font-semibold text-slate-900 dark:text-white">{{ selectedCashier.name }}</p>
                    <p v-if="selectedCashier.position" class="text-xs md:text-sm text-slate-400 dark:text-slate-500 mt-1">{{ selectedCashier.position }}</p>

                    <!-- PIN Dots -->
                    <div class="flex justify-center gap-3 md:gap-4 my-8 md:my-10">
                        <div
                            v-for="(filled, i) in pinDots"
                            :key="i"
                            class="w-3.5 h-3.5 md:w-4 md:h-4 rounded-full transition-all duration-200"
                            :class="filled
                                ? 'bg-primary scale-110 shadow-lg shadow-primary/30'
                                : 'bg-slate-200 dark:bg-slate-700'"
                        />
                    </div>

                    <!-- Error -->
                    <p v-if="error" class="text-sm text-red-500 dark:text-red-400 mb-3 text-center min-h-[20px]">{{ error }}</p>
                    <div v-else class="min-h-[20px] mb-3" />

                    <!-- Keypad -->
                    <div class="grid grid-cols-3 gap-2.5 md:gap-3 w-full max-w-[260px] md:max-w-[280px]">
                        <button
                            v-for="n in ['1','2','3','4','5','6','7','8','9']"
                            :key="n"
                            type="button"
                            class="h-14 md:h-16 rounded-xl md:rounded-2xl text-xl md:text-2xl font-semibold
                                   transition-all duration-100 active:scale-95 select-none"
                            :class="pressedKey === n
                                ? 'bg-primary text-white shadow-lg shadow-primary/30 scale-95'
                                : 'bg-slate-50 dark:bg-slate-800 text-slate-800 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-700 active:bg-slate-200 dark:active:bg-slate-600'"
                            @click="appendDigit(n)"
                        >
                            {{ n }}
                        </button>

                        <!-- Spacer -->
                        <div />

                        <!-- 0 -->
                        <button
                            type="button"
                            class="h-14 md:h-16 rounded-xl md:rounded-2xl text-xl md:text-2xl font-semibold
                                   transition-all duration-100 active:scale-95 select-none"
                            :class="pressedKey === '0'
                                ? 'bg-primary text-white shadow-lg shadow-primary/30 scale-95'
                                : 'bg-slate-50 dark:bg-slate-800 text-slate-800 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-700 active:bg-slate-200 dark:active:bg-slate-600'"
                            @click="appendDigit('0')"
                        >
                            0
                        </button>

                        <!-- Backspace -->
                        <button
                            type="button"
                            class="h-14 md:h-16 rounded-xl md:rounded-2xl flex items-center justify-center
                                   transition-all duration-100 active:scale-95 select-none"
                            :class="pressedKey === 'del'
                                ? 'bg-red-50 dark:bg-red-950/30 text-red-500 scale-95'
                                : 'bg-slate-50 dark:bg-slate-800 text-slate-400 dark:text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-700 active:bg-slate-200 dark:active:bg-slate-600'"
                            @click="removeDigit"
                        >
                            <Delete class="h-5 w-5 md:h-6 md:w-6" />
                        </button>
                    </div>

                    <!-- Submit Button -->
                    <button
                        type="button"
                        class="w-full max-w-[260px] md:max-w-[280px] h-12 md:h-14 rounded-xl md:rounded-2xl mt-5 md:mt-6
                               font-semibold text-sm md:text-base
                               transition-all duration-200 active:scale-[0.98] select-none"
                        :class="pin.length >= 4 && !isSubmitting
                            ? 'bg-primary text-white shadow-lg shadow-primary/25 hover:shadow-xl hover:shadow-primary/30 active:shadow-md'
                            : 'bg-slate-100 dark:bg-slate-800 text-slate-400 dark:text-slate-600 cursor-not-allowed'"
                        :disabled="pin.length < 4 || isSubmitting"
                        @click="submitPin"
                    >
                        <span v-if="isSubmitting" class="flex items-center justify-center gap-2">
                            <svg class="animate-spin h-5 w-5" viewBox="0 0 24 24" fill="none">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                            </svg>
                            Memproses...
                        </span>
                        <span v-else>Masuk</span>
                    </button>
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
