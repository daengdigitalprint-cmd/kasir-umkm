<script setup lang="ts">
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import AttendanceNav from '@/components/AttendanceNav.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Clock, CheckCircle2, XCircle, MinusCircle } from 'lucide-vue-next';

interface AttendanceRecord {
    id: number;
    work_date: string;
    clock_in: string | null;
    clock_out: string | null;
    status: string;
    late_minutes: number;
    hours_worked: number | null;
    overtime_hours: number | null;
    notes: string | null;
}

const props = defineProps<{
    records: AttendanceRecord[];
    from: string;
    to: string;
}>();

const from = ref(props.from);
const to = ref(props.to);

const statusBadge = (status: string) =>
    ({
        present: { label: 'Hadir', class: 'bg-green-100 text-green-700', icon: CheckCircle2 },
        late: { label: 'Terlambat', class: 'bg-yellow-100 text-yellow-700', icon: Clock },
        absent: { label: 'Absen', class: 'bg-red-100 text-red-700', icon: XCircle },
        leave: { label: 'Cuti', class: 'bg-slate-100 text-slate-600', icon: MinusCircle },
        sick: { label: 'Sakit', class: 'bg-orange-100 text-orange-700', icon: MinusCircle },
        holiday: { label: 'Libur', class: 'bg-blue-100 text-blue-700', icon: MinusCircle },
        day_off: { label: 'Off', class: 'bg-slate-50 text-slate-400', icon: MinusCircle },
    }[status] ?? { label: status, class: 'bg-slate-100 text-slate-600', icon: Clock });

const filter = () => {
    router.get('/attendance/history', { from: from.value, to: to.value }, { preserveState: true });
};
</script>

<template>
    <Head title="Riwayat Absensi" />

    <AdminLayout title="Riwayat Absensi">
        <div class="space-y-6 pb-8">
            <AttendanceNav />

            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <CardTitle>Filter Periode</CardTitle>
                    <CardDescription>Lihat riwayat berdasarkan rentang tanggal.</CardDescription>
                </CardHeader>
                <CardContent>
                    <form @submit.prevent="filter" class="flex flex-col gap-4 sm:flex-row sm:items-end">
                        <div class="grid gap-2">
                            <Label class="text-xs font-semibold text-slate-500">Dari</Label>
                            <Input type="date" v-model="from" class="w-full rounded-xl sm:w-44" />
                        </div>
                        <div class="grid gap-2">
                            <Label class="text-xs font-semibold text-slate-500">Sampai</Label>
                            <Input type="date" v-model="to" class="w-full rounded-xl sm:w-44" />
                        </div>
                        <Button type="submit" class="rounded-xl bg-indigo-600 hover:bg-indigo-700">Terapkan</Button>
                    </form>
                </CardContent>
            </Card>

            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <CardTitle>Riwayat Kehadiran</CardTitle>
                    <CardDescription>{{ records.length }} record ditemukan.</CardDescription>
                </CardHeader>
                <CardContent>
                    <!-- Desktop table -->
                    <div class="hidden overflow-x-auto md:block">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="border-b border-slate-100 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">
                                    <th class="px-4 py-3">Tanggal</th>
                                    <th class="px-4 py-3">Masuk</th>
                                    <th class="px-4 py-3">Pulang</th>
                                    <th class="px-4 py-3">Status</th>
                                    <th class="px-4 py-3 text-right">Telat (mnt)</th>
                                    <th class="px-4 py-3 text-right">Jam Kerja</th>
                                    <th class="px-4 py-3 text-right">Lembur</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr v-for="r in records" :key="r.id" class="hover:bg-slate-50/60 transition-colors">
                                    <td class="whitespace-nowrap px-4 py-3 font-medium text-slate-800">
                                        {{ new Date(r.work_date + 'T00:00:00').toLocaleDateString('id-ID', { weekday: 'short', day: '2-digit', month: 'short', year: 'numeric' }) }}
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 font-mono text-slate-700">{{ r.clock_in ?? '—' }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 font-mono text-slate-700">{{ r.clock_out ?? '—' }}</td>
                                    <td class="whitespace-nowrap px-4 py-3">
                                        <Badge :class="[statusBadge(r.status).class, 'rounded-lg text-xs font-semibold']">
                                            {{ statusBadge(r.status).label }}
                                        </Badge>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right font-mono text-slate-700">
                                        {{ r.late_minutes ? `−${r.late_minutes}` : '—' }}
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right font-mono text-slate-700">{{ r.hours_worked ?? '—' }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right font-mono text-slate-700">{{ r.overtime_hours ?? '—' }}</td>
                                </tr>
                                <tr v-if="records.length === 0">
                                    <td colspan="7" class="px-4 py-12 text-center text-slate-400">
                                        Tidak ada data untuk periode ini.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Mobile cards -->
                    <div class="space-y-3 md:hidden">
                        <div
                            v-for="r in records"
                            :key="r.id"
                            class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm"
                        >
                            <div class="mb-2 flex items-center justify-between">
                                <span class="text-sm font-semibold text-slate-800">
                                    {{ new Date(r.work_date + 'T00:00:00').toLocaleDateString('id-ID', { weekday: 'short', day: '2-digit', month: 'short', year: 'numeric' }) }}
                                </span>
                                <Badge :class="[statusBadge(r.status).class, 'rounded-lg text-xs font-semibold']">
                                    {{ statusBadge(r.status).label }}
                                </Badge>
                            </div>
                            <div class="grid grid-cols-2 gap-2 text-xs text-slate-600">
                                <div><span class="text-slate-400">Masuk:</span> {{ r.clock_in ?? '—' }}</div>
                                <div><span class="text-slate-400">Pulang:</span> {{ r.clock_out ?? '—' }}</div>
                                <div><span class="text-slate-400">Telat:</span> {{ r.late_minutes ? `${r.late_minutes} mnt` : '—' }}</div>
                                <div><span class="text-slate-400">Jam Kerja:</span> {{ r.hours_worked ?? '—' }} jam</div>
                            </div>
                        </div>
                        <div v-if="records.length === 0" class="py-12 text-center text-sm text-slate-400">
                            Tidak ada data untuk periode ini.
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AdminLayout>
</template>
