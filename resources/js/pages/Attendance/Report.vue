<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import AttendanceNav from '@/components/AttendanceNav.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import ReportExportButton from '@/components/ReportExportButton.vue';
import { confirmDelete } from '@/lib/swal';

interface SummaryRow {
    id: number;
    name: string;
    position: string | null;
    work_days: number;
    present_days: number;
    late_days: number;
    absent_days: number;
    leave_days: number;
    total_hours: number;
    total_overtime: number;
    total_late_min: number;
}

interface RecordRow {
    id: number;
    work_date: string;
    employee_name: string | null;
    clock_in: string | null;
    clock_out: string | null;
    status: string;
    late_minutes: number;
    hours_worked: number | null;
    overtime_hours: number | null;
}

const props = defineProps<{
    from: string;
    to: string;
    summary: SummaryRow[];
    records: RecordRow[];
}>();

const from = ref(props.from);
const to = ref(props.to);

const totals = computed(() => ({
    work_days: props.summary.reduce((sum, row) => sum + row.work_days, 0),
    present_days: props.summary.reduce((sum, row) => sum + row.present_days, 0),
    late_days: props.summary.reduce((sum, row) => sum + row.late_days, 0),
    absent_days: props.summary.reduce((sum, row) => sum + row.absent_days, 0),
    leave_days: props.summary.reduce((sum, row) => sum + row.leave_days, 0),
    total_hours: props.summary.reduce((sum, row) => sum + row.total_hours, 0),
    total_overtime: props.summary.reduce((sum, row) => sum + row.total_overtime, 0),
    total_late_min: props.summary.reduce((sum, row) => sum + row.total_late_min, 0),
}));

const statusLabel = (status: string) =>
    ({
        present: 'Hadir',
        late: 'Terlambat',
        absent: 'Absen',
        leave: 'Cuti',
        sick: 'Sakit',
        holiday: 'Libur',
        day_off: 'Off',
    })[status] ?? status;

const DONUT_R = 40;
const DONUT_C = 2 * Math.PI * DONUT_R;

const chart = computed(() => {
    const segs = [
        { label: 'Hadir', value: totals.value.present_days, color: '#22c55e' },
        { label: 'Telat', value: totals.value.late_days, color: '#eab308' },
        { label: 'Absen', value: totals.value.absent_days, color: '#ef4444' },
        { label: 'Cuti', value: totals.value.leave_days, color: '#94a3b8' },
    ].filter((s) => s.value > 0);
    const total = segs.reduce((sum, s) => sum + s.value, 0);
    return { segs, total };
});

const donutSegments = computed(() => {
    let offset = 0;
    return chart.value.segs.map((s) => {
        const dash = chart.value.total ? (s.value / chart.value.total) * DONUT_C : 0;
        const seg = { ...s, dash, offset };
        offset += dash;
        return seg;
    });
});

const employeeSegments = computed(() =>
    props.summary.map((row) => {
        const items = [
            { label: 'Hadir', value: row.present_days, color: '#22c55e' },
            { label: 'Telat', value: row.late_days, color: '#eab308' },
            { label: 'Absen', value: row.absent_days, color: '#ef4444' },
            { label: 'Cuti', value: row.leave_days, color: '#94a3b8' },
        ];
        const total = items.reduce((sum, i) => sum + i.value, 0);
        return { name: row.name, items, total };
    }),
);

const filter = () => {
    router.get('/attendance/report', { from: from.value, to: to.value }, { preserveState: true });
};

const editing = ref<RecordRow | null>(null);
const editForm = useForm({
    clock_in: '',
    clock_out: '',
});

const openEdit = (record: RecordRow) => {
    editing.value = record;
    editForm.clearErrors();
    editForm.clock_in = record.clock_in ?? '';
    editForm.clock_out = record.clock_out ?? '';
};

const closeEdit = () => {
    editing.value = null;
    editForm.clearErrors();
};

