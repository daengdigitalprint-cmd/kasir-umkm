<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { CheckCircle2, ChevronDown, ChevronRight, Circle, Rocket } from 'lucide-vue-next';

export interface SetupStep {
    key: string;
    title: string;
    description: string;
    href: string;
    done: boolean;
}

const props = defineProps<{
    setup: { steps: SetupStep[]; done: number; total: number; complete: boolean };
}>();

// Panduan terbuka selama masih ada langkah yang belum selesai; bisa diciutkan.
const open = ref(!props.setup.complete);
const nextStep = computed(() => props.setup.steps.find((step) => !step.done));
const percent = computed(() => Math.round((props.setup.done / props.setup.total) * 100));
</script>

<template>
    <section class="overflow-hidden rounded-2xl border border-indigo-100 bg-gradient-to-br from-indigo-50 via-white to-white shadow-sm">
        <button type="button" class="flex w-full items-center gap-3 px-5 py-4 text-left" @click="open = !open">
            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-indigo-600 text-white">
                <CheckCircle2 v-if="setup.complete" class="h-5 w-5" />
                <Rocket v-else class="h-5 w-5" />
            </span>
            <span class="min-w-0 flex-1">
                <span class="block font-semibold text-slate-800">
                    {{ setup.complete ? 'Absensi & gaji siap dipakai' : 'Panduan memulai absensi & gaji' }}
                </span>
                <span class="block text-sm text-slate-500">
                    <template v-if="setup.complete">Semua langkah awal sudah selesai. Buka lagi panduan kapan saja.</template>
                    <template v-else>
                        {{ setup.done }} dari {{ setup.total }} langkah selesai<span v-if="nextStep"> · berikutnya: <b class="text-indigo-700">{{ nextStep.title }}</b></span>
                    </template>
                </span>
            </span>
            <span class="hidden w-32 sm:block">
                <span class="block h-2 overflow-hidden rounded-full bg-indigo-100">
                    <span class="block h-full rounded-full bg-indigo-600 transition-all" :style="{ width: percent + '%' }"></span>
                </span>
            </span>
            <ChevronDown class="h-5 w-5 shrink-0 text-slate-400 transition" :class="open ? 'rotate-180' : ''" />
        </button>

        <ol v-if="open" class="grid gap-2 border-t border-indigo-100 p-4 sm:grid-cols-2 lg:grid-cols-3">
            <li v-for="(step, i) in setup.steps" :key="step.key">
                <Link
                    :href="step.href"
                    class="group flex h-full items-start gap-3 rounded-xl border bg-white p-3 transition hover:border-indigo-300 hover:shadow-sm"
                    :class="step === nextStep ? 'border-indigo-400 ring-2 ring-indigo-100' : 'border-slate-200'"
                >
                    <CheckCircle2 v-if="step.done" class="mt-0.5 h-5 w-5 shrink-0 text-emerald-500" />
                    <Circle v-else class="mt-0.5 h-5 w-5 shrink-0 text-slate-300" />
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-semibold" :class="step.done ? 'text-slate-500' : 'text-slate-800'">
                            {{ i + 1 }}. {{ step.title }}
                        </span>
                        <span class="mt-0.5 block text-xs leading-relaxed text-slate-500">{{ step.description }}</span>
                    </span>
                    <ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-slate-300 group-hover:text-indigo-500" />
                </Link>
            </li>
        </ol>
    </section>
</template>
