<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref, reactive, onMounted } from 'vue';
import {
    Download,
    Trash2,
    HardDrive,
    Clock,
    AlertCircle,
    Search,
    RefreshCw,
    RotateCcw,
    CheckCircle2,
    XCircle,
    Timer,
    User,
    ChevronLeft,
    ChevronRight,
    AlertTriangle,
    Database,
} from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { confirmDelete, toastSuccess, toastError } from '@/lib/swal';

interface Props {
    store: {
        id: number;
        name: string;
    } | null;
}

defineProps<Props>();

interface BackupHistoryItem {
    id: number;
    filename: string;
    filepath: string;
    file_size: number;
    status: 'pending' | 'completed' | 'failed';
    trigger_type: 'manual' | 'scheduled' | 'restore';
    duration_ms: number | null;
    records_count: number;
    records_summary: Record<string, number> | null;
    error_message: string | null;
    created_at: string;
    user: { id: number; name: string } | null;
}

interface HistoryResponse {
    success: boolean;
    data: BackupHistoryItem[];
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
}

// State
const history = ref<BackupHistoryItem[]>([]);
const meta = ref({ current_page: 1, last_page: 1, per_page: 20, total: 0 });
const isLoading = ref(false);
const isCreating = ref(false);
const searchQuery = ref('');
const filterStatus = ref('');
const filterTrigger = ref('');
const currentPage = ref(1);

// Restore state
const restoreDialogOpen = ref(false);
const restoreTarget = ref<BackupHistoryItem | null>(null);
const isRestoring = ref(false);
const restoreLog = ref<{ restored: string[]; errors: string[] } | null>(null);
const restoreStep = ref<'confirm' | 'progress' | 'done'>('confirm');

// Delete state
const deletingId = ref<number | null>(null);

// Download state
const downloadingId = ref<number | null>(null);

// CSRF helper
function getCsrf(): string {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

function formatBytes(bytes: number): string {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return Math.round(bytes / Math.pow(k, i)) + ' ' + sizes[i];
}

function formatDate(iso: string): string {
    return new Date(iso).toLocaleString('id-ID', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function formatDuration(ms: number | null): string {
    if (!ms) return '-';
    if (ms < 1000) return `${ms}ms`;
    return `${(ms / 1000).toFixed(1)}s`;
}

function triggerLabel(type: string): string {
    return { manual: 'Manual', scheduled: 'Otomatis', restore: 'Pemulihan' }[type] ?? type;
}

function triggerColor(type: string): string {
    return { manual: 'bg-blue-100 text-blue-800', scheduled: 'bg-purple-100 text-purple-800', restore: 'bg-amber-100 text-amber-800' }[type] ?? 'bg-slate-100 text-slate-800';
}

function statusIcon(status: string) {
    return { completed: CheckCircle2, failed: XCircle, pending: Timer }[status] ?? Timer;
}

function statusColor(status: string): string {
    return { completed: 'text-green-600', failed: 'text-red-600', pending: 'text-yellow-600' }[status] ?? 'text-slate-400';
}

function statusLabel(status: string): string {
    return { completed: 'Berhasil', failed: 'Gagal', pending: 'Diproses' }[status] ?? status;
}

// Fetch history
async function fetchHistory(page = 1) {
    isLoading.value = true;
    currentPage.value = page;
    try {
        const params = new URLSearchParams({ page: String(page) });
        if (searchQuery.value) params.set('search', searchQuery.value);
        if (filterStatus.value) params.set('status', filterStatus.value);
        if (filterTrigger.value) params.set('trigger_type', filterTrigger.value);

        const res = await fetch(`/settings/backup/history?${params}`);
        const json: HistoryResponse = await res.json();

        if (json.success) {
            history.value = json.data;
            meta.value = json.meta;
        }
    } catch (e) {
        console.error('Failed to fetch backup history:', e);
    } finally {
        isLoading.value = false;
    }
}

// Create backup
async function createBackup() {
    isCreating.value = true;
    try {
        const res = await fetch('/settings/backup/create', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCsrf() },
        });
        const json = await res.json();
        if (json.success) {
            fetchHistory(1);
        } else {
            toastError(json.message || 'Gagal membuat backup');
        }
    } catch (e) {
        toastError('Gagal membuat backup: ' + (e instanceof Error ? e.message : 'Unknown error'));
    } finally {
        isCreating.value = false;
    }
}

// Download backup
async function downloadBackup(item: BackupHistoryItem) {
    downloadingId.value = item.id;
    try {
        const res = await fetch('/settings/backup/download', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCsrf() },
            body: JSON.stringify({ backup_id: item.id }),
        });

        if (!res.ok) {
            const err = await res.json();
            throw new Error(err.message || 'Gagal mengunduh');
        }

        const blob = await res.blob();
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = item.filename;
        document.body.appendChild(a);
        a.click();
        window.URL.revokeObjectURL(url);
        document.body.removeChild(a);
    } catch (e) {
        toastError('Gagal mengunduh: ' + (e instanceof Error ? e.message : 'Unknown error'));
    } finally {
        downloadingId.value = null;
    }
}

