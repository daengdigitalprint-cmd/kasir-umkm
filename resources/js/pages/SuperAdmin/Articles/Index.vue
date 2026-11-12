<script setup lang="ts">
import SuperAdminLayout from '@/layouts/SuperAdminLayout.vue';
import { Link, router } from '@inertiajs/vue3';
import {
    Bot,
    CheckCircle,
    Eye,
    FileText,
    Lightbulb,
    Loader2,
    Newspaper,
    Pencil,
    Plus,
    Search,
    Send,
    Sparkles,
    Trash2,
    TrendingUp,
    XCircle,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';

interface ArticleRow {
    id: number;
    title: string;
    slug: string;
    category: string | null;
    status: string;
    strategy: string | null;
    is_pillar: boolean;
    views: number;
    reading_time: number;
    generated_by_ai: boolean;
    published_at: string | null;
    created_at: string;
    public_url: string;
}

interface Generation {
    id: number;
    type: string;
    status: string;
    input: { keyword?: string; focus?: string } | null;
    created_at?: string;
}

interface Topic {
    title: string;
    strategy: string;
    keyword: string;
    reason: string;
}

interface Props {
    articles: {
        data: ArticleRow[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
    };
    stats: { total: number; published: number; draft: number; total_views: number };
    strategies: Record<string, string>;
    activeGenerations: Generation[];
    filters: { q: string | null; status: string | null };
}

const props = defineProps<Props>();

const csrf = (): string => document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';

const postJson = async (url: string, body: Record<string, unknown>) => {
    const res = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf(),
            'X-Requested-With': 'XMLHttpRequest',
            Accept: 'application/json',
        },
        body: JSON.stringify(body),
    });
    if (!res.ok) {
        const data = await res.json().catch(() => null);
        throw new Error(data?.message ?? `Gagal (HTTP ${res.status})`);
    }
    return res.json();
};

/* ===================== Generate artikel ===================== */
const generateForm = reactive({
    keyword: '',
    strategy: 'howto',
    length: 'medium',
    audience: '',
    category: '',
    outline: '',
});

const generateError = ref<string | null>(null);
const pendingGenerations = ref<Generation[]>([...props.activeGenerations]);
const completedInfo = ref<{ articleId: number; title: string } | null>(null);

const isGeneratingArticle = computed(() =>
    pendingGenerations.value.some((g) => g.type === 'article' && (g.status === 'pending' || g.status === 'processing')),
);

const submitGenerate = async () => {
    generateError.value = null;
    completedInfo.value = null;

    if (generateForm.keyword.trim().length < 3) {
        generateError.value = 'Isi kata kunci utama dulu (minimal 3 karakter).';
        return;
    }

    try {
        const data = await postJson('/superadmin/articles/generate', {
            keyword: generateForm.keyword,
            strategy: generateForm.strategy,
            length: generateForm.length,
            audience: generateForm.audience || null,
            category: generateForm.category || null,
            outline: generateForm.outline || null,
        });
        pendingGenerations.value.push({
            id: data.generation_id,
            type: 'article',
            status: 'pending',
            input: { keyword: generateForm.keyword },
        });
        startPolling();
    } catch (e) {
        generateError.value = e instanceof Error ? e.message : 'Gagal memulai generate.';
    }
};

/* ===================== Saran topik ===================== */
const topicFocus = ref('');
const topics = ref<Topic[]>([]);
const topicError = ref<string | null>(null);

const isSuggestingTopics = computed(() =>
    pendingGenerations.value.some((g) => g.type === 'topics' && (g.status === 'pending' || g.status === 'processing')),
);

const requestTopics = async () => {
    topicError.value = null;
    topics.value = [];

    try {
        const data = await postJson('/superadmin/articles/suggest-topics', {
            focus: topicFocus.value || null,
        });
        pendingGenerations.value.push({ id: data.generation_id, type: 'topics', status: 'pending', input: {} });
        startPolling();
    } catch (e) {
        topicError.value = e instanceof Error ? e.message : 'Gagal meminta saran topik.';
    }
};

const useTopic = (t: Topic) => {
    generateForm.keyword = t.keyword || t.title;
    if (t.strategy in props.strategies) {
        generateForm.strategy = t.strategy;
    }
    document.getElementById('generate-card')?.scrollIntoView({ behavior: 'smooth' });
};

