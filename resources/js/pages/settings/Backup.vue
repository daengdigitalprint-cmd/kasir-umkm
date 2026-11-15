<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Download, Trash2, HardDrive, Clock, AlertCircle } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import { type BreadcrumbItem } from '@/types';

interface ScheduledBackup {
    filename: string;
    size: number;
    created_at: number;
}

interface Props {
    store: {
        id: number;
        name: string;
    } | null;
    scheduled_backups: ScheduledBackup[];
    /** Backup otomatis harian khusus paket berbayar (premium). */
    auto_backup_enabled: boolean;
}

const props = defineProps<Props>();

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'Backup Data',
        href: '/settings/backup',
    },
];

const isDownloading = ref(false);
const deletingFilename = ref<string | null>(null);

function formatBytes(bytes: number): string {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return Math.round(bytes / Math.pow(k, i)) + ' ' + sizes[i];
}

function formatDate(timestamp: number): string {
    return new Date(timestamp * 1000).toLocaleString('id-ID', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

async function downloadManual() {
    if (!props.store) return;
    
    isDownloading.value = true;
    try {
        const response = await fetch('/settings/backup/download', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
            },
        });

        if (!response.ok) {
            throw new Error('Gagal membuat backup');
        }

        const blob = await response.blob();
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        
        const contentDisposition = response.headers.get('content-disposition');
        const filenameMatch = contentDisposition?.match(/filename="(.+)"/);
        const filename = filenameMatch ? filenameMatch[1] : `backup-${Date.now()}.json`;
        
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        window.URL.revokeObjectURL(url);
        document.body.removeChild(a);
    } catch (error) {
        alert('Gagal mengunduh backup: ' + (error instanceof Error ? error.message : 'Unknown error'));
    } finally {
        isDownloading.value = false;
    }
}

function downloadScheduled(filename: string) {
    window.location.href = `/settings/backup/scheduled/${filename}`;
}

function deleteScheduled(filename: string) {
    if (!confirm(`Hapus backup "${filename}"?`)) return;
    
    deletingFilename.value = filename;
    
    router.delete(`/settings/backup/scheduled/${filename}`, {
        onFinish: () => {
            deletingFilename.value = null;
        },
    });
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Backup Data" />

        <SettingsLayout>
            <div class="space-y-6">
                <HeadingSmall 
                    title="Backup Data" 
                    description="Backup otomatis harian (jam 02:00, paket premium) dan unduh manual kapan saja. Data mencakup transaksi, produk, pelanggan, pembelian, stok, dan jurnal akunting." 
                />

                <div v-if="!store" class="flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 p-4 text-amber-800">
                    <AlertCircle class="mt-0.5 h-4 w-4 shrink-0" />
                    <p class="text-sm">
                        Store tidak ditemukan. Pastikan Anda sudah memiliki toko aktif.
                    </p>
                </div>

                <template v-else>
                    <!-- Download Manual -->
                    <div class="rounded-lg border border-slate-200 bg-white p-6">
                        <div class="flex items-start justify-between">
                            <div>
                                <h3 class="text-lg font-semibold text-slate-900">Backup Manual</h3>
                                <p class="mt-1 text-sm text-slate-600">
                                    Unduh backup lengkap data toko Anda sekarang dalam format JSON.
                                </p>
                            </div>
                            <Button 
                                @click="downloadManual" 
                                :disabled="isDownloading"
                                class="flex items-center gap-2"
                            >
                                <Download class="h-4 w-4" />
                                {{ isDownloading ? 'Membuat backup...' : 'Unduh Sekarang' }}
                            </Button>
                        </div>
                    </div>

                    <!-- Backup Terjadwal -->
                    <div class="rounded-lg border border-slate-200 bg-white p-6">
                        <div class="mb-4 flex items-center gap-2">
                            <Clock class="h-5 w-5 text-slate-600" />
                            <h3 class="text-lg font-semibold text-slate-900">Backup Otomatis Harian</h3>
                        </div>
                        <p v-if="auto_backup_enabled" class="mb-4 text-sm text-slate-600">
                            Backup otomatis berjalan setiap hari jam 02:00 WIB. Sistem menyimpan 7 backup terakhir.
                        </p>
                        <div
                            v-else
                            class="mb-4 flex flex-col gap-3 rounded-lg border border-amber-200 bg-amber-50 p-4 sm:flex-row sm:items-center sm:justify-between"
                        >
                            <p class="text-sm text-amber-900">
                                <strong>Khusus paket premium.</strong> Paket Anda saat ini tidak dicadangkan otomatis; backup manual tetap tersedia.
                            </p>
                            <a
                                href="/subscription"
                                class="inline-flex shrink-0 items-center justify-center rounded-xl bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-700"
                            >
                                Upgrade Paket
                            </a>
                        </div>

                        <div v-if="scheduled_backups.length === 0" class="rounded-lg bg-slate-50 p-8 text-center">
                            <HardDrive class="mx-auto h-12 w-12 text-slate-400" />
                            <p class="mt-2 text-sm text-slate-600">Belum ada backup terjadwal tersimpan.</p>
                            <p v-if="auto_backup_enabled" class="mt-1 text-xs text-slate-500">Backup pertama akan dibuat besok jam 02:00.</p>
                        </div>

                        <div v-else class="space-y-2">
                            <div 
                                v-for="backup in scheduled_backups" 
                                :key="backup.filename"
                                class="flex items-center justify-between rounded-lg border border-slate-200 bg-slate-50 p-4"
                            >
                                <div class="flex items-center gap-3">
                                    <HardDrive class="h-5 w-5 text-slate-500" />
                                    <div>
                                        <p class="font-medium text-slate-900">{{ backup.filename }}</p>
                                        <p class="text-xs text-slate-500">
                                            {{ formatBytes(backup.size) }} · {{ formatDate(backup.created_at) }}
                                        </p>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2">
                                    <Button 
                                        @click="downloadScheduled(backup.filename)" 
                                        variant="outline"
                                        size="sm"
                                        class="flex items-center gap-2"
                                    >
                                        <Download class="h-4 w-4" />
                                        Unduh
                                    </Button>
                                    <Button 
                                        @click="deleteScheduled(backup.filename)" 
                                        variant="destructive"
                                        size="sm"
                                        :disabled="deletingFilename === backup.filename"
                                        class="flex items-center gap-2"
                                    >
                                        <Trash2 class="h-4 w-4" />
                                        {{ deletingFilename === backup.filename ? 'Menghapus...' : 'Hapus' }}
                                    </Button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Informasi -->
                    <div class="rounded-lg bg-blue-50 p-4">
                        <p class="text-sm text-blue-900">
                            <strong>Catatan:</strong> Backup mencakup semua data penjualan, produk (termasuk varian & batch), 
                            pelanggan, supplier, pembelian & retur, stok movements & transfers, pre-order, diskon, voucher, 
                            cash shifts, dan jurnal akunting (chart of accounts + journal entries). File JSON dapat digunakan 
                            untuk restore atau analisis eksternal.
                        </p>
                    </div>
                </template>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
