<script setup lang="ts">
/**
 * ConnectionStatus.vue
 *
 * Indikator status koneksi di header/navbar:
 * - Hijau: Online
 * - Merah: Offline
 * - Kuning: Sedang sinkronisasi
 * - Badge angka: jumlah transaksi pending
 */
import { useOfflineSync } from '@/composables/useOfflineSync';
import { CloudOff, RefreshCw, Wifi, WifiOff } from 'lucide-vue-next';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        storeId?: number;
        cashierId?: number;
    }>(),
    {
        storeId: 0,
        cashierId: 0,
    },
);

const { isOnline, pendingCount, isSyncing, lastSyncAt, syncErrors, syncNow } = useOfflineSync({
    storeId: props.storeId,
    cashierId: props.cashierId,
    csrfToken: document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
});

const statusLabel = computed(() => {
    if (isSyncing.value) return 'Sinkronisasi...';
    if (!isOnline.value) return 'Offline';
    if (pendingCount.value > 0) return `Online - ${pendingCount.value} pending`;
    return 'Online';
});

const statusClass = computed(() => {
    if (isSyncing.value) return 'bg-amber-50 border-amber-200 text-amber-800';
    if (!isOnline.value) return 'bg-red-50 border-red-200 text-red-700';
    if (pendingCount.value > 0) return 'bg-amber-50 border-amber-200 text-amber-800';
    return 'bg-emerald-50 border-emerald-200 text-emerald-700';
});

const dotClass = computed(() => {
    if (isSyncing.value) return 'bg-amber-500 animate-pulse';
    if (!isOnline.value) return 'bg-red-500';
    if (pendingCount.value > 0) return 'bg-amber-500 animate-pulse';
    return 'bg-emerald-500';
});

function formatTime(iso: string | null): string {
    if (!iso) return '-';
    return new Date(iso).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
}
</script>

<template>
    <div class="relative inline-flex items-center">
        <!-- Chip utama -->
        <button
            type="button"
            :class="[
                'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-medium transition-colors',
                statusClass,
            ]"
            :aria-label="statusLabel"
            :title="statusLabel"
            @click="isOnline && !isSyncing ? syncNow() : undefined"
        >
            <!-- Ikon -->
            <RefreshCw v-if="isSyncing" class="h-3.5 w-3.5 animate-spin" aria-hidden="true" />
            <WifiOff v-else-if="!isOnline" class="h-3.5 w-3.5" aria-hidden="true" />
            <Wifi v-else class="h-3.5 w-3.5" aria-hidden="true" />

            <!-- Label teks -->
            <span>{{ statusLabel }}</span>

            <!-- Badge pending -->
            <span
                v-if="pendingCount > 0"
                class="ml-0.5 flex h-4 w-4 items-center justify-center rounded-full bg-amber-500 text-[10px] font-bold text-white"
                :aria-label="`${pendingCount} transaksi belum tersinkronisasi`"
            >
                {{ pendingCount > 99 ? '99+' : pendingCount }}
            </span>
        </button>

        <!-- Tooltip detail saat offline atau ada pending -->
        <div
            v-if="!isOnline || pendingCount > 0 || syncErrors.length > 0"
            class="absolute left-0 top-full z-50 mt-1.5 hidden w-64 rounded-xl border border-slate-200 bg-white p-3 shadow-lg group-hover:block"
            role="status"
            aria-live="polite"
        >
            <div class="space-y-1.5 text-xs text-slate-700">
                <div class="flex items-center gap-1.5">
                    <span :class="['h-2 w-2 rounded-full shrink-0', dotClass]" />
                    <span class="font-semibold">
                        {{ isOnline ? 'Terhubung ke internet' : 'Tidak ada koneksi internet' }}
                    </span>
                </div>

                <p v-if="pendingCount > 0" class="flex items-center gap-1 text-amber-700">
                    <CloudOff class="h-3 w-3 shrink-0" aria-hidden="true" />
                    {{ pendingCount }} transaksi tersimpan lokal, menunggu sinkronisasi.
                </p>

                <p v-if="lastSyncAt" class="text-slate-400">
                    Sync terakhir: {{ formatTime(lastSyncAt) }}
                </p>

                <div v-if="syncErrors.length > 0" class="rounded-lg bg-red-50 p-2">
                    <p class="mb-1 font-semibold text-red-700">Gagal sync:</p>
                    <ul class="space-y-0.5 text-red-600">
                        <li v-for="err in syncErrors.slice(0, 3)" :key="err">{{ err }}</li>
                    </ul>
                </div>

                <button
                    v-if="isOnline && pendingCount > 0 && !isSyncing"
                    type="button"
                    class="mt-1 w-full rounded-lg bg-teal-600 px-2 py-1 text-center text-xs font-medium text-white hover:bg-teal-700"
                    @click="syncNow()"
                >
                    Sinkronkan sekarang
                </button>
            </div>
        </div>
    </div>
</template>
