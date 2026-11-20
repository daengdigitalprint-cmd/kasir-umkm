<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { ArrowLeft, Ban, Download, Eye, EyeOff, Search, Trash2, Upload, CheckCircle2, AlertTriangle, Loader2, X } from 'lucide-vue-next';

interface LineRow {
    id: number;
    system_quantity: string;
    counted_quantity: string | null;
    movement_during_count: string;
    variance_quantity: string;
    unit_cost: string;
    variance_value: string;
    line_notes: string | null;
    product: {
        id: number;
        name: string;
        sku: string | null;
        barcode: string | null;
        unit: string | null;
        allows_decimal: boolean;
        product_category: { id: number; name: string } | null;
    } | null;
    product_variant: { id: number; name: string; sku: string | null } | null;
}

interface OpnameDetail {
    id: number;
    adjustment_number: string;
    status: string;
    notes: string | null;
    counted_at: string | null;
    blind_count: boolean;
    posted_at: string | null;
    cancelled_at: string | null;
    cancellation_reason: string | null;
    total_variance_value: string;
    lines: LineRow[];
    created_by: { id: number; name: string } | null;
    posted_by: { id: number; name: string } | null;
    cancelled_by: { id: number; name: string } | null;
}

interface ImportSummary {
    data_rows: number;
    matched_rows: number;
    error_rows: number;
    blank_rows?: number;
    missing_lines: number;
    counted_lines: number;
    total_variance_value: string;
}

interface ImportProblem {
    row: number;
    message: string;
    name: string | null;
    variant: string | null;
    sku: string | null;
    counted: string | null;
    note: string | null;
}

interface ImportPayload {
    id: number;
    file_name: string;
    status: 'pending' | 'processing' | 'completed' | 'failed';
    status_label: string;
    total_rows: number;
    processed_rows: number;
    matched_rows: number;
    error_rows: number;
    error_message: string | null;
    applied_at: string | null;
    summary: ImportSummary | null;
    can_apply: boolean;
}

interface ImportDetail extends ImportPayload {
    errors: string[];
    problems?: ImportProblem[];
}

interface MovedLine {
    line_id: number;
    name: string;
    movement: string;
}

const props = defineProps<{
    opname: OpnameDetail;
    statusLabel: string;
    canPost: boolean;
    canCancel: boolean;
    canDelete?: boolean;
    canApprove?: boolean;
    segregationBlocked?: boolean;
    imports: ImportPayload[];
    excelMovedLines?: MovedLine[];
}>();

const page = usePage();
const canViewCost = computed(() => Boolean((page.props as { abilities?: { view_cost?: boolean } }).abilities?.view_cost));

const isPendingApproval = computed(() => props.opname.status === 'pending_approval');
const approveForm = useForm({});

function submitApprove() {
    approveForm.post(`/stock-opnames/${props.opname.id}/approve`, { preserveScroll: true });
}

const isDraft = computed(() => props.opname.status === 'draft');

// Sesi hitung buta: stok sistem tersembunyi selama sesi berjalan.
const showSystemQty = ref(!isDraft.value || !props.opname.blind_count);
const search = ref('');
const searchInput = ref<HTMLInputElement | null>(null);
const statusFilter = ref<'all' | 'uncounted' | 'counted' | 'variance'>('all');
const categoryFilter = ref<string>('');
const showCancel = ref(false);
const showDelete = ref(false);
const showPostConfirm = ref(false);

// ── Baris hitung: kosong = belum dihitung ──
const toFormValue = (v: string | null) => (v === null || v === undefined ? '' : String(Number(v)));

const draftForm = useForm({
    lines: props.opname.lines.map((l) => ({
        id: l.id,
        counted_quantity: toFormValue(l.counted_quantity),
        line_notes: l.line_notes ?? '',
    })),
});

const postForm = useForm({ uncounted_mode: 'ignore' as 'ignore' | 'zero' });
const cancelForm = useForm({ cancellation_reason: '' });
const deleteForm = useForm({});

const lineMap = computed(() => new Map(props.opname.lines.map((l) => [l.id, l])));
const lineById = (id: number) => lineMap.value.get(id);

const isCounted = (row: { counted_quantity: string }) => String(row.counted_quantity ?? '').trim() !== '';

const countedCount = computed(() => draftForm.lines.filter(isCounted).length);
const totalLines = computed(() => draftForm.lines.length);
const uncountedCount = computed(() => totalLines.value - countedCount.value);
const progressPct = computed(() => (totalLines.value ? Math.round((countedCount.value / totalLines.value) * 100) : 0));

/** Selisih = fisik − (stok saat sesi dibuka + mutasi sampai baris dihitung). null = belum dihitung. */
function variance(rowId: number, counted: string): number | null {
    const line = lineById(rowId);
    if (!line || String(counted ?? '').trim() === '') return null;
    const n = Number(counted);
    if (Number.isNaN(n)) return null;
    return n - (Number(line.system_quantity) + Number(line.movement_during_count ?? 0));
}

function varianceValue(rowId: number, counted: string): number | null {
    const line = lineById(rowId);
    const v = variance(rowId, counted);
    if (!line || v === null) return null;
    return v * Number(line.unit_cost);
}

const hasVariance = (row: { id: number; counted_quantity: string }) => Math.abs(variance(row.id, row.counted_quantity) ?? 0) > 0.00001;

const affectedCount = computed(() => draftForm.lines.filter(hasVariance).length);
const totalValue = computed(() => draftForm.lines.reduce((s, r) => s + (varianceValue(r.id, r.counted_quantity) ?? 0), 0));

// Akurasi dihitung dari produk yang sudah dihitung saja.
const accuracy = computed(() => {
    let totalSystem = 0;
    let totalVariance = 0;
    for (const r of draftForm.lines) {
        const v = variance(r.id, r.counted_quantity);
        if (v === null) continue;
        const line = lineById(r.id);
        totalSystem += Math.abs(Number(line?.system_quantity ?? 0));
        totalVariance += Math.abs(v);
    }
    if (totalSystem === 0) return 100;
    return Math.max(0, (1 - totalVariance / totalSystem) * 100);
});

const categories = computed(() => {
    const names = new Set<string>();
    for (const l of props.opname.lines) {
        names.add(l.product?.product_category?.name ?? 'Tanpa kategori');
    }
    return [...names].sort((a, b) => a.localeCompare(b, 'id'));
});

