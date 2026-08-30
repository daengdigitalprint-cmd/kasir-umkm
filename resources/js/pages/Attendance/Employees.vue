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
    user_id: number | null;
    user_name: string | null;
    user_email: string | null;
    is_active: boolean;
}

interface User {
    id: number;
    name: string;
    email: string | null;
}

const props = defineProps<{
    employees: Employee[];
    users: User[];
}>();

const form = useForm({
    id: null as number | null,
    name: '',
    position: '',
    user_id: '',
    is_active: true,
});

const reset = () => {
    form.reset();
    form.clearErrors();
};

const onUserChange = () => {
    if (!form.name) {
        const user = props.users.find((u) => String(u.id) === form.user_id);
        if (user) form.name = user.name;
    }
};

const submit = () => {
    if (form.id) {
        form.put(`/attendance/employees/${form.id}`, { preserveScroll: true, onSuccess: reset });
    } else {
        form.post('/attendance/employees', { preserveScroll: true, onSuccess: reset });
    }
};

const edit = (employee: Employee) => {
    form.id = employee.id;
    form.name = employee.name;
    form.position = employee.position ?? '';
    form.user_id = employee.user_id ? String(employee.user_id) : '';
    form.is_active = employee.is_active;
};

const remove = (id: number) => {
    if (confirm('Hapus karyawan ini?')) {
        router.delete(`/attendance/employees/${id}`, { preserveScroll: true });
    }
};
</script>

<template>
    <Head title="Karyawan" />

    <AdminLayout title="Karyawan">
        <div class="space-y-6 pb-8">
            <AttendanceNav />

            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <CardTitle>{{ form.id ? 'Edit Karyawan' : 'Tambah Karyawan' }}</CardTitle>
                    <CardDescription>Kelola data karyawan untuk absensi, jadwal, cuti, dan lembur.</CardDescription>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div class="space-y-1">
                            <Label>Nama</Label>
                            <Input v-model="form.name" placeholder="Nama karyawan" />
                            <p v-if="form.errors.name" class="text-sm text-red-600">{{ form.errors.name }}</p>
                        </div>
                        <div class="space-y-1">
                            <Label>Jabatan</Label>
                            <Input v-model="form.position" placeholder="Kasir / Supervisor / dll." />
                            <p v-if="form.errors.position" class="text-sm text-red-600">{{ form.errors.position }}</p>
                        </div>
                        <div class="space-y-1">
                            <Label>Hubungkan ke akun (opsional)</Label>
                            <select v-model="form.user_id" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm" @change="onUserChange">
                                <option value="">Tanpa akun</option>
                                <option v-for="user in users" :key="user.id" :value="String(user.id)">
                                    {{ user.name }} ({{ user.email }})
                                </option>
                            </select>
                            <p v-if="form.errors.user_id" class="text-sm text-red-600">{{ form.errors.user_id }}</p>
                        </div>
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
                    <CardTitle>Daftar Karyawan</CardTitle>
                </CardHeader>
                <CardContent class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-slate-500">
                            <tr class="border-b border-slate-200">
                                <th class="py-2 pr-3">Nama</th>
                                <th class="py-2 pr-3">Jabatan</th>
                                <th class="py-2 pr-3">Akun</th>
                                <th class="py-2 pr-3">Status</th>
                                <th class="py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="employee in employees" :key="employee.id" class="border-b border-slate-100">
                                <td class="py-2 pr-3">{{ employee.name }}</td>
                                <td class="py-2 pr-3">{{ employee.position ?? '—' }}</td>
                                <td class="py-2 pr-3">{{ employee.user_email ?? '—' }}</td>
                                <td class="py-2 pr-3">
                                    <span :class="employee.is_active ? 'text-emerald-600' : 'text-slate-400'">
                                        {{ employee.is_active ? 'Aktif' : 'Nonaktif' }}
                                    </span>
                                </td>
                                <td class="py-2 text-right">
                                    <Button type="button" size="sm" variant="ghost" class="rounded-lg" @click="edit(employee)">
                                        <Pencil class="h-4 w-4" />
                                    </Button>
                                    <Button type="button" size="sm" variant="ghost" class="rounded-lg text-red-600" @click="remove(employee.id)">
                                        <Trash2 class="h-4 w-4" />
                                    </Button>
                                </td>
                            </tr>
                            <tr v-if="!employees.length">
                                <td colspan="5" class="py-6 text-center text-slate-400">Belum ada karyawan.</td>
                            </tr>
                        </tbody>
                    </table>
                </CardContent>
            </Card>
        </div>
    </AdminLayout>
</template>
