<script setup lang="ts">
import { Head, useForm, router } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import AttendanceNav from '@/components/AttendanceNav.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Check, X, Trash2 } from 'lucide-vue-next';

interface Employee {
    id: number;
    name: string;
    position: string | null;
}

interface Overtime {
    id: number;
    employee_id: number;
    employee_name: string | null;
    work_date: string;
    hours: number;
    status: string;
    reason: string | null;
}

const props = defineProps<{
    overtimes: Overtime[];
    employees: Employee[];
}>();

const statusLabel: Record<string, string> = {
    pending: 'Menunggu',
    approved: 'Disetujui',
    rejected: 'Ditolak',
};

const form = useForm({
    employee_id: '',
    work_date: '',
    hours: 1,
    reason: '',
});

const submit = () => {
    form.post('/attendance/overtimes', { preserveScroll: true, onSuccess: () => form.reset() });
};

const decide = (id: number, status: 'approved' | 'rejected') => {
    router.patch(`/attendance/overtimes/${id}`, { status }, { preserveScroll: true });
};

const remove = (id: number) => {
    if (confirm('Hapus pengajuan lembur ini?')) {
        router.delete(`/attendance/overtimes/${id}`, { preserveScroll: true });
    }
};

const fmtDate = (iso: string) => new Date(iso).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });
</script>

<template>
    <Head title="Lembur" />

    <AdminLayout title="Lembur Karyawan">
        <div class="space-y-6 pb-8">
            <AttendanceNav />

            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <CardTitle>Ajukan Lembur</CardTitle>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div class="space-y-1">
                            <Label>Karyawan</Label>
                            <select v-model="form.employee_id" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm">
                                <option value="" disabled>Pilih karyawan</option>
                                <option v-for="employee in employees" :key="employee.id" :value="String(employee.id)">
                                    {{ employee.name }}
                                </option>
                            </select>
                            <p v-if="form.errors.employee_id" class="text-sm text-red-600">{{ form.errors.employee_id }}</p>
                        </div>
                        <div class="space-y-1">
                            <Label>Tanggal</Label>
                            <Input v-model="form.work_date" type="date" />
                            <p v-if="form.errors.work_date" class="text-sm text-red-600">{{ form.errors.work_date }}</p>
                        </div>
                        <div class="space-y-1">
                            <Label>Jam lembur</Label>
                            <Input v-model="form.hours" type="number" step="0.5" min="0.5" max="24" />
                            <p v-if="form.errors.hours" class="text-sm text-red-600">{{ form.errors.hours }}</p>
                        </div>
                    </div>
                    <div class="space-y-1">
                        <Label>Alasan (opsional)</Label>
                        <Input v-model="form.reason" placeholder="Alasan lembur" />
                    </div>
                    <Button type="button" class="rounded-xl" :disabled="form.processing" @click="submit">Simpan</Button>
                </CardContent>
            </Card>

            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <CardTitle>Daftar Pengajuan Lembur</CardTitle>
                </CardHeader>
                <CardContent class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-slate-500">
                            <tr class="border-b border-slate-200">
                                <th class="py-2 pr-3">Karyawan</th>
                                <th class="py-2 pr-3">Tanggal</th>
                                <th class="py-2 pr-3">Jam</th>
                                <th class="py-2 pr-3">Status</th>
                                <th class="py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="overtime in overtimes" :key="overtime.id" class="border-b border-slate-100">
                                <td class="py-2 pr-3">{{ overtime.employee_name ?? '—' }}</td>
                                <td class="py-2 pr-3">{{ fmtDate(overtime.work_date) }}</td>
                                <td class="py-2 pr-3">{{ overtime.hours }}</td>
                                <td class="py-2 pr-3">
                                    <span
                                        class="rounded-full px-2 py-0.5 text-xs font-medium"
                                        :class="{
                                            'bg-amber-100 text-amber-700': overtime.status === 'pending',
                                            'bg-emerald-100 text-emerald-700': overtime.status === 'approved',
                                            'bg-red-100 text-red-700': overtime.status === 'rejected',
                                        }"
                                    >
                                        {{ statusLabel[overtime.status] ?? overtime.status }}
                                    </span>
                                </td>
                                <td class="py-2 text-right">
                                    <template v-if="overtime.status === 'pending'">
                                        <Button type="button" size="sm" variant="ghost" class="rounded-lg text-emerald-600" @click="decide(overtime.id, 'approved')">
                                            <Check class="h-4 w-4" />
                                        </Button>
                                        <Button type="button" size="sm" variant="ghost" class="rounded-lg text-red-600" @click="decide(overtime.id, 'rejected')">
                                            <X class="h-4 w-4" />
                                        </Button>
                                    </template>
                                    <Button type="button" size="sm" variant="ghost" class="rounded-lg text-red-600" @click="remove(overtime.id)">
                                        <Trash2 class="h-4 w-4" />
                                    </Button>
                                </td>
                            </tr>
                            <tr v-if="!overtimes.length">
                                <td colspan="5" class="py-6 text-center text-slate-400">Belum ada pengajuan lembur.</td>
                            </tr>
                        </tbody>
                    </table>
                </CardContent>
            </Card>
        </div>
    </AdminLayout>
</template>
