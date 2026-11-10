<script setup lang="ts">
import { Head, useForm, router, Link, usePage } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import PayrollNav from '@/components/PayrollNav.vue';
import PayrollSetupGuide, { type SetupStep } from '@/components/PayrollSetupGuide.vue';
import PayrollStatusBadge from '@/components/PayrollStatusBadge.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ArrowRight, CalendarPlus, CheckCircle2, Eye, Play, Trash2, Wallet } from 'lucide-vue-next';
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

defineProps<{
    periods: Period[];
    setup: { steps: SetupStep[]; done: number; total: number; complete: boolean };
}>();

const page = usePage();

const form = useForm({ name: '', period_start: '', period_end: '' });

const fmt = (n: number) =>
    new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(n);

const MONTHS = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
const iso = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

/** Isi otomatis periode satu bulan penuh (offset 0 = bulan ini, -1 = bulan lalu). */
const fillMonth = (offset: number) => {
    const now = new Date();
    const start = new Date(now.getFullYear(), now.getMonth() + offset, 1);
    const end = new Date(start.getFullYear(), start.getMonth() + 1, 0);
    form.period_start = iso(start);
    form.period_end = iso(end);
    form.name = `Gaji ${MONTHS[start.getMonth()]} ${start.getFullYear()}`;
};

const formatRange = (start: string, end: string) => {
    const s = new Date(`${start}T00:00:00`);
    const e = new Date(`${end}T00:00:00`);
    const sameYear = s.getFullYear() === e.getFullYear();
    const opt: Intl.DateTimeFormatOptions = { day: 'numeric', month: 'short' };
    return `${s.toLocaleDateString('id-ID', sameYear ? opt : { ...opt, year: 'numeric' })} – ${e.toLocaleDateString('id-ID', { ...opt, year: 'numeric' })}`;
};

const toast = (icon: 'success' | 'error', title: string) => {
    Swal.fire({ toast: true, position: 'top-end', icon, title, showConfirmButton: false, timer: 2500, timerProgressBar: true });
};

