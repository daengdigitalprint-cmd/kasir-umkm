<script setup lang="ts">
import SuperAdminLayout from '@/layouts/SuperAdminLayout.vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import {
    CheckCircle,
    ChevronDown,
    Download,
    Globe,
    Instagram,
    MapPin,
    MapPinned,
    MessageCircle,
    Phone,
    Search,
    StickyNote,
    ThumbsUp,
    Trash2,
    Users,
} from 'lucide-vue-next';
import { ref, watch } from 'vue';

interface ProspectRow {
    id: number;
    name: string;
    category: string;
    city: string;
    address: string | null;
    phone: string | null;
    phone_local: string | null;
    phone_is_mobile: boolean;
    website: string | null;
    instagram: string | null;
    rating: number | null;
    reviews_count: number | null;
    score: number;
    tier: 'high' | 'medium' | 'low';
    tier_label: string;
    status: string;
    notes: string | null;
    source: string;
    contacted_at: string | null;
}

interface SearchResult {
    keyword: string;
    city: string;
    category: string;
    found: number;
    added: number;
    updated: number;
    duplicates: number;
    with_phone: number;
    skipped_chains: number;
}

interface Props {
    prospects: {
        data: ProspectRow[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
    };
    stats: { total: number; with_phone: number; to_contact: number; interested: number };
    statuses: Record<string, string>;
    cities: string[];
    categories: string[];
    suggestions: string[];
    searchResult: SearchResult | null;
    filters: { q: string | null; city: string | null; category: string | null; status: string | null; contact: string | null };
}

const props = defineProps<Props>();

/* ===================== Pencarian baru ===================== */
const searchForm = useForm({ keyword: '', city: props.filters.city ?? '' });

const runSearch = () => {
    searchForm.post('/superadmin/prospects/search', { preserveScroll: true });
};

/* ===================== Draf pesan WhatsApp ===================== */
const DEFAULT_TEMPLATE =
    'Halo Kak, salam kenal. Saya [nama Anda] dari Kasir UMKM, aplikasi kasir untuk usaha seperti {nama_usaha}. ' +
    'Nomor ini saya dapat dari profil usaha Kakak di peta publik. Boleh saya kirim info singkatnya? ' +
    'Kalau tidak berkenan, balas saja TIDAK dan saya tidak akan menghubungi lagi.';
const TEMPLATE_KEY = 'prospek.templat-wa';

const readTemplate = () => {
    try {
        return localStorage.getItem(TEMPLATE_KEY) || DEFAULT_TEMPLATE;
    } catch {
        return DEFAULT_TEMPLATE;
    }
};

const template = ref(readTemplate());
const templateOpen = ref(false);

watch(template, (value) => {
    try {
        localStorage.setItem(TEMPLATE_KEY, value);
    } catch {
        /* penyimpanan browser tidak tersedia: draf tetap dipakai selama halaman terbuka */
    }
});

/** Membuka WhatsApp dengan draf terisi. Pesan baru terkirim setelah Anda menekan kirim sendiri. */
const whatsappUrl = (p: ProspectRow) => `https://wa.me/${p.phone}?text=${encodeURIComponent(template.value.replaceAll('{nama_usaha}', p.name))}`;

const mapsUrl = (p: ProspectRow) => `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(`${p.name} ${p.city}`)}`;

const websiteUrl = (url: string) => (/^https?:\/\//i.test(url) ? url : `https://${url}`);

/* ===================== Saringan daftar ===================== */
const search = ref(props.filters.q ?? '');
const cityFilter = ref(props.filters.city ?? '');
const categoryFilter = ref(props.filters.category ?? '');
const statusFilter = ref(props.filters.status ?? '');
const contactFilter = ref(props.filters.contact ?? '');

const currentFilters = () => ({
    q: search.value || undefined,
    city: cityFilter.value || undefined,
    category: categoryFilter.value || undefined,
    status: statusFilter.value || undefined,
    contact: contactFilter.value || undefined,
});

const applyFilter = () => {
    router.get('/superadmin/prospects', currentFilters(), { preserveState: true, preserveScroll: true });
};

/** Ekspor mengikuti saringan yang sedang aktif. */
const exportUrl = () => {
    const params = new URLSearchParams();
    Object.entries(currentFilters()).forEach(([key, value]) => {
        if (value) params.set(key, value);
    });
    const query = params.toString();
    return '/superadmin/prospects/export' + (query ? `?${query}` : '');
};

const tierBadge = (tier: string) =>
    ({
        high: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        medium: 'bg-violet-50 text-violet-700 ring-violet-200',
        low: 'bg-slate-100 text-slate-500 ring-slate-200',
    })[tier] ?? 'bg-slate-100 text-slate-500 ring-slate-200';

/* ===================== Tindak lanjut ===================== */
const updateStatus = (p: ProspectRow, status: string) => {
    router.patch(`/superadmin/prospects/${p.id}`, { status }, { preserveScroll: true, preserveState: true });
};

const editingId = ref<number | null>(null);
const noteDraft = ref('');

const openNote = (p: ProspectRow) => {
    editingId.value = editingId.value === p.id ? null : p.id;
    noteDraft.value = p.notes ?? '';
};

const saveNote = (p: ProspectRow) => {
    router.patch(
        `/superadmin/prospects/${p.id}`,
        { notes: noteDraft.value || null },
        { preserveScroll: true, preserveState: true, onSuccess: () => (editingId.value = null) },
    );
};

const remove = (p: ProspectRow) => {
    if (!confirm(`Hapus "${p.name}" dari daftar prospek? Data dan catatannya hilang permanen.`)) return;
    router.delete(`/superadmin/prospects/${p.id}`, { preserveScroll: true, preserveState: true });
};
</script>

<template>
    <SuperAdminLayout title="Cari Prospek" active-tab="prospects">
        <div class="space-y-6">
            <header class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-slate-900">Cari Prospek</h1>
                    <p class="text-sm text-slate-500">
                        Usaha dan kontak yang dipasang pemiliknya di peta terbuka (OpenStreetMap). Menghubungi tetap dilakukan manual, satu per satu.
                    </p>
                </div>
                <a
                    :href="exportUrl()"
                    class="inline-flex items-center gap-1.5 self-start rounded-xl bg-slate-900 px-3.5 py-2 text-sm font-semibold text-white hover:bg-slate-800"
                >
                    <Download class="h-4 w-4" /> Ekspor CSV
                </a>
            </header>

            <!-- Pencarian baru -->
            <section class="rounded-xl border border-slate-200 bg-white p-5">
                <h2 class="text-base font-semibold text-slate-900">Cari usaha baru</h2>
                <form class="mt-3 grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-start" @submit.prevent="runSearch">
                    <div>
                        <label for="ps-keyword" class="mb-1 block text-xs font-semibold text-slate-600">Jenis usaha</label>
                        <input
                            id="ps-keyword"
                            v-model="searchForm.keyword"
                            type="text"
                            list="ps-suggestions"
                            required
                            maxlength="80"
                            placeholder="mis. kedai kopi"
                            class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-violet-400 focus:ring-2 focus:ring-violet-100 focus:outline-none"
                            :aria-invalid="!!searchForm.errors.keyword"
                        />
                        <datalist id="ps-suggestions">
                            <option v-for="s in suggestions" :key="s" :value="s" />
                        </datalist>
                        <p v-if="searchForm.errors.keyword" class="mt-1 text-xs font-medium text-red-600">{{ searchForm.errors.keyword }}</p>
                    </div>
                    <div>
                        <label for="ps-city" class="mb-1 block text-xs font-semibold text-slate-600">Kota / kabupaten</label>
                        <input
                            id="ps-city"
                            v-model="searchForm.city"
                            type="text"
                            required
                            maxlength="100"
                            placeholder="mis. Makassar"
                            class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-violet-400 focus:ring-2 focus:ring-violet-100 focus:outline-none"
                            :aria-invalid="!!searchForm.errors.city"
                        />
                        <p v-if="searchForm.errors.city" class="mt-1 text-xs font-medium text-red-600">{{ searchForm.errors.city }}</p>
                    </div>
                    <button
                        type="submit"
                        :disabled="searchForm.processing"
                        class="inline-flex items-center justify-center gap-1.5 rounded-xl bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-700 disabled:opacity-60 sm:mt-5"
                    >
                        <Search class="h-4 w-4" /> {{ searchForm.processing ? 'Mencari…' : 'Cari' }}
                    </button>
                </form>
                <p v-if="searchForm.processing" class="mt-3 text-xs text-slate-500" role="status">
                    Mengambil data dari server peta publik. Kota besar bisa butuh sampai satu menit.
                </p>
                <p
                    v-else-if="searchResult"
                    class="mt-3 flex items-start gap-2 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800"
                    role="status"
                >
                    <CheckCircle class="mt-0.5 h-4 w-4 shrink-0" />
                    <span>
                        {{ searchResult.category }} di {{ searchResult.city }}: {{ searchResult.found }} usaha ditemukan —
                        {{ searchResult.added }} baru, {{ searchResult.updated }} diperbarui, {{ searchResult.duplicates }} duplikat dilewati,
                        {{ searchResult.skipped_chains }} jaringan besar dilewati. {{ searchResult.with_phone }} punya nomor telepon.
                    </span>
                </p>
            </section>

            <!-- Draf WhatsApp -->
            <section class="rounded-xl border border-slate-200 bg-white">
                <button
                    type="button"
                    class="flex w-full items-center justify-between gap-3 px-5 py-4 text-left"
                    :aria-expanded="templateOpen"
                    aria-controls="wa-template"
                    @click="templateOpen = !templateOpen"
                >
                    <span>
                        <span class="block text-base font-semibold text-slate-900">Draf pesan perkenalan WhatsApp</span>
                        <span class="block text-xs text-slate-500"
                            >Tombol WhatsApp hanya membuka obrolan dengan draf ini. Tidak ada pesan yang terkirim otomatis.</span
                        >
                    </span>
                    <ChevronDown :class="['h-4 w-4 shrink-0 text-slate-400 transition-transform', templateOpen ? 'rotate-180' : '']" />
                </button>
                <div v-show="templateOpen" id="wa-template" class="border-t border-slate-100 p-5">
                    <label for="wa-text" class="sr-only">Isi draf pesan</label>
                    <textarea
                        id="wa-text"
                        v-model="template"
                        rows="4"
                        maxlength="700"
                        class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-violet-400 focus:outline-none"
                    ></textarea>
                    <p class="mt-2 text-xs text-slate-500">
                        <code class="rounded bg-slate-100 px-1 py-0.5 font-mono text-[11px]">{nama_usaha}</code> diganti nama usaha. Draf tersimpan di
                        browser ini saja. Sebutkan dari mana nomor didapat dan beri cara menolak; yang menolak tandai "Tidak tertarik" agar tidak
                        dihubungi lagi. Kirim sedikit demi sedikit dari nomor biasa — kiriman massal bisa membuat nomor diblokir WhatsApp.
                    </p>
                    <button type="button" class="mt-2 text-xs font-medium text-violet-700 hover:underline" @click="template = DEFAULT_TEMPLATE">
                        Kembalikan ke draf awal
                    </button>
                </div>
            </section>

            <!-- KPI -->
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <div class="rounded-xl border border-slate-200 bg-white p-4">
                    <div class="flex items-center justify-between">
                        <p class="text-xs font-medium text-slate-500">Total prospek</p>
                        <Users class="h-4 w-4 text-slate-400" />
                    </div>
                    <p class="mt-2 text-2xl font-bold text-slate-900 tabular-nums">{{ stats.total }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4">
                    <div class="flex items-center justify-between">
                        <p class="text-xs font-medium text-slate-500">Punya nomor telepon</p>
                        <Phone class="h-4 w-4 text-emerald-500" />
                    </div>
                    <p class="mt-2 text-2xl font-bold text-slate-900 tabular-nums">{{ stats.with_phone }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4">
                    <div class="flex items-center justify-between">
                        <p class="text-xs font-medium text-slate-500">Belum dihubungi</p>
                        <MessageCircle class="h-4 w-4 text-amber-500" />
                    </div>
                    <p class="mt-2 text-2xl font-bold text-slate-900 tabular-nums">{{ stats.to_contact }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4">
                    <div class="flex items-center justify-between">
                        <p class="text-xs font-medium text-slate-500">Tertarik</p>
                        <ThumbsUp class="h-4 w-4 text-sky-500" />
                    </div>
                    <p class="mt-2 text-2xl font-bold text-slate-900 tabular-nums">{{ stats.interested }}</p>
                </div>
            </div>

            <section class="rounded-xl border border-slate-200 bg-white">
                <div class="flex flex-col gap-3 border-b border-slate-100 px-5 py-4 xl:flex-row xl:items-center xl:justify-between">
                    <h2 class="text-base font-semibold text-slate-900">Daftar prospek ({{ prospects.total }})</h2>
                    <div class="flex flex-wrap items-center gap-2">
                        <div class="relative">
                            <Search class="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-slate-400" />
                            <input
                                v-model="search"
                                type="search"
                                aria-label="Cari di daftar prospek"
                                placeholder="Cari nama, alamat, nomor…"
                                class="w-56 rounded-lg border border-slate-200 py-2 pr-3 pl-9 text-sm focus:border-slate-400 focus:outline-none"
                                @keyup.enter="applyFilter"
                            />
                        </div>
                        <select
                            v-model="cityFilter"
                            aria-label="Saring kota"
                            class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm focus:outline-none"
                            @change="applyFilter"
                        >
                            <option value="">Semua kota</option>
                            <option v-for="c in cities" :key="c" :value="c">{{ c }}</option>
                        </select>
                        <select
                            v-model="categoryFilter"
                            aria-label="Saring jenis usaha"
                            class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm focus:outline-none"
                            @change="applyFilter"
                        >
                            <option value="">Semua jenis</option>
                            <option v-for="c in categories" :key="c" :value="c">{{ c }}</option>
                        </select>
                        <select
                            v-model="contactFilter"
                            aria-label="Saring ketersediaan kontak"
                            class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm focus:outline-none"
                            @change="applyFilter"
                        >
                            <option value="">Semua kontak</option>
                            <option value="mobile">Nomor seluler (WA)</option>
                            <option value="phone">Ada telepon</option>
                            <option value="any">Ada kontak apa pun</option>
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

                <div v-if="prospects.data.length === 0" class="px-5 py-16 text-center">
                    <MapPinned class="mx-auto h-8 w-8 text-slate-300" />
                    <p class="mt-3 text-sm text-slate-500">
                        {{
                            stats.total === 0
                                ? 'Belum ada prospek. Isi jenis usaha dan kota di atas, lalu tekan Cari.'
                                : 'Tidak ada prospek yang cocok dengan saringan ini.'
                        }}
                    </p>
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-slate-50 text-xs font-medium tracking-wide text-slate-500 uppercase">
                            <tr>
                                <th scope="col" class="px-5 py-3">Usaha</th>
                                <th scope="col" class="px-3 py-3">Kontak publik</th>
                                <th scope="col" class="px-3 py-3">Prioritas</th>
                                <th scope="col" class="px-3 py-3">Status</th>
                                <th scope="col" class="px-5 py-3 text-right"><span class="sr-only">Aksi</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <template v-for="p in prospects.data" :key="p.id">
                                <tr class="align-top hover:bg-slate-50/60">
                                    <td class="max-w-xs px-5 py-3.5">
                                        <p class="font-semibold text-slate-900">{{ p.name }}</p>
                                        <p class="text-xs text-slate-500">{{ p.category }} · {{ p.city }}</p>
                                        <p v-if="p.address" class="mt-0.5 text-xs text-slate-400">{{ p.address }}</p>
                                    </td>
                                    <td class="px-3 py-3.5 text-xs leading-relaxed text-slate-600">
                                        <p v-if="p.phone_local" class="flex items-center gap-1.5 tabular-nums">
                                            <Phone class="h-3.5 w-3.5 text-slate-400" /> {{ p.phone_local }}
                                            <span v-if="!p.phone_is_mobile" class="text-slate-400">(kantor)</span>
                                        </p>
                                        <a
                                            v-if="p.instagram"
                                            :href="`https://www.instagram.com/${p.instagram}/`"
                                            target="_blank"
                                            rel="noopener"
                                            class="flex items-center gap-1.5 hover:text-violet-700 hover:underline"
                                        >
                                            <Instagram class="h-3.5 w-3.5 text-slate-400" /> @{{ p.instagram }}
                                        </a>
                                        <a
                                            v-if="p.website"
                                            :href="websiteUrl(p.website)"
                                            target="_blank"
                                            rel="noopener"
                                            class="flex max-w-[220px] items-center gap-1.5 hover:text-violet-700 hover:underline"
                                        >
                                            <Globe class="h-3.5 w-3.5 shrink-0 text-slate-400" /> <span class="truncate">{{ p.website }}</span>
                                        </a>
                                        <p v-if="!p.phone_local && !p.instagram && !p.website" class="text-slate-400">Belum ada — cek lewat Maps</p>
                                    </td>
                                    <td class="px-3 py-3.5">
                                        <span
                                            :class="[
                                                'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap ring-1 ring-inset',
                                                tierBadge(p.tier),
                                            ]"
                                        >
                                            {{ p.tier_label }}
                                        </span>
                                        <p class="mt-1 text-xs text-slate-400 tabular-nums">
                                            Skor {{ p.score }}/10
                                            <span v-if="p.rating"> · ★ {{ p.rating }} ({{ p.reviews_count }})</span>
                                        </p>
                                    </td>
                                    <td class="px-3 py-3.5">
                                        <select
                                            :value="p.status"
                                            :aria-label="`Status tindak lanjut ${p.name}`"
                                            class="rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-xs focus:outline-none"
                                            @change="updateStatus(p, ($event.target as HTMLSelectElement).value)"
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
                                                    p.notes ? 'text-amber-600' : 'text-slate-400 hover:text-slate-700',
                                                ]"
                                                :title="p.notes ? 'Lihat / ubah catatan' : 'Tambah catatan'"
                                                :aria-label="`Catatan untuk ${p.name}`"
                                                :aria-expanded="editingId === p.id"
                                                @click="openNote(p)"
                                            >
                                                <StickyNote class="h-4 w-4" />
                                            </button>
                                            <a
                                                :href="mapsUrl(p)"
                                                target="_blank"
                                                rel="noopener"
                                                class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700"
                                                title="Cek di Google Maps"
                                                :aria-label="`Cek ${p.name} di Google Maps`"
                                            >
                                                <MapPin class="h-4 w-4" />
                                            </a>
                                            <button
                                                type="button"
                                                class="rounded-lg p-1.5 text-slate-400 hover:bg-red-50 hover:text-red-600"
                                                title="Hapus dari daftar"
                                                :aria-label="`Hapus ${p.name} dari daftar`"
                                                @click="remove(p)"
                                            >
                                                <Trash2 class="h-4 w-4" />
                                            </button>
                                            <a
                                                v-if="p.phone && p.phone_is_mobile"
                                                :href="whatsappUrl(p)"
                                                target="_blank"
                                                rel="noopener"
                                                class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-2.5 py-1.5 text-xs font-semibold whitespace-nowrap text-white hover:bg-emerald-700"
                                            >
                                                <MessageCircle class="h-3.5 w-3.5" /> Buka WhatsApp
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="editingId === p.id" class="bg-slate-50/60">
                                    <td colspan="5" class="px-5 py-3">
                                        <label :for="`note-${p.id}`" class="mb-1 block text-xs font-semibold text-slate-600"
                                            >Catatan tindak lanjut — {{ p.name }}</label
                                        >
                                        <textarea
                                            :id="`note-${p.id}`"
                                            v-model="noteDraft"
                                            rows="2"
                                            maxlength="2000"
                                            placeholder="mis. sudah pakai buku tulis, minta dihubungi lagi Senin"
                                            class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm focus:border-slate-400 focus:outline-none"
                                        ></textarea>
                                        <div class="mt-2 flex justify-end gap-2">
                                            <button
                                                type="button"
                                                class="rounded-lg px-3 py-1.5 text-xs font-medium text-slate-600 hover:bg-slate-100"
                                                @click="editingId = null"
                                            >
                                                Batal
                                            </button>
                                            <button
                                                type="button"
                                                class="rounded-lg bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-slate-800"
                                                @click="saveNote(p)"
                                            >
                                                Simpan catatan
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>

                <div v-if="prospects.links && prospects.links.length > 3" class="flex justify-center border-t border-slate-100 px-5 py-4">
                    <nav class="flex flex-wrap items-center gap-1" aria-label="Halaman daftar prospek">
                        <Link
                            v-for="link in prospects.links"
                            :key="link.label"
                            :href="link.url || '#'"
                            :class="[
                                'rounded-lg px-3 py-1.5 text-sm font-medium transition-colors',
                                link.active
                                    ? 'bg-slate-900 text-white'
                                    : link.url
                                      ? 'text-slate-600 hover:bg-slate-100'
                                      : 'cursor-default text-slate-300',
                            ]"
                        >
                            <span v-html="link.label" />
                        </Link>
                    </nav>
                </div>

                <p class="border-t border-slate-100 px-5 py-3 text-xs text-slate-400">
                    Data usaha © kontributor OpenStreetMap (lisensi ODbL). Hanya memuat informasi yang dipublikasikan untuk usaha; hapus baris bila
                    pemiliknya meminta.
                </p>
            </section>
        </div>
    </SuperAdminLayout>
</template>
