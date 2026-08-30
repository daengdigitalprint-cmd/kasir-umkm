<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Separator } from '@/components/ui/separator';
import { usePrinter, type PrinterRole, type PrinterDevice } from '@/composables/usePrinter';
import { toastSuccess, toastError, toastInfo, modalError } from '@/lib/swal';
import {
    Bluetooth,
    Printer,
    Search,
    CheckCircle2,
    XCircle,
    AlertCircle,
    Loader2,
    Trash2,
    TestTube,
    WifiOff,
    Smartphone,
    ChefHat,
    Receipt,
} from 'lucide-vue-next';

const props = withDefaults(
    defineProps<{
        embedded?: boolean;
        showTitle?: boolean;
    }>(),
    { embedded: false, showTitle: true },
);

const {
    mode,
    isAndroid,
    isBrowser,
    isAvailable,
    pairedDevices,
    initialize,
    scanDevices,
    connectPrinter,
    connectBrowserDevice,
    disconnect,
    forgetPrinter,
    printEscPosBytes,
    getRoleState,
} = usePrinter();

const activeTab = ref<PrinterRole>('cashier');

const currentState = computed(() => getRoleState(activeTab.value));
const isConnected = computed(() => currentState.value.isConnected);
const isLoading = computed(() => currentState.value.isLoading);
const isPrinting = computed(() => currentState.value.isPrinting);
const error = computed(() => currentState.value.error);
const selectedPrinter = computed(() => currentState.value.selectedPrinter);

const modeLabel = computed(() => {
    if (isAndroid.value) return 'Android SPP (Classic)';
    if (isBrowser.value) return 'Web Bluetooth (BLE)';
    return 'Tidak tersedia';
});

onMounted(async () => {
    await initialize();
});

async function handleAndroidScan() {
    scanDevices();
}

async function handleConnect(device: PrinterDevice) {
    connectPrinter(device, activeTab.value);
}

async function handleTestPrint() {
    const bytes = new Uint8Array([0x1b, 0x40, 0x1b, 0x61, 0x01, ...new TextEncoder().encode(`\nTEST PRINT\nRole: ${activeTab.value}\n\n\n\n\n`), 0x1d, 0x56, 0x41, 0x00]);
    const result = await printEscPosBytes(bytes, activeTab.value);
    if (result.success) {
        await toastSuccess('Struk test berhasil dicetak!', 3000);
    } else {
        await modalError('Gagal mencetak', result.error || 'Terjadi kesalahan');
    }
}

function handleDisconnect() {
    disconnect(activeTab.value);
    void toastInfo('Printer diputuskan', 2000);
}

function handleForget() {
    forgetPrinter(activeTab.value);
    void toastInfo('Printer di-reset.', 3500);
}

async function handleBrowserScan() {
    const result = await connectBrowserDevice(activeTab.value);
    if (result.success) {
        await toastSuccess('Printer terhubung!', 3000);
    }
}
</script>

