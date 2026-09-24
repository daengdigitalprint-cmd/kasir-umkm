<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { CheckCircle2, FileText, Lock } from 'lucide-vue-next';

const props = defineProps<{
    reportType: string;
    periodEnd: string;
    reportStatus: {
        status: 'draft' | 'final' | 'closed';
        label: string;
        finalized_at: string | null;
        finalized_by: string | null;
    };
}>();

const setStatus = (status: 'draft' | 'final') => {
    router.post(
        '/financial/report-status',
        {
            report_type: props.reportType,
            period_end: props.periodEnd,
            status,
        },
        { preserveScroll: true, preserveState: true },
    );
};
</script>

<template>
    <div class="flex items-center gap-2">
        <span
            class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold"
            :class="{
                'bg-slate-100 text-slate-600': reportStatus.status === 'draft',
                'bg-emerald-100 text-emerald-700': reportStatus.status === 'final',
                'bg-amber-100 text-amber-800': reportStatus.status === 'closed',
            }"
        >
            <FileText v-if="reportStatus.status === 'draft'" class="h-3.5 w-3.5" />
            <CheckCircle2 v-else-if="reportStatus.status === 'final'" class="h-3.5 w-3.5" />
            <Lock v-else class="h-3.5 w-3.5" />
            {{ reportStatus.label }}
        </span>

        <template v-if="reportStatus.status !== 'closed'">
            <Button
                v-if="reportStatus.status === 'draft'"
                size="sm"
                variant="outline"
                @click="setStatus('final')"
            >
                Jadikan final
            </Button>
            <Button v-else size="sm" variant="outline" @click="setStatus('draft')">
                Kembali ke draft
            </Button>
        </template>

        <span v-if="reportStatus.finalized_by" class="text-xs text-slate-400">
            oleh {{ reportStatus.finalized_by }}
        </span>
    </div>
</template>
