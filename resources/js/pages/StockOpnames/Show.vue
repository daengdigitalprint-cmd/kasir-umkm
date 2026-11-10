<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { ArrowLeft, Ban, Download, Eye, EyeOff, Save, Search, Trash2, Upload, CheckCircle2, AlertTriangle, Loader2 } from 'lucide-vue-next';

interface LineRow {
    id: number;
    system_quantity: string;
    counted_quantity: string;
    variance_quantity: string;
    unit_cost: string;
    variance_value: string;
    line_notes: string | null;
    product: {
        id: number;
        name: string;
        sku: string | null;
        unit: string | null;
        allows_decimal: boolean;
    } | null;
    product_variant: { id: number; name: string; sku: string | null } | null;
}

interface OpnameDetail {
    id: number;
    adjustment_number: string;
    status: string;
    notes: string | null;
    counted_at: string | null;
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
    missing_lines: number;
    counted_lines: number;
    positive_value: string;
    negative_value: string;
    total_variance_value: string;
}

interface ImportPreviewRow {
    line_id: number;
    file_row: number | null;
    product_id: number | null;
    product_name: string;
    sku: string;
    variant: string | null;
    batch_code: string | null;
    unit: string;
    system_quantity: string;
    counted_quantity: string;
    variance_quantity: string;
    unit_cost: string;
    variance_value: string;
    note: string | null;
    missing: boolean;
}

interface ImportPayload {
    id: number;
    file_name: string;
    status: 'pending' | 'processing' | 'completed' | 'failed';
    status_label: string;
    missing_mode: 'ignore' | 'zero';
    missing_mode_label: string;
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
    preview: ImportPreviewRow[];
    preview_total: number;
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
}>();

const isPendingApproval = computed(() => props.opname.status === 'pending_approval');
const approveForm = useForm({});

function submitApprove() {
    approveForm.post(`/stock-opnames/${props.opname.id}/approve`, { preserveScroll: true });
}

const isDraft = computed(() => props.opname.status === 'draft');
const isCancelled = computed(() => props.opname.status === 'cancelled');

// Blind count aktif secara bawaan selama sesi masih berjalan.
const showSystemQty = ref(!isDraft.value);
const search = ref('');
const showCancel = ref(false);
const showDelete = ref(false);
const showPostConfirm = ref(false);

const draftForm = useForm({
    lines: props.opname.lines.map((l) => ({
        id: l.id,
        counted_quantity: String(Number(l.counted_quantity)),
        line_notes: l.line_notes ?? '',
    })),
});

const postForm = useForm({});
const cancelForm = useForm({ cancellation_reason: '' });
const deleteForm = useForm({});

// ── Impor berkas hitung: unduh template → unggah → preview selisih → terapkan ──
const activeImport = ref<ImportDetail | null>(null);
const importFile = ref<File | null>(null);
const importFileInput = ref<HTMLInputElement | null>(null);
const showApplyConfirm = ref(false);

const importForm = useForm({
    file: null as File | null,
    missing_mode: 'ignore' as 'ignore' | 'zero',
});
const applyForm = useForm({});

let importPollTimer: ReturnType<typeof setTimeout> | null = null;

const importStatusClass: Record<string, string> = {
    pending: 'bg-slate-100 text-slate-700',
    processing: 'bg-blue-100 text-blue-800',
    completed: 'bg-emerald-100 text-emerald-800',
    failed: 'bg-red-100 text-red-700',
};

const importProgress = computed(() => {
    const imp = activeImport.value;
    if (!imp) return 0;
    if (imp.total_rows <= 0) return 5;
    return Math.min(100, Math.max(5, Math.round((imp.processed_rows / imp.total_rows) * 100)));
});

const applyImportError = computed(() => (applyForm.errors as Record<string, string>).import ?? null);

function importIdFromUrl(): number | null {
    const raw = new URLSearchParams(window.location.search).get('import');
    const id = Number(raw);
    return raw && Number.isFinite(id) && id > 0 ? id : null;
}

function downloadTemplate() {
    window.location.href = `/stock-opnames/${props.opname.id}/template`;
}

function onImportFileChange(event: Event) {
    const target = event.target as HTMLInputElement;
    const file = target.files?.[0] ?? null;
    importFile.value = file;
    importForm.file = file;
    importForm.clearErrors();
}

function submitImport() {
    if (!importFile.value) {
        importForm.setError('file', 'Pilih berkas hasil hitung terlebih dahulu.');
        return;
    }

    importForm.post(`/stock-opnames/${props.opname.id}/imports`, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            importFile.value = null;
            importForm.reset();
            if (importFileInput.value) importFileInput.value.value = '';
            const id = importIdFromUrl();
            if (id) openImport(id);
        },
    });
}

