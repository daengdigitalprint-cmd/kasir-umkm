<script setup lang="ts">
import { computed, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { AlertTriangle, X } from 'lucide-vue-next';
import { router } from '@inertiajs/vue3';

type UnclosedPeriod = { id: number; name: string; end_date: string };

const page = usePage();
const reminders = computed<UnclosedPeriod[]>(
    () => (page.props.unclosed_period_reminders as UnclosedPeriod[] | undefined) ?? [],
);

const dismissed = ref(false);

const show = computed(() => reminders.value.length > 0 && !dismissed.value);

const message = computed(() => {
    if (reminders.value.length === 1) {
        return `Periode "${reminders.value[0].name}" belum ditutup. Segera lakukan penutupan buku.`;
    }
    const names = reminders.value.map((r) => r.name).join(', ');
    return `Ada ${reminders.value.length} periode yang belum ditutup: ${names}. Segera lakukan penutupan buku.`;
});

const goToPeriods = () => router.visit('/financial/accounting-periods');
</script>

<template>
    <div
        v-if="show"
        class="mb-4 flex items-start gap-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-amber-800"
        role="alert"
    >
        <AlertTriangle class="mt-0.5 h-5 w-5 shrink-0" />
        <div class="flex-1 text-sm">
            <p class="font-semibold">Pengingat tutup buku</p>
            <p class="mt-0.5">{{ message }}</p>
            <button
                class="mt-2 rounded-lg bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-amber-700"
                @click="goToPeriods"
            >
                Tutup periode sekarang
            </button>
        </div>
        <button class="text-amber-500 hover:text-amber-700" aria-label="Tutup pengingat" @click="dismissed = true">
            <X class="h-4 w-4" />
        </button>
    </div>
</template>