// Delete backup
async function deleteBackup(item: BackupHistoryItem) {
    const ok = await confirmDelete(
        'Hapus Backup?',
        `Backup "${item.filename}" akan dihapus permanen. Tindakan ini tidak dapat dibatalkan.`,
    );
    if (!ok) return;

    deletingId.value = item.id;
    try {
        const res = await fetch(`/settings/backup/${item.id}`, {
            method: 'DELETE',
            headers: { 'X-CSRF-TOKEN': getCsrf() },
        });
        const json = await res.json();
        if (json.success) {
            fetchHistory(currentPage.value);
        } else {
            toastError(json.message || 'Gagal mengunduh');
        }
    } catch (e) {
        toastError('Gagal mengunduh: ' + (e instanceof Error ? e.message : 'Unknown error'));
    } finally {
        deletingId.value = null;
    }
}

// Restore
function openRestoreDialog(item: BackupHistoryItem) {
    restoreTarget.value = item;
    restoreStep.value = 'confirm';
    restoreLog.value = null;
    restoreDialogOpen.value = true;
}

function closeRestoreDialog() {
    restoreDialogOpen.value = false;
    restoreTarget.value = null;
    restoreStep.value = 'confirm';
    restoreLog.value = null;
}

async function confirmRestore() {
    if (!restoreTarget.value) return;

    isRestoring.value = true;
    restoreStep.value = 'progress';

    try {
        const res = await fetch('/settings/backup/restore', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCsrf() },
            body: JSON.stringify({ backup_id: restoreTarget.value.id }),
        });
        const json = await res.json();

        if (json.success) {
            restoreLog.value = json.log;
            restoreStep.value = 'done';
        } else {
            toastError(json.message || 'Gagal memulihkan data');
            restoreStep.value = 'confirm';
        }
    } catch (e) {
        toastError('Gagal memulihkan: ' + (e instanceof Error ? e.message : 'Unknown error'));
        restoreStep.value = 'confirm';
    } finally {
        isRestoring.value = false;
    }
}

function applyFilter() {
    fetchHistory(1);
}

function clearFilters() {
    searchQuery.value = '';
    filterStatus.value = '';
    filterTrigger.value = '';
    fetchHistory(1);
}

onMounted(() => fetchHistory());
</script>

