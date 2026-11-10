<script setup lang="ts">
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { CalendarCheck, ChevronRight, Clock, Inbox, Umbrella, UserX, Wallet } from 'lucide-vue-next';

/**
 * Ringkasan SDM lintas outlet untuk Dashboard Owner: kehadiran hari ini,
 * pengajuan yang menunggu, dan status gaji periode terakhir per outlet.
 */
export interface HrOutlet {
    id: number;
    name: string;
    employees: number;
    today: { present: number; late: number; on_leave: number; not_yet: number; not_yet_names: string[] };
    pending_leave: number;
    pending_overtime: number;
    payroll: { name: string; status: string; total_net: number } | null;
}

export interface HrSummary {
    outlets: HrOutlet[];
    totals: {
        employees: number;
        present: number;
        late: number;
        on_leave: number;
        not_yet: number;
        pending_leave: number;
        pending_overtime: number;
    };
}

const props = defineProps<{ hr: HrSummary }>();

const payrollStatus: Record<string, { label: string; cls: string }> = {
    draft: { label: 'Draf', cls: 'bg-slate-100 text-slate-600' },
    approved: { label: 'Disetujui', cls: 'bg-indigo-100 text-indigo-700' },
    paid: { label: 'Dibayar', cls: 'bg-emerald-100 text-emerald-700' },
};

const rupiah = (n: number) => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(n);

const pendingTotal = computed(() => props.hr.totals.pending_leave + props.hr.totals.pending_overtime);

const tiles = computed(() => [
    { label: 'Hadir', value: props.hr.totals.present, icon: CalendarCheck, cls: 'text-emerald-600 bg-emerald-50' },
    { label: 'Telat', value: props.hr.totals.late, icon: Clock, cls: 'text-amber-600 bg-amber-50' },
    { label: 'Belum absen', value: props.hr.totals.not_yet, icon: UserX, cls: 'text-red-600 bg-red-50' },
    { label: 'Cuti/Izin', value: props.hr.totals.on_leave, icon: Umbrella, cls: 'text-sky-600 bg-sky-50' },
]);

/** Buka halaman SDM outlet tertentu: pindahkan toko aktif lalu kunjungi halaman. */
const opening = ref<string | null>(null);
const open = (outlet: HrOutlet, path: string) => {
    opening.value = `${outlet.id}${path}`;
    router.post('/stores/switch', { store_id: outlet.id }, { onSuccess: () => router.visit(path), onFinish: () => (opening.value = null) });
};
</script>

<template>
    <section class="mb-6 rounded-2xl bg-white p-4 shadow-lg lg:p-6">
        <div class="flex flex-wrap items-center gap-2">
            <h2 class="text-lg font-bold text-gray-900">SDM hari ini</h2>
            <span class="text-sm text-slate-500">· {{ hr.totals.employees }} karyawan di {{ hr.outlets.length }} outlet</span>
            <span v-if="pendingTotal" class="ml-auto flex items-center gap-1.5 rounded-full bg-red-50 px-3 py-1 text-xs font-bold text-red-700">
                <Inbox class="h-4 w-4" /> {{ pendingTotal }} pengajuan menunggu
            </span>
        </div>

        <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div v-for="tile in tiles" :key="tile.label" class="flex items-center gap-3 rounded-xl p-3 ring-1 ring-slate-100">
                <span class="flex h-10 w-10 items-center justify-center rounded-xl" :class="tile.cls">
                    <component :is="tile.icon" class="h-5 w-5" />
                </span>
                <span>
                    <span class="block text-2xl font-extrabold leading-none text-slate-900">{{ tile.value }}</span>
                    <span class="text-xs text-slate-500">{{ tile.label }}</span>
                </span>
            </div>
        </div>

        <div class="mt-4 divide-y divide-slate-100 rounded-xl ring-1 ring-slate-100">
            <div v-for="outlet in hr.outlets" :key="outlet.id" class="flex flex-wrap items-center gap-x-4 gap-y-2 p-3">
                <div class="min-w-[9rem] flex-1">
                    <p class="font-semibold text-slate-900">{{ outlet.name }}</p>
                    <p class="text-xs text-slate-500">
                        Hadir {{ outlet.today.present }}/{{ outlet.employees }}
                        <template v-if="outlet.today.late"> · telat {{ outlet.today.late }}</template>
                        <template v-if="outlet.today.on_leave"> · cuti {{ outlet.today.on_leave }}</template>
                    </p>
                    <p v-if="outlet.today.not_yet" class="text-xs font-medium text-red-600">
                        Belum absen: {{ outlet.today.not_yet_names.join(', ') }}<template v-if="outlet.today.not_yet > outlet.today.not_yet_names.length"> +{{ outlet.today.not_yet - outlet.today.not_yet_names.length }}</template>
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2 text-xs">
                    <button
                        v-if="outlet.pending_leave + outlet.pending_overtime"
                        type="button"
                        class="flex items-center gap-1 rounded-full bg-red-500 px-3 py-1.5 font-bold text-white hover:bg-red-600 disabled:opacity-60"
                        :disabled="opening !== null"
                        @click="open(outlet, outlet.pending_leave ? '/attendance/leaves' : '/attendance/overtimes')"
                    >
                        {{ outlet.pending_leave + outlet.pending_overtime }} pengajuan <ChevronRight class="h-3.5 w-3.5" />
                    </button>
                    <span v-if="outlet.payroll" class="flex items-center gap-1.5 rounded-full px-2.5 py-1 font-semibold" :class="payrollStatus[outlet.payroll.status]?.cls">
                        <Wallet class="h-3.5 w-3.5" />
                        {{ outlet.payroll.name }} · {{ payrollStatus[outlet.payroll.status]?.label ?? outlet.payroll.status }} · {{ rupiah(outlet.payroll.total_net) }}
                    </span>
                    <button
                        type="button"
                        class="flex items-center gap-1 rounded-full bg-slate-100 px-3 py-1.5 font-semibold text-slate-700 hover:bg-slate-200 disabled:opacity-60"
                        :disabled="opening !== null"
                        @click="open(outlet, '/attendance/report')"
                    >
                        Kehadiran <ChevronRight class="h-3.5 w-3.5" />
                    </button>
                </div>
            </div>
        </div>
    </section>
</template>
