<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { ArrowLeft, Heart, MessageCircle, Search, Settings2, Sparkles, UserX } from 'lucide-vue-next';
import { ref, watch } from 'vue';

type SegmentKey = 'setia' | 'baru' | 'lama';

interface CustomerRow {
    id: number;
    name: string;
    phone: string | null;
    whatsapp: string | null;
    visits: number;
    recent_visits: number;
    first_visit: string;
    last_visit: string;
    spent: number;
    points: number;
}

interface SegmentSettings {
    loyal_visits: number;
    loyal_days: number;
    new_days: number;
    lapsed_min_visits: number;
    lapsed_days: number;
    messages: Record<SegmentKey, string>;
}

const props = defineProps<{
    segment: SegmentKey;
    labels: Record<SegmentKey, string>;
    counts: Record<SegmentKey, number>;
    customers: { data: CustomerRow[]; links: { url: string | null; label: string; active: boolean }[]; total: number };
    settings: SegmentSettings;
    search: string;
    store: { id: number; name: string };
    canEditSettings: boolean;
}>();

const icons = { setia: Heart, baru: Sparkles, lama: UserX };
const tones: Record<SegmentKey, string> = {
    setia: 'border-rose-200 bg-rose-50 text-rose-800',
    baru: 'border-sky-200 bg-sky-50 text-sky-800',
    lama: 'border-amber-200 bg-amber-50 text-amber-800',
};

const explain = (key: SegmentKey) =>
    ({
        setia: `≥ ${props.settings.loyal_visits} kunjungan dalam ${props.settings.loyal_days} hari terakhir`,
        baru: `Kunjungan pertama dalam ${props.settings.new_days} hari terakhir`,
        lama: `Pernah ≥ ${props.settings.lapsed_min_visits} kunjungan, tidak datang ≥ ${props.settings.lapsed_days} hari`,
    })[key];

function go(params: Record<string, string>) {
    router.get('/customers/segments', params, { preserveState: true, preserveScroll: true, replace: true });
}

const search = ref(props.search);
let timer: ReturnType<typeof setTimeout> | null = null;
watch(search, () => {
    if (timer) clearTimeout(timer);
    timer = setTimeout(() => go({ segment: props.segment, ...(search.value ? { search: search.value } : {}) }), 350);
});

// Pesan WhatsApp: template segmen, bisa diubah sebelum dikirim.
const message = ref(props.settings.messages[props.segment]);
watch(
    () => props.segment,
    (s) => (message.value = props.settings.messages[s]),
);

function waLink(c: CustomerRow) {
    const text = message.value.replaceAll('{nama}', c.name).replaceAll('{toko}', props.store.name);
    return `https://wa.me/${c.whatsapp}?text=${encodeURIComponent(text)}`;
}

const daysAgo = (iso: string) => {
    const days = Math.floor((Date.now() - new Date(iso).getTime()) / 86400000);
    return days <= 0 ? 'hari ini' : `${days} hari lalu`;
};
const fmt = (n: number) => new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(n);

// ── Pengaturan ambang & pesan ──
const showSettings = ref(false);
const settingsForm = useForm({ ...props.settings, messages: { ...props.settings.messages } });
function saveSettings() {
    settingsForm.put('/customers/segments/settings', {
        preserveScroll: true,
        onSuccess: () => (showSettings.value = false),
    });
}
</script>