<template>
    <div class="space-y-6">
        <!-- Alert no store -->
        <div v-if="!store" class="flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 p-4 text-amber-800">
            <AlertCircle class="mt-0.5 h-4 w-4 shrink-0" />
            <p class="text-sm">Store tidak ditemukan. Pastikan Anda sudah memiliki toko aktif.</p>
        </div>

        <template v-else>
            <!-- Tombol Buat Backup -->
            <div class="rounded-lg border border-slate-200 bg-white p-6">
                <div class="flex items-start justify-between">
                    <div>
                        <h3 class="text-lg font-semibold text-slate-900">Buat Backup Baru</h3>
                        <p class="mt-1 text-sm text-slate-600">
                            Buat cadangan lengkap data toko dan simpan ke server.
                        </p>
                    </div>
                    <Button @click="createBackup" :disabled="isCreating" class="flex items-center gap-2">
                        <HardDrive class="h-4 w-4" />
                        {{ isCreating ? 'Membuat backup...' : 'Backup Sekarang' }}
                    </Button>
                </div>
            </div>

            <!-- Riwayat Pencadangan -->
            <div class="rounded-lg border border-slate-200 bg-white p-6">
                <div class="mb-4 flex items-center gap-2">
                    <Clock class="h-5 w-5 text-slate-600" />
                    <h3 class="text-lg font-semibold text-slate-900">Riwayat Pencadangan</h3>
                </div>

                <!-- Filter & Pencarian -->
                <div class="mb-4 flex flex-wrap gap-3">
                    <div class="relative flex-1 min-w-[200px]">
                        <Search class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                        <input
                            v-model="searchQuery"
                            type="text"
                            placeholder="Cari nama file..."
                            class="w-full rounded-lg border border-slate-300 bg-white py-2 pl-9 pr-3 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                            @keyup.enter="applyFilter"
                        />
                    </div>
                    <select
                        v-model="filterStatus"
                        class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                    >
                        <option value="">Semua Status</option>
                        <option value="completed">Berhasil</option>
                        <option value="failed">Gagal</option>
                        <option value="pending">Diproses</option>
                    </select>
                    <select
                        v-model="filterTrigger"
                        class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                    >
                        <option value="">Semua Tipe</option>
                        <option value="manual">Manual</option>
                        <option value="scheduled">Otomatis</option>
                        <option value="restore">Pemulihan</option>
                    </select>
                    <Button variant="outline" size="sm" @click="applyFilter" class="flex items-center gap-1">
                        <Search class="h-3.5 w-3.5" />
                        Filter
                    </Button>
                    <Button variant="ghost" size="sm" @click="clearFilters" class="flex items-center gap-1">
                        <RefreshCw class="h-3.5 w-3.5" />
                        Reset
                    </Button>
                </div>

                <!-- Loading -->
                <div v-if="isLoading" class="flex items-center justify-center py-12">
                    <RefreshCw class="h-6 w-6 animate-spin text-slate-400" />
                    <span class="ml-2 text-sm text-slate-500">Memuat riwayat...</span>
                </div>

                <!-- Empty state -->
                <div v-else-if="history.length === 0" class="rounded-lg bg-slate-50 p-8 text-center">
                    <HardDrive class="mx-auto h-12 w-12 text-slate-400" />
                    <p class="mt-2 text-sm text-slate-600">Belum ada riwayat pencadangan.</p>
                    <p class="mt-1 text-xs text-slate-500">Klik "Backup Sekarang" untuk membuat cadangan pertama.</p>
                </div>

                <!-- History list -->
                <div v-else class="space-y-3">
                    <div
                        v-for="item in history"
                        :key="item.id"
                        class="rounded-lg border border-slate-200 bg-slate-50 p-4 transition hover:bg-slate-100"
                    >
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <!-- Left: info -->
                            <div class="flex items-start gap-3">
                                <component
                                    :is="statusIcon(item.status)"
                                    :class="['mt-0.5 h-5 w-5 shrink-0', statusColor(item.status)]"
                                />
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p class="font-medium text-slate-900 truncate">{{ item.filename }}</p>
                                        <span
                                            :class="[
                                                'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
                                                triggerColor(item.trigger_type),
                                            ]"
                                        >
                                            {{ triggerLabel(item.trigger_type) }}
                                        </span>
                                        <span
                                            :class="[
                                                'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
                                                item.status === 'completed'
                                                    ? 'bg-green-100 text-green-800'
                                                    : item.status === 'failed'
                                                      ? 'bg-red-100 text-red-800'
                                                      : 'bg-yellow-100 text-yellow-800',
                                            ]"
                                        >
                                            {{ statusLabel(item.status) }}
                                        </span>
                                    </div>
                                    <div class="mt-1 flex flex-wrap items-center gap-3 text-xs text-slate-500">
                                        <span class="flex items-center gap-1">
                                            <Database class="h-3 w-3" />
                                            {{ formatBytes(item.file_size) }}
                                        </span>
                                        <span class="flex items-center gap-1">
                                            <Timer class="h-3 w-3" />
                                            {{ formatDuration(item.duration_ms) }}
                                        </span>
                                        <span>{{ item.records_count }} records</span>
                                        <span class="flex items-center gap-1">
                                            <Clock class="h-3 w-3" />
                                            {{ formatDate(item.created_at) }}
                                        </span>
                                        <span v-if="item.user" class="flex items-center gap-1">
                                            <User class="h-3 w-3" />
                                            {{ item.user.name }}
                                        </span>
                                    </div>
                                    <!-- Records summary -->
                                    <div v-if="item.records_summary && Object.keys(item.records_summary).length > 0" class="mt-2 flex flex-wrap gap-2">
                                        <span
                                            v-for="(count, label) in item.records_summary"
                                            :key="String(label)"
                                            class="inline-flex items-center gap-1 rounded bg-white px-2 py-0.5 text-xs text-slate-600 ring-1 ring-slate-200"
                                        >
                                            {{ label }}: {{ count }}
                                        </span>
                                    </div>
                                    <!-- Error message -->
                                    <p v-if="item.error_message" class="mt-1 text-xs text-red-600">
                                        {{ item.error_message }}
                                    </p>
                                </div>
                            </div>

                            <!-- Right: actions -->
                            <div class="flex shrink-0 items-center gap-2">
                                <Button
                                    v-if="item.status === 'completed'"
                                    variant="outline"
                                    size="sm"
                                    :disabled="downloadingId === item.id"
                                    @click="downloadBackup(item)"
                                    class="flex items-center gap-1"
                                >
                                    <Download class="h-3.5 w-3.5" />
                                    Unduh
                                </Button>
                                <Button
                                    v-if="item.status === 'completed'"
                                    variant="outline"
                                    size="sm"
                                    @click="openRestoreDialog(item)"
                                    class="flex items-center gap-1 text-amber-700 hover:bg-amber-50 hover:text-amber-800"
                                >
                                    <RotateCcw class="h-3.5 w-3.5" />
                                    Restore
                                </Button>
                                <Button
                                    variant="destructive"
                                    size="sm"
                                    :disabled="deletingId === item.id"
                                    @click="deleteBackup(item)"
                                    class="flex items-center gap-1"
                                >
                                    <Trash2 class="h-3.5 w-3.5" />
                                </Button>
                            </div>
                        </div>
                    </div>

                    <!-- Pagination -->
                    <div v-if="meta.last_page > 1" class="flex items-center justify-between pt-2">
                        <span class="text-xs text-slate-500">
                            Menampilkan {{ (meta.current_page - 1) * meta.per_page + 1 }}-{{ Math.min(meta.current_page * meta.per_page, meta.total) }} dari {{ meta.total }} backup
                        </span>
                        <div class="flex items-center gap-1">
                            <Button
                                variant="outline"
                                size="sm"
                                :disabled="meta.current_page <= 1"
                                @click="fetchHistory(meta.current_page - 1)"
                            >
                                <ChevronLeft class="h-4 w-4" />
                            </Button>
                            <span class="px-2 text-sm text-slate-600">
                                {{ meta.current_page }} / {{ meta.last_page }}
                            </span>
                            <Button
                                variant="outline"
                                size="sm"
                                :disabled="meta.current_page >= meta.last_page"
                                @click="fetchHistory(meta.current_page + 1)"
                            >
                                <ChevronRight class="h-4 w-4" />
                            </Button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Info -->
            <div class="rounded-lg bg-blue-50 p-4">
                <p class="text-sm text-blue-900">
                    <strong>Catatan:</strong> Backup mencakup data penjualan, produk, pelanggan,
                    supplier, pembelian, stok, diskon, cash shifts, dan jurnal akunting.
                    Backup otomatis berjalan setiap jam 02:00 dan menyimpan 7 backup terakhir.
                </p>
            </div>

            <!-- Restore Dialog -->
            <Teleport to="body">
                <div
                    v-if="restoreDialogOpen"
                    class="fixed inset-0 z-50 flex items-center justify-center p-4"
                >
                    <!-- Backdrop -->
                    <div class="fixed inset-0 bg-black/50" @click="!isRestoring && closeRestoreDialog()" />

                    <!-- Dialog -->
                    <div class="relative z-10 w-full max-w-lg rounded-xl bg-white shadow-2xl">
                        <!-- Step: Confirm -->
                        <template v-if="restoreStep === 'confirm'">
                            <div class="p-6">
                                <div class="flex items-center gap-3">
                                    <div class="flex h-10 w-10 items-center justify-center rounded-full bg-amber-100">
                                        <AlertTriangle class="h-5 w-5 text-amber-600" />
                                    </div>
                                    <div>
                                        <h3 class="text-lg font-semibold text-slate-900">Konfirmasi Pemulihan</h3>
                                        <p class="text-sm text-slate-500">Tindakan ini tidak dapat dibatalkan</p>
                                    </div>
                                </div>
                                <div class="mt-4 rounded-lg bg-amber-50 p-4 text-sm text-amber-800">
                                    <p class="font-medium">Peringatan: Seluruh data saat ini akan DIHAPUS</p>
                                    <ul class="mt-2 list-inside list-disc space-y-1">
                                        <li>Semua data produk, transaksi, dan pembelian akan diganti dengan data dari backup</li>
                                        <li>Data yang sudah ada tidak dapat dipulihkan kecuali ada backup lain</li>
                                        <li>Pastikan Anda memilih backup yang benar</li>
                                    </ul>
                                </div>
                                <div class="mt-4 rounded-lg bg-slate-50 p-3">
                                    <p class="text-sm text-slate-600">
                                        <strong>File:</strong> {{ restoreTarget?.filename }}<br />
                                        <strong>Ukuran:</strong> {{ formatBytes(restoreTarget?.file_size ?? 0) }}<br />
                                        <strong>Tanggal:</strong> {{ formatDate(restoreTarget?.created_at ?? '') }}<br />
                                        <strong>Records:</strong> {{ restoreTarget?.records_count ?? 0 }}
                                    </p>
                                </div>
                            </div>
                            <div class="flex justify-end gap-3 border-t border-slate-200 px-6 py-4">
                                <Button variant="outline" @click="closeRestoreDialog">Batal</Button>
                                <Button variant="destructive" @click="confirmRestore" class="flex items-center gap-2">
                                    <RotateCcw class="h-4 w-4" />
                                    Ya, Pulihkan Data
                                </Button>
                            </div>
                        </template>

                        <!-- Step: Progress -->
                        <template v-else-if="restoreStep === 'progress'">
                            <div class="p-6 text-center">
                                <RefreshCw class="mx-auto h-12 w-12 animate-spin text-blue-500" />
                                <p class="mt-4 text-lg font-semibold text-slate-900">Memulihkan Data...</p>
                                <p class="mt-1 text-sm text-slate-500">Mohon tunggu, jangan tutup halaman ini.</p>
                            </div>
                        </template>

                        <!-- Step: Done -->
                        <template v-else-if="restoreStep === 'done'">
                            <div class="p-6">
                                <div class="flex items-center gap-3">
                                    <div class="flex h-10 w-10 items-center justify-center rounded-full bg-green-100">
                                        <CheckCircle2 class="h-5 w-5 text-green-600" />
                                    </div>
                                    <div>
                                        <h3 class="text-lg font-semibold text-slate-900">Pemulihan Berhasil</h3>
                                        <p class="text-sm text-slate-500">Data telah dipulihkan dari backup</p>
                                    </div>
                                </div>

                                <div v-if="restoreLog" class="mt-4 space-y-3">
                                    <div v-if="restoreLog.restored.length > 0" class="rounded-lg bg-green-50 p-3">
                                        <p class="text-sm font-medium text-green-800">Berhasil dipulihkan:</p>
                                        <ul class="mt-1 list-inside list-disc text-sm text-green-700">
                                            <li v-for="item in restoreLog.restored" :key="item">{{ item }}</li>
                                        </ul>
                                    </div>
                                    <div v-if="restoreLog.errors.length > 0" class="rounded-lg bg-red-50 p-3">
                                        <p class="text-sm font-medium text-red-800">Error:</p>
                                        <ul class="mt-1 list-inside list-disc text-sm text-red-700">
                                            <li v-for="item in restoreLog.errors" :key="item">{{ item }}</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                            <div class="flex justify-end border-t border-slate-200 px-6 py-4">
                                <Button @click="closeRestoreDialog">Tutup</Button>
                            </div>
                        </template>
                    </div>
                </div>
            </Teleport>
        </template>
    </div>
</template>
