<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Gift, History, Info, PartyPopper, QrCode, Stamp, Store } from 'lucide-vue-next';
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
        qr.value = await QRCode.toDataURL(props.card.url, {
            margin: 1,
            width: 420,
            errorCorrectionLevel: 'M',
            color: { dark: '#1e1b4b', light: '#ffffff' },
        });
    } catch {
        qr.value = '';
    }
});

const target = computed(() => props.card?.target ?? 0);
const count = computed(() => Math.min(props.card?.count ?? 0, target.value));
const slots = computed(() => Array.from({ length: target.value }, (_, i) => ({ filled: i < count.value, last: i === target.value - 1 })));
const remaining = computed(() => Math.max(0, target.value - count.value));
const percent = computed(() => (target.value ? Math.round((count.value / target.value) * 100) : 0));
// Kolom grid menyesuaikan jumlah stempel agar baris tetap rapi (maks. 5 per baris).
const cols = computed(() => (target.value <= 4 ? target.value : target.value % 4 === 0 && target.value <= 8 ? 4 : 5));

const initials = computed(() => {
    const words = props.store.name.split(/\s+/).filter(Boolean);
    if (words.length === 1) return words[0].slice(0, 2).toUpperCase();
    return words
        .slice(0, 2)
        .map((w) => w[0]?.toUpperCase())
        .join('');
});

// Cap tinta: tiap stempel miring sedikit berbeda (tetap, tidak acak per muat) supaya terasa dicap tangan.
const stampTilt = (i: number) => [-14, 9, -6, 12, -10, 5, -16, 8, -4, 11][i % 10];

const progressText = computed(() => {
    if (!props.card) return '';
    if (props.card.rewards_ready > 0) return 'Hadiah kamu sudah siap!';
    if (remaining.value === 1) return 'Tinggal 1 stempel lagi!';
    if (count.value === 0) return 'Ayo kumpulkan stempel pertamamu';
    return `${remaining.value} stempel lagi menuju hadiah`;
});

function historyLabel(h: HistoryRow): string {
    switch (h.type) {
        case 'earn':
            return h.rewards_delta > 0 ? 'Kartu penuh, dapat hadiah' : `Dapat ${h.stamps} stempel`;
        case 'redeem':
            return 'Hadiah ditukar';
        case 'reverse':
            return 'Stempel dibatalkan';
        case 'expire':
            return 'Stempel hangus';
        default:
            if (h.stamps < 0) return `Koreksi ${h.stamps} stempel`;
            return h.rewards_delta > 0 ? `Tambahan ${h.stamps} stempel, kartu penuh` : `Tambahan ${h.stamps} stempel`;
    }
}
const historyTone = (h: HistoryRow) =>
    h.type === 'redeem'
        ? 'bg-amber-100 text-amber-700'
        : h.stamps < 0 || h.type === 'expire'
          ? 'bg-slate-100 text-slate-500'
          : 'bg-primary/10 text-primary';

