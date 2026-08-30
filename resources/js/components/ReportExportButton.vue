<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue';
import { Button } from '@/components/ui/button';
import { Download, FileText, FileSpreadsheet, ChevronDown } from 'lucide-vue-next';

/**
 * Tombol export laporan yang dipakai ulang di semua halaman laporan.
 * Meneruskan filter aktif (tanggal / product_id / user_id, dst) ke endpoint export generik.
 */
const props = defineProps<{
    // Kunci laporan sesuai builder di ReportExportController (mis. 'penjualan')
    report: string;
    // Parameter query filter yang sedang aktif di halaman
    params?: Record<string, string | number | null | undefined>;
}>();

const open = ref(false);
const toggle = () => (open.value = !open.value);
const close = () => (open.value = false);

const buildUrl = (format: 'pdf' | 'excel') => {
    const qs = new URLSearchParams();
    Object.entries(props.params ?? {}).forEach(([k, v]) => {
        if (v !== null && v !== undefined && v !== '') qs.append(k, String(v));
    });
    const query = qs.toString();
    return `/reports/export/${props.report}/${format}${query ? '?' + query : ''}`;
};

const exportAs = (format: 'pdf' | 'excel') => {
    window.open(buildUrl(format), '_blank');
    close();
};

const handleClickOutside = (e: Event) => {
    const target = e.target as HTMLElement;
    if (!target.closest('.report-export-menu')) close();
};

onMounted(() => document.addEventListener('click', handleClickOutside));
onUnmounted(() => document.removeEventListener('click', handleClickOutside));
</script>

<template>
    <div class="relative report-export-menu">
        <Button @click="toggle" variant="outline" class="inline-flex items-center gap-2 shrink-0">
            <Download class="h-4 w-4" />
            <span class="hidden sm:inline">Export</span>
            <ChevronDown class="h-4 w-4" />
        </Button>

        <div
            v-if="open"
            class="absolute right-0 mt-2 w-52 bg-white rounded-xl shadow-lg border border-slate-200 z-50"
        >
            <div class="py-1">
                <button
                    type="button"
                    @click="exportAs('pdf')"
                    class="flex items-center w-full px-4 py-2.5 text-sm text-slate-700 hover:bg-slate-50 rounded-lg"
                >
                    <FileText class="h-4 w-4 mr-3 text-primary" />
                    Export ke PDF
                </button>
                <button
                    type="button"
                    @click="exportAs('excel')"
                    class="flex items-center w-full px-4 py-2.5 text-sm text-slate-700 hover:bg-slate-50 rounded-lg"
                >
                    <FileSpreadsheet class="h-4 w-4 mr-3 text-primary" />
                    Export ke Excel
                </button>
            </div>
        </div>
    </div>
</template>