const notifyFlash = () => {
    const flash = page.props.flash as { success?: string; error?: string } | undefined;
    if (flash?.success) toast('success', flash.success);
    else if (flash?.error) toast('error', flash.error);
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

const run = async (period: Period) => {
    const res = await Swal.fire({
        title: period.runs_count ? 'Hitung ulang gaji?' : 'Hitung gaji?',
        text: 'Gaji dihitung dari komponen gaji dan data absensi periode ini. Hasilnya masih bisa dicek sebelum disetujui.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Ya, hitung',
        cancelButtonText: 'Batal',
    });
    if (res.isConfirmed) {
        router.post(`/payroll/${period.id}/run`, {}, { preserveScroll: true, onSuccess: () => notifyFlash() });
    }
};

const remove = async (period: Period) => {
    const res = await Swal.fire({
        title: 'Hapus periode?',
        text: `${period.name} beserta hasil hitungnya akan dihapus.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Ya, hapus',
        cancelButtonText: 'Batal',
        confirmButtonColor: '#dc2626',
    });
    if (res.isConfirmed) {
        router.delete(`/payroll/${period.id}`, { preserveScroll: true, onSuccess: () => notifyFlash() });
    }
};

const FLOW = [
    { icon: Play, title: 'Hitung', text: 'Sistem menghitung gaji dari absensi' },
    { icon: Eye, title: 'Periksa', text: 'Cek rincian tiap karyawan' },
    { icon: CheckCircle2, title: 'Setujui', text: 'Angka dikunci, slip terlihat karyawan' },
    { icon: Wallet, title: 'Bayar', text: 'Tandai dibayar, jurnal otomatis' },
];
</script>

<template>
    <Head title="Penggajian" />

    <AdminLayout title="Penggajian">
        <div class="space-y-6 pb-8">
            <PayrollNav />

            <PayrollSetupGuide :setup="setup" />

            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <CardTitle>Buat Periode Gaji</CardTitle>
                    <CardDescription>Satu periode biasanya satu bulan. Pilih cepat di bawah, atau isi tanggal sendiri.</CardDescription>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div class="flex flex-wrap gap-2">
                        <Button type="button" size="sm" variant="outline" class="rounded-full" @click="fillMonth(-1)">Bulan lalu</Button>
                        <Button type="button" size="sm" variant="outline" class="rounded-full" @click="fillMonth(0)">Bulan ini</Button>
                    </div>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div class="space-y-1">
                            <Label>Nama periode</Label>
                            <Input v-model="form.name" placeholder="Gaji Januari 2026" />
                            <p v-if="form.errors.name" class="text-sm text-red-600">{{ form.errors.name }}</p>
                        </div>
                        <div class="space-y-1">
                            <Label>Dari tanggal</Label>
                            <Input v-model="form.period_start" type="date" />
                            <p v-if="form.errors.period_start" class="text-sm text-red-600">{{ form.errors.period_start }}</p>
                        </div>
                        <div class="space-y-1">
                            <Label>Sampai tanggal</Label>
                            <Input v-model="form.period_end" type="date" />
                            <p v-if="form.errors.period_end" class="text-sm text-red-600">{{ form.errors.period_end }}</p>
                        </div>
                    </div>
                    <Button type="button" class="rounded-xl" :disabled="form.processing" @click="submit">
                        <CalendarPlus class="mr-1 h-4 w-4" /> Buat Periode
                    </Button>
                </CardContent>
            </Card>

            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader class="space-y-3">
                    <CardTitle>Daftar Periode</CardTitle>
                    <!-- Alur singkat agar orang baru tahu urutannya -->
                    <ol class="grid gap-2 sm:grid-cols-4">
                        <li v-for="(step, i) in FLOW" :key="step.title" class="flex items-start gap-2 rounded-xl bg-slate-50 p-2.5">
                            <span class="grid h-7 w-7 shrink-0 place-items-center rounded-lg bg-white text-indigo-600 ring-1 ring-slate-200">
                                <component :is="step.icon" class="h-4 w-4" />
                            </span>
                            <span class="text-xs leading-snug text-slate-500">
                                <b class="block text-slate-700">{{ i + 1 }}. {{ step.title }}</b>{{ step.text }}
                            </span>
                        </li>
                    </ol>
                </CardHeader>
                <CardContent class="overflow-x-auto">
                    <table v-if="periods.length" class="w-full text-left text-sm">
                        <thead class="text-xs uppercase tracking-wide text-slate-400">
                            <tr class="border-b border-slate-200">
                                <th class="py-2 pr-3 font-medium">Periode</th>
                                <th class="py-2 pr-3 font-medium">Karyawan</th>
                                <th class="py-2 pr-3 text-right font-medium">Total gaji bersih</th>
                                <th class="py-2 pr-3 font-medium">Status</th>
                                <th class="py-2 text-right font-medium">Langkah berikutnya</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="period in periods" :key="period.id" class="border-b border-slate-100 last:border-0">
                                <td class="py-3 pr-3">
                                    <Link :href="`/payroll/${period.id}`" class="font-semibold text-slate-800 hover:text-indigo-600">
                                        {{ period.name }}
                                    </Link>
                                    <p class="text-xs text-slate-400">{{ formatRange(period.period_start, period.period_end) }}</p>
                                </td>
                                <td class="py-3 pr-3 text-slate-600">{{ period.runs_count ? `${period.runs_count} orang` : '—' }}</td>
                                <td class="py-3 pr-3 text-right font-semibold tabular-nums">{{ period.runs_count ? fmt(period.total_net) : '—' }}</td>
                                <td class="py-3 pr-3"><PayrollStatusBadge :status="period.status" /></td>
                                <td class="whitespace-nowrap py-3 text-right">
                                    <div class="inline-flex items-center gap-1">
                                        <template v-if="period.status === 'draft'">
                                            <Button v-if="!period.runs_count" type="button" size="sm" class="rounded-lg" @click="run(period)">
                                                <Play class="mr-1 h-4 w-4" /> Hitung gaji
                                            </Button>
                                            <template v-else>
                                                <Button type="button" size="sm" variant="ghost" class="rounded-lg text-slate-500" @click="run(period)">
                                                    Hitung ulang
                                                </Button>
                                                <Link
                                                    :href="`/payroll/${period.id}`"
                                                    class="inline-flex h-8 items-center gap-1 rounded-lg bg-indigo-600 px-3 text-sm font-medium text-white hover:bg-indigo-700"
                                                >
                                                    Periksa & setujui <ArrowRight class="h-4 w-4" />
                                                </Link>
                                            </template>
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="ghost"
                                                class="rounded-lg text-slate-400 hover:text-red-600"
                                                title="Hapus periode"
                                                @click="remove(period)"
                                            >
                                                <Trash2 class="h-4 w-4" />
                                            </Button>
                                        </template>
                                        <Link
                                            v-else-if="period.status === 'approved'"
                                            :href="`/payroll/${period.id}`"
                                            class="inline-flex h-8 items-center gap-1 rounded-lg bg-indigo-600 px-3 text-sm font-medium text-white hover:bg-indigo-700"
                                        >
                                            <Wallet class="h-4 w-4" /> Bayar gaji
                                        </Link>
                                        <Link
                                            v-else
                                            :href="`/payroll/${period.id}`"
                                            class="inline-flex h-8 items-center gap-1 rounded-lg px-3 text-sm font-medium text-slate-600 hover:bg-slate-100"
                                        >
                                            <Eye class="h-4 w-4" /> Lihat slip
                                        </Link>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div v-else class="rounded-xl border border-dashed border-slate-300 py-10 text-center">
                        <CalendarPlus class="mx-auto h-8 w-8 text-slate-300" />
                        <p class="mt-2 font-medium text-slate-700">Belum ada periode gaji</p>
                        <p class="text-sm text-slate-500">Buat periode pertama di atas, misalnya "Bulan lalu", lalu tekan Hitung gaji.</p>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AdminLayout>
</template>
