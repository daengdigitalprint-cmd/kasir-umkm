<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { ref, watch } from 'vue';

const props = defineProps<{
    reportType: string;
    periodEnd: string;
    reportStatus: {
        status: 'draft' | 'final' | 'closed';
        label: string;
        finalized_at: string | null;
        finalized_by: string | null;
        report_number: string | null;
        issued_at: string | null;
        prepared_by: string | null;
        reviewed_by: string | null;
    };
}>();

const reviewer = ref(props.reportStatus.reviewed_by ?? '');

watch(
    () => props.reportStatus.reviewed_by,
    (v) => {
        reviewer.value = v ?? '';
    },
);

const saveReviewer = () => {
    router.post(
        '/financial/report-reviewer',
        {
            report_type: props.reportType,
            period_end: props.periodEnd,
            reviewed_by: reviewer.value,
        },
        { preserveScroll: true, preserveState: true },
    );
};

const formatDate = (s: string | null) =>
    s
        ? new Date(s).toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric' })
        : '—';
</script>

<template>
    <div class="mb-6 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500 mb-3">Identitas Laporan</p>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 text-sm">
            <div>
                <p class="text-xs text-slate-500 mb-1">Nomor Laporan</p>
                <p class="font-semibold text-slate-900">{{ reportStatus.report_number ?? '—' }}</p>
            </div>
            <div>
                <p class="text-xs text-slate-500 mb-1">Tanggal Penerbitan</p>
                <p class="font-semibold text-slate-900">{{ formatDate(reportStatus.issued_at) }}</p>
            </div>
            <div>
                <p class="text-xs text-slate-500 mb-1">Penyusun</p>
                <p class="font-semibold text-slate-900">{{ reportStatus.prepared_by ?? '—' }}</p>
            </div>
            <div>
                <p class="text-xs text-slate-500 mb-1">Pemeriksa</p>
                <template v-if="reportStatus.status === 'final'">
                    <div class="flex gap-2">
                        <Input v-model="reviewer" class="h-8 text-sm" placeholder="Nama pemeriksa" />
                        <Button size="sm" variant="outline" @click="saveReviewer">Simpan</Button>
                    </div>
                </template>
                <p v-else class="font-semibold text-slate-900">{{ reportStatus.reviewed_by ?? '—' }}</p>
            </div>
        </div>
    </div>
</template>