/** Muat detail impor (JSON) sekaligus jadwalkan polling saat masih diproses */
async function openImport(id: number): Promise<void> {
    try {
        const resp = await fetch(`/stock-opnames/${props.opname.id}/imports/${id}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!resp.ok) return;
        activeImport.value = (await resp.json()) as ImportDetail;
        scheduleImportPoll();
    } catch {
        // Biarkan panel kosong; user dapat membuka lagi dari riwayat unggahan.
    }
}

function scheduleImportPoll() {
    if (importPollTimer) {
        clearTimeout(importPollTimer);
        importPollTimer = null;
    }
    const imp = activeImport.value;
    if (!imp || (imp.status !== 'pending' && imp.status !== 'processing')) return;
    importPollTimer = setTimeout(() => openImport(imp.id), 2000);
}

function closeImportPanel() {
    if (importPollTimer) {
        clearTimeout(importPollTimer);
        importPollTimer = null;
    }
    activeImport.value = null;
}

function submitApply() {
    const imp = activeImport.value;
    if (!imp || applyForm.processing) return;
    showApplyConfirm.value = false;
    applyForm.post(`/stock-opnames/${props.opname.id}/imports/${imp.id}/apply`, {
        preserveScroll: true,
        onSuccess: () => {
            syncDraftFromOpname();
            openImport(imp.id);
        },
    });
}

/** Selaraskan form draft dengan nilai yang baru saja ditulis impor */
function syncDraftFromOpname() {
    draftForm.lines = props.opname.lines.map((l) => ({
        id: l.id,
        counted_quantity: String(Number(l.counted_quantity)),
        line_notes: l.line_notes ?? '',
    }));
    snapshotDraft();
}

// ── Navigasi guard: auto-save & konfirmasi saat pindah halaman ──
const initialSnapshot = ref(JSON.stringify(draftForm.lines));
const isDirty = computed(() => JSON.stringify(draftForm.lines) !== initialSnapshot.value);

const showNavigateWarning = ref(false);
const pendingNavigationUrl = ref<string | null>(null);
const autoSaving = ref(false);

function snapshotDraft() {
    initialSnapshot.value = JSON.stringify(draftForm.lines);
}

function xsrfToken(): string {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

/** Simpan draft via fetch biasa tanpa trigger redirect Inertia */
async function saveDraftViaFetch(): Promise<void> {
    const resp = await fetch(`/stock-opnames/${props.opname.id}/draft`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: JSON.stringify({ lines: draftForm.lines }),
        redirect: 'manual',
    });
    if (resp.type === 'opaqueredirect' || resp.status === 0) {
        // Redirect dari server — berarti save berhasil
        snapshotDraft();
        return;
    }
    if (!resp.ok) {
        throw new Error('Gagal menyimpan draft');
    }
    snapshotDraft();
}

/** Simpan draft + navigasi ke URL pending */
function saveAndNavigate() {
    const url = pendingNavigationUrl.value;
    if (!url) return;
    showNavigateWarning.value = false;
    autoSaving.value = true;
    isNavigatingAway = true;
    saveDraftViaFetch()
        .then(() => {
            pendingNavigationUrl.value = null;
            router.visit(url, { replace: true });
        })
        .catch(() => {
            autoSaving.value = false;
            isNavigatingAway = false;
        });
}

/** Buang perubahan & navigasi */
function discardAndNavigate() {
    const url = pendingNavigationUrl.value;
    if (!url) return;
    showNavigateWarning.value = false;
    pendingNavigationUrl.value = null;
    isNavigatingAway = true;
    router.visit(url, { replace: true });
}

function cancelNavigation() {
    showNavigateWarning.value = false;
    pendingNavigationUrl.value = null;
}

/** Cegah tab/browser close saat dirty */
function onBeforeUnload(e: BeforeUnloadEvent) {
    if (isDraft.value && isDirty.value) {
        e.preventDefault();
        e.returnValue = '';
    }
}

// Intercept semua navigasi Inertia (sidebar, Link, router.visit) saat dirty
let isNavigatingAway = false;

function onInertiaBefore(event: { detail: { visit: { url: string; method: string } } }) {
    if (isNavigatingAway) return true;
    if (!isDraft.value || !isDirty.value) return true;

    // Jangan intercept saat form sedang dikirim (saveDraft/postOpname/cancel/impor)
    if (
        draftForm.processing ||
        postForm.processing ||
        approveForm.processing ||
        cancelForm.processing ||
        importForm.processing ||
        applyForm.processing
    ) {
        return true;
    }

    const targetUrl = event.detail.visit.url;
    const method = event.detail.visit.method?.toLowerCase() || 'get';
    if (!targetUrl) return true;

    // Izinkan semua POST/PUT/PATCH/DELETE request (form submissions)
    if (method !== 'get') return true;

    // Bandingkan pathname saja (abaikan query string & hash)
    const currentPath = new URL(window.location.href).pathname;
    const targetPath = new URL(targetUrl, window.location.origin).pathname;

    // Jika pathname sama, izinkan (reload, partial, dll)
    if (currentPath === targetPath) return true;

    // Cek apakah target adalah halaman berbeda
    pendingNavigationUrl.value = targetUrl;
    showNavigateWarning.value = true;
    return false;
}

// Auto-save draft setiap 10 detik jika ada perubahan
let autoSaveTimer: ReturnType<typeof setTimeout> | null = null;
let offInertiaBefore: (() => void) | null = null;

onMounted(() => {
    // Buka panel impor dari ?import= atau lanjutkan polling impor yang masih berjalan.
    const initialImportId =
        importIdFromUrl() ?? props.imports.find((i) => i.status === 'pending' || i.status === 'processing')?.id ?? null;
    if (initialImportId) {
        openImport(initialImportId);
    }

    if (!isDraft.value) return;
    window.addEventListener('beforeunload', onBeforeUnload);
    offInertiaBefore = router.on('before', onInertiaBefore);
});

onUnmounted(() => {
    window.removeEventListener('beforeunload', onBeforeUnload);
    offInertiaBefore?.();
    if (autoSaveTimer) clearTimeout(autoSaveTimer);
    if (importPollTimer) clearTimeout(importPollTimer);
});

watch(
    () => JSON.stringify(draftForm.lines),
    () => {
        if (!isDraft.value || autoSaving.value) return;
        if (autoSaveTimer) clearTimeout(autoSaveTimer);
        autoSaveTimer = setTimeout(() => {
            if (isDirty.value && !autoSaving.value && draftForm.isDirty) {
                autoSaving.value = true;
                saveDraftViaFetch()
                    .catch(() => { /* auto-save gagal, user bisa save manual */ })
                    .finally(() => { autoSaving.value = false; });
            }
        }, 10_000);
    },
);

const lineById = (id: number) => props.opname.lines.find((l) => l.id === id);

const visibleRows = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (!q) return draftForm.lines;
    return draftForm.lines.filter((row) => {
        const line = lineById(row.id);
        if (!line?.product) return false;
        const haystack = [
            line.product.name,
            line.product.sku ?? '',
            line.product_variant?.name ?? '',
            line.product_variant?.sku ?? '',
        ]
            .join(' ')
            .toLowerCase();
        return haystack.includes(q);
    });
});

function variance(rowId: number, counted: string) {
    const line = lineById(rowId);
    if (!line) return 0;
    const n = Number(counted);
    if (Number.isNaN(n)) return 0;
    return n - Number(line.system_quantity);
}

function varianceValue(rowId: number, counted: string) {
    const line = lineById(rowId);
    if (!line) return 0;
    return variance(rowId, counted) * Number(line.unit_cost);
}

const affectedCount = computed(
    () => draftForm.lines.filter((r) => Math.abs(variance(r.id, r.counted_quantity)) > 0.00001).length,
);

const totalValue = computed(() => draftForm.lines.reduce((s, r) => s + varianceValue(r.id, r.counted_quantity), 0));

// Akurasi inventori: seberapa dekat hitungan fisik dengan catatan sistem.
const accuracy = computed(() => {
    const totalSystem = props.opname.lines.reduce((s, l) => s + Math.abs(Number(l.system_quantity)), 0);
    if (totalSystem === 0) return 100;
    const totalVariance = draftForm.lines.reduce((s, r) => s + Math.abs(variance(r.id, r.counted_quantity)), 0);
    return Math.max(0, (1 - totalVariance / totalSystem) * 100);
});

const formatQty = (n: number | string) => {
    const v = Number(n ?? 0);
    const rounded = Math.round(v * 10000) / 10000;
    return Number.isInteger(rounded) ? String(rounded) : rounded.toLocaleString('id-ID', { maximumFractionDigits: 4 });
};

const formatRupiah = (n: number | string) => {
    const v = Number(n ?? 0);
    const sign = v < 0 ? '-' : v > 0 ? '+' : '';
    return `${sign}Rp${Math.abs(Math.round(v)).toLocaleString('id-ID')}`;
};

const valueClass = (n: number | string) => {
    const v = Number(n ?? 0);
    if (v < -0.00001) return 'text-red-600';
    if (v > 0.00001) return 'text-emerald-600';
    return 'text-slate-400';
};

const statusClass: Record<string, string> = {
    draft: 'bg-amber-100 text-amber-800',
    posted: 'bg-emerald-100 text-emerald-800',
    cancelled: 'bg-red-100 text-red-700',
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

function saveDraft() {
    draftForm.post(`/stock-opnames/${props.opname.id}/draft`, {
        preserveScroll: true,
        onSuccess: () => {
            snapshotDraft();
        },
    });
}

function openPostConfirm() {
    // Pastikan draft tersimpan dulu sebelum tampilkan dialog konfirmasi
    if (isDirty.value) {
        draftForm.post(`/stock-opnames/${props.opname.id}/draft`, {
            preserveScroll: true,
            onSuccess: () => {
                snapshotDraft();
                showPostConfirm.value = true;
            },
        });
    } else {
        showPostConfirm.value = true;
    }
}

function postOpname() {
    if (postForm.processing) return;
    showPostConfirm.value = false;
    isNavigatingAway = true;
    postForm.post(`/stock-opnames/${props.opname.id}/post`, {
        preserveScroll: true,
        onSuccess: () => {
            snapshotDraft();
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
</script>

<template>
    <Head :title="props.opname.adjustment_number" />

    <AdminLayout :title="props.opname.adjustment_number">
        <div class="mx-auto space-y-6 pb-8" style="max-width: 90rem">
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
                        </div>
                        <p class="mt-1 text-sm text-slate-600">
                            {{ props.opname.notes || 'Sesi hitung fisik' }} · dibuka {{ formatWhen(props.opname.counted_at) }}
                        </p>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <Button
                        v-if="isDraft"
                        variant="outline"
                        class="rounded-xl"
                        @click="showSystemQty = !showSystemQty"
                    >
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
                <p class="mt-1 text-sm text-amber-800">
                    Stok belum berubah. Selisih baru diterapkan setelah sesi ini disetujui.
                </p>

                <p v-if="props.segregationBlocked" class="mt-3 text-sm font-semibold text-amber-900">
                    Anda adalah pembuat sesi ini, sehingga persetujuan harus dilakukan pengguna lain.
                </p>

                <p v-if="approveForm.errors.approval" class="mt-2 text-sm font-semibold text-red-700">
                    {{ approveForm.errors.approval }}
                </p>

                <Button v-if="props.canApprove" class="mt-3 rounded-xl" :disabled="approveForm.processing" @click="submitApprove">
                    <CheckCircle2 class="mr-2 h-4 w-4" />
                    {{ approveForm.processing ? 'Memproses…' : 'Setujui dan posting' }}
                </Button>
            </div>

            <div v-if="showCancel" class="rounded-2xl border border-red-200 bg-red-50 p-5">
                <p class="font-bold text-red-900">Batalkan sesi opname ini?</p>
                <p class="mt-1 text-sm text-red-800">
                    Bila sesi sudah diposting, stok akan dikembalikan lewat mutasi pembalik.
                </p>
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
                    Sesi beserta seluruh baris hitung dan berkas unggahannya akan dihapus dari sistem. Tindakan ini tidak
                    dapat dibatalkan.
                </p>
                <p v-if="deleteForm.errors.delete" class="mt-2 text-sm font-semibold text-red-700">
                    {{ deleteForm.errors.delete }}
                </p>
                <div class="mt-3 flex gap-2">
                    <Button
                        class="rounded-xl bg-red-600 hover:bg-red-700"
                        :disabled="deleteForm.processing"
                        @click="submitDelete"
                    >
                        {{ deleteForm.processing ? 'Menghapus...' : 'Ya, hapus permanen' }}
                    </Button>
                    <Button variant="outline" class="rounded-xl" @click="showDelete = false">Tidak jadi</Button>
                </div>
            </div>

            <div
                v-if="props.opname.cancellation_reason"
                class="rounded-2xl border border-red-200 bg-red-50 px-5 py-4 text-sm text-red-900"
            >
                <p class="font-bold">
                    Dibatalkan oleh {{ props.opname.cancelled_by?.name ?? '—' }} pada {{ formatWhen(props.opname.cancelled_at) }}
                </p>
                <p class="mt-1">{{ props.opname.cancellation_reason }}</p>
            </div>

            <div class="grid gap-4 md:grid-cols-4">
                <Card class="rounded-2xl border-slate-200 shadow-sm">
                    <CardContent class="pt-6">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Produk dihitung</p>
                        <p class="mt-1 text-xl font-black text-slate-900">{{ props.opname.lines.length }}</p>
                    </CardContent>
                </Card>
                <Card class="rounded-2xl border-slate-200 shadow-sm">
                    <CardContent class="pt-6">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Item selisih</p>
                        <p class="mt-1 text-xl font-black text-slate-900">{{ affectedCount }}</p>
                    </CardContent>
                </Card>
                <Card class="rounded-2xl border-slate-200 shadow-sm">
                    <CardContent class="pt-6">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Nilai selisih</p>
                        <p class="mt-1 text-xl font-black tabular-nums" :class="valueClass(totalValue)">
                            {{ formatRupiah(totalValue) }}
                        </p>
                    </CardContent>
                </Card>
                <Card class="rounded-2xl border-slate-200 shadow-sm">
                    <CardContent class="pt-6">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Akurasi inventori</p>
                        <p class="mt-1 text-xl font-black tabular-nums text-slate-900">{{ accuracy.toFixed(1) }}%</p>
                    </CardContent>
                </Card>
            </div>

            <Card v-if="isDraft || props.imports.length > 0" class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <CardTitle class="text-base font-bold">Impor berkas hitung</CardTitle>
                    <CardDescription>
                        Unduh lembar hitung, isi kolom stok fisik di Excel saat menghitung di gudang, lalu unggah untuk melihat
                        preview selisih sebelum diterapkan.
                    </CardDescription>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div v-if="isDraft" class="flex flex-wrap items-center gap-3">
                        <Button variant="outline" class="rounded-xl" @click="downloadTemplate">
                            <Download class="mr-2 h-4 w-4" />
                            Unduh lembar hitung (.xlsx)
                        </Button>
                        <p class="text-xs text-slate-500">
                            Kolom stok sistem pada berkas adalah snapshot saat sesi dibuka.
                        </p>
                    </div>

                    <div v-if="isDraft" class="rounded-2xl border border-slate-200 p-4">
                        <p class="text-sm font-semibold text-slate-900">Unggah hasil hitung</p>
                        <div class="mt-3 grid gap-4 lg:grid-cols-2">
                            <div>
                                <label class="block">
                                    <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Berkas hasil hitung</span>
                                    <input
                                        ref="importFileInput"
                                        type="file"
                                        accept=".xlsx,.xls,.csv"
                                        class="mt-2 block w-full text-sm text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-900 file:px-3 file:py-1.5 file:text-white"
                                        :disabled="importForm.processing"
                                        @change="onImportFileChange"
                                    />
                                </label>
                                <p class="mt-1 text-xs text-slate-400">Format .xlsx, .xls, atau .csv · maksimal 10 MB</p>
                            </div>
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                                    Produk yang tidak ada di berkas
                                </p>
                                <label class="mt-2 flex items-start gap-2 text-sm text-slate-700">
                                    <input v-model="importForm.missing_mode" type="radio" value="ignore" class="mt-0.5" />
                                    <span>Abaikan (tidak dihitung)</span>
                                </label>
                                <label class="mt-1.5 flex items-start gap-2 text-sm text-slate-700">
                                    <input v-model="importForm.missing_mode" type="radio" value="zero" class="mt-0.5" />
                                    <span>Anggap stok 0 (selisih = −stok sistem)</span>
                                </label>
                            </div>
                        </div>

                        <p v-if="importForm.errors.file" class="mt-2 text-sm font-semibold text-red-700">
                            {{ importForm.errors.file }}
                        </p>
                        <p v-if="importForm.errors.missing_mode" class="mt-1 text-sm font-semibold text-red-700">
                            {{ importForm.errors.missing_mode }}
                        </p>

                        <Button class="mt-3 rounded-xl" :disabled="importForm.processing || !importFile" @click="submitImport">
                            <Loader2 v-if="importForm.processing" class="mr-2 h-4 w-4 animate-spin" />
                            <Upload v-else class="mr-2 h-4 w-4" />
                            {{ importForm.processing ? 'Mengunggah…' : 'Unggah dan proses' }}
                        </Button>
                    </div>

                    <div v-if="activeImport" class="rounded-2xl border border-slate-200 bg-slate-50/60 p-4">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <p class="font-semibold text-slate-900">{{ activeImport.file_name }}</p>
                                <p class="text-xs text-slate-500">
                                    Produk absen: {{ activeImport.missing_mode_label }}
                                    <span v-if="activeImport.applied_at" class="ml-1">
                                        · diterapkan {{ formatWhen(activeImport.applied_at) }}
                                    </span>
                                </p>
                            </div>
                            <div class="flex items-center gap-2">
                                <span
                                    class="rounded-full px-2.5 py-1 text-xs font-bold"
                                    :class="importStatusClass[activeImport.status] ?? 'bg-slate-100 text-slate-700'"
                                >
                                    {{ activeImport.status_label }}
                                </span>
                                <Button variant="ghost" size="sm" class="rounded-xl text-slate-500" @click="closeImportPanel">
                                    Tutup
                                </Button>
                            </div>
                        </div>

                        <div v-if="activeImport.status === 'pending' || activeImport.status === 'processing'" class="mt-4">
                            <div class="flex items-center gap-2 text-sm text-slate-600">
                                <Loader2 class="h-4 w-4 animate-spin" />
                                Memproses berkas… {{ activeImport.processed_rows }} dari {{ activeImport.total_rows }} baris
                            </div>
                            <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-200">
                                <div
                                    class="h-2 rounded-full bg-slate-900 transition-all"
                                    :style="{ width: importProgress + '%' }"
                                ></div>
                            </div>
                        </div>

                        <div v-else-if="activeImport.status === 'failed'" class="mt-4 rounded-xl border border-red-200 bg-red-50 p-4">
                            <p class="text-sm font-semibold text-red-800">Pemrosesan gagal — lembar hitung tidak berubah.</p>
                            <p class="mt-1 text-sm text-red-700">{{ activeImport.error_message }}</p>
                        </div>

                        <div v-else class="mt-4 space-y-4">
                            <div v-if="activeImport.summary" class="grid grid-cols-2 gap-3 md:grid-cols-5">
                                <div class="rounded-xl border border-slate-200 bg-white px-3 py-2">
                                    <p class="text-xs text-slate-500">Baris data</p>
                                    <p class="font-bold text-slate-900">{{ activeImport.summary.data_rows }}</p>
                                </div>
                                <div class="rounded-xl border border-slate-200 bg-white px-3 py-2">
                                    <p class="text-xs text-slate-500">Baris cocok</p>
                                    <p class="font-bold text-slate-900">{{ activeImport.summary.matched_rows }}</p>
                                </div>
                                <div class="rounded-xl border border-slate-200 bg-white px-3 py-2">
                                    <p class="text-xs text-slate-500">Baris error</p>
                                    <p class="font-bold" :class="activeImport.summary.error_rows > 0 ? 'text-red-600' : 'text-slate-900'">
                                        {{ activeImport.summary.error_rows }}
                                    </p>
                                </div>
                                <div class="rounded-xl border border-slate-200 bg-white px-3 py-2">
                                    <p class="text-xs text-slate-500">Produk absen</p>
                                    <p class="font-bold text-slate-900">{{ activeImport.summary.missing_lines }}</p>
                                </div>
                                <div class="rounded-xl border border-slate-200 bg-white px-3 py-2">
                                    <p class="text-xs text-slate-500">Nilai selisih</p>
                                    <p
                                        class="font-bold tabular-nums"
                                        :class="valueClass(activeImport.summary.total_variance_value)"
                                    >
                                        {{ formatRupiah(activeImport.summary.total_variance_value) }}
                                    </p>
                                </div>
                            </div>

                            <div v-if="activeImport.errors.length > 0" class="rounded-xl border border-red-200 bg-red-50 p-4">
                                <p class="text-sm font-semibold text-red-800">
                                    Laporan error per baris — perbaiki berkas lalu unggah ulang sebelum hasil dapat diterapkan.
                                </p>
                                <ul class="mt-2 max-h-52 list-inside list-disc space-y-1 overflow-y-auto">
                                    <li v-for="(message, idx) in activeImport.errors" :key="idx" class="text-xs text-red-700">
                                        {{ message }}
                                    </li>
                                </ul>
                            </div>

                            <div v-if="activeImport.preview.length > 0" class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
                                <table class="w-full text-sm">
                                    <thead>
                                        <tr
                                            class="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500"
                                        >
                                            <th class="py-2.5 pl-3 pr-4 font-semibold">Produk</th>
                                            <th class="py-2.5 pr-4 text-right font-semibold">Stok sistem</th>
                                            <th class="py-2.5 pr-4 text-right font-semibold">Stok fisik</th>
                                            <th class="py-2.5 pr-4 text-right font-semibold">Selisih</th>
                                            <th class="py-2.5 pr-4 text-right font-semibold">Nilai</th>
                                            <th class="py-2.5 pr-3 font-semibold">Catatan</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr
                                            v-for="row in activeImport.preview"
                                            :key="`${row.line_id}-${row.file_row ?? 'absen'}`"
                                            class="border-b border-slate-100"
                                            :class="row.missing ? 'bg-amber-50/60' : ''"
                                        >
                                            <td class="py-2.5 pl-3 pr-4">
                                                <p class="font-semibold text-slate-900">
                                                    {{ row.product_name
                                                    }}<span v-if="row.variant" class="text-slate-500"> — {{ row.variant }}</span>
                                                </p>
                                                <p class="text-xs text-slate-400">
                                                    {{ row.sku || '—' }}
                                                    <span v-if="row.batch_code"> · Batch {{ row.batch_code }}</span>
                                                    <span
                                                        v-if="row.missing"
                                                        class="ml-1 rounded-full bg-amber-100 px-1.5 py-0.5 text-[10px] font-bold text-amber-800"
                                                    >
                                                        tidak ada di berkas
                                                    </span>
                                                </p>
                                            </td>
                                            <td class="py-2.5 pr-4 text-right tabular-nums text-slate-600">
                                                {{ formatQty(row.system_quantity) }}
                                            </td>
                                            <td class="py-2.5 pr-4 text-right font-semibold tabular-nums text-slate-900">
                                                {{ formatQty(row.counted_quantity) }}
                                            </td>
                                            <td
                                                class="py-2.5 pr-4 text-right font-bold tabular-nums"
                                                :class="valueClass(row.variance_quantity)"
                                            >
                                                {{ Number(row.variance_quantity) > 0 ? '+' : ''
                                                }}{{ formatQty(row.variance_quantity) }}
                                            </td>
                                            <td
                                                class="py-2.5 pr-4 text-right font-semibold tabular-nums"
                                                :class="valueClass(row.variance_value)"
                                            >
                                                {{ formatRupiah(row.variance_value) }}
                                            </td>
                                            <td class="py-2.5 pr-3 text-xs text-slate-500">{{ row.note || '—' }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <p v-if="activeImport.preview_total > activeImport.preview.length" class="text-xs text-slate-500">
                                Menampilkan {{ activeImport.preview.length }} dari {{ activeImport.preview_total }} baris preview.
                            </p>

                            <div
                                v-if="activeImport.applied_at"
                                class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800"
                            >
                                Hasil hitung sudah diterapkan ke lembar hitung. Periksa kembali sebelum memposting sesi ini.
                            </div>
                            <div
                                v-else-if="activeImport.can_apply"
                                class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white p-4"
                            >
                                <p class="text-sm text-slate-600">
                                    Penyimpanan hanya memperbarui lembar hitung; stok berubah setelah sesi diposting.
                                </p>
                                <Button class="rounded-xl" :disabled="applyForm.processing" @click="showApplyConfirm = true">
                                    <CheckCircle2 class="mr-2 h-4 w-4" />
                                    Simpan ke lembar hitung
                                </Button>
                            </div>
                            <p v-else-if="activeImport.errors.length > 0" class="text-sm font-semibold text-red-700">
                                Hasil belum dapat diterapkan karena masih ada error pada berkas.
                            </p>
                            <div
                                v-else-if="activeImport.summary && activeImport.summary.counted_lines === 0"
                                class="rounded-xl border border-slate-200 bg-white p-4 text-sm text-slate-600"
                            >
                                Tidak ada baris yang cocok dengan produk pada sesi ini.
                            </div>

                            <p v-if="applyImportError" class="text-sm font-semibold text-red-700">
                                {{ applyImportError }}
                            </p>
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
                            <span class="flex items-center gap-2 text-xs text-slate-500">
                                <span>{{ imp.missing_mode_label }}</span>
                                <span
                                    class="rounded-full px-2 py-0.5 font-bold"
                                    :class="importStatusClass[imp.status] ?? 'bg-slate-100 text-slate-700'"
                                >
                                    {{ imp.status_label }}
                                </span>
                                <span
                                    v-if="imp.applied_at"
                                    class="rounded-full bg-emerald-100 px-2 py-0.5 font-bold text-emerald-800"
                                >
                                    diterapkan
                                </span>
                            </span>
                        </button>
                    </div>
                </CardContent>
            </Card>

            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <CardTitle class="text-base font-bold">Lembar hitung</CardTitle>
                    <CardDescription v-if="isDraft && !showSystemQty">
                        Stok sistem disembunyikan agar hitungan Anda tidak terpengaruh angka catatan.
                    </CardDescription>
                    <CardDescription v-else-if="isDraft">
                        Stok sistem terlihat. Pastikan Anda tetap menghitung barang secara fisik.
                    </CardDescription>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div class="relative">
                        <Search class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                        <input
                            v-model="search"
                            type="text"
                            placeholder="Cari atau pindai barcode produk…"
                            class="w-full rounded-xl border border-slate-200 py-2 pl-9 pr-3 text-sm focus:border-slate-900 focus:outline-none"
                        />
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500">
                                    <th class="py-3 pr-4 font-semibold">Produk</th>
                                    <th v-if="showSystemQty" class="py-3 pr-4 text-right font-semibold">Stok sistem</th>
                                    <th class="py-3 pr-4 text-right font-semibold">Jumlah fisik</th>
                                    <th v-if="showSystemQty" class="py-3 pr-4 text-right font-semibold">Selisih</th>
                                    <th v-if="showSystemQty" class="py-3 pr-4 text-right font-semibold">Nilai</th>
                                    <th class="py-3 font-semibold">Catatan</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="row in visibleRows"
                                    :key="row.id"
                                    class="border-b border-slate-100"
                                    :class="
                                        showSystemQty && Math.abs(variance(row.id, row.counted_quantity)) > 0.00001
                                            ? 'bg-amber-50/50'
                                            : ''
                                    "
                                >
                                    <td class="py-3 pr-4">
                                        <p class="font-semibold text-slate-900">
                                            {{ lineById(row.id)?.product?.name ?? '—'
                                            }}<span v-if="lineById(row.id)?.product_variant" class="text-slate-500">
                                                — {{ lineById(row.id)?.product_variant?.name }}</span
                                            >
                                        </p>
                                        <p class="text-xs text-slate-400">
                                            {{ lineById(row.id)?.product_variant?.sku ?? lineById(row.id)?.product?.sku ?? '—' }}
                                        </p>
                                    </td>
                                    <td v-if="showSystemQty" class="py-3 pr-4 text-right tabular-nums text-slate-600">
                                        {{ formatQty(lineById(row.id)?.system_quantity ?? 0) }}
                                    </td>
                                    <td class="py-3 pr-4 text-right">
                                        <input
                                            v-model="row.counted_quantity"
                                            type="number"
                                            min="0"
                                            :step="lineById(row.id)?.product?.allows_decimal ? '0.0001' : '1'"
                                            :disabled="!isDraft"
                                            class="w-28 rounded-lg border border-slate-200 px-2 py-1.5 text-right text-sm tabular-nums focus:border-slate-900 focus:outline-none disabled:bg-slate-50 disabled:opacity-70"
                                        />
                                        <span class="ml-1 text-xs text-slate-400">{{ lineById(row.id)?.product?.unit }}</span>
                                    </td>
                                    <td
                                        v-if="showSystemQty"
                                        class="py-3 pr-4 text-right font-bold tabular-nums"
                                        :class="valueClass(variance(row.id, row.counted_quantity))"
                                    >
                                        {{ variance(row.id, row.counted_quantity) > 0 ? '+' : ''
                                        }}{{ formatQty(variance(row.id, row.counted_quantity)) }}
                                    </td>
                                    <td
                                        v-if="showSystemQty"
                                        class="py-3 pr-4 text-right font-semibold tabular-nums"
                                        :class="valueClass(varianceValue(row.id, row.counted_quantity))"
                                    >
                                        {{ formatRupiah(varianceValue(row.id, row.counted_quantity)) }}
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

            <div
                v-if="isDraft"
                class="flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"
            >
                <p class="text-sm text-slate-600">
                    <span v-if="!isDirty && affectedCount > 0">
                        Data sudah tersimpan. Klik tombol "Lanjut ke Posting" untuk memposting stock opname ini.
                    </span>
                    <span v-else>
                        Simpan draft bila hitungan belum selesai. Sesi dapat dilanjutkan kapan saja tanpa kehilangan data.
                    </span>
                </p>
                <div class="flex items-center gap-3">
                    <span v-if="autoSaving" class="inline-flex items-center gap-1.5 text-xs text-slate-500">
                        <Loader2 class="h-3 w-3 animate-spin" />
                        Auto-save draft...
                    </span>
                    <span v-else-if="isDirty" class="text-xs text-amber-600 font-medium">
                        Ada perubahan belum tersimpan
                    </span>

                    <!-- Step 1: Simpan Draft (tampil jika ada perubahan) -->
                    <Button 
                        v-if="isDirty" 
                        variant="outline" 
                        class="rounded-xl" 
                        :disabled="draftForm.processing" 
                        @click="saveDraft"
                    >
                        <Save class="mr-2 h-4 w-4" />
                        {{ draftForm.processing ? 'Menyimpan...' : 'Simpan draft' }}
                    </Button>

                    <!-- Step 2: Lanjut ke Posting (tampil jika tidak ada perubahan dan bisa posting) -->
                    <Button 
                        v-if="!isDirty && props.canPost" 
                        class="rounded-xl" 
                        :disabled="draftForm.processing || postForm.processing" 
                        @click="openPostConfirm"
                    >
                        <CheckCircle2 class="mr-2 h-4 w-4" />
                        Lanjut ke Posting
                    </Button>
                </div>
            </div>

            <p v-if="isDraft && isDirty" class="text-center text-xs text-slate-500">
                Simpan draft dahulu sebelum dapat melanjutkan ke posting.
            </p>
        </div>
    </AdminLayout>

    <!-- Dialog konfirmasi posting -->
    <Dialog :open="showPostConfirm" @update:open="(v: boolean) => !v && (showPostConfirm = false)">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <div class="flex items-center gap-3">
                    <div class="flex items-center justify-center w-12 h-12 rounded-full bg-blue-100 shrink-0">
                        <CheckCircle2 class="h-6 w-6 text-blue-600" />
                    </div>
                    <div>
                        <DialogTitle class="text-lg font-bold">Konfirmasi Posting Stock Opname</DialogTitle>
                        <DialogDescription class="text-sm text-slate-500 mt-1">
                            Pastikan data hitungan sudah benar sebelum diposting
                        </DialogDescription>
                    </div>
                </div>
            </DialogHeader>

            <div class="space-y-4 py-4">
                <div class="rounded-xl bg-slate-50 p-4 space-y-3">
                    <div class="flex justify-between items-center">
                        <span class="text-sm text-slate-600">Total produk dihitung</span>
                        <span class="font-bold text-slate-900">{{ props.opname.lines.length }} item</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-sm text-slate-600">Item dengan selisih</span>
                        <span class="font-bold text-slate-900">{{ affectedCount }} item</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-sm text-slate-600">Nilai selisih total</span>
                        <span class="font-bold tabular-nums" :class="valueClass(totalValue)">
                            {{ formatRupiah(totalValue) }}
                        </span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-sm text-slate-600">Akurasi inventori</span>
                        <span class="font-bold text-slate-900">{{ accuracy.toFixed(1) }}%</span>
                    </div>
                </div>

                <div class="rounded-xl bg-amber-50 border border-amber-200 p-4">
                    <div class="flex gap-2">
                        <AlertTriangle class="h-5 w-5 text-amber-600 shrink-0 mt-0.5" />
                        <div class="text-sm text-amber-900">
                            <p class="font-semibold mb-1">Perhatian:</p>
                            <ul class="space-y-1 list-disc list-inside">
                                <li>Stok sistem akan langsung disesuaikan</li>
                                <li>Nilai persediaan akan berubah sesuai selisih</li>
                                <li>Jurnal akuntansi akan dibuat otomatis</li>
                                <li>Data tidak dapat diubah setelah diposting</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <p class="text-sm text-slate-600">
                    Apakah Anda yakin data hitungan sudah akurat dan siap untuk diposting?
                </p>
            </div>

            <DialogFooter class="flex flex-wrap justify-end gap-2">
                <Button 
                    variant="outline" 
                    class="rounded-xl" 
                    @click="showPostConfirm = false"
                    :disabled="postForm.processing"
                >
                    Periksa Kembali
                </Button>
                <Button 
                    class="rounded-xl bg-blue-600 hover:bg-blue-700" 
                    @click="postOpname"
                    :disabled="postForm.processing"
                >
                    <Loader2 v-if="postForm.processing" class="mr-2 h-4 w-4 animate-spin" />
                    <CheckCircle2 v-else class="mr-2 h-4 w-4" />
                    {{ postForm.processing ? 'Memposting...' : 'Ya, Posting Sekarang' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <!-- Dialog konfirmasi penerapan hasil impor ke lembar hitung -->
    <Dialog :open="showApplyConfirm" @update:open="(v: boolean) => !v && (showApplyConfirm = false)">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <div class="flex items-center gap-3">
                    <div class="flex items-center justify-center w-10 h-10 rounded-full bg-blue-100 shrink-0">
                        <CheckCircle2 class="h-5 w-5 text-blue-600" />
                    </div>
                    <div>
                        <DialogTitle class="text-base font-bold">Terapkan hasil hitung ke lembar hitung?</DialogTitle>
                        <DialogDescription class="text-sm text-slate-500">
                            Jumlah fisik dari berkas akan mengisi kolom hitung sesi ini dan menimpa isian manual yang belum
                            disimpan. Stok baru berubah setelah sesi diposting.
                        </DialogDescription>
                    </div>
                </div>
            </DialogHeader>
            <DialogFooter class="flex flex-wrap justify-end gap-2">
                <Button variant="outline" class="rounded-xl" :disabled="applyForm.processing" @click="showApplyConfirm = false">
                    Periksa lagi
                </Button>
                <Button class="rounded-xl" :disabled="applyForm.processing" @click="submitApply">
                    <Loader2 v-if="applyForm.processing" class="mr-2 h-4 w-4 animate-spin" />
                    <CheckCircle2 v-else class="mr-2 h-4 w-4" />
                    {{ applyForm.processing ? 'Menerapkan…' : 'Ya, terapkan' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <!-- Dialog peringatan navigasi saat ada perubahan tersimpan -->
    <Dialog :open="showNavigateWarning" @update:open="(v: boolean) => !v && cancelNavigation()">
        <DialogContent class="sm:max-w-md overflow-hidden">
            <DialogHeader>
                <div class="flex items-center gap-3">
                    <div class="flex items-center justify-center w-10 h-10 rounded-full bg-amber-100 shrink-0">
                        <AlertTriangle class="h-5 w-5 text-amber-600" />
                    </div>
                    <div>
                        <DialogTitle class="text-base font-bold">Data belum tersimpan</DialogTitle>
                        <DialogDescription class="text-sm text-slate-500">
                            Anda memiliki data hitung stok opname yang belum disimpan. Jika Anda meninggalkan halaman ini, perubahan yang belum disimpan akan hilang.
                        </DialogDescription>
                    </div>
                </div>
            </DialogHeader>
            <DialogFooter class="flex flex-wrap justify-end gap-2">
                <Button class="rounded-xl bg-amber-600 hover:bg-amber-700 text-white whitespace-nowrap" :disabled="autoSaving" @click="saveAndNavigate">
                    <Loader2 v-if="autoSaving" class="mr-2 h-4 w-4 animate-spin" />
                    <Save v-else class="mr-2 h-4 w-4" />
                    {{ autoSaving ? 'Menyimpan...' : 'Simpan draft & tinggalkan' }}
                </Button>
                <Button variant="ghost" class="rounded-xl text-slate-600 whitespace-nowrap" @click="cancelNavigation">
                    Tetap di Halaman Ini
                </Button>
                <Button variant="outline" class="rounded-xl text-red-600 border-red-200 hover:bg-red-50 whitespace-nowrap" @click="discardAndNavigate">
                    Tinggalkan Halaman
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
