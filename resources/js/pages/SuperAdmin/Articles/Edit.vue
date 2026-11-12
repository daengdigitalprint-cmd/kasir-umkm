<script setup lang="ts">
import SuperAdminLayout from '@/layouts/SuperAdminLayout.vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft,
    CalendarClock,
    CheckCircle2,
    CircleAlert,
    Eye,
    Globe,
    ImagePlus,
    Info,
    Plus,
    Save,
    Sparkles,
    Trash2,
    Undo2,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface FaqItem {
    question: string;
    answer: string;
}

interface ArticleData {
    id: number;
    title: string;
    slug: string;
    excerpt: string | null;
    content_markdown: string | null;
    featured_image: string | null;
    featured_image_url: string | null;
    featured_image_alt: string | null;
    suggested_image_alt: string;
    category: string | null;
    tags: string[] | null;
    faq: FaqItem[] | null;
    focus_keyword: string | null;
    meta_title: string | null;
    meta_description: string | null;
    canonical_url: string | null;
    status: string;
    published_at: string | null;
    is_pillar: boolean;
    strategy: string | null;
    generated_by_ai: boolean;
    ai_model: string | null;
    views: number;
    public_url: string;
}

interface Props {
    article: ArticleData | null;
    strategies: Record<string, string>;
}

const props = defineProps<Props>();

const isNew = computed(() => props.article === null);

const form = useForm({
    title: props.article?.title ?? '',
    slug: props.article?.slug ?? '',
    excerpt: props.article?.excerpt ?? '',
    content_markdown: props.article?.content_markdown ?? '',
    featured_image: null as File | null,
    featured_image_alt: props.article?.featured_image_alt ?? '',
    remove_featured_image: false,
    category: props.article?.category ?? '',
    tags: (props.article?.tags ?? []).join(', '),
    faq: (props.article?.faq ?? []) as FaqItem[],
    focus_keyword: props.article?.focus_keyword ?? '',
    meta_title: props.article?.meta_title ?? '',
    meta_description: props.article?.meta_description ?? '',
    canonical_url: props.article?.canonical_url ?? '',
    strategy: props.article?.strategy ?? '',
    is_pillar: props.article?.is_pillar ?? false,
});

const submit = () => {
    const base = (data: Record<string, any>) => ({
        ...data,
        tags: data.tags
            .split(',')
            .map((t: string) => t.trim())
            .filter(Boolean),
        strategy: data.strategy || null,
        canonical_url: data.canonical_url || null,
    });

    if (isNew.value) {
        form.transform(base).post('/superadmin/articles', { preserveScroll: true, forceFormData: true });
        return;
    }

    // Unggahan file tidak bisa lewat PUT, jadi dikirim POST dengan _method.
    form.transform((data) => ({ ...base(data), _method: 'put' })).post(`/superadmin/articles/${props.article!.id}`, {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            form.featured_image = null;
            form.remove_featured_image = false;
            if (fileInput.value) fileInput.value.value = '';
            previewUrl.value = null;
        },
    });
};

/* ============ Thumbnail ============ */
const fileInput = ref<HTMLInputElement | null>(null);
const previewUrl = ref<string | null>(null);
const imageError = ref<string | null>(null);

/** Gambar yang ditampilkan: pratinjau unggahan baru, atau yang tersimpan. */
const shownImage = computed(() => {
    if (previewUrl.value) return previewUrl.value;
    if (form.remove_featured_image) return null;
    return props.article?.featured_image_url ?? null;
});

/** Alt otomatis yang akan dipakai server bila kolom alt dikosongkan. */
const autoAlt = computed(() => {
    const title = form.title.trim();
    const kw = form.focus_keyword.trim();
    if (!title) return props.article?.suggested_image_alt ?? '';
    return kw && !title.toLowerCase().includes(kw.toLowerCase()) ? `${title} - ${kw}` : title;
});