const saveEdit = () => {
    if (!editing.value) return;
    editForm.post(`/attendance/report/${editing.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            editing.value = null;
        },
    });
};

const deleteRecord = async (record: RecordRow) => {
    const confirmed = await confirmDelete(
        'Hapus absensi?',
        `Absensi ${record.employee_name ?? ''} tanggal ${record.work_date} akan dihapus. Karyawan dapat melakukan absen ulang.`,
    );
    if (!confirmed) return;
    router.delete(`/attendance/report/${record.id}`, { preserveScroll: true });
};
</script>

<template>
    <Head title="Laporan Absensi" />

    <AdminLayout title="Laporan Absensi">
        <div class="space-y-6 pb-8">
            <AttendanceNav />

            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader class="flex flex-row items-start justify-between gap-4">
                    <div>
                        <CardTitle>Filter Periode</CardTitle>
                        <CardDescription>Rekap kehadiran karyawan per rentang tanggal.</CardDescription>
                    </div>
                    <ReportExportButton report="absensi" :params="{ from, to }" />
                </CardHeader>
                <CardContent>
                    <div class="flex flex-wrap items-end gap-4">
                        <div class="space-y-1">
                            <Label>Dari</Label>
                            <Input v-model="from" type="date" />
                        </div>
                        <div class="space-y-1">
                            <Label>Sampai</Label>
                            <Input v-model="to" type="date" />
                        </div>
                        <Button type="button" class="rounded-xl" @click="filter">Tampilkan</Button>
                    </div>
                </CardContent>
            </Card>

            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <CardTitle>Grafik Kehadiran</CardTitle>
                    <CardDescription>Komposisi status kehadiran selama periode terpilih.</CardDescription>
                </CardHeader>
                <CardContent>
                    <div v-if="chart.total" class="grid gap-8 md:grid-cols-2">
                        <div class="flex items-center gap-6">
                            <svg
                                viewBox="0 0 120 120"
                                class="h-40 w-40 -rotate-90"
                                role="img"
                                aria-label="Komposisi status kehadiran"
                            >
                                <circle cx="60" cy="60" :r="DONUT_R" fill="none" stroke="#e2e8f0" stroke-width="16" />
                                <circle
                                    v-for="seg in donutSegments"
                                    :key="seg.label"
                                    cx="60"
                                    cy="60"
                                    :r="DONUT_R"
                                    fill="none"
                                    :stroke="seg.color"
                                    stroke-width="16"
                                    :stroke-dasharray="`${seg.dash} ${DONUT_C - seg.dash}`"
                                    :stroke-dashoffset="-seg.offset"
                                />
                            </svg>
                            <div class="space-y-2 text-sm">
                                <div v-for="seg in chart.segs" :key="seg.label" class="flex items-center gap-2">
                                    <span class="h-3 w-3 rounded-full" :style="{ backgroundColor: seg.color }"></span>
                                    <span class="text-slate-600">{{ seg.label }}</span>
                                    <span class="ml-auto font-medium">{{ seg.value }} hari</span>
                                </div>
                            </div>
                        </div>

                        <div class="space-y-4">
                            <div v-for="emp in employeeSegments" :key="emp.name" class="space-y-1">
                                <div class="flex items-center justify-between text-xs">
                                    <span class="font-medium text-slate-700">{{ emp.name }}</span>
                                    <span class="text-slate-500">{{ emp.total }} hari</span>
                                </div>
                                <div class="flex h-3 w-full overflow-hidden rounded-full bg-slate-100">
                                    <div
                                        v-for="item in emp.items.filter((i) => i.value > 0)"
                                        :key="item.label"
                                        class="h-full"
                                        :style="{ width: `${(item.value / emp.total) * 100}%`, backgroundColor: item.color }"
                                        :title="`${item.label}: ${item.value}`"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>
                    <p v-else class="py-6 text-center text-sm text-slate-400">
                        Belum ada data kehadiran untuk digambarkan.
                    </p>
                </CardContent>
            </Card>

            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <CardTitle>Rekap per Karyawan</CardTitle>
                    <CardDescription>
                        {{ from }} s.d. {{ to }}
                    </CardDescription>
                </CardHeader>
                <CardContent class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-slate-500">
                            <tr class="border-b border-slate-200">
                                <th class="py-2 pr-3">Karyawan</th>
                                <th class="py-2 pr-3">Hari</th>
                                <th class="py-2 pr-3">Hadir</th>
                                <th class="py-2 pr-3">Telat</th>
                                <th class="py-2 pr-3">Absen</th>
                                <th class="py-2 pr-3">Cuti</th>
                                <th class="py-2 pr-3">Jam Kerja</th>
                                <th class="py-2 pr-3">Lembur</th>
                                <th class="py-2">Telat (menit)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in summary" :key="row.id" class="border-b border-slate-100">
                                <td class="py-2 pr-3">{{ row.name }}</td>
                                <td class="py-2 pr-3">{{ row.work_days }}</td>
                                <td class="py-2 pr-3">{{ row.present_days }}</td>
                                <td class="py-2 pr-3">{{ row.late_days }}</td>
                                <td class="py-2 pr-3">{{ row.absent_days }}</td>
                                <td class="py-2 pr-3">{{ row.leave_days }}</td>
                                <td class="py-2 pr-3">{{ row.total_hours }}</td>
                                <td class="py-2 pr-3">{{ row.total_overtime }}</td>
                                <td class="py-2">{{ row.total_late_min }}</td>
                            </tr>
                            <tr v-if="summary.length" class="border-t border-slate-200 font-medium">
                                <td class="py-2 pr-3">Total</td>
                                <td class="py-2 pr-3">{{ totals.work_days }}</td>
                                <td class="py-2 pr-3">{{ totals.present_days }}</td>
                                <td class="py-2 pr-3">{{ totals.late_days }}</td>
                                <td class="py-2 pr-3">{{ totals.absent_days }}</td>
                                <td class="py-2 pr-3">{{ totals.leave_days }}</td>
                                <td class="py-2 pr-3">{{ totals.total_hours }}</td>
                                <td class="py-2 pr-3">{{ totals.total_overtime }}</td>
                                <td class="py-2">{{ totals.total_late_min }}</td>
                            </tr>
                            <tr v-if="!summary.length">
                                <td colspan="9" class="py-6 text-center text-slate-400">Belum ada data absensi.</td>
                            </tr>
                        </tbody>
                    </table>
                </CardContent>
            </Card>

            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <CardTitle>Detail Harian</CardTitle>
                </CardHeader>
                <CardContent class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-slate-500">
                            <tr class="border-b border-slate-200">
                                <th class="py-2 pr-3">Tanggal</th>
                                <th class="py-2 pr-3">Karyawan</th>
                                <th class="py-2 pr-3">Masuk</th>
                                <th class="py-2 pr-3">Pulang</th>
                                <th class="py-2 pr-3">Status</th>
                                <th class="py-2 pr-3">Telat</th>
                                <th class="py-2 pr-3">Jam</th>
                                <th class="py-2 pr-3">Lembur</th>
                                <th class="py-2">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="record in records" :key="record.id" class="border-b border-slate-100">
                                <td class="py-2 pr-3">{{ record.work_date }}</td>
                                <td class="py-2 pr-3">{{ record.employee_name ?? '—' }}</td>
                                <td class="py-2 pr-3">{{ record.clock_in ?? '—' }}</td>
                                <td class="py-2 pr-3">{{ record.clock_out ?? '—' }}</td>
                                <td class="py-2 pr-3">{{ statusLabel(record.status) }}</td>
                                <td class="py-2 pr-3">{{ record.late_minutes }} m</td>
                                <td class="py-2 pr-3">{{ record.hours_worked ?? '—' }}</td>
                                <td class="py-2 pr-3">{{ record.overtime_hours ?? '—' }}</td>
                                <td class="py-2">
                                    <div class="flex gap-2">
                                        <Button type="button" size="sm" variant="outline" class="rounded-lg" @click="openEdit(record)">
                                            Revisi
                                        </Button>
                                        <Button type="button" size="sm" variant="destructive" class="rounded-lg" @click="deleteRecord(record)">
                                            Hapus
                                        </Button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!records.length">
                                <td colspan="9" class="py-6 text-center text-slate-400">Belum ada detail absensi.</td>
                            </tr>
                        </tbody>
                    </table>
                </CardContent>
            </Card>

            <!-- Modal revisi jam absen -->
            <div v-if="editing" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                <div class="absolute inset-0 bg-black/50" @click="closeEdit"></div>
                <div class="relative w-full max-w-sm rounded-2xl bg-white p-6 shadow-xl">
                    <h3 class="text-lg font-bold">Revisi Jam Absen</h3>
                    <p class="mt-1 text-sm text-slate-500">{{ editing.employee_name ?? '—' }} · {{ editing.work_date }}</p>

                    <div class="mt-4 space-y-3">
                        <div class="space-y-1">
                            <Label>Jam Masuk</Label>
                            <Input v-model="editForm.clock_in" type="time" />
                            <p v-if="editForm.errors.clock_in" class="text-xs text-red-600">{{ editForm.errors.clock_in }}</p>
                        </div>
                        <div class="space-y-1">
                            <Label>Jam Pulang</Label>
                            <Input v-model="editForm.clock_out" type="time" />
                            <p v-if="editForm.errors.clock_out" class="text-xs text-red-600">{{ editForm.errors.clock_out }}</p>
                        </div>
                    </div>

                    <div class="mt-5 flex gap-2">
                        <Button type="button" variant="outline" class="flex-1 rounded-xl" @click="closeEdit">Batal</Button>
                        <Button type="button" class="flex-1 rounded-xl" :disabled="editForm.processing" @click="saveEdit">
                            {{ editForm.processing ? 'Menyimpan...' : 'Simpan' }}
                        </Button>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
