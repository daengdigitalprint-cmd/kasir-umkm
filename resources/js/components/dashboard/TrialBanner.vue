<script setup lang="ts">
/**
 * Kartu trial Pro di dashboard (pola reverse trial).
 * - Selama trial: "Pro aktif · sisa N hari" + progres + pesan pengingat terbaru.
 * - Setelah trial: jaminan data aman + ajakan lanjut Pro (dan promo tahunan bila masih berlaku).
 * Data dari props bersama `trial` (lihat App\Services\TrialService::payload).
 */
import { Link, router, usePage } from '@inertiajs/vue3';
import { Crown, ShieldCheck, Sparkles, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

type TrialPayload = {
    active: boolean;
    days_total: number;
    days_left: number;
    ends_at: string;
    ended: boolean;
    on_free_plan: boolean;
    promo_eligible: boolean;
    promo_deadline: string | null;
    notice: { id: number; type: string; title: string; body: string } | null;
};

const page = usePage();
const trial = computed(() => (page.props as { trial?: TrialPayload | null }).trial ?? null);
const hidden = ref(false);

const formatDate = (iso: string | null) =>
    iso ? new Date(iso).toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric' }) : '';

const progress = computed(() => {
    const t = trial.value;
    if (!t || !t.active) return 0;
    return Math.min(100, Math.max(4, Math.round(((t.days_total - t.days_left) / t.days_total) * 100)));
});

/** Setelah trial: tampil hanya bila ada pesan baru atau promo masih berlaku. */
const show = computed(() => {
    const t = trial.value;
    if (!t || hidden.value) return false;
    if (t.active) return true;
    return t.on_free_plan && (t.notice !== null || t.promo_eligible);
});

const urgent = computed(() => Boolean(trial.value?.active && trial.value.days_left <= 3));

function dismiss() {
    const id = trial.value?.notice?.id;
    hidden.value = !trial.value?.active;
    if (id) {
        router.post(`/trial-notifications/${id}/read`, {}, { preserveScroll: true, preserveState: true });
    }
}
</script>

<template>
    <div
        v-if="show && trial"
        :class="[
            'relative mt-0 mb-6 overflow-hidden rounded-2xl border p-4 md:p-5',
            trial.active
                ? urgent
                    ? 'border-amber-200 bg-gradient-to-r from-amber-50 to-violet-50'
                    : 'border-violet-200 bg-gradient-to-r from-violet-50 to-fuchsia-50'
                : 'border-emerald-200 bg-gradient-to-r from-emerald-50 to-violet-50',
        ]"
    >
        <button
            v-if="trial.notice || !trial.active"
            type="button"
            class="absolute top-3 right-3 rounded-lg p-1 text-slate-400 transition hover:bg-white/70 hover:text-slate-600"
            aria-label="Tutup"
            @click="dismiss"
        >
            <X class="h-4 w-4" />
        </button>

        <div class="flex flex-col gap-4 pr-6 sm:flex-row sm:items-center">
            <div
                :class="[
                    'flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-xl',
                    trial.active ? (urgent ? 'bg-amber-100' : 'bg-violet-100') : 'bg-emerald-100',
                ]"
            >
                <Crown v-if="trial.active" :class="['h-5 w-5', urgent ? 'text-amber-600' : 'text-violet-600']" />
                <ShieldCheck v-else class="h-5 w-5 text-emerald-600" />
            </div>

            <div class="min-w-0 flex-1">
                <!-- Judul -->
                <h3 v-if="trial.active" class="text-sm font-bold text-violet-950">
                    Pro aktif · sisa {{ trial.days_left }} hari
                    <span class="ml-1 font-medium text-violet-600/80">(sampai {{ formatDate(trial.ends_at) }})</span>
                </h3>
                <h3 v-else class="text-sm font-bold text-emerald-900">
                    {{ trial.notice?.title ?? 'Paket Gratis aktif — data Anda aman' }}
                </h3>

                <!-- Progres trial -->
                <div v-if="trial.active" class="mt-2 h-1.5 w-full max-w-md overflow-hidden rounded-full bg-violet-100">
                    <div
                        :class="['h-full rounded-full transition-all', urgent ? 'bg-amber-500' : 'bg-violet-500']"
                        :style="{ width: `${progress}%` }"
                    ></div>
                </div>

                <!-- Isi pesan -->
                <p v-if="trial.active && trial.notice" class="mt-2 text-xs leading-relaxed text-slate-700">
                    <span class="font-semibold text-slate-900">{{ trial.notice.title }}.</span>
                    {{ trial.notice.body }}
                </p>
                <p v-else-if="trial.active" class="mt-2 text-xs leading-relaxed text-slate-600">
                    Semua fitur GrowUp terbuka selama trial. Menu bertanda <span class="font-semibold text-violet-700">Pro</span> akan
                    dikunci setelah trial; data Anda tetap aman.
                </p>
                <p v-else class="mt-1 text-xs leading-relaxed text-slate-700">
                    {{
                        trial.notice?.body ??
                        'Semua produk, pelanggan, dan riwayat transaksi tetap ada dan tetap bisa dipakai berjualan.'
                    }}
                </p>

                <p v-if="trial.promo_eligible && trial.promo_deadline" class="mt-2 inline-flex items-center gap-1.5 text-xs font-semibold text-violet-700">
                    <Sparkles class="h-3.5 w-3.5" />
                    Promo pengguna baru: GrowUp tahunan bayar 8 bulan untuk 12 bulan, sampai {{ formatDate(trial.promo_deadline) }}.
                </p>
            </div>

            <div class="flex flex-shrink-0 items-center gap-2">
                <button
                    v-if="trial.active && trial.notice"
                    type="button"
                    class="rounded-xl px-3 py-2 text-xs font-semibold text-slate-500 transition hover:bg-white/70 hover:text-slate-700"
                    @click="dismiss"
                >
                    Mengerti
                </button>
                <Link
                    href="/subscription"
                    :class="[
                        'rounded-xl px-4 py-2 text-sm font-bold text-white shadow-md transition',
                        urgent || !trial.active ? 'bg-violet-600 hover:bg-violet-700' : 'bg-violet-500 hover:bg-violet-600',
                    ]"
                >
                    {{ trial.active ? 'Lihat paket' : 'Lanjutkan Pro' }}
                </Link>
            </div>
        </div>
    </div>
</template>