const pickImage = (e: Event) => {
    const file = (e.target as HTMLInputElement).files?.[0] ?? null;
    imageError.value = null;

    if (!file) {
        form.featured_image = null;
        previewUrl.value = null;
        return;
    }

    if (file.size > 2 * 1024 * 1024) {
        imageError.value = 'Ukuran gambar maksimal 2 MB.';
        if (fileInput.value) fileInput.value.value = '';
        return;
    }

    form.featured_image = file;
    form.remove_featured_image = false;
    previewUrl.value = URL.createObjectURL(file);
};

const removeImage = () => {
    form.featured_image = null;
    form.remove_featured_image = true;
    previewUrl.value = null;
    imageError.value = null;
    if (fileInput.value) fileInput.value.value = '';
};

/* ============ Status / publish ============ */
const scheduleAt = ref('');

const setStatus = (status: string) => {
    router.post(
        `/superadmin/articles/${props.article!.id}/status`,
        { status, published_at: status === 'scheduled' ? scheduleAt.value : undefined },
        { preserveScroll: true },
    );
};

const statusLabel = computed(
    () => ({ published: 'Terbit', scheduled: 'Terjadwal', draft: 'Draft' })[props.article?.status ?? 'draft'] ?? 'Draft',
);

/* ============ FAQ repeater ============ */
const addFaq = () => form.faq.push({ question: '', answer: '' });
const removeFaq = (i: number) => form.faq.splice(i, 1);

/* ============ Checklist SEO ============ */
interface Check {
    label: string;
    ok: boolean;
}

const wordCount = computed(() => (form.content_markdown.trim() ? form.content_markdown.trim().split(/\s+/).length : 0));

const seoChecks = computed<Check[]>(() => {
    const kw = form.focus_keyword.trim().toLowerCase();
    const metaTitle = (form.meta_title || form.title).toLowerCase();
    const content = form.content_markdown.toLowerCase();
    const first150 = content.slice(0, 1000);

    return [
        { label: `Meta title ≤ 60 karakter (${(form.meta_title || form.title).length})`, ok: (form.meta_title || form.title).length > 0 && (form.meta_title || form.title).length <= 60 },
        {
            label: `Meta description 120–158 karakter (${form.meta_description.length})`,
            ok: form.meta_description.length >= 120 && form.meta_description.length <= 158,
        },
        { label: 'Kata kunci ada di meta title', ok: kw !== '' && metaTitle.includes(kw) },
        { label: 'Kata kunci ada di awal artikel', ok: kw !== '' && first150.includes(kw) },
        { label: `Minimal 600 kata (${wordCount.value})`, ok: wordCount.value >= 600 },
        { label: 'Ada sub-heading (##)', ok: /(^|\n)##\s/.test(form.content_markdown) },
        { label: 'Ada internal link (/blog/ atau /)', ok: /\]\((\/blog\/|\/)\S*\)/.test(form.content_markdown) },
        { label: 'Ada FAQ (untuk FAQ schema)', ok: form.faq.length >= 2 },
        { label: 'Ada ringkasan (excerpt)', ok: form.excerpt.trim().length > 20 },
        { label: 'Ada thumbnail (untuk og:image)', ok: shownImage.value !== null },
    ];
});

const seoScore = computed(() => {
    const ok = seoChecks.value.filter((c) => c.ok).length;
    return Math.round((ok / seoChecks.value.length) * 100);
});
</script>

