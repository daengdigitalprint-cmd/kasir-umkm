<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Check, Gift, Stamp } from 'lucide-vue-next';
import QRCode from 'qrcode';
import { computed, onMounted, ref } from 'vue';

interface HistoryRow {
    type: 'earn' | 'redeem' | 'adjust' | 'reverse' | 'expire';
    stamps: number;
    rewards_delta: number;
    at: string | null;
}

const props = defineProps<{
    enabled: boolean;
    store: { name: string; logo_url: string | null };
    card: {
        title: string;
        first_name: string;
        count: number;
        target: number;
        rewards_ready: number;
        reward: string;
        rule_text: string;
        expiry_months: number;
        url: string;
        history: HistoryRow[];
    } | null;
}>();

const qr = ref('');
onMounted(async () => {
    if (!props.card) return;
    try {
        qr.value = await QRCode.toDataURL(props.card.url, { margin: 1, width: 360, errorCorrectionLevel: 'M' });
    } catch {
        qr.value = '';
    }
});

const slots = computed(() => Array.from({ length: props.card?.target ?? 0 }, (_, i) => i < (props.card?.count ?? 0)));
const remaining = computed(() => Math.max(0, (props.card?.target ?? 0) - (props.card?.count ?? 0)));

function historyLabel(h: HistoryRow): string {
    switch (h.type) {
        case 'earn':
            return h.rewards_delta > 0 ? `+${h.stamps} stempel, kartu penuh` : `+${h.stamps} stempel`;
        case 'redeem':
            return 'Hadiah ditukar';
        case 'reverse':
            return `${h.stamps} stempel (transaksi dibatalkan)`;
        case 'expire':
            return 'Stempel hangus';
        default:
            return `${h.stamps > 0 ? '+' : ''}${h.stamps} stempel (koreksi)`;
    }
}

const fmtDate = (iso: string | null) =>
    iso ? new Date(iso).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' }) : '';
</script>

<template>
    <Head :title="card ? `${card.title} · ${store.name}` : 'Kartu stempel'" />

    <div class="min-h-screen bg-slate-50 px-4 py-6">
        <div class="mx-auto max-w-sm">
            <div v-if="!enabled || !card" class="mt-24 text-center">
                <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-slate-100">
                    <Stamp class="h-8 w-8 text-slate-400" />
                </div>
                <h1 class="text-lg font-black text-slate-900">Kartu stempel tidak aktif</h1>
                <p class="mt-2 text-sm text-slate-500">Program kartu stempel di {{ store.name }} sedang tidak berjalan. Tanyakan ke kasir.</p>
            </div>

            <template v-else>
                <div class="overflow-hidden rounded-3xl bg-white shadow-sm ring-1 ring-slate-200">
                    <div class="flex items-center gap-3 bg-primary px-5 py-4 text-white">
                        <img v-if="store.logo_url" :src="store.logo_url" alt="" class="h-10 w-10 rounded-xl bg-white object-contain p-1" />
                        <div v-else class="flex h-10 w-10 items-center justify-center rounded-xl bg-white/20"><Stamp class="h-5 w-5" /></div>
                        <div class="min-w-0">
                            <div class="truncate font-bold">{{ store.name }}</div>
                            <div class="text-xs text-white/80">{{ card.title }}</div>
                        </div>
                    </div>

                    <div class="px-5 pt-5 pb-6">
                        <p class="text-sm text-slate-500">Halo{{ card.first_name ? `, ${card.first_name}` : '' }}</p>
                        <p class="text-2xl font-black text-slate-900">{{ card.count }} dari {{ card.target }} stempel</p>

                        <div class="mt-4 grid grid-cols-5 gap-3">
                            <div
                                v-for="(filled, i) in slots"
                                :key="i"
                                class="flex aspect-square items-center justify-center rounded-full text-sm font-semibold"
                                :class="filled ? 'bg-primary text-white' : 'border-2 border-dashed border-slate-200 text-slate-300'"
                            >
                                <Check v-if="filled" class="h-5 w-5" stroke-width="3" />
                                <Gift v-else-if="i === slots.length - 1" class="h-5 w-5" />
                                <span v-else>{{ i + 1 }}</span>
                            </div>
                        </div>

                        <div class="mt-4 flex items-start gap-2 rounded-2xl bg-primary/5 px-3 py-2.5 text-sm text-slate-700">
                            <Gift class="mt-0.5 h-4 w-4 shrink-0 text-primary" />
                            <span>Hadiah: <b class="font-semibold">{{ card.reward }}</b>. {{ card.rule_text }}</span>
                        </div>

                        <div v-if="card.rewards_ready > 0" class="mt-3 rounded-2xl bg-emerald-50 px-3 py-2.5 text-sm font-semibold text-emerald-700">
                            {{ card.rewards_ready }} hadiah siap ditukar. Tunjukkan kartu ini ke kasir.
                        </div>
                        <p v-else class="mt-3 text-sm text-slate-500">{{ remaining }} stempel lagi untuk dapat hadiah.</p>
                    </div>

                    <div class="border-t border-dashed border-slate-200 px-5 py-5 text-center">
                        <img v-if="qr" :src="qr" alt="QR kartu stempel" class="mx-auto h-44 w-44" />
                        <p class="mt-2 text-xs text-slate-500">Tunjukkan QR ini ke kasir setiap belanja, atau sebutkan nomor WA kamu.</p>
                    </div>
                </div>

                <div v-if="card.history.length" class="mt-5 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                    <p class="mb-2 text-xs font-semibold tracking-wide text-slate-400 uppercase">Riwayat terakhir</p>
                    <div v-for="(h, i) in card.history" :key="i" class="flex justify-between border-b border-slate-100 py-2 text-sm last:border-0">
                        <span class="text-slate-700">{{ historyLabel(h) }}</span>
                        <span class="text-slate-400">{{ fmtDate(h.at) }}</span>
                    </div>
                </div>

                <p v-if="card.expiry_months > 0" class="mt-4 text-center text-xs text-slate-400">
                    Stempel hangus bila tidak berbelanja selama {{ card.expiry_months }} bulan.
                </p>
                <p class="mt-2 text-center text-xs text-slate-400">Simpan halaman ini (tambahkan ke layar utama) agar mudah dibuka.</p>
            </template>
        </div>
    </div>
</template>
