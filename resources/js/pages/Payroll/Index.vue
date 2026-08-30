<script setup lang="ts">
import { Head, useForm, router, Link, usePage } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import PayrollNav from '@/components/PayrollNav.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { CalendarPlus, Play, Trash2 } from 'lucide-vue-next';
import Swal from 'sweetalert2';

interface Period {
    id: number;
    name: string;
    period_start: string;
    period_end: string;
    status: string;
    runs_count: number;
    total_net: number;
}

const props = defineProps<{ periods: Period[] }>();

const page = usePage();

const form = useForm({ name: '', period_start: '', period_end: '' });

const fmt = (n: number) =>
    new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(n);

const toast = (icon: 'success' | 'error', title: string) => {
    Swal.fire({
        toast: true,
        position: 'top-end',
        icon,
        title,
        showConfirmButton: false,
        timer: 2500,
        timerProgressBar: true,
    });
};

const notifyFlash = () => {
    const flash = page.props.flash as { success?: string; error?: string } | undefined;
    if (flash?.success) {
        toast('success', flash.success);
    } else if (flash?.error) {
        toast('error', flash.error);
    }
};

const submit = () => {
    form.post('/payroll', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            notifyFlash();
        },
    });
};

const run = async (id: number) => {
    const res = await Swal.fire({
        title: 'Hitung gaji?',
        text: 'Gaji akan dihitung untuk periode ini berdasarkan data absensi.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Ya, hitung',
        cancelButtonText: 'Batal',
    });
    if (res.isConfirmed) {
        router.post(`/payroll/${id}/run`, {}, { preserveScroll: true, onSuccess: () => notifyFlash() });
    }
};

const remove = async (id: number) => {
    const res = await Swal.fire({
        title: 'Hapus periode?',
        text: 'Periode beserta data gajinya akan dihapus permanen.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Ya, hapus',
        cancelButtonText: 'Batal',
        confirmButtonColor: '#dc2626',
    });
    if (res.isConfirmed) {
        router.delete(`/payroll/${id}`, { preserveScroll: true, onSuccess: () => notifyFlash() });
    }
};
</script>

<template>
    <Head title="Payroll" />

    <AdminLayout title="Payroll">
        <div class="space-y-6 pb-8">
            <PayrollNav />

            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <CardTitle>Buat Periode Gaji</CardTitle>
                    <CardDescription>Periode dipakai untuk menghitung gaji karyawan dari data absensi.</CardDescription>
                </CardHeader>
                <CardContent>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div class="space-y-1">
                            <Label>Nama Periode</Label>
                            <Input v-model="form.name" placeholder="Gaji Januari 2026" />
                            <p v-if="form.errors.name" class="text-sm text-red-600">{{ form.errors.name }}</p>
                        </div>
                        <div class="space-y-1">
                            <Label>Dari</Label>
                            <Input v-model="form.period_start" type="date" />
                            <p v-if="form.errors.period_start" class="text-sm text-red-600">{{ form.errors.period_start }}</p>
                        </div>
                        <div class="space-y-1">
                            <Label>Sampai</Label>
                            <Input v-model="form.period_end" type="date" />
                            <p v-if="form.errors.period_end" class="text-sm text-red-600">{{ form.errors.period_end }}</p>
                        </div>
                    </div>
                    <Button type="button" class="mt-4 rounded-xl" :disabled="form.processing" @click="submit">
                        <CalendarPlus class="h-4 w-4 mr-1" /> Buat Periode
                    </Button>
                </CardContent>
            </Card>

            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <CardTitle>Daftar Periode</CardTitle>
                </CardHeader>
                <CardContent class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-slate-500">
                            <tr class="border-b border-slate-200">
                                <th class="py-2 pr-3">Periode</th>
                                <th class="py-2 pr-3">Rentang</th>
                                <th class="py-2 pr-3">Karyawan</th>
                                <th class="py-2 pr-3">Total Gaji Bersih</th>
                                <th class="py-2 pr-3">Status</th>
                                <th class="py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="period in periods" :key="period.id" class="border-b border-slate-100">
                                <td class="py-2 pr-3">
                                    <Link :href="`/payroll/${period.id}`" class="font-medium text-primary hover:underline">
                                        {{ period.name }}
                                    </Link>
                                </td>
                                <td class="py-2 pr-3">{{ period.period_start }} s.d. {{ period.period_end }}</td>
                                <td class="py-2 pr-3">{{ period.runs_count }}</td>
                                <td class="py-2 pr-3">{{ fmt(period.total_net) }}</td>
                                <td class="py-2 pr-3">
                                    <span :class="period.status === 'locked' ? 'text-emerald-600' : 'text-amber-600'">
                                        {{ period.status === 'locked' ? 'Terkunci' : 'Draft' }}
                                    </span>
                                </td>
                                <td class="py-2 text-right whitespace-nowrap">
                                    <Button type="button" size="sm" variant="outline" class="rounded-lg mr-1" @click="run(period.id)">
                                        <Play class="h-4 w-4" /> Hitung
                                    </Button>
                                    <Button type="button" size="sm" variant="ghost" class="rounded-lg text-red-600" @click="remove(period.id)">
                                        <Trash2 class="h-4 w-4" />
                                    </Button>
                                </td>
                            </tr>
                            <tr v-if="!periods.length">
                                <td colspan="6" class="py-6 text-center text-slate-400">Belum ada periode gaji.</td>
                            </tr>
                        </tbody>
                    </table>
                </CardContent>
            </Card>
        </div>
    </AdminLayout>
</template>