<template>
    <SuperAdminLayout :title="isNew ? 'Tulis Artikel' : 'Edit Artikel'" active-tab="articles">
        <form class="space-y-6" @submit.prevent="submit">
            <header class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-3">
                    <Link
                        href="/superadmin/articles"
                        class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50"
                    >
                        <ArrowLeft class="h-4 w-4" /> Kembali
                    </Link>
                    <div>
                        <h1 class="text-xl font-bold tracking-tight text-slate-900">{{ isNew ? 'Tulis Artikel Baru' : 'Edit Artikel' }}</h1>
                        <p v-if="article?.generated_by_ai" class="flex items-center gap-1 text-xs text-violet-600">
                            <Sparkles class="h-3 w-3" /> Dibuat oleh AI{{ article.ai_model ? ` (${article.ai_model})` : '' }} — review sebelum terbit
                        </p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <a
                        v-if="article"
                        :href="article.public_url"
                        target="_blank"
                        rel="noopener"
                        class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
                    >
                        <Eye class="h-4 w-4" /> Pratinjau
                    </a>
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="inline-flex items-center gap-1.5 rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 disabled:opacity-60"
                    >
                        <Save class="h-4 w-4" /> Simpan
                    </button>
                </div>
            </header>

            <div class="grid gap-5 xl:grid-cols-3">
                <!-- ============ Kolom utama ============ -->
                <div class="space-y-5 xl:col-span-2">
                    <section class="space-y-4 rounded-xl border border-slate-200 bg-white p-5">
                        <div>
                            <label for="f-title" class="mb-1 block text-xs font-semibold text-slate-600">Judul (H1) *</label>
                            <input
                                id="f-title"
                                v-model="form.title"
                                type="text"
                                required
                                class="w-full rounded-lg border border-slate-200 px-3 py-2.5 text-base font-semibold focus:border-violet-400 focus:ring-2 focus:ring-violet-100 focus:outline-none"
                            />
                            <p v-if="form.errors.title" class="mt-1 text-xs text-red-600">{{ form.errors.title }}</p>
                        </div>

                        <div>
                            <label for="f-slug" class="mb-1 block text-xs font-semibold text-slate-600">Slug URL</label>
                            <div class="flex items-center gap-1 text-sm">
                                <span class="shrink-0 text-slate-400">/blog/</span>
                                <input
                                    id="f-slug"
                                    v-model="form.slug"
                                    type="text"
                                    placeholder="otomatis dari judul jika kosong"
                                    class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-violet-400 focus:outline-none"
                                />
                            </div>
                        </div>

                        <div>
                            <label for="f-excerpt" class="mb-1 block text-xs font-semibold text-slate-600">Ringkasan (excerpt)</label>
                            <textarea
                                id="f-excerpt"
                                v-model="form.excerpt"
                                rows="2"
                                class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-violet-400 focus:outline-none"
                            ></textarea>
                        </div>

                        <div>
                            <div class="mb-1 flex items-center justify-between">
                                <label for="f-content" class="block text-xs font-semibold text-slate-600">Isi artikel (markdown) *</label>
                                <span class="text-xs text-slate-400">{{ wordCount }} kata</span>
                            </div>
                            <textarea
                                id="f-content"
                                v-model="form.content_markdown"
                                rows="24"
                                required
                                class="w-full rounded-lg border border-slate-200 px-3 py-2.5 font-mono text-sm leading-relaxed focus:border-violet-400 focus:ring-2 focus:ring-violet-100 focus:outline-none"
                                placeholder="Tulis dengan markdown: ## Sub judul, **tebal**, [link](/blog/slug-artikel), dll. Jangan tulis H1 — judul sudah dari field di atas."
                            ></textarea>
                            <p v-if="form.errors.content_markdown" class="mt-1 text-xs text-red-600">{{ form.errors.content_markdown }}</p>
                        </div>
                    </section>

                    <!-- FAQ -->
                    <section class="rounded-xl border border-slate-200 bg-white p-5">
                        <div class="mb-3 flex items-center justify-between">
                            <div>
                                <h2 class="text-sm font-semibold text-slate-900">FAQ (FAQ Schema)</h2>
                                <p class="text-xs text-slate-500">Tampil di akhir artikel + markup schema.org untuk hasil pencarian.</p>
                            </div>
                            <button
                                type="button"
                                class="inline-flex items-center gap-1 rounded-lg bg-slate-100 px-2.5 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-200"
                                @click="addFaq"
                            >
                                <Plus class="h-3.5 w-3.5" /> Tambah
                            </button>
                        </div>

                        <p v-if="form.faq.length === 0" class="py-4 text-center text-sm text-slate-400">Belum ada FAQ.</p>

                        <div v-for="(f, i) in form.faq" :key="i" class="mb-3 rounded-lg border border-slate-200 p-3">
                            <div class="flex items-start gap-2">
                                <div class="flex-1 space-y-2">
                                    <input
                                        v-model="f.question"
                                        type="text"
                                        placeholder="Pertanyaan"
                                        class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm font-medium focus:border-violet-400 focus:outline-none"
                                    />
                                    <textarea
                                        v-model="f.answer"
                                        rows="2"
                                        placeholder="Jawaban ringkas"
                                        class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-violet-400 focus:outline-none"
                                    ></textarea>
                                </div>
                                <button type="button" class="rounded-lg p-1.5 text-slate-400 hover:bg-red-50 hover:text-red-600" @click="removeFaq(i)">
                                    <Trash2 class="h-4 w-4" />
                                </button>
                            </div>
                        </div>
                    </section>
                </div>

                <!-- ============ Sidebar ============ -->
                <div class="space-y-5">
                    <!-- Status & publish -->
                    <section v-if="article" class="rounded-xl border border-slate-200 bg-white p-5">
                        <h2 class="mb-3 text-sm font-semibold text-slate-900">Status Publikasi</h2>
                        <div class="mb-3 flex items-center justify-between text-sm">
                            <span class="text-slate-500">Status saat ini</span>
                            <span
                                :class="[
                                    'rounded-full px-2.5 py-0.5 text-xs font-semibold',
                                    article.status === 'published'
                                        ? 'bg-emerald-50 text-emerald-700'
                                        : article.status === 'scheduled'
                                          ? 'bg-amber-50 text-amber-700'
                                          : 'bg-slate-100 text-slate-600',
                                ]"
                                >{{ statusLabel }}</span
                            >
                        </div>
                        <p v-if="article.published_at" class="mb-3 text-xs text-slate-500">
                            Waktu terbit: {{ new Date(article.published_at).toLocaleString('id-ID') }}
                        </p>

                        <div class="space-y-2">
                            <button
                                v-if="article.status !== 'published'"
                                type="button"
                                class="inline-flex w-full items-center justify-center gap-1.5 rounded-xl bg-emerald-600 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-700"
                                @click="setStatus('published')"
                            >
                                <Globe class="h-4 w-4" /> Terbitkan Sekarang
                            </button>

                            <div v-if="article.status !== 'published'" class="flex gap-2">
                                <input
                                    v-model="scheduleAt"
                                    type="datetime-local"
                                    class="w-full rounded-lg border border-slate-200 px-2.5 py-2 text-xs focus:outline-none"
                                />
                                <button
                                    type="button"
                                    :disabled="!scheduleAt"
                                    class="inline-flex shrink-0 items-center gap-1 rounded-xl bg-amber-500 px-3 py-2 text-xs font-semibold text-white hover:bg-amber-600 disabled:opacity-50"
                                    @click="setStatus('scheduled')"
                                >
                                    <CalendarClock class="h-3.5 w-3.5" /> Jadwalkan
                                </button>
                            </div>

                            <button
                                v-if="article.status !== 'draft'"
                                type="button"
                                class="inline-flex w-full items-center justify-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50"
                                @click="setStatus('draft')"
                            >
                                <Undo2 class="h-4 w-4" /> Kembalikan ke Draft
                            </button>
                        </div>
                        <p v-if="article.views > 0" class="mt-3 text-xs text-slate-400">Sudah dibaca {{ article.views }} kali.</p>
                    </section>

                    <!-- Thumbnail artikel -->
                    <section class="space-y-3 rounded-xl border border-slate-200 bg-white p-5">
                        <div>
                            <h2 class="text-sm font-semibold text-slate-900">Thumbnail Artikel</h2>
                            <p class="text-xs text-slate-500">Tampil di daftar artikel, halaman artikel, dan saat dibagikan ke sosial media.</p>
                        </div>

                        <div
                            v-if="shownImage"
                            class="relative overflow-hidden rounded-lg border border-slate-200 bg-slate-50"
                        >
                            <img :src="shownImage" :alt="form.featured_image_alt || autoAlt" class="aspect-[16/9] w-full object-cover" />
                            <button
                                type="button"
                                class="absolute top-2 right-2 inline-flex items-center gap-1 rounded-lg bg-white/95 px-2 py-1 text-xs font-semibold text-red-600 ring-1 ring-slate-200 hover:bg-red-50"
                                @click="removeImage"
                            >
                                <Trash2 class="h-3.5 w-3.5" /> Hapus
                            </button>
                            <span
                                v-if="previewUrl"
                                class="absolute bottom-2 left-2 rounded-lg bg-violet-600 px-2 py-0.5 text-[11px] font-semibold text-white"
                            >
                                Belum disimpan
                            </span>
                        </div>

                        <label
                            v-else
                            class="flex cursor-pointer flex-col items-center justify-center gap-1.5 rounded-lg border-2 border-dashed border-slate-200 bg-slate-50 px-4 py-8 text-center transition hover:border-violet-300 hover:bg-violet-50/40"
                        >
                            <ImagePlus class="h-6 w-6 text-slate-400" />
                            <span class="text-sm font-semibold text-slate-700">Unggah thumbnail</span>
                            <span class="text-xs text-slate-500">JPG, PNG, atau WebP · maks 2 MB · rasio 16:9</span>
                            <input ref="fileInput" type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="pickImage" />
                        </label>

                        <div v-if="shownImage" class="flex gap-2">
                            <label
                                class="inline-flex flex-1 cursor-pointer items-center justify-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50"
                            >
                                <ImagePlus class="h-3.5 w-3.5" /> Ganti gambar
                                <input ref="fileInput" type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="pickImage" />
                            </label>
                        </div>

                        <p v-if="imageError" class="text-xs text-red-600">{{ imageError }}</p>
                        <p v-if="form.errors.featured_image" class="text-xs text-red-600">{{ form.errors.featured_image }}</p>

                        <div>
                            <div class="mb-1 flex items-center justify-between">
                                <label for="f-alt" class="block text-xs font-semibold text-slate-600">Teks alt gambar</label>
                                <span class="text-[11px] text-slate-400">{{ form.featured_image_alt.length }}/160</span>
                            </div>
                            <input
                                id="f-alt"
                                v-model="form.featured_image_alt"
                                type="text"
                                maxlength="160"
                                :placeholder="autoAlt"
                                class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-violet-400 focus:outline-none"
                            />
                            <p class="mt-1 flex items-start gap-1 text-[11px] text-slate-500">
                                <Info class="mt-0.5 h-3 w-3 shrink-0" />
                                <span v-if="form.featured_image_alt.trim()">Dipakai pembaca layar &amp; mesin pencari untuk memahami gambar.</span>
                                <span v-else>Dikosongkan? Akan diisi otomatis: <strong class="font-semibold text-slate-700">{{ autoAlt || '—' }}</strong></span>
                            </p>
                        </div>
                    </section>

                    <!-- Checklist SEO -->
                    <section class="rounded-xl border border-slate-200 bg-white p-5">
                        <div class="mb-3 flex items-center justify-between">
                            <h2 class="text-sm font-semibold text-slate-900">Checklist SEO</h2>
                            <span
                                :class="[
                                    'rounded-full px-2.5 py-0.5 text-xs font-bold',
                                    seoScore >= 80 ? 'bg-emerald-50 text-emerald-700' : seoScore >= 50 ? 'bg-amber-50 text-amber-700' : 'bg-red-50 text-red-700',
                                ]"
                                >{{ seoScore }}%</span
                            >
                        </div>
                        <ul class="space-y-1.5">
                            <li v-for="(check, i) in seoChecks" :key="i" class="flex items-start gap-2 text-xs">
                                <CheckCircle2 v-if="check.ok" class="mt-0.5 h-3.5 w-3.5 shrink-0 text-emerald-500" />
                                <CircleAlert v-else class="mt-0.5 h-3.5 w-3.5 shrink-0 text-slate-300" />
                                <span :class="check.ok ? 'text-slate-700' : 'text-slate-400'">{{ check.label }}</span>
                            </li>
                        </ul>
                    </section>

                    <!-- Meta SEO -->
                    <section class="space-y-3 rounded-xl border border-slate-200 bg-white p-5">
                        <h2 class="text-sm font-semibold text-slate-900">SEO On-Page</h2>

                        <div>
                            <label for="f-keyword" class="mb-1 block text-xs font-semibold text-slate-600">Kata kunci utama</label>
                            <input
                                id="f-keyword"
                                v-model="form.focus_keyword"
                                type="text"
                                class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-violet-400 focus:outline-none"
                            />
                        </div>

                        <div>
                            <div class="mb-1 flex items-center justify-between">
                                <label for="f-meta-title" class="block text-xs font-semibold text-slate-600">Meta title</label>
                                <span :class="['text-[11px]', form.meta_title.length > 60 ? 'text-red-500' : 'text-slate-400']">{{ form.meta_title.length }}/60</span>
                            </div>
                            <input
                                id="f-meta-title"
                                v-model="form.meta_title"
                                type="text"
                                maxlength="70"
                                :placeholder="form.title || 'Judul untuk hasil pencarian'"
                                class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-violet-400 focus:outline-none"
                            />
                        </div>

                        <div>
                            <div class="mb-1 flex items-center justify-between">
                                <label for="f-meta-desc" class="block text-xs font-semibold text-slate-600">Meta description</label>
                                <span :class="['text-[11px]', form.meta_description.length > 158 ? 'text-red-500' : 'text-slate-400']"
                                    >{{ form.meta_description.length }}/158</span
                                >
                            </div>
                            <textarea
                                id="f-meta-desc"
                                v-model="form.meta_description"
                                rows="3"
                                maxlength="300"
                                class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-violet-400 focus:outline-none"
                            ></textarea>
                        </div>

                        <div>
                            <label for="f-canonical" class="mb-1 block text-xs font-semibold text-slate-600">Canonical URL (opsional)</label>
                            <input
                                id="f-canonical"
                                v-model="form.canonical_url"
                                type="url"
                                placeholder="Kosongkan = URL artikel ini"
                                class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-violet-400 focus:outline-none"
                            />
                            <p v-if="form.errors.canonical_url" class="mt-1 text-xs text-red-600">{{ form.errors.canonical_url }}</p>
                        </div>
                    </section>

                    <!-- Taksonomi -->
                    <section class="space-y-3 rounded-xl border border-slate-200 bg-white p-5">
                        <h2 class="text-sm font-semibold text-slate-900">Kategori & Strategi</h2>

                        <div>
                            <label for="f-category" class="mb-1 block text-xs font-semibold text-slate-600">Kategori</label>
                            <input
                                id="f-category"
                                v-model="form.category"
                                type="text"
                                placeholder="mis. Manajemen Stok"
                                class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-violet-400 focus:outline-none"
                            />
                        </div>

                        <div>
                            <label for="f-tags" class="mb-1 block text-xs font-semibold text-slate-600">Tag (pisahkan dengan koma)</label>
                            <input
                                id="f-tags"
                                v-model="form.tags"
                                type="text"
                                placeholder="kasir, stok, umkm"
                                class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-violet-400 focus:outline-none"
                            />
                        </div>

                        <div>
                            <label for="f-strategy" class="mb-1 block text-xs font-semibold text-slate-600">Strategi SEO</label>
                            <select
                                id="f-strategy"
                                v-model="form.strategy"
                                class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm focus:outline-none"
                            >
                                <option value="">— Tidak diset —</option>
                                <option v-for="(label, key) in strategies" :key="key" :value="key">{{ label }}</option>
                            </select>
                        </div>

                        <label class="flex items-center gap-2 text-sm text-slate-700">
                            <input v-model="form.is_pillar" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-violet-600 focus:ring-violet-400" />
                            Artikel pilar (panduan utama)
                        </label>
                    </section>
                </div>
            </div>
        </form>
    </SuperAdminLayout>
</template>