const haystackOf = (id: number) => {
    const line = lineById(id);
    if (!line?.product) return '';
    return [
        line.product.name,
        line.product.sku ?? '',
        line.product.barcode ?? '',
        line.product_variant?.name ?? '',
        line.product_variant?.sku ?? '',
    ]
        .join(' ')
        .toLowerCase();
};

const visibleRows = computed(() => {
    const q = search.value.trim().toLowerCase();
    return draftForm.lines.filter((row) => {
        if (statusFilter.value === 'uncounted' && isCounted(row)) return false;
        if (statusFilter.value === 'counted' && !isCounted(row)) return false;
        if (statusFilter.value === 'variance' && !hasVariance(row)) return false;
        if (categoryFilter.value) {
            const cat = lineById(row.id)?.product?.product_category?.name ?? 'Tanpa kategori';
            if (cat !== categoryFilter.value) return false;
        }
        return !q || haystackOf(row.id).includes(q);
    });
});

// ── Pindai barcode: Enter di kolom cari → langsung ke kotak jumlah produk itu ──
const qtyInputs = new Map<number, HTMLInputElement>();
function setQtyInput(id: number, el: unknown) {
    if (el instanceof HTMLInputElement) qtyInputs.set(id, el);
    else qtyInputs.delete(id);
}

const scanMessage = ref('');

async function onSearchEnter() {
    const q = search.value.trim().toLowerCase();
    if (!q) return;

    const exact = draftForm.lines.filter((row) => {
        const line = lineById(row.id);
        return [line?.product?.barcode, line?.product?.sku, line?.product_variant?.sku]
            .some((code) => (code ?? '').toLowerCase() === q);
    });
    const target = exact.length === 1 ? exact[0] : visibleRows.value.length === 1 ? visibleRows.value[0] : null;

    if (!target) {
        scanMessage.value = visibleRows.value.length === 0
            ? `Produk «${search.value.trim()}» tidak ada di sesi ini.`
            : 'Lebih dari satu produk cocok — pilih dari daftar.';
        return;
    }

    scanMessage.value = '';
    statusFilter.value = 'all';
    await nextTick();
    const input = qtyInputs.get(target.id);
    input?.focus();
    input?.select();
}

/** Selesai mengisi jumlah (Enter) → kembali ke kolom cari untuk pindai berikutnya */
function onQtyEnter() {
    if (!search.value) return;
    search.value = '';
    scanMessage.value = '';
    searchInput.value?.focus();
}

// ── Simpan otomatis ──
const initialSnapshot = ref(JSON.stringify(draftForm.lines));
const isDirty = computed(() => JSON.stringify(draftForm.lines) !== initialSnapshot.value);
const autoSaving = ref(false);
const saveError = ref(false);
let autoSaveTimer: ReturnType<typeof setTimeout> | null = null;

function snapshotDraft() {
    initialSnapshot.value = JSON.stringify(draftForm.lines);
}

function syncDraftFromOpname() {
    draftForm.lines = props.opname.lines.map((l) => ({
        id: l.id,
        counted_quantity: toFormValue(l.counted_quantity),
        line_notes: l.line_notes ?? '',
    }));
    snapshotDraft();
}

/** Kirim isian; props sesi (selisih, mutasi selama hitung) ikut diperbarui. */
function saveNow(): Promise<boolean> {
    if (!isDraft.value) return Promise.resolve(true);
    const payload = JSON.parse(JSON.stringify(draftForm.lines));
    const sentSnapshot = JSON.stringify(payload);
    autoSaving.value = true;

    return new Promise((resolve) => {
        router.post(
            `/stock-opnames/${props.opname.id}/draft`,
            { lines: payload, autosave: true },
            {
                preserveScroll: true,
                preserveState: true,
                only: ['opname', 'excelMovedLines'],
                onSuccess: () => {
                    initialSnapshot.value = sentSnapshot;
                    saveError.value = false;
                    resolve(true);
                },
                onError: () => {
                    saveError.value = true;
                    resolve(false);
                },
                onFinish: () => {
                    autoSaving.value = false;
                },
            },
        );
    });
}

watch(
    () => JSON.stringify(draftForm.lines),
    () => {
        if (!isDraft.value) return;
        if (autoSaveTimer) clearTimeout(autoSaveTimer);
        autoSaveTimer = setTimeout(() => {
            if (isDirty.value && !autoSaving.value) saveNow();
        }, 2500);
    },
);

// ── Unggah Excel: unduh → isi → unggah (langsung masuk lembar hitung) ──
const activeImport = ref<ImportDetail | null>(null);
const importFile = ref<File | null>(null);
const importFileInput = ref<HTMLInputElement | null>(null);
const importForm = useForm({ file: null as File | null });
const resolvedProblems = ref<Set<number>>(new Set());
const problemPick = ref<Record<number, number | ''>>({});
let importPollTimer: ReturnType<typeof setTimeout> | null = null;

const importProgress = computed(() => {
    const imp = activeImport.value;
    if (!imp) return 0;
    if (imp.total_rows <= 0) return 5;
    return Math.min(100, Math.max(5, Math.round((imp.processed_rows / imp.total_rows) * 100)));
});

const openProblems = computed(() =>
    (activeImport.value?.problems ?? []).filter((p) => !resolvedProblems.value.has(p.row)),
);

function importIdFromUrl(): number | null {
    const raw = new URLSearchParams(window.location.search).get('import');
    const id = Number(raw);
    return raw && Number.isFinite(id) && id > 0 ? id : null;
}

async function downloadTemplate() {
    // Isian yang belum tersimpan ikut tercetak di lembar hitung.
    if (isDirty.value) await saveNow();
    window.location.href = `/stock-opnames/${props.opname.id}/template`;
}

function onImportFileChange(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    importFile.value = file;
    importForm.file = file;
    importForm.clearErrors();
}

async function submitImport() {
    if (!importFile.value) {
        importForm.setError('file', 'Pilih berkas hasil hitung terlebih dahulu.');
        return;
    }
    if (isDirty.value) await saveNow();

    importForm.post(`/stock-opnames/${props.opname.id}/imports`, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            importFile.value = null;
            importForm.reset();
            if (importFileInput.value) importFileInput.value.value = '';
            resolvedProblems.value = new Set();
            const id = importIdFromUrl();
            if (id) openImport(id);
        },
    });
}