<template>
    <Head title="Segmen Pelanggan" />

    <AdminLayout title="Segmen Pelanggan">
        <div class="mx-auto max-w-6xl space-y-6 pb-8">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <Link href="/customers" class="mb-2 inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-800">
                        <ArrowLeft class="h-4 w-4" /> Pelanggan
                    </Link>
                    <h1 class="text-2xl font-black text-slate-900">Segmen Pelanggan</h1>
                    <p class="mt-1 text-sm text-slate-600">Dikelompokkan otomatis dari riwayat belanja. Sapa lewat WhatsApp dengan satu klik.</p>
                </div>
                <Button v-if="canEditSettings" variant="outline" class="rounded-xl" @click="showSettings = !showSettings">
                    <Settings2 class="mr-2 h-4 w-4" /> Atur segmen &amp; pesan
                </Button>
            </div>

            <!-- Pengaturan -->
            <Card v-if="showSettings" class="rounded-2xl border-slate-200 shadow-sm">
                <CardContent class="space-y-4 pt-6 text-sm">
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                        <label class="space-y-1"><span class="font-semibold text-slate-700">Setia: minimal kunjungan</span>
                            <input v-model.number="settingsForm.loyal_visits" type="number" min="2" class="h-9 w-full rounded-lg border border-slate-200 px-2" /></label>
                        <label class="space-y-1"><span class="font-semibold text-slate-700">Setia: dalam (hari)</span>
                            <input v-model.number="settingsForm.loyal_days" type="number" min="7" class="h-9 w-full rounded-lg border border-slate-200 px-2" /></label>
                        <label class="space-y-1"><span class="font-semibold text-slate-700">Baru: dalam (hari)</span>
                            <input v-model.number="settingsForm.new_days" type="number" min="7" class="h-9 w-full rounded-lg border border-slate-200 px-2" /></label>
                        <label class="space-y-1"><span class="font-semibold text-slate-700">Lama: pernah ≥ kunjungan</span>
                            <input v-model.number="settingsForm.lapsed_min_visits" type="number" min="1" class="h-9 w-full rounded-lg border border-slate-200 px-2" /></label>
                        <label class="space-y-1"><span class="font-semibold text-slate-700">Lama: tidak datang ≥ (hari)</span>
                            <input v-model.number="settingsForm.lapsed_days" type="number" min="7" class="h-9 w-full rounded-lg border border-slate-200 px-2" /></label>
                    </div>
                    <div v-for="(label, key) in labels" :key="key" class="space-y-1">
                        <span class="font-semibold text-slate-700">Pesan WhatsApp — {{ label }}</span>
                        <textarea v-model="settingsForm.messages[key]" rows="2" maxlength="500" class="w-full rounded-lg border border-slate-200 px-3 py-2" />
                    </div>
                    <p class="text-xs text-slate-500">Pakai {nama} untuk nama pelanggan dan {toko} untuk nama toko.</p>
                    <p v-for="(err, key) in settingsForm.errors" :key="key" class="text-xs text-red-600">{{ err }}</p>
                    <Button class="rounded-xl" :disabled="settingsForm.processing" @click="saveSettings">Simpan pengaturan</Button>
                </CardContent>
            </Card>

            <!-- Tab segmen -->
            <div class="grid gap-3 sm:grid-cols-3">
                <button
                    v-for="(label, key) in labels"
                    :key="key"
                    type="button"
                    class="rounded-2xl border p-4 text-left transition"
                    :class="segment === key ? tones[key] + ' ring-2 ring-offset-1 ring-current' : 'border-slate-200 bg-white text-slate-700 hover:border-slate-300'"
                    @click="go({ segment: key })"
                >
                    <div class="flex items-center gap-2">
                        <component :is="icons[key]" class="h-5 w-5" />
                        <span class="font-bold">{{ label }}</span>
                        <span class="ml-auto text-2xl font-black">{{ fmt(counts[key]) }}</span>
                    </div>
                    <p class="mt-1 text-xs opacity-80">{{ explain(key) }}</p>
                </button>
            </div>

            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardContent class="space-y-4 pt-6">
                    <div class="flex flex-wrap items-start gap-3">
                        <div class="relative min-w-60 flex-1">
                            <Search class="absolute left-3 top-3 h-4 w-4 text-slate-400" />
                            <input v-model="search" type="text" placeholder="Cari nama atau nomor…" class="h-10 w-full rounded-lg border border-slate-200 pl-9 pr-3 text-sm" aria-label="Cari pelanggan" />
                        </div>
                        <label class="min-w-72 flex-[2] space-y-1 text-xs text-slate-600">
                            Pesan WhatsApp untuk segmen ini (bisa diubah sebelum kirim)
                            <textarea v-model="message" rows="2" maxlength="500" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-800" />
                        </label>
                    </div>

                    <div v-if="customers.data.length === 0" class="py-12 text-center text-sm text-slate-500">
                        Belum ada pelanggan di segmen ini. Segmen terisi dari transaksi yang dicatat dengan nama pelanggan.
                    </div>
                    <div v-else class="overflow-x-auto rounded-xl border border-slate-100">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b bg-slate-50 text-left text-xs font-bold uppercase tracking-wide text-slate-600">
                                    <th class="p-3">Pelanggan</th>
                                    <th class="p-3 text-right">Kunjungan</th>
                                    <th class="p-3">Terakhir datang</th>
                                    <th class="p-3 text-right">Total belanja</th>
                                    <th class="p-3"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="c in customers.data" :key="c.id" class="border-b border-slate-100 last:border-0">
                                    <td class="p-3">
                                        <Link :href="`/customers/${c.id}`" class="font-semibold text-slate-900 hover:underline">{{ c.name }}</Link>
                                        <p class="text-xs text-slate-500">{{ c.phone || 'Tanpa nomor' }}</p>
                                    </td>
                                    <td class="p-3 text-right tabular-nums">
                                        {{ c.visits }}
                                        <span class="block text-xs text-slate-400">{{ c.recent_visits }} dalam {{ settings.loyal_days }} hari</span>
                                    </td>
                                    <td class="p-3 text-slate-600">{{ daysAgo(c.last_visit) }}</td>
                                    <td class="p-3 text-right tabular-nums">Rp{{ fmt(c.spent) }}</td>
                                    <td class="p-3 text-right">
                                        <a
                                            v-if="c.whatsapp"
                                            :href="waLink(c)"
                                            target="_blank"
                                            rel="noopener"
                                            class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700"
                                        >
                                            <MessageCircle class="h-3.5 w-3.5" /> WhatsApp
                                        </a>
                                        <span v-else class="text-xs text-slate-400">Nomor tidak valid</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-if="customers.links.length > 3" class="flex flex-wrap gap-1">
                        <Link
                            v-for="(link, i) in customers.links"
                            :key="i"
                            :href="link.url ?? ''"
                            class="rounded-lg px-3 py-1.5 text-xs"
                            :class="[link.active ? 'bg-teal-600 text-white' : 'bg-slate-100 text-slate-700', !link.url ? 'pointer-events-none opacity-40' : '']"
                            preserve-scroll
                        >
                            <span v-html="link.label" />
                        </Link>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AdminLayout>
</template>