const fmtDate = (iso: string | null) => (iso ? new Date(iso).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' }) : '');
</script>

<template>
    <Head :title="card ? `${card.title} · ${store.name}` : 'Kartu stempel'" />

    <div class="min-h-screen bg-stone-100">
        <!-- Tekstur tinta untuk cap stempel: tepi sedikit kasar dan bintik kosong seperti cap karet -->
        <svg width="0" height="0" class="absolute" aria-hidden="true">
            <filter id="stamp-ink" x="-10%" y="-10%" width="120%" height="120%">
                <feTurbulence type="fractalNoise" baseFrequency="0.9" numOctaves="2" seed="7" result="noise" />
                <feDisplacementMap in="SourceGraphic" in2="noise" scale="1.1" result="rough" />
                <feTurbulence type="fractalNoise" baseFrequency="0.35" numOctaves="1" seed="3" result="speck" />
                <feColorMatrix in="speck" type="matrix" values="0 0 0 0 0  0 0 0 0 0  0 0 0 0 0  0 0 0 -1.5 1.6" result="speckMask" />
                <feComposite in="rough" in2="speckMask" operator="in" />
            </filter>
        </svg>
        <div class="mx-auto max-w-sm px-4 pt-6 pb-10">
            <!-- Kartu tidak aktif -->
            <div v-if="!enabled || !card" class="mt-24 rounded-3xl bg-white p-8 text-center ring-1 ring-stone-200">
                <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-slate-100">
                    <Stamp class="h-8 w-8 text-slate-400" />
                </div>
                <h1 class="text-lg font-black text-slate-900">Kartu stempel tidak aktif</h1>
                <p class="mt-2 text-sm text-slate-500">Program kartu stempel di {{ store.name }} sedang tidak berjalan. Tanyakan ke kasir.</p>
            </div>

            <template v-else>
                <!-- Toko -->
                <div class="mb-4 flex items-center gap-3">
                    <img
                        v-if="store.logo_url"
                        :src="store.logo_url"
                        alt=""
                        class="h-11 w-11 rounded-xl bg-white object-contain p-1 ring-1 ring-stone-200"
                    />
                    <div v-else class="flex h-11 w-11 items-center justify-center rounded-xl bg-primary text-sm font-black text-white">
                        {{ initials || '?' }}
                    </div>
                    <div class="min-w-0">
                        <div class="truncate text-base leading-tight font-bold text-slate-900">{{ store.name }}</div>
                        <div class="text-xs text-slate-500">{{ card.title }}</div>
                    </div>
                </div>

                <!-- Kartu utama -->
                <div class="overflow-hidden rounded-3xl bg-white ring-1 ring-stone-200">
                    <div class="px-5 pt-5">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-sm text-slate-500">Halo{{ card.first_name ? `, ${card.first_name}` : '' }}</p>
                                <p class="mt-0.5 text-[28px] leading-none font-black text-slate-900">
                                    {{ count }}<span class="text-lg font-bold text-slate-400"> / {{ target }}</span>
                                </p>
                                <p class="mt-1 text-sm font-semibold" :class="card.rewards_ready > 0 ? 'text-amber-600' : 'text-primary'">
                                    {{ progressText }}
                                </p>
                            </div>
                            <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-primary/10 text-primary">
                                <Stamp class="h-6 w-6" />
                            </div>
                        </div>

                        <!-- Progres -->
                        <div class="mt-4 h-2 overflow-hidden rounded-full bg-slate-100">
                            <div class="h-full rounded-full bg-primary" :style="{ width: `${percent}%` }"></div>
                        </div>

                        <!-- Stempel -->
                        <div class="mt-5 grid gap-3" :style="{ gridTemplateColumns: `repeat(${cols}, minmax(0, 1fr))` }">
                            <div v-for="(s, i) in slots" :key="i" class="relative aspect-square">
                                <!-- Cap tinta: cincin ganda + inisial toko, sedikit miring dan bertekstur seperti dicap -->
                                <svg
                                    v-if="s.filled"
                                    viewBox="0 0 64 64"
                                    class="h-full w-full text-primary"
                                    :style="{ transform: `rotate(${stampTilt(i)}deg)` }"
                                    role="img"
                                    :aria-label="`Stempel ke-${i + 1}`"
                                >
                                    <g filter="url(#stamp-ink)" fill="none" stroke="currentColor" opacity="0.92">
                                        <circle cx="32" cy="32" r="29" stroke-width="3.6" />
                                        <circle cx="32" cy="32" r="23.5" stroke-width="1.6" stroke-dasharray="2.4 2.2" />
                                        <text x="32" y="16.5" text-anchor="middle" font-size="7" fill="currentColor" stroke="none">★</text>
                                        <text
                                            x="32"
                                            y="38"
                                            text-anchor="middle"
                                            :font-size="initials.length > 1 ? 17 : 21"
                                            font-weight="900"
                                            letter-spacing="0.5"
                                            fill="currentColor"
                                            stroke="currentColor"
                                            stroke-width="0.9"
                                            font-family="ui-sans-serif, system-ui, sans-serif"
                                        >
                                            {{ initials || '★' }}
                                        </text>
                                        <text
                                            x="32"
                                            y="51.5"
                                            text-anchor="middle"
                                            font-size="6.5"
                                            font-weight="700"
                                            fill="currentColor"
                                            stroke="none"
                                        >
                                            {{ s.last ? 'GRATIS' : `NO ${i + 1}` }}
                                        </text>
                                    </g>
                                </svg>
                                <div
                                    v-else
                                    class="flex h-full w-full items-center justify-center rounded-full border-2 border-dashed"
                                    :class="s.last ? 'border-amber-300 bg-amber-50 text-amber-500' : 'border-slate-200 text-slate-300'"
                                >
                                    <Gift v-if="s.last" class="h-1/2 w-1/2" />
                                    <span v-else class="text-sm font-bold">{{ i + 1 }}</span>
                                </div>
                            </div>
                        </div>

                        <!-- Hadiah -->
                        <div v-if="card.rewards_ready > 0" class="mt-5 flex items-center gap-3 rounded-2xl bg-amber-500 px-4 py-3 text-white">
                            <PartyPopper class="h-7 w-7 shrink-0" />
                            <div class="min-w-0">
                                <div class="text-sm font-black">{{ card.rewards_ready }} hadiah siap ditukar</div>
                                <div class="text-xs leading-snug text-white/90">{{ card.reward }} · tunjukkan kartu ini ke kasir</div>
                            </div>
                        </div>
                        <div v-else class="mt-5 flex items-center gap-3 rounded-2xl bg-amber-50 px-4 py-3 ring-1 ring-amber-100">
                            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-amber-100 text-amber-600">
                                <Gift class="h-5 w-5" />
                            </div>
                            <div class="min-w-0">
                                <div class="text-xs text-amber-700/80">Hadiah kartu penuh</div>
                                <div class="text-sm font-bold text-amber-900">{{ card.reward }}</div>
                            </div>
                        </div>
                    </div>

                    <!-- Sobekan tiket -->
                    <div class="relative my-5 flex items-center">
                        <div class="absolute -left-3 h-6 w-6 rounded-full bg-stone-100"></div>
                        <div class="mx-5 w-full border-t-2 border-dashed border-slate-200"></div>
                        <div class="absolute -right-3 h-6 w-6 rounded-full bg-stone-100"></div>
                    </div>

                    <!-- QR -->
                    <div class="px-5 pb-6 text-center">
                        <div class="mx-auto w-fit rounded-3xl bg-white p-3 ring-1 ring-slate-200">
                            <img v-if="qr" :src="qr" alt="QR kartu stempel" class="h-44 w-44" />
                            <div v-else class="flex h-44 w-44 items-center justify-center text-slate-300"><QrCode class="h-10 w-10" /></div>
                        </div>
                        <p class="mt-3 text-sm font-semibold text-slate-800">Tunjukkan QR ini ke kasir</p>
                        <p class="text-xs text-slate-500">setiap kali belanja, atau sebutkan nomor WA kamu</p>
                    </div>
                </div>

                <!-- Cara kerja -->
                <div class="mt-4 flex items-start gap-3 rounded-2xl bg-white px-4 py-3 text-sm text-slate-600 ring-1 ring-stone-200">
                    <Info class="mt-0.5 h-4 w-4 shrink-0 text-primary" />
                    <div>
                        {{ card.rule_text }} Kumpulkan {{ target }} stempel untuk dapat <b class="font-semibold text-slate-800">{{ card.reward }}</b
                        >.
                        <span v-if="card.expiry_months > 0"> Stempel hangus bila tidak belanja selama {{ card.expiry_months }} bulan.</span>
                    </div>
                </div>

                <!-- Riwayat -->
                <div v-if="card.history.length" class="mt-4 rounded-2xl bg-white p-4 ring-1 ring-stone-200">
                    <p class="mb-3 flex items-center gap-1.5 text-xs font-bold tracking-wider text-slate-400 uppercase">
                        <History class="h-3.5 w-3.5" /> Riwayat terakhir
                    </p>
                    <div v-for="(h, i) in card.history" :key="i" class="flex items-center gap-3 py-2">
                        <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full" :class="historyTone(h)">
                            <Gift v-if="h.type === 'redeem' || h.rewards_delta > 0" class="h-4 w-4" />
                            <Stamp v-else class="h-4 w-4" />
                        </div>
                        <span class="flex-1 text-sm text-slate-700">{{ historyLabel(h) }}</span>
                        <span class="text-xs text-slate-400">{{ fmtDate(h.at) }}</span>
                    </div>
                </div>

                <p class="mt-6 text-center text-xs text-slate-400">Simpan halaman ini (Tambahkan ke layar utama) agar mudah dibuka.</p>
                <p class="mt-2 flex items-center justify-center gap-1 text-[11px] text-slate-400"><Store class="h-3 w-3" /> Kasir UMKM by MaccaPOS</p>
            </template>
        </div>
    </div>
</template>
