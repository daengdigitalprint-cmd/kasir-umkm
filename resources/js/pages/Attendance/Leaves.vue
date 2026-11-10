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

interface Leave {
    id: number;
    employee_id: number;
    employee_name: string | null;
    leave_type: string;
    start_date: string;
    end_date: string;
    status: string;
    reason: string | null;
}

const props = defineProps<{
    leaves: Leave[];
    employees: Employee[];
}>();

const leaveTypes = [
    { value: 'annual', label: 'Cuti tahunan' },
    { value: 'sick', label: 'Sakit' },
    { value: 'maternity', label: 'Cuti melahirkan' },
    { value: 'marriage', label: 'Cuti menikah' },
    { value: 'bereavement', label: 'Cuti duka' },
    { value: 'unpaid', label: 'Tanpa gaji' },
    { value: 'other', label: 'Lainnya' },
];

const statusLabel: Record<string, string> = {
    pending: 'Menunggu',
    approved: 'Disetujui',
    rejected: 'Ditolak',
};

const form = useForm({
    employee_id: '',
    leave_type: 'annual',
    start_date: '',
    end_date: '',
    reason: '',
});

const submit = () => {
    form.post('/attendance/leaves', { preserveScroll: true, onSuccess: () => form.reset() });
};

const decide = (id: number, status: 'approved' | 'rejected') => {
    router.patch(`/attendance/leaves/${id}`, { status }, { preserveScroll: true });
};

const remove = (id: number) => {
    if (confirm('Hapus pengajuan cuti ini?')) {
        router.delete(`/attendance/leaves/${id}`, { preserveScroll: true });
    }
};

const fmtDate = (iso: string) => new Date(iso).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });
</script>

<template>
    <Head title="Cuti" />

    <AdminLayout title="Pengajuan Cuti">
        <div class="space-y-6 pb-8">
            <AttendanceNav />

            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <CardTitle>Catat Cuti Karyawan</CardTitle>
                    <CardDescription>
                        Cuti yang disetujui otomatis tercatat di absensi, jadi karyawan tidak dianggap alpa. Jenis <b>Tanpa gaji</b>
                        memotong gaji bila potongan alpa diaktifkan di Pengaturan Gaji.
                    </CardDescription>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
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
                            <Label>Jenis cuti</Label>
                            <select v-model="form.leave_type" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm">
                                <option v-for="type in leaveTypes" :key="type.value" :value="type.value">{{ type.label }}</option>
                            </select>
                        </div>
                        <div class="space-y-1">
                            <Label>Mulai</Label>
                            <Input v-model="form.start_date" type="date" />
                            <p v-if="form.errors.start_date" class="text-sm text-red-600">{{ form.errors.start_date }}</p>
                        </div>
                        <div class="space-y-1">
                            <Label>Selesai</Label>
                            <Input v-model="form.end_date" type="date" />
                            <p v-if="form.errors.end_date" class="text-sm text-red-600">{{ form.errors.end_date }}</p>
                        </div>
                    </div>
                    <div class="space-y-1">
                        <Label>Alasan (opsional)</Label>
                        <Input v-model="form.reason" placeholder="Alasan cuti" />
                    </div>
                    <Button type="button" class="rounded-xl" :disabled="form.processing" @click="submit">Simpan</Button>
                </CardContent>
            </Card>

            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <CardTitle>Daftar Pengajuan Cuti</CardTitle>
                </CardHeader>
                <CardContent class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-slate-500">
                            <tr class="border-b border-slate-200">
                                <th class="py-2 pr-3">Karyawan</th>
                                <th class="py-2 pr-3">Jenis</th>
                                <th class="py-2 pr-3">Tanggal</th>
                                <th class="py-2 pr-3">Status</th>
                                <th class="py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="leave in leaves" :key="leave.id" class="border-b border-slate-100">
                                <td class="py-2 pr-3">{{ leave.employee_name ?? '—' }}</td>
                                <td class="py-2 pr-3">{{ leaveTypes.find((t) => t.value === leave.leave_type)?.label ?? leave.leave_type }}</td>
                                <td class="py-2 pr-3">{{ fmtDate(leave.start_date) }} – {{ fmtDate(leave.end_date) }}</td>
                                <td class="py-2 pr-3">
                                    <span
                                        class="rounded-full px-2 py-0.5 text-xs font-medium"
                                        :class="{
                                            'bg-amber-100 text-amber-700': leave.status === 'pending',
                                            'bg-emerald-100 text-emerald-700': leave.status === 'approved',
                                            'bg-red-100 text-red-700': leave.status === 'rejected',
                                        }"
                                    >
                                        {{ statusLabel[leave.status] ?? leave.status }}
                                    </span>
                                </td>
                                <td class="py-2 text-right">
                                    <template v-if="leave.status === 'pending'">
                                        <Button type="button" size="sm" variant="ghost" class="rounded-lg text-emerald-600" @click="decide(leave.id, 'approved')">
                                            <Check class="h-4 w-4" />
                                        </Button>
                                        <Button type="button" size="sm" variant="ghost" class="rounded-lg text-red-600" @click="decide(leave.id, 'rejected')">
                                            <X class="h-4 w-4" />
                                        </Button>
                                    </template>
                                    <Button type="button" size="sm" variant="ghost" class="rounded-lg text-red-600" @click="remove(leave.id)">
                                        <Trash2 class="h-4 w-4" />
                                    </Button>
                                </td>
                            </tr>
                            <tr v-if="!leaves.length">
                                <td colspan="5" class="py-6 text-center text-slate-400">Belum ada pengajuan cuti.</td>
                            </tr>
                        </tbody>
                    </table>
                </CardContent>
            </Card>
        </div>
    </AdminLayout>
</template>
