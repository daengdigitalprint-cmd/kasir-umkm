<script setup lang="ts">
/**
 * Kartu Stempel di layar bayar kasir: cari pelanggan lewat no. WA atau pindai QR kartu,
 * daftar cepat bila belum ada, tukar hadiah, dan kirim tautan kartu lewat WhatsApp.
 */
import BarcodeScanner from '@/components/BarcodeScanner.vue';
import { stampCardShareLink, type StampCardCustomer } from '@/composables/useStampCard';
import { confirmDialog, toastError, toastSuccess } from '@/lib/swal';
import { Gift, Loader2, MessageCircle, QrCode, Search, Stamp, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{ modelValue: StampCardCustomer | null; storeName: string }>();
const emit = defineEmits<{ 'update:modelValue': [value: StampCardCustomer | null] }>();

const query = ref('');
const loading = ref(false);
const notFound = ref(false);
const newName = ref('');
const showScanner = ref(false);
const redeeming = ref(false);

function xsrf(): string {
    const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

async function getJson(url: string) {
    const res = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    return res.json();
}

async function lookup(input?: string) {
    const q = (input ?? query.value).trim();
    if (q.length < 4) {
        toastError('Masukkan no. WA pelanggan atau pindai QR kartunya.');
        return;
    }
    loading.value = true;
    notFound.value = false;
    try {
        const data = await getJson(`/pos/stamp-card/lookup?q=${encodeURIComponent(q)}`);
        if (data.customer) {
            emit('update:modelValue', data.customer);
            query.value = '';
        } else if (/\/k\//.test(q)) {
            toastError('Kartu tidak dikenali di toko ini.');
        } else {
            notFound.value = true;
        }
    } catch {
        toastError('Gagal mencari kartu. Periksa koneksi.');
    } finally {
        loading.value = false;
    }
}

function onScanned(code: string) {
    showScanner.value = false;
    query.value = code;
    void lookup(code);
}

async function register() {
    const name = newName.value.trim();
    if (!name) {
        toastError('Isi nama pelanggan dulu.');
        return;
    }
    loading.value = true;
    try {
        const res = await fetch('/customers/quick-store', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
            body: JSON.stringify({ name, phone: query.value.trim() }),
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(data.message ?? 'Gagal mendaftarkan pelanggan.');
        const card = await getJson(`/pos/stamp-card/customers/${data.customer.id}`);
        emit('update:modelValue', card.customer);
        notFound.value = false;
        newName.value = '';
        query.value = '';
    } catch (e) {
        toastError(e instanceof Error ? e.message : 'Gagal mendaftarkan pelanggan.');
    } finally {
        loading.value = false;
    }
}

async function redeem() {
    const c = props.modelValue;
    if (!c) return;
    const ok = await confirmDialog('Tukar hadiah?', `Berikan "${c.reward}" ke ${c.name}. 1 hadiah akan dipakai.`, { confirmText: 'Tukar', cancelText: 'Batal' });
    if (!ok) return;
    redeeming.value = true;
    try {
        const res = await fetch(`/pos/stamp-card/customers/${c.id}/redeem`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(data.message ?? 'Gagal menukar hadiah.');
        emit('update:modelValue', data.customer);
        void toastSuccess(data.message ?? 'Hadiah ditukar.');
    } catch (e) {
        toastError(e instanceof Error ? e.message : 'Gagal menukar hadiah.');
    } finally {
        redeeming.value = false;
    }
}

const shareLink = computed(() => (props.modelValue ? stampCardShareLink(props.modelValue, props.storeName) : null));
</script>

<template>
    <div class="rounded-xl border border-primary/20 bg-primary/5 p-3">
        <div class="mb-2 flex items-center gap-1.5 text-sm font-medium text-gray-700"><Stamp class="h-4 w-4 text-primary" /> Kartu stempel</div>

        <!-- Sudah dipilih -->
        <div v-if="modelValue" class="space-y-2">
            <div class="flex items-center gap-2 rounded-lg bg-white px-3 py-2">
                <div class="min-w-0 flex-1">
                    <div class="truncate text-sm font-semibold text-gray-900">{{ modelValue.name }}</div>
                    <div class="text-xs text-primary">{{ modelValue.count }}/{{ modelValue.target }} stempel</div>
                </div>
                <a
                    v-if="shareLink"
                    :href="shareLink"
                    target="_blank"
                    rel="noopener"
                    class="rounded-lg p-1.5 text-emerald-600 hover:bg-emerald-50"
                    title="Kirim kartu lewat WhatsApp"
                    aria-label="Kirim kartu lewat WhatsApp"
                >
                    <MessageCircle class="h-4 w-4" />
                </a>
                <button type="button" class="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100" aria-label="Ganti pelanggan" @click="emit('update:modelValue', null)">
                    <X class="h-4 w-4" />
                </button>
            </div>
            <button
                v-if="modelValue.rewards_ready > 0"
                type="button"
                class="flex w-full items-center justify-center gap-1.5 rounded-lg bg-emerald-600 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-60"
                :disabled="redeeming"
                @click="redeem"
            >
                <Gift class="h-4 w-4" /> Tukar hadiah ({{ modelValue.rewards_ready }} siap): {{ modelValue.reward }}
            </button>
        </div>

        <!-- Belum dipilih -->
        <div v-else class="space-y-2">
            <div class="flex gap-2">
                <input
                    v-model="query"
                    type="text"
                    inputmode="tel"
                    placeholder="No. WA pelanggan"
                    class="h-10 min-w-0 flex-1 rounded-lg border border-gray-300 bg-white px-3 text-sm focus:border-primary/70 focus:outline-none"
                    @keydown.enter.prevent="lookup()"
                    @input="notFound = false"
                />
                <button type="button" class="flex h-10 w-10 items-center justify-center rounded-lg border border-gray-300 bg-white text-gray-600" aria-label="Pindai QR kartu" title="Pindai QR kartu" @click="showScanner = !showScanner">
                    <QrCode class="h-5 w-5" />
                </button>
                <button type="button" class="flex h-10 items-center gap-1 rounded-lg bg-primary px-3 text-sm font-semibold text-white disabled:opacity-60" :disabled="loading" @click="lookup()">
                    <Loader2 v-if="loading" class="h-4 w-4 animate-spin" /><Search v-else class="h-4 w-4" /> Cari
                </button>
            </div>
            <BarcodeScanner v-if="showScanner" mode="camera" @scanned="onScanned" @error="(m) => toastError(m)" />
            <div v-if="notFound" class="space-y-2 rounded-lg bg-white p-2">
                <div class="text-xs text-gray-600">Nomor ini belum terdaftar. Daftarkan sekarang supaya dapat stempel:</div>
                <div class="flex gap-2">
                    <input v-model="newName" type="text" placeholder="Nama pelanggan" class="h-9 min-w-0 flex-1 rounded-lg border border-gray-300 px-3 text-sm" @keydown.enter.prevent="register" />
                    <button type="button" class="h-9 rounded-lg bg-primary px-3 text-sm font-semibold text-white disabled:opacity-60" :disabled="loading" @click="register">Daftarkan</button>
                </div>
            </div>
        </div>
    </div>
</template>
