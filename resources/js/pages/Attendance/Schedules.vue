<script setup lang="ts">
import { Head, useForm, router } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import AttendanceNav from '@/components/AttendanceNav.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import { Plus, Pencil, Trash2 } from 'lucide-vue-next';

interface Employee {
    id: number;
    name: string;
    position: string | null;
}

interface Schedule {
    id: number;
    employee_id: number;
    employee_name: string | null;
    name: string;
    work_days: number[];
    shift_start: string;
    shift_end: string;
    break_minutes: number;
    valid_from: string | null;
    valid_to: string | null;
    is_active: boolean;
}

const props = defineProps<{
    schedules: Schedule[];
    employees: Employee[];
}>();

const days = [
    { value: 1, label: 'Sen' },
    { value: 2, label: 'Sel' },
    { value: 3, label: 'Rab' },
    { value: 4, label: 'Kam' },
    { value: 5, label: 'Jum' },
    { value: 6, label: 'Sab' },
    { value: 7, label: 'Min' },
];

const form = useForm({
    id: null as number | null,
    employee_id: '',
    name: '',
    work_days: [] as number[],
    shift_start: '09:00',
    shift_end: '17:00',
    break_minutes: 60,
    valid_from: '',
    valid_to: '',
    is_active: true,
});

const toggleDay = (day: number) => {
    const index = form.work_days.indexOf(day);
    if (index >= 0) form.work_days.splice(index, 1);
    else form.work_days.push(day);
};

const reset = () => {
    form.reset();
    form.clearErrors();
};

const submit = () => {
    if (form.id) {
        form.put(`/attendance/schedules/${form.id}`, { preserveScroll: true, onSuccess: reset });
    } else {
        form.post('/attendance/schedules', { preserveScroll: true, onSuccess: reset });
    }
};

const edit = (schedule: Schedule) => {
    form.id = schedule.id;
    form.employee_id = String(schedule.employee_id);
    form.name = schedule.name;
    form.work_days = [...schedule.work_days];
    form.shift_start = schedule.shift_start;
    form.shift_end = schedule.shift_end;
    form.break_minutes = schedule.break_minutes;
    form.valid_from = schedule.valid_from ?? '';
    form.valid_to = schedule.valid_to ?? '';
    form.is_active = schedule.is_active;
};

const remove = (id: number) => {
    if (confirm('Hapus jadwal ini?')) {
        router.delete(`/attendance/schedules/${id}`, { preserveScroll: true });
    }
};

const dayLabel = (schedule: Schedule) =>
    schedule.work_days.map((d) => days.find((x) => x.value === d)?.label).filter(Boolean).join(', ');
</script>

