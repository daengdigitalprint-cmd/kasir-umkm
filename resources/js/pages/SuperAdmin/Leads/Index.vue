<script setup lang="ts">
import SuperAdminLayout from '@/layouts/SuperAdminLayout.vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import {
    Activity,
    CheckCircle,
    ChevronDown,
    Download,
    ExternalLink,
    Flame,
    Megaphone,
    MessageCircle,
    Search,
    StickyNote,
    Users,
} from 'lucide-vue-next';
import { ref } from 'vue';

interface LeadRow {
    id: number;
    name: string;
    business_name: string;
    whatsapp: string;
    whatsapp_local: string;
    city: string | null;
    business_type: string;
    business_status: string;
    daily_transactions: string;
    start_timeline: string;
    score: number;
    tier: 'hot' | 'warm' | 'cold';
    tier_label: string;
    status: string;
    notes: string | null;
    utm_campaign: string | null;
    utm_content: string | null;
    utm_source: string | null;
    submissions: number;
    submitted_at: string | null;
    contacted_at: string | null;
}

interface Props {
    leads: {
        data: LeadRow[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
    };
    stats: { total: number; qualified: number; qualified_rate: number; to_contact: number; converted: number };
    statuses: Record<string, string>;
    settings: {
        meta_pixel_id: string | null;
        has_capi_token: boolean;
        meta_test_event_code: string | null;
        offer_text: string | null;
        active_pixel_id: string | null;
        pixel_from_env: boolean;
        capi_active: boolean;
    };
    filters: { q: string | null; tier: string | null; status: string | null };
}

const props = defineProps<Props>();

/* ===================== Pengaturan Meta Pixel ===================== */
// Terbuka sendiri selama Pixel belum diatur, supaya langkah pertama jelas.
const settingsOpen = ref(!props.settings.active_pixel_id);
const settingsSaved = ref(false);

const settingsForm = useForm({
    meta_pixel_id: props.settings.meta_pixel_id ?? '',
    // Token tidak pernah dikirim dari server; kosong = biarkan yang tersimpan.
    meta_capi_token: '',
    clear_capi_token: false,
    meta_test_event_code: props.settings.meta_test_event_code ?? '',
    offer_text: props.settings.offer_text ?? '',
});

const saveSettings = () => {
    settingsSaved.value = false;
    settingsForm.put('/superadmin/leads/settings', {
        preserveScroll: true,
        onSuccess: () => {
            settingsSaved.value = true;
            // Bila kode Pixel utuh ditempel, tampilkan ID hasil ekstraksi.
            settingsForm.meta_pixel_id = props.settings.meta_pixel_id ?? '';
            settingsForm.meta_capi_token = '';
            settingsForm.clear_capi_token = false;
        },
    });
};

const trackedEvents = [
    { name: 'PageView', when: 'Setiap halaman landing dibuka' },
    { name: 'FormStart', when: 'Pengunjung menjawab pertanyaan pertama' },
    { name: 'SubmitApplication', when: 'Form terkirim — semua pengisi' },
    { name: 'Lead', when: 'Form terkirim — hanya lead serius (pakai ini untuk optimasi)' },
    { name: 'Contact', when: 'Tombol chat WhatsApp diklik' },
];

const search = ref(props.filters.q ?? '');
const tierFilter = ref(props.filters.tier ?? '');
const statusFilter = ref(props.filters.status ?? '');

const currentFilters = () => ({
    q: search.value || undefined,
    tier: tierFilter.value || undefined,
    status: statusFilter.value || undefined,
});

const applyFilter = () => {
    router.get('/superadmin/leads', currentFilters(), { preserveState: true, preserveScroll: true });
};

/** Ekspor mengikuti filter yang sedang aktif. */
const exportUrl = () => {
    const params = new URLSearchParams();
    Object.entries(currentFilters()).forEach(([key, value]) => {
        if (value) params.set(key, value);
    });
    const query = params.toString();
    return '/superadmin/leads/export' + (query ? `?${query}` : '');
};

const tierBadge = (tier: string) =>
    ({
        hot: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        warm: 'bg-violet-50 text-violet-700 ring-violet-200',
        cold: 'bg-slate-100 text-slate-500 ring-slate-200',
    })[tier] ?? 'bg-slate-100 text-slate-500 ring-slate-200';

const formatDate = (d: string | null) =>
    d ? new Date(d).toLocaleString('id-ID', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—';

const whatsappUrl = (lead: LeadRow) => {
    const firstName = lead.name.trim().split(/\s+/)[0];
    const text = `Halo ${firstName}, saya dari tim MaccaPOS. Terima kasih sudah mengisi form Kasir UMKM untuk ${lead.business_name}. Boleh saya bantu siapkan akunnya sekarang?`;
    return `https://wa.me/${lead.whatsapp}?text=${encodeURIComponent(text)}`;
};

const updateStatus = (lead: LeadRow, status: string) => {
    router.patch(`/superadmin/leads/${lead.id}`, { status }, { preserveScroll: true, preserveState: true });
};

/* Catatan: satu baris terbuka pada satu waktu. */
const editingId = ref<number | null>(null);
const noteDraft = ref('');

const openNote = (lead: LeadRow) => {
    editingId.value = editingId.value === lead.id ? null : lead.id;
    noteDraft.value = lead.notes ?? '';
};

const saveNote = (lead: LeadRow) => {
    router.patch(
        `/superadmin/leads/${lead.id}`,
        { notes: noteDraft.value || null },
        { preserveScroll: true, preserveState: true, onSuccess: () => (editingId.value = null) },
    );
};
</script>

<template>
    <SuperAdminLayout title="Lead Iklan" active-tab="leads">
        <div class="space-y-6">
            <header class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-slate-900">Lead Iklan</h1>
                    <p class="text-sm text-slate-500">Calon pengguna dari landing page iklan. Yang serius dan belum dihubungi tampil paling atas.</p>
                </div>
                <div class="flex items-center gap-2">
                    <a
                        href="/mulai"
                        target="_blank"
                        rel="noopener"
                        class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
                    >
                        <ExternalLink class="h-4 w-4" /> Lihat landing
                    </a>
                    <a
                        :href="exportUrl()"
                        class="inline-flex items-center gap-1.5 rounded-xl bg-slate-900 px-3.5 py-2 text-sm font-semibold text-white hover:bg-slate-800"
                    >
                        <Download class="h-4 w-4" /> Ekspor CSV
                    </a>
                </div>
            </header>

            <!-- Pengaturan Meta Pixel -->
            <section class="rounded-xl border border-slate-200 bg-white">
                <button
                    type="button"
                    class="flex w-full items-center justify-between gap-3 px-5 py-4 text-left"
                    :aria-expanded="settingsOpen"
                    aria-controls="pixel-settings"
                    @click="settingsOpen = !settingsOpen"
                >
                    <span class="flex items-center gap-2.5">
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-violet-100">
                            <Activity class="h-4 w-4 text-violet-600" />
                        </span>
                        <span>
                            <span class="block text-base font-semibold text-slate-900">Meta Pixel &amp; pelacakan iklan</span>
                            <span class="block text-xs text-slate-500">Berlaku untuk halaman /mulai dan halaman hasilnya.</span>
                        </span>
                    </span>
                    <span class="flex shrink-0 items-center gap-2">
                        <span
                            v-if="settings.active_pixel_id"
                            class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700 ring-1 ring-emerald-200 ring-inset"
                        >
                            <CheckCircle class="h-3.5 w-3.5" /> Pixel aktif<span class="hidden sm:inline">&nbsp;· {{ settings.active_pixel_id }}</span>
                        </span>
                        <span
                            v-else
                            class="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-700 ring-1 ring-amber-200 ring-inset"
                        >
                            Pixel belum diatur
                        </span>
                        <ChevronDown :class="['h-4 w-4 text-slate-400 transition-transform', settingsOpen ? 'rotate-180' : '']" />
                    </span>
                </button>

                <form v-show="settingsOpen" id="pixel-settings" class="border-t border-slate-100 p-5" @submit.prevent="saveSettings">
                    <div class="grid gap-6 lg:grid-cols-5">
                        <div class="space-y-4 lg:col-span-3">
                            <div>
                                <label for="set-pixel" class="mb-1 block text-xs font-semibold text-slate-600">ID Pixel Meta</label>
                                <input
                                    id="set-pixel"
                                    v-model="settingsForm.meta_pixel_id"
                                    type="text"
                                    autocomplete="off"
                                    placeholder="mis. 123456789012345"
                                    class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-violet-400 focus:ring-2 focus:ring-violet-100 focus:outline-none"
                                    :aria-invalid="!!settingsForm.errors.meta_pixel_id"
                                />
                                <p v-if="settingsForm.errors.meta_pixel_id" class="mt-1 text-xs font-medium text-red-600">
                                    {{ settingsForm.errors.meta_pixel_id }}
                                </p>
                                <p v-else class="mt-1 text-xs text-slate-500">
                                    Events Manager → Sumber data → pilih Pixel → salin ID. Boleh juga tempel kode Pixel utuh, ID-nya diambil otomatis.
                                    Kosongkan untuk mematikan Pixel.
                                    <span v-if="settings.pixel_from_env" class="font-medium text-amber-700">Saat ini memakai ID dari file .env.</span>
                                </p>
                            </div>

                            <div class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label for="set-token" class="mb-1 block text-xs font-semibold text-slate-600">Token Conversions API (opsional)</label>
                                    <input
                                        id="set-token"
                                        v-model="settingsForm.meta_capi_token"
                                        type="password"
                                        autocomplete="off"
                                        :placeholder="settings.has_capi_token ? 'Tersimpan — isi hanya bila diganti' : 'Tempel token akses'"
                                        :disabled="settingsForm.clear_capi_token"
                                        class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-violet-400 focus:outline-none disabled:bg-slate-50"
                                    />
                                    <p v-if="settingsForm.errors.meta_capi_token" class="mt-1 text-xs font-medium text-red-600">
                                        {{ settingsForm.errors.meta_capi_token }}
                                    </p>
                                    <p v-else class="mt-1 text-xs text-slate-500">
                                        Mengirim event Lead dari server juga, agar tetap tercatat saat Pixel diblokir di HP.
                                    </p>
                                    <label v-if="settings.has_capi_token" class="mt-2 flex items-center gap-2 text-xs text-slate-600">
                                        <input v-model="settingsForm.clear_capi_token" type="checkbox" class="rounded border-slate-300" />
                                        Hapus token yang tersimpan
                                    </label>
                                </div>
                                <div>
                                    <label for="set-test" class="mb-1 block text-xs font-semibold text-slate-600">Kode uji (Test Events)</label>
                                    <input
                                        id="set-test"
                                        v-model="settingsForm.meta_test_event_code"
                                        type="text"
                                        autocomplete="off"
                                        placeholder="mis. TEST12345"
                                        class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-violet-400 focus:outline-none"
                                    />
                                    <p v-if="settingsForm.errors.meta_test_event_code" class="mt-1 text-xs font-medium text-red-600">
                                        {{ settingsForm.errors.meta_test_event_code }}
                                    </p>
                                    <p v-else class="mt-1 text-xs text-slate-500">Hanya saat menguji kiriman server. Kosongkan lagi setelah selesai.</p>
                                </div>
                            </div>

                            <div>
                                <label for="set-offer" class="mb-1 block text-xs font-semibold text-slate-600">Teks penawaran di landing (opsional)</label>
                                <input
                                    id="set-offer"
                                    v-model="settingsForm.offer_text"
                                    type="text"
                                    maxlength="160"
                                    placeholder="mis. Gratis coba paket GrowUp 7 hari untuk usaha yang lolos cek"
                                    class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-violet-400 focus:outline-none"
                                />
                                <p v-if="settingsForm.errors.offer_text" class="mt-1 text-xs font-medium text-red-600">{{ settingsForm.errors.offer_text }}</p>
                                <p v-else class="mt-1 text-xs text-slate-500">
                                    Tampil sebagai sorotan di atas form dan di halaman hasil lead serius. Tulis hanya yang benar-benar Anda berikan. Kosong
                                    = tidak ditampilkan.
                                </p>
                            </div>

                            <div class="flex items-center gap-3">
                                <button
                                    type="submit"
                                    :disabled="settingsForm.processing"
                                    class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 disabled:opacity-60"
                                >
                                    {{ settingsForm.processing ? 'Menyimpan…' : 'Simpan pengaturan' }}
                                </button>
                                <p v-if="settingsSaved" class="flex items-center gap-1.5 text-sm font-medium text-emerald-700" role="status">
                                    <CheckCircle class="h-4 w-4" /> Tersimpan. Landing langsung memakai pengaturan ini.
                                </p>
                            </div>
                        </div>

                        <div class="lg:col-span-2">
                            <p class="mb-2 text-xs font-semibold text-slate-600">Event yang dikirim ke Meta</p>
                            <ul class="divide-y divide-slate-100 rounded-lg border border-slate-200 text-xs">
                                <li v-for="event in trackedEvents" :key="event.name" class="flex items-start gap-3 px-3 py-2">
                                    <code class="shrink-0 rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[11px] text-slate-700">{{ event.name }}</code>
                                    <span class="text-slate-600">{{ event.when }}</span>
                                </li>
                            </ul>
                            <p class="mt-3 text-xs text-slate-500">
                                Conversions API
                                <span :class="settings.capi_active ? 'font-medium text-emerald-700' : 'font-medium text-slate-600'">{{
                                    settings.capi_active ? 'aktif' : 'belum aktif'
                                }}</span
                                >. Setelah menyimpan, cek kiriman di Events Manager → Uji event.
                            </p>
                        </div>
                    </div>
                </form>
            </section>

            <!-- KPI -->
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <div class="rounded-xl border border-slate-200 bg-white p-4">
                    <div class="flex items-center justify-between">
                        <p class="text-xs font-medium text-slate-500">Total lead</p>
                        <Users class="h-4 w-4 text-slate-400" />
                    </div>
                    <p class="mt-2 text-2xl font-bold text-slate-900 tabular-nums">{{ stats.total }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4">
                    <div class="flex items-center justify-between">
                        <p class="text-xs font-medium text-slate-500">Lead serius</p>
                        <Flame class="h-4 w-4 text-emerald-500" />
                    </div>
                    <p class="mt-2 text-2xl font-bold text-slate-900 tabular-nums">
                        {{ stats.qualified }}
                        <span class="text-sm font-medium text-slate-400">({{ stats.qualified_rate }}%)</span>
                    </p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4">
                    <div class="flex items-center justify-between">
                        <p class="text-xs font-medium text-slate-500">Perlu dihubungi</p>
                        <MessageCircle class="h-4 w-4 text-amber-500" />
                    </div>
                    <p class="mt-2 text-2xl font-bold text-slate-900 tabular-nums">{{ stats.to_contact }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4">
                    <div class="flex items-center justify-between">
                        <p class="text-xs font-medium text-slate-500">Jadi pengguna</p>
                        <CheckCircle class="h-4 w-4 text-sky-500" />
                    </div>
                    <p class="mt-2 text-2xl font-bold text-slate-900 tabular-nums">{{ stats.converted }}</p>
                </div>
            </div>

            <section class="rounded-xl border border-slate-200 bg-white">
                <div class="flex flex-col gap-3 border-b border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <h2 class="text-base font-semibold text-slate-900">Daftar lead ({{ leads.total }})</h2>
                    <div class="flex flex-wrap items-center gap-2">
                        <div class="relative">
                            <Search class="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-slate-400" />
                            <input
                                v-model="search"
                                type="search"
                                aria-label="Cari lead"
                                placeholder="Cari nama, usaha, nomor, kampanye…"
                                class="w-64 rounded-lg border border-slate-200 py-2 pr-3 pl-9 text-sm focus:border-slate-400 focus:outline-none"
                                @keyup.enter="applyFilter"
                            />
                        </div>
                        <select
                            v-model="tierFilter"
                            aria-label="Saring tingkat keseriusan"
                            class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm focus:outline-none"
                            @change="applyFilter"
                        >
                            <option value="">Semua tingkat</option>
                            <option value="qualified">Serius saja</option>
                            <option value="hot">Siap mulai</option>
                            <option value="warm">Serius</option>
                            <option value="cold">Belum siap</option>
                        </select>
                        <select
                            v-model="statusFilter"
                            aria-label="Saring status tindak lanjut"
                            class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm focus:outline-none"
                            @change="applyFilter"
                        >
                            <option value="">Semua status</option>
                            <option v-for="(label, key) in statuses" :key="key" :value="key">{{ label }}</option>
                        </select>
                    </div>
                </div>

                <div v-if="leads.data.length === 0" class="px-5 py-16 text-center">
                    <Megaphone class="mx-auto h-8 w-8 text-slate-300" />
                    <p class="mt-3 text-sm text-slate-500">Belum ada lead. Arahkan iklan Meta ke halaman /mulai untuk mulai mengumpulkan.</p>
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-slate-50 text-xs font-medium tracking-wide text-slate-500 uppercase">
                            <tr>
                                <th scope="col" class="px-5 py-3">Lead</th>
                                <th scope="col" class="px-3 py-3">Tingkat</th>
                                <th scope="col" class="px-3 py-3">Jawaban</th>
                                <th scope="col" class="px-3 py-3">Iklan</th>
                                <th scope="col" class="px-3 py-3">Masuk</th>
                                <th scope="col" class="px-3 py-3">Status</th>
                                <th scope="col" class="px-5 py-3 text-right"><span class="sr-only">Aksi</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <template v-for="lead in leads.data" :key="lead.id">
                                <tr class="align-top hover:bg-slate-50/60">
                                    <td class="px-5 py-3.5">
                                        <p class="font-semibold text-slate-900">{{ lead.business_name }}</p>
                                        <p class="text-xs text-slate-500">{{ lead.name }} · {{ lead.city ?? '—' }}</p>
                                        <p class="mt-0.5 text-xs text-slate-400 tabular-nums">{{ lead.whatsapp_local }}</p>
                                    </td>
                                    <td class="px-3 py-3.5">
                                        <span :class="['inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap ring-1 ring-inset', tierBadge(lead.tier)]">
                                            {{ lead.tier_label }}
                                        </span>
                                        <p class="mt-1 text-xs text-slate-400 tabular-nums">Nilai {{ lead.score }}/9</p>
                                    </td>
                                    <td class="px-3 py-3.5 text-xs leading-relaxed text-slate-600">
                                        <p class="font-medium text-slate-800">{{ lead.business_type }}</p>
                                        <p>{{ lead.business_status }} · {{ lead.daily_transactions }} trx/hari</p>
                                        <p>{{ lead.start_timeline }}</p>
                                    </td>
                                    <td class="max-w-[180px] px-3 py-3.5 text-xs text-slate-600">
                                        <p class="truncate" :title="lead.utm_campaign ?? ''">{{ lead.utm_campaign ?? '—' }}</p>
                                        <p v-if="lead.utm_content" class="truncate text-slate-400" :title="lead.utm_content">{{ lead.utm_content }}</p>
                                    </td>
                                    <td class="px-3 py-3.5 text-xs whitespace-nowrap text-slate-600">
                                        {{ formatDate(lead.submitted_at) }}
                                        <p v-if="lead.submissions > 1" class="text-slate-400">isi {{ lead.submissions }}×</p>
                                    </td>
                                    <td class="px-3 py-3.5">
                                        <select
                                            :value="lead.status"
                                            :aria-label="`Status tindak lanjut ${lead.business_name}`"
                                            class="rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-xs focus:outline-none"
                                            @change="updateStatus(lead, ($event.target as HTMLSelectElement).value)"
                                        >
                                            <option v-for="(label, key) in statuses" :key="key" :value="key">{{ label }}</option>
                                        </select>
                                    </td>
                                    <td class="px-5 py-3.5">
                                        <div class="flex items-center justify-end gap-1">
                                            <button
                                                type="button"
                                                :class="[
                                                    'rounded-lg p-1.5 hover:bg-slate-100',
                                                    lead.notes ? 'text-amber-600' : 'text-slate-400 hover:text-slate-700',
                                                ]"
                                                :title="lead.notes ? 'Lihat / ubah catatan' : 'Tambah catatan'"
                                                :aria-expanded="editingId === lead.id"
                                                @click="openNote(lead)"
                                            >
                                                <StickyNote class="h-4 w-4" />
                                            </button>
                                            <a
                                                :href="whatsappUrl(lead)"
                                                target="_blank"
                                                rel="noopener"
                                                class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-2.5 py-1.5 text-xs font-semibold whitespace-nowrap text-white hover:bg-emerald-700"
                                            >
                                                <MessageCircle class="h-3.5 w-3.5" /> WhatsApp
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="editingId === lead.id" class="bg-slate-50/60">
                                    <td colspan="7" class="px-5 py-3">
                                        <label :for="`note-${lead.id}`" class="mb-1 block text-xs font-semibold text-slate-600">
                                            Catatan tindak lanjut — {{ lead.business_name }}
                                        </label>
                                        <textarea
                                            :id="`note-${lead.id}`"
                                            v-model="noteDraft"
                                            rows="2"
                                            maxlength="2000"
                                            placeholder="mis. minta demo Sabtu pagi, punya 2 kasir"
                                            class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm focus:border-slate-400 focus:outline-none"
                                        ></textarea>
                                        <div class="mt-2 flex justify-end gap-2">
                                            <button type="button" class="rounded-lg px-3 py-1.5 text-xs font-medium text-slate-600 hover:bg-slate-100" @click="editingId = null">
                                                Batal
                                            </button>
                                            <button type="button" class="rounded-lg bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-slate-800" @click="saveNote(lead)">
                                                Simpan catatan
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div v-if="leads.links && leads.links.length > 3" class="flex justify-center border-t border-slate-100 px-5 py-4">
                    <nav class="flex items-center gap-1" aria-label="Halaman daftar lead">
                        <Link
                            v-for="link in leads.links"
                            :key="link.label"
                            :href="link.url || '#'"
                            :class="[
                                'rounded-lg px-3 py-1.5 text-sm font-medium transition-colors',
                                link.active ? 'bg-slate-900 text-white' : link.url ? 'text-slate-600 hover:bg-slate-100' : 'cursor-default text-slate-300',
                            ]"
                        >
                            <span v-html="link.label" />
                        </Link>
                    </nav>
                </div>
            </section>
        </div>
    </SuperAdminLayout>
</template>