<template>
    <Card :class="embedded ? 'border-0 shadow-lg mb-6' : 'mb-6'">
        <CardHeader v-if="showTitle">
            <CardTitle class="flex items-center gap-2 text-base">
                <Bluetooth class="h-5 w-5" />
                Pengaturan Dual Printer
            </CardTitle>
            <CardDescription>
                Mode aktif: <strong>{{ modeLabel }}</strong> — hubungkan printer Kasir dan Dapur secara terpisah.
            </CardDescription>
        </CardHeader>

        <CardContent class="space-y-4">
            <div class="flex gap-2 p-1 bg-slate-100 rounded-xl">
                <button
                    v-for="role in (['cashier', 'kitchen'] as PrinterRole[])"
                    :key="role"
                    class="flex-1 flex items-center justify-center gap-2 py-2 px-3 rounded-lg text-sm font-bold transition-all"
                    :class="activeTab === role ? 'bg-white shadow-sm text-blue-600' : 'text-slate-500 hover:text-slate-700'"
                    @click="activeTab = role"
                >
                    <Receipt v-if="role === 'cashier'" class="h-4 w-4" />
                    <ChefHat v-else class="h-4 w-4" />
                    {{ role === 'cashier' ? 'Kasir (Struk)' : 'Dapur (KOT)' }}
                </button>
            </div>

            <!-- APK Android -->
            <div v-if="isAndroid" class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-xs text-emerald-800">
                <div class="flex items-start gap-2">
                    <Smartphone class="mt-0.5 h-4 w-4 shrink-0" />
                    <p>Pair printer di <strong>Settings → Bluetooth</strong> Android, lalu pilih untuk <strong>{{ activeTab === 'cashier' ? 'Kasir' : 'Dapur' }}</strong>.</p>
                </div>
            </div>

            <template v-if="isAvailable">
                <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                    <div class="mb-3 flex items-center justify-between">
                        <span class="text-sm font-semibold">Status {{ activeTab === 'cashier' ? 'Kasir' : 'Dapur' }}</span>
                        <Badge :variant="isConnected ? 'default' : error ? 'destructive' : 'outline'">
                            <Loader2 v-if="isLoading" class="mr-1 h-3 w-3 animate-spin" />
                            <CheckCircle2 v-else-if="isConnected" class="mr-1 h-3 w-3" />
                            <XCircle v-else-if="error" class="mr-1 h-3 w-3" />
                            <WifiOff v-else class="mr-1 h-3 w-3" />
                            {{ isLoading ? 'Menghubungkan...' : isConnected ? 'Terhubung' : error ? 'Error' : 'Tidak Terhubung' }}
                        </Badge>
                    </div>

                    <div v-if="isConnected && selectedPrinter" class="flex items-center gap-2">
                        <Printer class="h-5 w-5 shrink-0 text-emerald-600" />
                        <div>
                            <p class="text-sm font-semibold text-emerald-900">{{ selectedPrinter.name }}</p>
                            <p class="text-xs text-emerald-700">{{ selectedPrinter.mac ?? selectedPrinter.id }}</p>
                        </div>
                    </div>
                    <p v-else class="text-sm text-slate-500 italic">Belum ada printer yang dipilih untuk {{ activeTab === 'cashier' ? 'kasir' : 'dapur' }}.</p>
                </div>

                <div v-if="error" class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">{{ error }}</div>

                <template v-if="isAndroid">
                    <Button class="w-full" :disabled="isLoading" @click="handleAndroidScan">
                        <Search v-if="!isLoading" class="mr-2 h-4 w-4" />
                        <Loader2 v-else class="mr-2 h-4 w-4 animate-spin" />
                        {{ isLoading ? 'Mencari...' : 'Scan Perangkat Paired' }}
                    </Button>

                    <div v-if="pairedDevices.length > 0" class="space-y-2">
                        <p class="text-xs text-slate-400 font-bold uppercase tracking-wider">Pilih perangkat:</p>
                        <button
                            v-for="device in pairedDevices"
                            :key="device.id"
                            type="button"
                            class="flex w-full items-center justify-between rounded-xl border p-3 text-left transition-all"
                            :class="selectedPrinter?.id === device.id ? 'border-blue-500 bg-blue-50 ring-1 ring-blue-500' : 'border-slate-200 hover:border-slate-300'"
                            @click="handleConnect(device)"
                        >
                            <div>
                                <p class="text-sm font-bold">{{ device.name }}</p>
                                <p class="text-xs text-slate-400">{{ device.mac ?? device.id }}</p>
                            </div>
                            <CheckCircle2 v-if="selectedPrinter?.id === device.id && isConnected" class="h-4 w-4 text-emerald-600" />
                        </button>
                    </div>
                </template>

                <!-- Browser / WebView scan button -->
                <template v-if="isBrowser">
                    <Button class="w-full" :disabled="isLoading" @click="handleBrowserScan">
                        <Search v-if="!isLoading" class="mr-2 h-4 w-4" />
                        <Loader2 v-else class="mr-2 h-4 w-4 animate-spin" />
                        {{ isLoading ? 'Menghubungkan...' : 'Cari & Hubungkan Printer' }}
                    </Button>
                    <p class="text-xs text-slate-400 text-center">Dialog pemilihan printer akan muncul dari browser/WebView.</p>
                </template>

                <div v-if="isConnected" class="grid grid-cols-2 gap-2">
                    <Button variant="secondary" :disabled="isPrinting" @click="handleTestPrint">
                        <TestTube v-if="!isPrinting" class="mr-2 h-4 w-4" />
                        <Loader2 v-else class="mr-2 h-4 w-4 animate-spin" />
                        Test Cetak
                    </Button>
                    <Button variant="destructive" @click="handleDisconnect">
                        <Trash2 class="mr-2 h-4 w-4" />
                        Putuskan
                    </Button>
                </div>

                <Button v-if="selectedPrinter" variant="ghost" size="sm" class="w-full text-slate-400 hover:text-red-500" @click="handleForget">
                    Lupakan Printer {{ activeTab === 'cashier' ? 'Kasir' : 'Dapur' }}
                </Button>
            </template>

            <div v-else class="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                <AlertCircle class="mt-0.5 h-5 w-5 shrink-0" />
                <div>
                    <p>Gunakan aplikasi Android untuk menghubungkan dua printer thermal.</p>
                    <a
                        href="https://play.google.com/store/apps/details?id=com.kasirumkm.idea&hl=id"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="mt-2 inline-flex font-bold text-amber-900 underline hover:text-amber-950"
                    >
                        Download versi Android
                    </a>
                </div>
            </div>
        </CardContent>
    </Card>
</template>