async function openImport(id: number): Promise<void> {
    try {
        const resp = await fetch(`/stock-opnames/${props.opname.id}/imports/${id}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!resp.ok) return;
        const wasRunning = activeImport.value?.id === id && ['pending', 'processing'].includes(activeImport.value.status);
        activeImport.value = (await resp.json()) as ImportDetail;

        // Selesai diproses → isi lembar hitung di layar dengan hasil berkas.
        if (wasRunning && activeImport.value.status === 'completed') {
            router.reload({
                only: ['opname', 'imports', 'excelMovedLines'],
                onSuccess: () => syncDraftFromOpname(),
            });
        }
        scheduleImportPoll();
    } catch {
        // Biarkan panel kosong; user dapat membuka lagi dari riwayat unggahan.
    }
}

function scheduleImportPoll() {
    if (importPollTimer) clearTimeout(importPollTimer);
    importPollTimer = null;
    const imp = activeImport.value;
    if (!imp || (imp.status !== 'pending' && imp.status !== 'processing')) return;
    importPollTimer = setTimeout(() => openImport(imp.id), 1500);
}

function closeImportPanel() {
    if (importPollTimer) clearTimeout(importPollTimer);
    importPollTimer = null;
    activeImport.value = null;
}

/** Berkas lama yang belum sempat diterapkan (sebelum penerapan otomatis). */
const applyForm = useForm({});
function applyOldImport() {
    const imp = activeImport.value;
    if (!imp || applyForm.processing) return;
    applyForm.post(`/stock-opnames/${props.opname.id}/imports/${imp.id}/apply`, {
        preserveScroll: true,
        onSuccess: () => {
            syncDraftFromOpname();
            openImport(imp.id);
        },
    });
}

/** Baris berkas yang gagal dicocokkan: pilih produknya, isi jumlah dari berkas. */
function resolveProblem(problem: ImportProblem) {
    const lineId = problemPick.value[problem.row];
    if (!lineId) return;
    const row = draftForm.lines.find((r) => r.id === lineId);
    if (!row) return;
    const qty = Number(String(problem.counted ?? '').replace(',', '.'));
    if (!Number.isNaN(qty) && qty >= 0) row.counted_quantity = String(qty);
    if (problem.note) row.line_notes = problem.note;
    resolvedProblems.value = new Set([...resolvedProblems.value, problem.row]);
}

function skipProblem(problem: ImportProblem) {
    resolvedProblems.value = new Set([...resolvedProblems.value, problem.row]);
}

const lineLabel = (id: number) => {
    const line = lineById(id);
    if (!line) return '';
    return `${line.product?.name ?? '—'}${line.product_variant ? ' — ' + line.product_variant.name : ''}`;
};

/** Saran produk untuk baris bermasalah: nama yang mirip lebih dulu. */
function problemOptions(problem: ImportProblem) {
    const words = String(problem.name ?? problem.sku ?? '')
        .toLowerCase()
        .split(/[^a-z0-9]+/)
        .filter((w) => w.length >= 3);
    const scored = props.opname.lines.map((l) => {
        const hay = haystackOf(l.id);
        return { id: l.id, label: lineLabel(l.id), score: words.reduce((s, w) => s + (hay.includes(w) ? 1 : 0), 0) };
    });
    return scored.sort((a, b) => b.score - a.score || a.label.localeCompare(b.label, 'id'));
}

// ── Navigasi: simpan dulu bila ada isian yang belum terkirim ──
const showNavigateWarning = ref(false);
const pendingNavigationUrl = ref<string | null>(null);
let isNavigatingAway = false;

function saveAndNavigate() {
    const url = pendingNavigationUrl.value;
    if (!url) return;
    showNavigateWarning.value = false;
    saveNow().then((ok) => {
        if (!ok) return;
        isNavigatingAway = true;
        pendingNavigationUrl.value = null;
        router.visit(url);
    });
}

function discardAndNavigate() {
    const url = pendingNavigationUrl.value;
    if (!url) return;
    showNavigateWarning.value = false;
    pendingNavigationUrl.value = null;
    isNavigatingAway = true;
    router.visit(url);
}

function cancelNavigation() {
    showNavigateWarning.value = false;
    pendingNavigationUrl.value = null;
}

function onBeforeUnload(e: BeforeUnloadEvent) {
    if (isDraft.value && isDirty.value) {
        e.preventDefault();
        e.returnValue = '';
    }
}

function onInertiaBefore(event: { detail: { visit: { url: URL | string; method: string } } }) {
    if (isNavigatingAway || !isDraft.value || !isDirty.value) return true;
    const method = event.detail.visit.method?.toLowerCase() || 'get';
    if (method !== 'get') return true;
    const targetPath = new URL(String(event.detail.visit.url), window.location.origin).pathname;
    if (targetPath === window.location.pathname) return true;

    pendingNavigationUrl.value = String(event.detail.visit.url);
    showNavigateWarning.value = true;
    return false;
}

let offInertiaBefore: (() => void) | null = null;

onMounted(() => {
    const initialImportId =
        importIdFromUrl() ?? props.imports.find((i) => i.status === 'pending' || i.status === 'processing')?.id ?? null;
    if (initialImportId) openImport(initialImportId);

    if (!isDraft.value) return;
    window.addEventListener('beforeunload', onBeforeUnload);
    offInertiaBefore = router.on('before', onInertiaBefore as never);
});

onUnmounted(() => {
    window.removeEventListener('beforeunload', onBeforeUnload);
    offInertiaBefore?.();
    if (autoSaveTimer) clearTimeout(autoSaveTimer);
    if (importPollTimer) clearTimeout(importPollTimer);
});

// ── Posting ──
async function openPostConfirm() {
    if (isDirty.value) {
        const ok = await saveNow();
        if (!ok) return;
    }
    postForm.uncounted_mode = 'ignore';
    showPostConfirm.value = true;
}

function postOpname() {
    if (postForm.processing) return;
    isNavigatingAway = true;
    postForm.post(`/stock-opnames/${props.opname.id}/post`, {
        preserveScroll: true,
        onSuccess: () => {
            showPostConfirm.value = false;
            syncDraftFromOpname();
        },
        onFinish: () => {
            isNavigatingAway = false;
        },
    });
}

function submitCancel() {
    cancelForm.post(`/stock-opnames/${props.opname.id}/cancel`, {
        preserveScroll: true,
        onSuccess: () => {
            showCancel.value = false;
        },
    });
}

function submitDelete() {
    if (deleteForm.processing) return;
    deleteForm.delete(`/stock-opnames/${props.opname.id}`, {
        onFinish: () => {
            showDelete.value = false;
        },
    });
}

// ── Format ──
const formatQty = (n: number | string | null) => {
    const v = Number(n ?? 0);
    const rounded = Math.round(v * 10000) / 10000;
    return Number.isInteger(rounded) ? String(rounded) : rounded.toLocaleString('id-ID', { maximumFractionDigits: 4 });
};

const formatRupiah = (n: number | string | null) => {
    const v = Number(n ?? 0);
    const sign = v < 0 ? '-' : v > 0 ? '+' : '';
    return `${sign}Rp${Math.abs(Math.round(v)).toLocaleString('id-ID')}`;
};

const valueClass = (n: number | string | null) => {
    const v = Number(n ?? 0);
    if (v < -0.00001) return 'text-red-600';
    if (v > 0.00001) return 'text-emerald-600';
    return 'text-slate-400';
};

const statusClass: Record<string, string> = {
    draft: 'bg-amber-100 text-amber-800',
    pending_approval: 'bg-blue-100 text-blue-800',
    posted: 'bg-emerald-100 text-emerald-800',
    cancelled: 'bg-red-100 text-red-700',
};

const importStatusClass: Record<string, string> = {
    pending: 'bg-slate-100 text-slate-700',
    processing: 'bg-blue-100 text-blue-800',
    completed: 'bg-emerald-100 text-emerald-800',
    failed: 'bg-red-100 text-red-700',
};

const formatWhen = (iso: string | null) =>
    iso
        ? new Date(iso).toLocaleString('id-ID', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
              hour: '2-digit',
              minute: '2-digit',
          })
        : '—';
</script>

<template>
    <Head :title="props.opname.adjustment_number" />

    <AdminLayout :title="props.opname.adjustment_number">
        <div class="mx-auto space-y-6 pb-28" style="max-width: 90rem">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <Button variant="ghost" size="icon" class="rounded-xl" @click="router.visit('/stock-opnames')">
                        <ArrowLeft class="h-4 w-4" />
                    </Button>
                    <div>
                        <div class="flex items-center gap-3">
                            <h1 class="text-2xl font-black text-slate-900">{{ props.opname.adjustment_number }}</h1>
                            <span
                                class="rounded-full px-2.5 py-1 text-xs font-bold"
                                :class="statusClass[props.opname.status] ?? 'bg-slate-100 text-slate-700'"
                            >
                                {{ props.statusLabel }}
                            </span>
                            <span
                                v-if="props.opname.blind_count && isDraft"
                                class="rounded-full bg-slate-900 px-2.5 py-1 text-xs font-bold text-white"
                            >
                                Hitung buta
                            </span>
                        </div>
                        <p class="mt-1 text-sm text-slate-600">
                            {{ props.opname.notes || 'Sesi hitung fisik' }} · dibuka {{ formatWhen(props.opname.counted_at) }}
                        </p>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <Button v-if="isDraft" variant="outline" class="rounded-xl" @click="showSystemQty = !showSystemQty">
                        <component :is="showSystemQty ? EyeOff : Eye" class="mr-2 h-4 w-4" />
                        {{ showSystemQty ? 'Sembunyikan stok sistem' : 'Tampilkan stok sistem' }}
                    </Button>
                    <Button v-if="props.canCancel" variant="outline" class="rounded-xl text-red-600" @click="showCancel = !showCancel">
                        <Ban class="mr-2 h-4 w-4" />
                        {{ isPendingApproval ? 'Tolak' : 'Batalkan' }}
                    </Button>
                    <Button v-if="props.canDelete" variant="outline" class="rounded-xl text-red-600" @click="showDelete = !showDelete">
                        <Trash2 class="mr-2 h-4 w-4" />
                        Hapus permanen
                    </Button>
                </div>
            </div>

            <div v-if="isPendingApproval" class="rounded-2xl border border-amber-200 bg-amber-50 p-5">
                <p class="font-bold text-amber-900">Sesi menunggu persetujuan</p>
                <p class="mt-1 text-sm text-amber-800">Stok belum berubah. Selisih baru diterapkan setelah sesi ini disetujui.</p>
                <p v-if="props.segregationBlocked" class="mt-3 text-sm font-semibold text-amber-900">
                    Anda adalah pembuat sesi ini, sehingga persetujuan harus dilakukan pengguna lain.
                </p>
                <p v-if="(approveForm.errors as Record<string, string>).approval" class="mt-2 text-sm font-semibold text-red-700">
                    {{ (approveForm.errors as Record<string, string>).approval }}
                </p>
                <Button v-if="props.canApprove" class="mt-3 rounded-xl" :disabled="approveForm.processing" @click="submitApprove">
                    <CheckCircle2 class="mr-2 h-4 w-4" />
                    {{ approveForm.processing ? 'Memproses…' : 'Setujui dan posting' }}
                </Button>
            </div>

            <div v-if="showCancel" class="rounded-2xl border border-red-200 bg-red-50 p-5">
                <p class="font-bold text-red-900">Batalkan sesi opname ini?</p>
                <p class="mt-1 text-sm text-red-800">Bila sesi sudah diposting, stok akan dikembalikan lewat mutasi pembalik.</p>
                <textarea
                    v-model="cancelForm.cancellation_reason"
                    rows="2"
                    placeholder="Alasan pembatalan (wajib)"
                    class="mt-3 w-full rounded-xl border border-red-200 px-3 py-2 text-sm focus:border-red-500 focus:outline-none"
                ></textarea>
                <p v-if="cancelForm.errors.cancellation_reason" class="mt-1 text-sm font-semibold text-red-700">
                    {{ cancelForm.errors.cancellation_reason }}
                </p>
                <div class="mt-3 flex gap-2">
                    <Button
                        class="rounded-xl bg-red-600 hover:bg-red-700"
                        :disabled="cancelForm.processing || !cancelForm.cancellation_reason"
                        @click="submitCancel"
                    >
                        Ya, batalkan
                    </Button>
                    <Button variant="outline" class="rounded-xl" @click="showCancel = false">Tidak jadi</Button>
                </div>
            </div>

            <div v-if="showDelete" class="rounded-2xl border border-red-200 bg-red-50 p-5">
                <p class="font-bold text-red-900">Hapus permanen sesi opname ini?</p>
                <p class="mt-1 text-sm text-red-800">
                    Sesi beserta seluruh baris hitung dan berkas unggahannya akan dihapus dari sistem. Tindakan ini tidak dapat
                    dibatalkan.
                </p>
                <p v-if="(deleteForm.errors as Record<string, string>).delete" class="mt-2 text-sm font-semibold text-red-700">{{ (deleteForm.errors as Record<string, string>).delete }}</p>
                <div class="mt-3 flex gap-2">
                    <Button class="rounded-xl bg-red-600 hover:bg-red-700" :disabled="deleteForm.processing" @click="submitDelete">
                        {{ deleteForm.processing ? 'Menghapus...' : 'Ya, hapus permanen' }}
                    </Button>
                    <Button variant="outline" class="rounded-xl" @click="showDelete = false">Tidak jadi</Button>
                </div>
            </div>

            <div v-if="props.opname.cancellation_reason" class="rounded-2xl border border-red-200 bg-red-50 px-5 py-4 text-sm text-red-900">
                <p class="font-bold">
                    Dibatalkan oleh {{ props.opname.cancelled_by?.name ?? '—' }} pada {{ formatWhen(props.opname.cancelled_at) }}
                </p>
                <p class="mt-1">{{ props.opname.cancellation_reason }}</p>
            </div>

            <!-- Kemajuan & ringkasan -->
            <div class="grid gap-4 md:grid-cols-4">
                <Card class="rounded-2xl border-slate-200 shadow-sm md:col-span-2">
                    <CardContent class="pt-6">
                        <div class="flex items-baseline justify-between gap-2">
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Sudah dihitung</p>
                            <p class="text-xs font-semibold text-slate-500">{{ progressPct }}%</p>
                        </div>
                        <p class="mt-1 text-xl font-black text-slate-900">
                            {{ countedCount }} <span class="text-base font-semibold text-slate-400">/ {{ totalLines }} produk</span>
                        </p>
                        <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-100">
                            <div class="h-2 rounded-full bg-emerald-500 transition-all" :style="{ width: progressPct + '%' }"></div>
                        </div>
                        <button
                            v-if="isDraft && uncountedCount > 0"
                            type="button"
                            class="mt-2 text-xs font-semibold text-amber-700 hover:underline"
                            @click="statusFilter = 'uncounted'"
                        >
                            {{ uncountedCount }} produk belum dihitung →
                        </button>
                    </CardContent>
                </Card>
                <Card v-if="showSystemQty" class="rounded-2xl border-slate-200 shadow-sm">
                    <CardContent class="pt-6">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Selisih</p>
                        <p class="mt-1 text-xl font-black text-slate-900">{{ affectedCount }} produk</p>
                        <p v-if="canViewCost" class="text-sm font-bold tabular-nums" :class="valueClass(totalValue)">{{ formatRupiah(totalValue) }}</p>
                    </CardContent>
                </Card>
                <Card v-if="showSystemQty" class="rounded-2xl border-slate-200 shadow-sm">
                    <CardContent class="pt-6">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Akurasi inventori</p>
                        <p class="mt-1 text-xl font-black tabular-nums text-slate-900">{{ accuracy.toFixed(1) }}%</p>
                        <p class="text-xs text-slate-500">dari produk yang sudah dihitung</p>
                    </CardContent>
                </Card>
            </div>

            <!-- Hitung lewat Excel -->
            <Card v-if="isDraft || props.imports.length > 0" class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <CardTitle class="text-base font-bold">Hitung lewat Excel</CardTitle>
                    <CardDescription>
                        Unduh lembar hitung, isi kolom <b>Stok Fisik</b>, lalu unggah. Hasilnya langsung masuk ke lembar hitung
                        di bawah. Baris yang dibiarkan kosong dianggap belum dihitung.
                    </CardDescription>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div v-if="isDraft" class="grid gap-3 md:grid-cols-2">
                        <div class="rounded-2xl border border-slate-200 p-4">
                            <p class="text-xs font-bold uppercase tracking-wide text-slate-400">Langkah 1</p>
                            <p class="mt-1 text-sm font-semibold text-slate-900">Unduh lembar hitung</p>
                            <p class="mt-1 text-xs text-slate-500">
                                Urut per kategori. Produk yang sudah dihitung di layar ikut tercetak, jadi lembar bisa dilanjutkan.
                            </p>
                            <Button variant="outline" class="mt-3 rounded-xl" @click="downloadTemplate">
                                <Download class="mr-2 h-4 w-4" />
                                Unduh (.xlsx)
                            </Button>
                        </div>
                        <div class="rounded-2xl border border-slate-200 p-4">
                            <p class="text-xs font-bold uppercase tracking-wide text-slate-400">Langkah 2</p>
                            <p class="mt-1 text-sm font-semibold text-slate-900">Unggah hasil hitung</p>
                            <input
                                ref="importFileInput"
                                type="file"
                                accept=".xlsx,.xls,.csv"
                                class="mt-2 block w-full text-sm text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-900 file:px-3 file:py-1.5 file:text-white"
                                :disabled="importForm.processing"
                                @change="onImportFileChange"
                            />
                            <p v-if="importForm.errors.file" class="mt-2 text-sm font-semibold text-red-700">{{ importForm.errors.file }}</p>
                            <Button class="mt-3 rounded-xl" :disabled="importForm.processing || !importFile" @click="submitImport">
                                <Loader2 v-if="importForm.processing" class="mr-2 h-4 w-4 animate-spin" />
                                <Upload v-else class="mr-2 h-4 w-4" />
                                {{ importForm.processing ? 'Mengunggah…' : 'Unggah' }}
                            </Button>
                        </div>
                    </div>

                    <div v-if="activeImport" class="rounded-2xl border border-slate-200 bg-slate-50/60 p-4">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <p class="font-semibold text-slate-900">{{ activeImport.file_name }}</p>
                            <Button variant="ghost" size="sm" class="rounded-xl text-slate-500" @click="closeImportPanel">Tutup</Button>
                        </div>

                        <div v-if="activeImport.status === 'pending' || activeImport.status === 'processing'" class="mt-3">
                            <div class="flex items-center gap-2 text-sm text-slate-600">
                                <Loader2 class="h-4 w-4 animate-spin" />
                                Membaca berkas… {{ activeImport.processed_rows }} dari {{ activeImport.total_rows }} baris
                            </div>
                            <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-200">
                                <div class="h-2 rounded-full bg-slate-900 transition-all" :style="{ width: importProgress + '%' }"></div>
                            </div>
                        </div>

                        <div v-else-if="activeImport.status === 'failed'" class="mt-3 rounded-xl border border-red-200 bg-red-50 p-4">
                            <p class="text-sm font-semibold text-red-800">Berkas tidak bisa dibaca — lembar hitung tidak berubah.</p>
                            <p class="mt-1 text-sm text-red-700">{{ activeImport.error_message }}</p>
                        </div>

                        <div v-else class="mt-3 space-y-3">
                            <div
                                v-if="activeImport.applied_at"
                                class="flex items-start gap-2 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-900"
                            >
                                <CheckCircle2 class="mt-0.5 h-4 w-4 shrink-0" />
                                <span>
                                    <b>{{ activeImport.summary?.matched_rows ?? 0 }} produk</b> masuk ke lembar hitung.
                                    <span v-if="activeImport.summary?.blank_rows">
                                        {{ activeImport.summary.blank_rows }} baris kosong dilewati (belum dihitung).
                                    </span>
                                    Stok baru berubah setelah sesi diposting.
                                </span>
                            </div>
                            <div
                                v-else-if="activeImport.can_apply"
                                class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white p-3"
                            >
                                <p class="text-sm text-slate-600">Berkas ini sudah diperiksa tetapi belum dimasukkan ke lembar hitung.</p>
                                <Button class="rounded-xl" :disabled="applyForm.processing" @click="applyOldImport">
                                    Masukkan ke lembar hitung
                                </Button>
                            </div>
                            <p
                                v-else-if="(activeImport.summary?.matched_rows ?? 0) === 0 && openProblems.length === 0"
                                class="rounded-xl border border-slate-200 bg-white p-3 text-sm text-slate-600"
                            >
                                Tidak ada baris berisi Stok Fisik di berkas ini.
                            </p>
                            <p v-if="(applyForm.errors as Record<string, string>).import" class="text-sm font-semibold text-red-700">
                                {{ (applyForm.errors as Record<string, string>).import }}
                            </p>

                            <!-- Baris yang perlu dicek: perbaiki di sini, tanpa unggah ulang -->
                            <div v-if="openProblems.length > 0" class="rounded-xl border border-amber-200 bg-amber-50 p-3">
                                <p class="text-sm font-bold text-amber-900">
                                    {{ openProblems.length }} baris perlu dicek
                                </p>
                                <p class="text-xs text-amber-800">
                                    Pilih produk yang benar lalu klik <b>Isi</b>. Tidak perlu mengunggah ulang berkas.
                                </p>
                                <div class="mt-3 space-y-2">
                                    <div
                                        v-for="p in openProblems"
                                        :key="p.row"
                                        class="flex flex-wrap items-center gap-2 rounded-lg bg-white p-2.5 text-sm"
                                    >
                                        <div class="min-w-48 flex-1">
                                            <p class="font-semibold text-slate-900">
                                                Baris {{ p.row }} · {{ p.name || p.sku || 'tanpa nama' }}
                                                <span v-if="p.counted" class="text-slate-500">· fisik {{ p.counted }}</span>
                                            </p>
                                            <p class="text-xs text-red-700">{{ p.message }}</p>
                                        </div>
                                        <select
                                            v-model="problemPick[p.row]"
                                            class="h-9 max-w-72 rounded-lg border border-slate-200 bg-white px-2 text-sm"
                                        >
                                            <option value="">Pilih produk…</option>
                                            <option v-for="o in problemOptions(p)" :key="o.id" :value="o.id">{{ o.label }}</option>
                                        </select>
                                        <Button size="sm" class="rounded-lg" :disabled="!problemPick[p.row]" @click="resolveProblem(p)">
                                            Isi
                                        </Button>
                                        <Button size="sm" variant="ghost" class="rounded-lg text-slate-500" @click="skipProblem(p)">
                                            Lewati
                                        </Button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div v-if="!activeImport && props.imports.length > 0" class="space-y-2">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Riwayat unggahan</p>
                        <button
                            v-for="imp in props.imports"
                            :key="imp.id"
                            type="button"
                            class="flex w-full flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 px-3 py-2 text-left text-sm hover:border-slate-300 hover:bg-slate-50"
                            @click="openImport(imp.id)"
                        >
                            <span class="font-medium text-slate-900">{{ imp.file_name }}</span>
                            <span class="flex items-center gap-2 text-xs">
                                <span
                                    v-if="imp.applied_at"
                                    class="rounded-full bg-emerald-100 px-2 py-0.5 font-bold text-emerald-800"
                                >
                                    {{ imp.matched_rows }} produk masuk
                                </span>
                                <span
                                    v-else
                                    class="rounded-full px-2 py-0.5 font-bold"
                                    :class="importStatusClass[imp.status] ?? 'bg-slate-100 text-slate-700'"
                                >
                                    {{ imp.status_label }}
                                </span>
                                <span v-if="imp.error_rows > 0" class="rounded-full bg-amber-100 px-2 py-0.5 font-bold text-amber-800">
                                    {{ imp.error_rows }} perlu dicek
                                </span>
                            </span>
                        </button>
                    </div>
                </CardContent>
            </Card>

            <!-- Lembar hitung -->
            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <CardTitle class="text-base font-bold">Lembar hitung</CardTitle>
                    <CardDescription v-if="isDraft && !showSystemQty">
                        Stok sistem disembunyikan agar hitungan tidak terpengaruh angka catatan. Kosongkan kotak bila produk belum dihitung.
                    </CardDescription>
                    <CardDescription v-else-if="isDraft">
                        Kotak kosong = belum dihitung. Isi jumlah fisik; tersimpan otomatis.
                    </CardDescription>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div class="flex flex-wrap gap-2">
                        <div class="relative min-w-60 flex-1">
                            <Search class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                            <input
                                ref="searchInput"
                                v-model="search"
                                type="text"
                                placeholder="Cari nama, atau pindai barcode lalu Enter…"
                                class="w-full rounded-xl border border-slate-200 py-2 pl-9 pr-9 text-sm focus:border-slate-900 focus:outline-none"
                                @keydown.enter.prevent="onSearchEnter"
                                @input="scanMessage = ''"
                            />
                            <button
                                v-if="search"
                                type="button"
                                class="absolute right-2 top-1/2 -translate-y-1/2 rounded p-1 text-slate-400 hover:text-slate-700"
                                @click="search = ''; scanMessage = ''"
                            >
                                <X class="h-4 w-4" />
                            </button>
                        </div>
                        <select
                            v-if="categories.length > 1"
                            v-model="categoryFilter"
                            class="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm"
                        >
                            <option value="">Semua kategori</option>
                            <option v-for="c in categories" :key="c" :value="c">{{ c }}</option>
                        </select>
                    </div>
                    <p v-if="scanMessage" class="-mt-2 text-sm font-semibold text-amber-700">{{ scanMessage }}</p>

                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="f in [
                                { key: 'all', label: `Semua (${totalLines})` },
                                { key: 'uncounted', label: `Belum dihitung (${uncountedCount})` },
                                { key: 'counted', label: `Sudah dihitung (${countedCount})` },
                                ...(showSystemQty ? [{ key: 'variance', label: `Ada selisih (${affectedCount})` }] : []),
                            ]"
                            :key="f.key"
                            type="button"
                            class="rounded-full border px-3 py-1 text-sm font-medium transition-colors"
                            :class="statusFilter === f.key
                                ? 'border-slate-900 bg-slate-900 text-white'
                                : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300'"
                            @click="statusFilter = f.key as typeof statusFilter"
                        >
                            {{ f.label }}
                        </button>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500">
                                    <th class="py-3 pr-4 font-semibold">Produk</th>
                                    <th v-if="showSystemQty" class="py-3 pr-4 text-right font-semibold">Stok sistem</th>
                                    <th class="py-3 pr-4 text-right font-semibold">Jumlah fisik</th>
                                    <th v-if="showSystemQty" class="py-3 pr-4 text-right font-semibold">Selisih</th>
                                    <th v-if="showSystemQty && canViewCost" class="py-3 pr-4 text-right font-semibold">Nilai</th>
                                    <th class="py-3 font-semibold">Catatan</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="visibleRows.length === 0">
                                    <td colspan="6" class="py-8 text-center text-sm text-slate-500">Tidak ada produk pada filter ini.</td>
                                </tr>
                                <tr
                                    v-for="row in visibleRows"
                                    :key="row.id"
                                    class="border-b border-slate-100"
                                    :class="showSystemQty && hasVariance(row) ? 'bg-amber-50/50' : ''"
                                >
                                    <td class="py-3 pr-4">
                                        <p class="font-semibold text-slate-900">
                                            {{ lineById(row.id)?.product?.name ?? '—'
                                            }}<span v-if="lineById(row.id)?.product_variant" class="text-slate-500">
                                                — {{ lineById(row.id)?.product_variant?.name }}</span
                                            >
                                        </p>
                                        <p class="text-xs text-slate-400">
                                            {{ lineById(row.id)?.product?.product_category?.name ?? 'Tanpa kategori' }}
                                            · {{ lineById(row.id)?.product_variant?.sku ?? lineById(row.id)?.product?.sku ?? lineById(row.id)?.product?.barcode ?? '—' }}
                                        </p>
                                    </td>
                                    <td v-if="showSystemQty" class="py-3 pr-4 text-right tabular-nums text-slate-600">
                                        {{ formatQty(lineById(row.id)?.system_quantity ?? 0) }}
                                        <p
                                            v-if="Math.abs(Number(lineById(row.id)?.movement_during_count ?? 0)) > 0.00001"
                                            class="text-[11px] text-blue-600"
                                            title="Terjual/masuk antara sesi dibuka dan saat produk ini dihitung"
                                        >
                                            {{ Number(lineById(row.id)?.movement_during_count) > 0 ? '+' : ''
                                            }}{{ formatQty(lineById(row.id)?.movement_during_count ?? 0) }} selama hitung
                                        </p>
                                    </td>
                                    <td class="py-3 pr-4 text-right whitespace-nowrap">
                                        <input
                                            :ref="(el) => setQtyInput(row.id, el)"
                                            v-model="row.counted_quantity"
                                            type="number"
                                            inputmode="decimal"
                                            min="0"
                                            :step="lineById(row.id)?.product?.allows_decimal ? '0.0001' : '1'"
                                            :disabled="!isDraft"
                                            placeholder="belum"
                                            class="w-28 rounded-lg border px-2 py-1.5 text-right text-sm tabular-nums placeholder:text-slate-300 focus:border-slate-900 focus:outline-none disabled:bg-slate-50 disabled:opacity-70"
                                            :class="isCounted(row) ? 'border-slate-300' : 'border-dashed border-slate-300 bg-slate-50'"
                                            @keydown.enter.prevent="onQtyEnter"
                                        />
                                        <span class="ml-1 text-xs text-slate-400">{{ lineById(row.id)?.product?.unit }}</span>
                                    </td>
                                    <td
                                        v-if="showSystemQty"
                                        class="py-3 pr-4 text-right font-bold tabular-nums whitespace-nowrap"
                                        :class="valueClass(variance(row.id, row.counted_quantity))"
                                    >
                                        <template v-if="variance(row.id, row.counted_quantity) === null">
                                            <span class="font-normal text-slate-300">—</span>
                                        </template>
                                        <template v-else>
                                            {{ (variance(row.id, row.counted_quantity) ?? 0) > 0 ? '+' : ''
                                            }}{{ formatQty(variance(row.id, row.counted_quantity)) }}
                                        </template>
                                    </td>
                                    <td
                                        v-if="showSystemQty && canViewCost"
                                        class="py-3 pr-4 text-right font-semibold tabular-nums whitespace-nowrap"
                                        :class="valueClass(varianceValue(row.id, row.counted_quantity))"
                                    >
                                        <span v-if="varianceValue(row.id, row.counted_quantity) === null" class="font-normal text-slate-300">—</span>
                                        <span v-else>{{ formatRupiah(varianceValue(row.id, row.counted_quantity)) }}</span>
                                    </td>
                                    <td class="py-3">
                                        <input
                                            v-model="row.line_notes"
                                            type="text"
                                            placeholder="opsional"
                                            :disabled="!isDraft"
                                            class="w-full min-w-36 rounded-lg border border-slate-200 px-2 py-1.5 text-sm focus:border-slate-900 focus:outline-none disabled:bg-slate-50"
                                        />
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </CardContent>
            </Card>
        </div>

        <!-- Bilah bawah: status simpan + posting -->
        <div
            v-if="isDraft"
            class="sticky bottom-0 z-20 -mx-4 border-t border-slate-200 bg-white/95 px-4 py-3 backdrop-blur sm:mx-0 sm:rounded-2xl sm:border"
        >
            <div class="mx-auto flex flex-wrap items-center justify-between gap-3" style="max-width: 90rem">
                <div class="text-sm text-slate-600">
                    <span class="font-semibold text-slate-900">{{ countedCount }} / {{ totalLines }}</span> dihitung
                    <span class="mx-1 text-slate-300">·</span>
                    <span v-if="autoSaving" class="inline-flex items-center gap-1 text-slate-500">
                        <Loader2 class="h-3 w-3 animate-spin" /> Menyimpan…
                    </span>
                    <span v-else-if="saveError" class="font-semibold text-red-600">Gagal menyimpan — periksa koneksi</span>
                    <span v-else-if="isDirty" class="text-amber-600">Belum tersimpan</span>
                    <span v-else class="text-emerald-600">Tersimpan</span>
                </div>
                <Button
                    v-if="props.canPost"
                    class="rounded-xl"
                    :disabled="autoSaving || postForm.processing || countedCount === 0"
                    @click="openPostConfirm"
                >
                    <CheckCircle2 class="mr-2 h-4 w-4" />
                    Posting
                </Button>
                <p v-else class="text-sm text-slate-500">Selesai menghitung? Minta admin, manajer, atau pemilik memposting.</p>
            </div>
        </div>
    </AdminLayout>

    <!-- Dialog posting -->
    <Dialog :open="showPostConfirm" @update:open="(v: boolean) => !v && (showPostConfirm = false)">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle class="text-lg font-bold">Posting stock opname</DialogTitle>
                <DialogDescription class="text-sm text-slate-500">
                    Stok sistem disesuaikan dengan hasil hitung. Setelah diposting, data tidak dapat diubah.
                </DialogDescription>
            </DialogHeader>

            <div class="space-y-4 py-2">
                <div class="space-y-2 rounded-xl bg-slate-50 p-4 text-sm">
                    <div class="flex justify-between">
                        <span class="text-slate-600">Sudah dihitung</span>
                        <span class="font-bold text-slate-900">{{ countedCount }} produk</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-slate-600">Ada selisih</span>
                        <span class="font-bold text-slate-900">{{ affectedCount }} produk</span>
                    </div>
                    <div v-if="canViewCost" class="flex justify-between">
                        <span class="text-slate-600">Nilai selisih</span>
                        <span class="font-bold tabular-nums" :class="valueClass(totalValue)">{{ formatRupiah(totalValue) }}</span>
                    </div>
                </div>

                <div v-if="uncountedCount > 0" class="rounded-xl border border-amber-200 bg-amber-50 p-4">
                    <p class="text-sm font-bold text-amber-900">{{ uncountedCount }} produk belum dihitung. Bagaimana stoknya?</p>
                    <label class="mt-2 flex items-start gap-2 text-sm text-slate-800">
                        <input v-model="postForm.uncounted_mode" type="radio" value="ignore" class="mt-0.5" />
                        <span><b>Biarkan</b> — stok produk itu tidak diubah (disarankan bila belum sempat dihitung)</span>
                    </label>
                    <label class="mt-1.5 flex items-start gap-2 text-sm text-slate-800">
                        <input v-model="postForm.uncounted_mode" type="radio" value="zero" class="mt-0.5" />
                        <span><b>Anggap habis (0)</b> — pilih bila semua barang sudah dihitung dan sisanya memang tidak ada</span>
                    </label>
                </div>

                <div v-if="(props.excelMovedLines ?? []).length > 0" class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900">
                    <p class="font-bold">{{ props.excelMovedLines!.length }} produk dari Excel terjual/masuk selama sesi berjalan</p>
                    <p class="mt-1 text-xs">
                        Hasil Excel dibandingkan dengan stok saat sesi dibuka. Bila produk ini dihitung <b>setelah</b> terjual, isi
                        ulang jumlahnya di layar agar penjualan tidak terhitung dua kali.
                    </p>
                    <ul class="mt-2 max-h-28 list-inside list-disc overflow-y-auto text-xs">
                        <li v-for="m in props.excelMovedLines" :key="m.line_id">
                            {{ m.name }} ({{ Number(m.movement) > 0 ? '+' : '' }}{{ formatQty(m.movement) }})
                        </li>
                    </ul>
                </div>

                <p v-if="(postForm.errors as Record<string, string>).lines" class="text-sm font-semibold text-red-700">
                    {{ (postForm.errors as Record<string, string>).lines }}
                </p>
            </div>

            <DialogFooter class="flex flex-wrap justify-end gap-2">
                <Button variant="outline" class="rounded-xl" :disabled="postForm.processing" @click="showPostConfirm = false">
                    Periksa lagi
                </Button>
                <Button class="rounded-xl" :disabled="postForm.processing" @click="postOpname">
                    <Loader2 v-if="postForm.processing" class="mr-2 h-4 w-4 animate-spin" />
                    <CheckCircle2 v-else class="mr-2 h-4 w-4" />
                    {{ postForm.processing ? 'Memposting...' : 'Ya, posting' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <!-- Peringatan pindah halaman saat isian belum terkirim -->
    <Dialog :open="showNavigateWarning" @update:open="(v: boolean) => !v && cancelNavigation()">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle class="text-base font-bold">Isian belum tersimpan</DialogTitle>
                <DialogDescription class="text-sm text-slate-500">
                    Ada jumlah fisik yang belum terkirim ke server. Simpan dulu sebelum meninggalkan halaman?
                </DialogDescription>
            </DialogHeader>
            <DialogFooter class="flex flex-wrap justify-end gap-2">
                <Button class="rounded-xl" :disabled="autoSaving" @click="saveAndNavigate">
                    <Loader2 v-if="autoSaving" class="mr-2 h-4 w-4 animate-spin" />
                    Simpan & lanjut
                </Button>
                <Button variant="ghost" class="rounded-xl" @click="cancelNavigation">Tetap di sini</Button>
                <Button variant="outline" class="rounded-xl text-red-600" @click="discardAndNavigate">Buang isian</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