<template>
    <Head title="Jadwal Kerja" />

    <AdminLayout title="Jadwal Kerja">
        <div class="space-y-6 pb-8">
            <AttendanceNav />

            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <CardTitle>{{ form.id ? 'Edit Jadwal' : 'Tambah Jadwal' }}</CardTitle>
                    <CardDescription>Atur hari kerja, jam masuk, dan jam pulang per karyawan.</CardDescription>
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
                            <Label>Nama shift</Label>
                            <Input v-model="form.name" placeholder="Pagi / Siang / Malam" />
                            <p v-if="form.errors.name" class="text-sm text-red-600">{{ form.errors.name }}</p>
                        </div>
                        <div class="space-y-1">
                            <Label>Jam masuk</Label>
                            <Input v-model="form.shift_start" type="time" />
                            <p v-if="form.errors.shift_start" class="text-sm text-red-600">{{ form.errors.shift_start }}</p>
                        </div>
                        <div class="space-y-1">
                            <Label>Jam pulang</Label>
                            <Input v-model="form.shift_end" type="time" />
                            <p v-if="form.errors.shift_end" class="text-sm text-red-600">{{ form.errors.shift_end }}</p>
                        </div>
                        <div class="space-y-1">
                            <Label>Istirahat (menit)</Label>
                            <Input v-model="form.break_minutes" type="number" min="0" max="240" />
                        </div>
                        <div class="space-y-1">
                            <Label>Berlaku dari (opsional)</Label>
                            <Input v-model="form.valid_from" type="date" />
                        </div>
                        <div class="space-y-1">
                            <Label>Berlaku sampai (opsional)</Label>
                            <Input v-model="form.valid_to" type="date" />
                            <p v-if="form.errors.valid_to" class="text-sm text-red-600">{{ form.errors.valid_to }}</p>
                        </div>
                    </div>

                    <div class="space-y-2">
                        <Label>Hari kerja</Label>
                        <div class="flex flex-wrap gap-2">
                            <label
                                v-for="day in days"
                                :key="day.value"
                                class="flex cursor-pointer items-center gap-1.5 rounded-xl border border-slate-200 px-3 py-1.5 text-sm"
                                :class="form.work_days.includes(day.value) ? 'border-indigo-500 bg-indigo-50' : ''"
                            >
                                <Checkbox
                                    :model-value="form.work_days.includes(day.value)"
                                    @update:model-value="toggleDay(day.value)"
                                />
                                {{ day.label }}
                            </label>
                        </div>
                        <p v-if="form.errors.work_days" class="text-sm text-red-600">{{ form.errors.work_days }}</p>
                    </div>

                    <div class="flex items-center gap-2">
                        <Checkbox v-model="form.is_active" />
                        <Label>Aktif</Label>
                    </div>

                    <div class="flex gap-2">
                        <Button type="button" class="rounded-xl" :disabled="form.processing" @click="submit">
                            <Plus class="h-4 w-4 mr-1" /> {{ form.id ? 'Simpan Perubahan' : 'Tambah' }}
                        </Button>
                        <Button v-if="form.id" type="button" variant="outline" class="rounded-xl" @click="reset">Batal</Button>
                    </div>
                </CardContent>
            </Card>

            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <CardTitle>Daftar Jadwal</CardTitle>
                </CardHeader>
                <CardContent class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-slate-500">
                            <tr class="border-b border-slate-200">
                                <th class="py-2 pr-3">Karyawan</th>
                                <th class="py-2 pr-3">Shift</th>
                                <th class="py-2 pr-3">Hari</th>
                                <th class="py-2 pr-3">Jam</th>
                                <th class="py-2 pr-3">Status</th>
                                <th class="py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="schedule in schedules" :key="schedule.id" class="border-b border-slate-100">
                                <td class="py-2 pr-3">{{ schedule.employee_name ?? '—' }}</td>
                                <td class="py-2 pr-3">{{ schedule.name }}</td>
                                <td class="py-2 pr-3">{{ dayLabel(schedule) }}</td>
                                <td class="py-2 pr-3">{{ schedule.shift_start }}–{{ schedule.shift_end }}</td>
                                <td class="py-2 pr-3">
                                    <span :class="schedule.is_active ? 'text-emerald-600' : 'text-slate-400'">
                                        {{ schedule.is_active ? 'Aktif' : 'Nonaktif' }}
                                    </span>
                                </td>
                                <td class="py-2 text-right">
                                    <Button type="button" size="sm" variant="ghost" class="rounded-lg" @click="edit(schedule)">
                                        <Pencil class="h-4 w-4" />
                                    </Button>
                                    <Button type="button" size="sm" variant="ghost" class="rounded-lg text-red-600" @click="remove(schedule.id)">
                                        <Trash2 class="h-4 w-4" />
                                    </Button>
                                </td>
                            </tr>
                            <tr v-if="!schedules.length">
                                <td colspan="6" class="py-6 text-center text-slate-400">Belum ada jadwal.</td>
                            </tr>
                        </tbody>
                    </table>
                </CardContent>
            </Card>
        </div>
    </AdminLayout>
</template>