/* ===================== Polling status ===================== */
let pollTimer: ReturnType<typeof setInterval> | null = null;

const pollOnce = async () => {
    const active = pendingGenerations.value.filter((g) => g.status === 'pending' || g.status === 'processing');

    if (active.length === 0) {
        stopPolling();
        return;
    }

    for (const gen of active) {
        try {
            const res = await fetch(`/superadmin/articles/generations/${gen.id}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!res.ok) continue;
            const data = await res.json();
            gen.status = data.status;

            if (data.status === 'completed') {
                if (data.type === 'topics') {
                    topics.value = data.result?.topics ?? [];
                } else if (data.type === 'article' && data.article_id) {
                    completedInfo.value = { articleId: data.article_id, title: data.result?.title ?? 'Artikel baru' };
                    router.reload({ only: ['articles', 'stats'] });
                }
            } else if (data.status === 'failed') {
                const msg = data.error ?? 'Generate gagal tanpa pesan error.';
                if (data.type === 'topics') topicError.value = msg;
                else generateError.value = msg;
            }
        } catch {
            // koneksi sesaat putus — biarkan polling berikutnya mencoba lagi
        }
    }
};

const startPolling = () => {
    if (pollTimer) return;
    pollTimer = setInterval(pollOnce, 4000);
};

const stopPolling = () => {
    if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
};

onMounted(() => {
    if (pendingGenerations.value.some((g) => g.status === 'pending' || g.status === 'processing')) {
        startPolling();
    }
});

onBeforeUnmount(stopPolling);

/* ===================== Daftar & filter ===================== */
const search = ref(props.filters.q ?? '');
const statusFilter = ref(props.filters.status ?? '');

const applyFilter = () => {
    router.get(
        '/superadmin/articles',
        { q: search.value || undefined, status: statusFilter.value || undefined },
        { preserveState: true, preserveScroll: true },
    );
};

const statusBadge = (status: string) =>
    ({
        published: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        scheduled: 'bg-amber-50 text-amber-700 ring-amber-200',
        draft: 'bg-slate-100 text-slate-600 ring-slate-200',
    })[status] ?? 'bg-slate-100 text-slate-600 ring-slate-200';

const statusLabel = (status: string) => ({ published: 'Terbit', scheduled: 'Terjadwal', draft: 'Draft' })[status] ?? status;

const formatDate = (d: string | null) =>
    d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—';

const quickPublish = (article: ArticleRow) => {
    router.post(`/superadmin/articles/${article.id}/status`, { status: 'published' }, { preserveScroll: true });
};

const deleteArticle = (article: ArticleRow) => {
    if (confirm(`Hapus artikel "${article.title}"? Tindakan ini tidak bisa dibatalkan.`)) {
        router.delete(`/superadmin/articles/${article.id}`, { preserveScroll: true });
    }
};
</script>

<template>
    <SuperAdminLayout title="Artikel SEO" active-tab="articles">
        <div class="space-y-6">
            <header class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-slate-900">Artikel SEO</h1>
                    <p class="text-sm text-slate-500">Buat artikel blog (manual atau AI) untuk menarik trafik organik.</p>
                </div>
                <div class="flex items-center gap-2">
                    <a
                        href="/blog"
                        target="_blank"
                        rel="noopener"
                        class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
                    >
                        <Eye class="h-4 w-4" /> Lihat Blog
                    </a>
                    <Link
                        href="/superadmin/articles/create"
                        class="inline-flex items-center gap-1.5 rounded-xl bg-slate-900 px-3.5 py-2 text-sm font-semibold text-white hover:bg-slate-800"
                    >
                        <Plus class="h-4 w-4" /> Tulis Manual
                    </Link>
                </div>
            </header>

            <!-- KPI -->
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <div class="rounded-xl border border-slate-200 bg-white p-4">
                    <div class="flex items-center justify-between">
                        <p class="text-xs font-medium text-slate-500">Total artikel</p>
                        <Newspaper class="h-4 w-4 text-slate-400" />
                    </div>
                    <p class="mt-2 text-2xl font-bold text-slate-900 tabular-nums">{{ stats.total }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4">
                    <div class="flex items-center justify-between">
                        <p class="text-xs font-medium text-slate-500">Terbit</p>
                        <CheckCircle class="h-4 w-4 text-emerald-500" />
                    </div>
                    <p class="mt-2 text-2xl font-bold text-slate-900 tabular-nums">{{ stats.published }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4">
                    <div class="flex items-center justify-between">
                        <p class="text-xs font-medium text-slate-500">Draft</p>
                        <FileText class="h-4 w-4 text-amber-500" />
                    </div>
                    <p class="mt-2 text-2xl font-bold text-slate-900 tabular-nums">{{ stats.draft }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4">
                    <div class="flex items-center justify-between">
                        <p class="text-xs font-medium text-slate-500">Total dibaca</p>
                        <TrendingUp class="h-4 w-4 text-sky-500" />
                    </div>
                    <p class="mt-2 text-2xl font-bold text-slate-900 tabular-nums">{{ stats.total_views }}</p>
                </div>
            </div>

            <div class="grid gap-5 xl:grid-cols-5">
                <!-- ============ Generate AI ============ -->
                <section id="generate-card" class="rounded-xl border border-slate-200 bg-white xl:col-span-3">
                    <div class="flex items-center gap-2.5 border-b border-slate-100 px-5 py-4">
                        <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-violet-100">
                            <Sparkles class="h-4 w-4 text-violet-600" />
                        </div>
                        <div>
                            <h2 class="text-base font-semibold text-slate-900">Generate Artikel dengan AI</h2>
                            <p class="text-xs text-slate-500">Hasil masuk sebagai draft — review dulu sebelum terbit.</p>
                        </div>
                    </div>

                    <div class="space-y-4 p-5">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="sm:col-span-2">
                                <label for="gen-keyword" class="mb-1 block text-xs font-semibold text-slate-600">Kata kunci utama *</label>
                                <input
                                    id="gen-keyword"
                                    v-model="generateForm.keyword"
                                    type="text"
                                    placeholder="mis. cara mengelola stok warung makan"
                                    class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-violet-400 focus:ring-2 focus:ring-violet-100 focus:outline-none"
                                />
                            </div>
                            <div>
                                <label for="gen-strategy" class="mb-1 block text-xs font-semibold text-slate-600">Strategi SEO</label>
                                <select
                                    id="gen-strategy"
                                    v-model="generateForm.strategy"
                                    class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm focus:border-violet-400 focus:outline-none"
                                >
                                    <option v-for="(label, key) in strategies" :key="key" :value="key">{{ label }}</option>
                                </select>
                            </div>
                            <div>
                                <label for="gen-length" class="mb-1 block text-xs font-semibold text-slate-600">Panjang artikel</label>
                                <select
                                    id="gen-length"
                                    v-model="generateForm.length"
                                    class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm focus:border-violet-400 focus:outline-none"
                                >
                                    <option value="short">Pendek (800–1200 kata)</option>
                                    <option value="medium">Sedang (1200–1800 kata)</option>
                                    <option value="long">Panjang (1800–2500 kata)</option>
                                </select>
                            </div>
                            <div>
                                <label for="gen-audience" class="mb-1 block text-xs font-semibold text-slate-600">Target pembaca (opsional)</label>
                                <input
                                    id="gen-audience"
                                    v-model="generateForm.audience"
                                    type="text"
                                    placeholder="mis. pemilik laundry pemula"
                                    class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-violet-400 focus:outline-none"
                                />
                            </div>
                            <div>
                                <label for="gen-category" class="mb-1 block text-xs font-semibold text-slate-600">Kategori (opsional)</label>
                                <input
                                    id="gen-category"
                                    v-model="generateForm.category"
                                    type="text"
                                    placeholder="mis. Manajemen Stok"
                                    class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-violet-400 focus:outline-none"
                                />
                            </div>
                            <div class="sm:col-span-2">
                                <label for="gen-outline" class="mb-1 block text-xs font-semibold text-slate-600">Kerangka / poin wajib (opsional)</label>
                                <textarea
                                    id="gen-outline"
                                    v-model="generateForm.outline"
                                    rows="2"
                                    placeholder="Poin-poin yang harus dibahas, satu per baris"
                                    class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-violet-400 focus:outline-none"
                                ></textarea>
                            </div>
                        </div>

                        <div v-if="generateError" class="flex items-start gap-2 rounded-lg bg-red-50 px-3 py-2.5 text-sm text-red-700">
                            <XCircle class="mt-0.5 h-4 w-4 shrink-0" />
                            <span>{{ generateError }}</span>
                        </div>

                        <div v-if="completedInfo" class="flex items-start gap-2 rounded-lg bg-emerald-50 px-3 py-2.5 text-sm text-emerald-700">
                            <CheckCircle class="mt-0.5 h-4 w-4 shrink-0" />
                            <span>
                                Artikel "<strong>{{ completedInfo.title }}</strong>" selesai dibuat sebagai draft.
                                <Link :href="`/superadmin/articles/${completedInfo.articleId}/edit`" class="font-semibold underline">
                                    Review & edit sekarang →
                                </Link>
                            </span>
                        </div>

                        <div class="flex items-center gap-3">
                            <button
                                type="button"
                                :disabled="isGeneratingArticle"
                                class="inline-flex items-center gap-2 rounded-xl bg-violet-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-violet-700 disabled:cursor-not-allowed disabled:opacity-60"
                                @click="submitGenerate"
                            >
                                <Loader2 v-if="isGeneratingArticle" class="h-4 w-4 animate-spin" />
                                <Send v-else class="h-4 w-4" />
                                {{ isGeneratingArticle ? 'Sedang membuat artikel…' : 'Generate Artikel' }}
                            </button>
                            <p v-if="isGeneratingArticle" class="text-xs text-slate-500">Biasanya 1–3 menit. Boleh tinggal — hasil masuk ke daftar draft.</p>
                        </div>
                    </div>
                </section>

                <!-- ============ Saran topik ============ -->
                <section class="rounded-xl border border-slate-200 bg-white xl:col-span-2">
                    <div class="flex items-center gap-2.5 border-b border-slate-100 px-5 py-4">
                        <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-amber-100">
                            <Lightbulb class="h-4 w-4 text-amber-600" />
                        </div>
                        <div>
                            <h2 class="text-base font-semibold text-slate-900">Saran Topik</h2>
                            <p class="text-xs text-slate-500">AI mengusulkan topik yang belum pernah ditulis.</p>
                        </div>
                    </div>

                    <div class="space-y-3 p-5">
                        <div class="flex gap-2">
                            <input
                                v-model="topicFocus"
                                type="text"
                                placeholder="Fokus tema (opsional), mis. laundry"
                                class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-amber-400 focus:outline-none"
                                @keyup.enter="requestTopics"
                            />
                            <button
                                type="button"
                                :disabled="isSuggestingTopics"
                                class="inline-flex shrink-0 items-center gap-1.5 rounded-xl bg-amber-500 px-3.5 py-2 text-sm font-semibold text-white hover:bg-amber-600 disabled:opacity-60"
                                @click="requestTopics"
                            >
                                <Loader2 v-if="isSuggestingTopics" class="h-4 w-4 animate-spin" />
                                <Bot v-else class="h-4 w-4" />
                                Minta Ide
                            </button>
                        </div>

                        <div v-if="topicError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ topicError }}</div>

                        <p v-if="isSuggestingTopics" class="text-xs text-slate-500">AI sedang menyusun ide topik…</p>

                        <ul v-if="topics.length" class="max-h-[420px] space-y-2 overflow-y-auto">
                            <li v-for="(t, i) in topics" :key="i" class="rounded-lg border border-slate-200 p-3">
                                <p class="text-sm font-semibold text-slate-900">{{ t.title }}</p>
                                <p class="mt-0.5 text-xs text-slate-500">{{ t.reason }}</p>
                                <div class="mt-2 flex items-center justify-between gap-2">
                                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-600">
                                        {{ strategies[t.strategy] ?? t.strategy }}
                                    </span>
                                    <button
                                        type="button"
                                        class="rounded-lg bg-violet-50 px-2.5 py-1 text-xs font-semibold text-violet-700 hover:bg-violet-100"
                                        @click="useTopic(t)"
                                    >
                                        Pakai topik ini
                                    </button>
                                </div>
                            </li>
                        </ul>
                    </div>
                </section>
            </div>

            <!-- ============ Daftar artikel ============ -->
            <section class="overflow-hidden rounded-xl border border-slate-200 bg-white">
                <div class="flex flex-col gap-3 border-b border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <h2 class="text-base font-semibold text-slate-900">Daftar Artikel</h2>
                    <div class="flex items-center gap-2">
                        <div class="relative">
                            <Search class="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-slate-400" />
                            <input
                                v-model="search"
                                type="text"
                                placeholder="Cari judul / kata kunci…"
                                class="w-56 rounded-lg border border-slate-200 py-2 pr-3 pl-9 text-sm focus:border-slate-400 focus:outline-none"
                                @keyup.enter="applyFilter"
                            />
                        </div>
                        <select
                            v-model="statusFilter"
                            class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm focus:outline-none"
                            @change="applyFilter"
                        >
                            <option value="">Semua status</option>
                            <option value="draft">Draft</option>
                            <option value="scheduled">Terjadwal</option>
                            <option value="published">Terbit</option>
                        </select>
                    </div>
                </div>

                <div v-if="articles.data.length === 0" class="px-5 py-16 text-center">
                    <Newspaper class="mx-auto h-8 w-8 text-slate-300" />
                    <p class="mt-3 text-sm text-slate-500">Belum ada artikel. Generate dengan AI atau tulis manual.</p>
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-slate-50 text-xs font-medium tracking-wide text-slate-500 uppercase">
                            <tr>
                                <th scope="col" class="px-5 py-3">Artikel</th>
                                <th scope="col" class="px-3 py-3">Status</th>
                                <th scope="col" class="px-3 py-3">Kategori</th>
                                <th scope="col" class="px-3 py-3">Dibaca</th>
                                <th scope="col" class="px-3 py-3">Terbit</th>
                                <th scope="col" class="px-5 py-3 text-right"><span class="sr-only">Aksi</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="article in articles.data" :key="article.id" class="align-middle hover:bg-slate-50/60">
                                <td class="max-w-[340px] px-5 py-3.5">
                                    <Link :href="`/superadmin/articles/${article.id}/edit`" class="block">
                                        <p class="truncate font-semibold text-slate-900 hover:text-violet-700">{{ article.title }}</p>
                                        <p class="mt-0.5 flex items-center gap-1.5 text-xs text-slate-400">
                                            <span class="truncate">/blog/{{ article.slug }}</span>
                                            <span v-if="article.generated_by_ai" class="inline-flex shrink-0 items-center gap-0.5 rounded-full bg-violet-50 px-1.5 py-0.5 font-medium text-violet-600">
                                                <Sparkles class="h-2.5 w-2.5" /> AI
                                            </span>
                                            <span v-if="article.is_pillar" class="shrink-0 rounded-full bg-emerald-50 px-1.5 py-0.5 font-medium text-emerald-600">Pilar</span>
                                        </p>
                                    </Link>
                                </td>
                                <td class="px-3 py-3.5">
                                    <span :class="['inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset', statusBadge(article.status)]">
                                        {{ statusLabel(article.status) }}
                                    </span>
                                </td>
                                <td class="px-3 py-3.5 text-slate-600">{{ article.category ?? '—' }}</td>
                                <td class="px-3 py-3.5 text-slate-600 tabular-nums">{{ article.views }}</td>
                                <td class="px-3 py-3.5 text-slate-600">{{ formatDate(article.published_at) }}</td>
                                <td class="px-5 py-3.5">
                                    <div class="flex items-center justify-end gap-1">
                                        <button
                                            v-if="article.status === 'draft'"
                                            type="button"
                                            class="rounded-lg px-2 py-1.5 text-xs font-semibold text-emerald-700 hover:bg-emerald-50"
                                            title="Terbitkan sekarang"
                                            @click="quickPublish(article)"
                                        >
                                            Terbitkan
                                        </button>
                                        <a
                                            :href="article.public_url"
                                            target="_blank"
                                            rel="noopener"
                                            class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700"
                                            title="Lihat / pratinjau"
                                        >
                                            <Eye class="h-4 w-4" />
                                        </a>
                                        <Link
                                            :href="`/superadmin/articles/${article.id}/edit`"
                                            class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700"
                                            title="Edit"
                                        >
                                            <Pencil class="h-4 w-4" />
                                        </Link>
                                        <button
                                            type="button"
                                            class="rounded-lg p-1.5 text-slate-400 hover:bg-red-50 hover:text-red-600"
                                            title="Hapus"
                                            @click="deleteArticle(article)"
                                        >
                                            <Trash2 class="h-4 w-4" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div v-if="articles.links && articles.links.length > 3" class="flex justify-center border-t border-slate-100 px-5 py-4">
                    <nav class="flex items-center gap-1">
                        <Link
                            v-for="link in articles.links"
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
