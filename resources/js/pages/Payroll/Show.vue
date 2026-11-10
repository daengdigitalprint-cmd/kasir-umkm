<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Swal from 'sweetalert2';
import AdminLayout from '@/layouts/AdminLayout.vue';
import PayrollNav from '@/components/PayrollNav.vue';
import PayrollStatusBadge from '@/components/PayrollStatusBadge.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { AlertTriangle, Check, CheckCircle2, ChevronDown, ChevronUp, FileDown, RotateCcw, Wallet } from 'lucide-vue-next';

interface ComponentBreakdown {
    earnings: { name: string; amount: number; is_fixed?: boolean }[];
    deductions: { name: string; amount: number }[];
    proration?: { factor: number; days_employed: number; days_in_period: number };
    absence?: { enabled: boolean; days: number; daily_wage: number; total: number };
    late_penalty: {
        late_days: number;
        late_minutes: number;
        penalty_per_day: number;
        penalty_per_minute: number;
        total: number;
    };
    overtime: {
        hours: number;
        regular_hours?: number;
        holiday_hours?: number;
        rate_multiplier: number;
        rate_next_hour?: number;
        rate_holiday?: number;
        hourly_rate: number;
        total: number;
    };
    bpjs: {
        health: { employee: number; employer: number };
        jht: { employee: number; employer: number };
        jp: { employee: number; employer: number };
        jkk: number;
        jkm: number;
        total_employee: number;
    } | null;
    taxable_gross?: number;
    tax: {
        method: string;
        category?: string;
        rate_percent?: number;
        monthly_gross?: number;
        annual_gross?: number;
        biaya_jabatan?: number;
        pension_deduction?: number;
        neto?: number;
        ptwp?: number;
        pkp?: number;
        annual_tax?: number;
        accumulated_ter?: number;
        monthly_tax: number;
        refund?: number;
    } | null;
    shortfall?: number;
}

interface Run {
    id: number;
    employee: string;
    position: string | null;
    basic_salary: number;
    deductions: number;
    overtime_hours: number;
    overtime_pay: number;
    gross_salary: number;
    net_salary: number;
    attendance_summary: {
        work_days: number;
        present_days: number;
        late_days: number;
        absent_days: number;
        leave_days: number;
        total_hours: number;
        total_overtime: number;
        total_late_min: number;
    } | null;
    component_breakdown: ComponentBreakdown | null;
}

const props = defineProps<{
    period: {
        id: number;
        name: string;
        period_start: string;
        period_end: string;
        status: 'draft' | 'approved' | 'paid' | string;
        approved_by: string | null;
        approved_at: string | null;
        paid_by: string | null;
        paid_at: string | null;
        payment_date: string | null;
        payment_method: 'cash' | 'bank' | null;
        journal_entry_id: number | null;
    };
    accounting_enabled: boolean;
    approval?: { owner_only: boolean; can_approve: boolean };
    skipped_employees: string[];
    runs: Run[];
    totals: {
        basic_salary: number;
        deductions: number;
        overtime_pay: number;
        gross_salary: number;
        net_salary: number;
    };
}>();

const expandedRun = ref<number | null>(null);

const toggleExpand = (id: number) => {
    expandedRun.value = expandedRun.value === id ? null : id;
};

const fmt = (n: number) =>
    new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(n);

// Langkah alur gaji dan posisi periode saat ini, agar orang baru tahu apa yang harus dilakukan.
const STEPS = ['Hitung', 'Periksa', 'Setujui', 'Bayar'];
const currentStep = computed(() => {
    if (props.period.status === 'paid') return 4;
    if (props.period.status === 'approved') return 3;
    return props.runs.length ? 1 : 0;
});
const nextHint = computed(
    () =>
        [
            'Kembali ke daftar periode lalu tekan "Hitung gaji".',
            'Buka rincian tiap karyawan (tombol panah). Kalau sudah benar, tekan "Setujui".',
            '',
            'Gaji sudah disetujui. Setelah uang dibayarkan ke karyawan, tekan "Tandai dibayar".',
            'Selesai. Periode ini final; slip bisa diunduh kapan saja.',
        ][currentStep.value],
);

const page = usePage();
const flash = () => {
    const f = page.props.flash as { success?: string; error?: string } | undefined;
    const message = f?.success ?? f?.error;
    if (message) {
        Swal.fire({ toast: true, position: 'top-end', icon: f?.success ? 'success' : 'error', title: message, showConfirmButton: false, timer: 3000 });
    }
};

const approve = async () => {
    const res = await Swal.fire({
        title: 'Setujui gaji periode ini?',
        text: 'Angka gaji dibekukan dan slip bisa dilihat karyawan.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Ya, setujui',
        cancelButtonText: 'Batal',
    });
    if (res.isConfirmed) {
        router.post(`/payroll/${props.period.id}/approve`, {}, { preserveScroll: true, onSuccess: flash });
    }
};

const reopen = async () => {
    const res = await Swal.fire({
        title: 'Buka kembali ke draft?',
        input: 'text',
        inputLabel: 'Alasan (tercatat di log aktivitas)',
        inputPlaceholder: 'Mis. koreksi lembur karyawan A',
        showCancelButton: true,
        confirmButtonText: 'Buka kembali',
        cancelButtonText: 'Batal',
        inputValidator: (value) => (!value ? 'Alasan wajib diisi' : null),
    });
    if (res.isConfirmed) {
        router.post(`/payroll/${props.period.id}/reopen`, { reason: res.value }, { preserveScroll: true, onSuccess: flash });
    }
};

const showPay = ref(false);
const payForm = useForm({
    payment_method: 'cash' as 'cash' | 'bank',
    payment_date: new Date().toISOString().slice(0, 10),
});
const pay = () => {
    payForm.post(`/payroll/${props.period.id}/pay`, {
        preserveScroll: true,
        onSuccess: () => {
            showPay.value = false;
            flash();
        },
    });
};
</script>

<template>
    <Head :title="period.name" />

    <AdminLayout title="Detail Penggajian">
        <div class="space-y-6 pb-8">
            <PayrollNav />

            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader class="flex flex-row flex-wrap items-start justify-between gap-3">
                    <div class="space-y-1">
                        <CardTitle>{{ period.name }}</CardTitle>
                        <CardDescription class="flex flex-wrap items-center gap-2">
                            {{ period.period_start }} s.d. {{ period.period_end }}
                            <PayrollStatusBadge :status="period.status" />
                        </CardDescription>
                        <p v-if="period.approved_by" class="text-xs text-slate-500">
                            Disetujui {{ period.approved_by }} · {{ period.approved_at }}
                        </p>
                        <p v-if="period.status === 'paid'" class="text-xs text-emerald-700">
                            Dibayar {{ period.payment_date }} via {{ period.payment_method === 'bank' ? 'bank' : 'kas tunai' }} oleh
                            {{ period.paid_by }}<span v-if="period.journal_entry_id"> · jurnal #{{ period.journal_entry_id }}</span>
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Button
                            v-if="period.status === 'draft' && runs.length && approval?.can_approve !== false"
                            type="button"
                            class="rounded-xl"
                            @click="approve"
                        >
                            <CheckCircle2 class="mr-1 h-4 w-4" /> Setujui
                        </Button>
                        <p
                            v-else-if="period.status === 'draft' && runs.length"
                            class="max-w-xs rounded-xl bg-amber-50 px-3 py-2 text-xs font-medium text-amber-800"
                        >
                            Menunggu persetujuan pemilik. Gaji di toko ini hanya bisa disetujui oleh owner.
                        </p>
                        <template v-if="period.status === 'approved'">
                            <Button type="button" variant="outline" class="rounded-xl" @click="reopen">
                                <RotateCcw class="mr-1 h-4 w-4" /> Buka kembali
                            </Button>
                            <Button type="button" class="rounded-xl" @click="showPay = true">
                                <Wallet class="mr-1 h-4 w-4" /> Tandai dibayar
                            </Button>
                        </template>
                    </div>
                </CardHeader>
                <CardContent class="space-y-4">
                    <!-- Pelacak langkah -->
                    <div class="rounded-xl border border-slate-200 p-3">
                        <ol class="flex items-center gap-2">
                            <li v-for="(step, i) in STEPS" :key="step" class="flex flex-1 items-center gap-2">
                                <span
                                    class="grid h-7 w-7 shrink-0 place-items-center rounded-full text-xs font-bold"
                                    :class="
                                        i < currentStep
                                            ? 'bg-emerald-500 text-white'
                                            : i === currentStep
                                              ? 'bg-indigo-600 text-white ring-4 ring-indigo-100'
                                              : 'bg-slate-100 text-slate-400'
                                    "
                                >
                                    <Check v-if="i < currentStep" class="h-4 w-4" />
                                    <template v-else>{{ i + 1 }}</template>
                                </span>
                                <span class="text-sm font-medium" :class="i <= currentStep ? 'text-slate-800' : 'text-slate-400'">{{ step }}</span>
                                <span v-if="i < STEPS.length - 1" class="hidden h-px flex-1 bg-slate-200 sm:block"></span>
                            </li>
                        </ol>
                        <p v-if="nextHint" class="mt-2 text-sm text-slate-600">
                            <b class="text-indigo-700">Langkah berikutnya:</b> {{ nextHint }}
                        </p>
                    </div>

                    <div
                        v-if="skipped_employees.length && period.status === 'draft'"
                        class="flex gap-2 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800"
                    >
                        <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" />
                        <span>
                            Tidak ikut dihitung karena belum punya komponen gaji: <b>{{ skipped_employees.join(', ') }}</b>.
                            Isi di menu <a href="/payroll/salaries" class="font-semibold underline">Komponen Gaji</a> lalu hitung ulang,
                            atau nonaktifkan bila bukan karyawan bergaji.
                        </span>
                    </div>

                    <div class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-5">
                        <div class="rounded-xl bg-slate-50 p-3">
                            <p class="text-slate-500">Gaji Tetap</p>
                            <p class="font-bold">{{ fmt(totals.basic_salary) }}</p>
                        </div>
                        <div class="rounded-xl bg-slate-50 p-3">
                            <p class="text-slate-500">Lembur</p>
                            <p class="font-bold">{{ fmt(totals.overtime_pay) }}</p>
                        </div>
                        <div class="rounded-xl bg-slate-50 p-3">
                            <p class="text-slate-500">Potongan</p>
                            <p class="font-bold">{{ fmt(totals.deductions) }}</p>
                        </div>
                        <div class="rounded-xl bg-slate-50 p-3">
                            <p class="text-slate-500">Gaji Kotor</p>
                            <p class="font-bold">{{ fmt(totals.gross_salary) }}</p>
                        </div>
                        <div class="rounded-xl bg-emerald-50 p-3">
                            <p class="text-emerald-700">Gaji Bersih</p>
                            <p class="font-bold text-emerald-700">{{ fmt(totals.net_salary) }}</p>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <CardTitle>Slip Gaji Karyawan</CardTitle>
                </CardHeader>
                <CardContent class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-slate-500">
                            <tr class="border-b border-slate-200">
                                <th class="py-2 pr-3">Karyawan</th>
                                <th class="py-2 pr-3">Hadir</th>
                                <th class="py-2 pr-3">Lembur</th>
                                <th class="py-2 pr-3" title="Gaji pokok + tunjangan tetap">Gaji Tetap</th>
                                <th class="py-2 pr-3">Potongan</th>
                                <th class="py-2">Gaji Bersih</th>
                                <th class="py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="run in runs" :key="run.id">
                                <tr class="border-b border-slate-100">
                                    <td class="py-2 pr-3">
                                        <p class="font-medium">{{ run.employee }}</p>
                                        <p class="text-xs text-slate-400">{{ run.position ?? '—' }}</p>
                                    </td>
                                    <td class="py-2 pr-3">{{ run.attendance_summary?.present_days ?? 0 }} / {{ run.attendance_summary?.work_days ?? 0 }}</td>
                                    <td class="py-2 pr-3">{{ run.overtime_hours }} jam</td>
                                    <td class="py-2 pr-3">{{ fmt(run.basic_salary) }}</td>
                                    <td class="py-2 pr-3 text-red-600">{{ fmt(run.deductions) }}</td>
                                    <td class="py-2 font-bold">{{ fmt(run.net_salary) }}</td>
                                    <td class="py-2">
                                        <div class="flex items-center justify-end">
                                            <a
                                                :href="`/payroll/${period.id}/runs/${run.id}/slip`"
                                                class="inline-flex h-8 w-8 items-center justify-center rounded-md text-slate-500 hover:bg-slate-100 hover:text-indigo-600"
                                                title="Unduh slip gaji (PDF)"
                                            >
                                                <FileDown class="h-4 w-4" />
                                            </a>
                                            <Button type="button" variant="ghost" size="sm" @click="toggleExpand(run.id)">
                                                <ChevronDown v-if="expandedRun !== run.id" class="h-4 w-4" />
                                                <ChevronUp v-else class="h-4 w-4" />
                                            </Button>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="expandedRun === run.id && run.component_breakdown" class="border-b border-slate-100 bg-slate-50">
                                    <td colspan="7" class="p-4">
                                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                            <!-- Penghasilan -->
                                            <div class="space-y-2">
                                                <h4 class="font-medium text-emerald-700">Penghasilan</h4>
                                                <div v-for="item in run.component_breakdown.earnings" :key="item.name" class="flex justify-between text-sm">
                                                    <span>{{ item.name }}</span>
                                                    <span>{{ fmt(item.amount) }}</span>
                                                </div>
                                                <div v-if="run.component_breakdown.overtime.total > 0" class="flex justify-between text-sm">
                                                    <span>
                                                        Lembur {{ run.component_breakdown.overtime.hours }} jam
                                                        <span v-if="run.component_breakdown.overtime.holiday_hours" class="text-xs text-slate-500">
                                                            ({{ run.component_breakdown.overtime.holiday_hours }} jam hari libur)
                                                        </span>
                                                    </span>
                                                    <span>{{ fmt(run.component_breakdown.overtime.total) }}</span>
                                                </div>
                                                <div v-if="run.component_breakdown.absence?.total" class="flex justify-between text-sm">
                                                    <span>Potongan alpa / cuti tanpa gaji ({{ run.component_breakdown.absence.days }} hari)</span>
                                                    <span class="text-red-600">−{{ fmt(run.component_breakdown.absence.total) }}</span>
                                                </div>
                                                <p
                                                    v-if="run.component_breakdown.proration && run.component_breakdown.proration.factor < 1"
                                                    class="text-xs text-amber-700"
                                                >
                                                    Gaji tetap prorata {{ run.component_breakdown.proration.days_employed }}/{{
                                                        run.component_breakdown.proration.days_in_period
                                                    }}
                                                    hari (masuk/keluar di tengah periode).
                                                </p>
                                            </div>

                                            <!-- Potongan -->
                                            <div class="space-y-2">
                                                <h4 class="font-medium text-red-700">Potongan</h4>
                                                <div v-for="item in run.component_breakdown.deductions" :key="item.name" class="flex justify-between text-sm">
                                                    <span>{{ item.name }}</span>
                                                    <span class="text-red-600">{{ fmt(item.amount) }}</span>
                                                </div>
                                                <div v-if="run.component_breakdown.late_penalty.total > 0" class="flex justify-between text-sm">
                                                    <span>Telat ({{ run.component_breakdown.late_penalty.late_days }} hari, {{ run.component_breakdown.late_penalty.late_minutes }} menit)</span>
                                                    <span class="text-red-600">{{ fmt(run.component_breakdown.late_penalty.total) }}</span>
                                                </div>
                                            </div>

                                            <!-- BPJS -->
                                            <div v-if="run.component_breakdown.bpjs" class="space-y-2">
                                                <h4 class="font-medium text-blue-700">BPJS</h4>
                                                <div class="flex justify-between text-sm">
                                                    <span>Kesehatan</span>
                                                    <span>{{ fmt(run.component_breakdown.bpjs.health.employee) }}</span>
                                                </div>
                                                <div class="flex justify-between text-sm">
                                                    <span>JHT</span>
                                                    <span>{{ fmt(run.component_breakdown.bpjs.jht.employee) }}</span>
                                                </div>
                                                <div class="flex justify-between text-sm">
                                                    <span>JP</span>
                                                    <span>{{ fmt(run.component_breakdown.bpjs.jp.employee) }}</span>
                                                </div>
                                                <div class="flex justify-between text-sm font-medium border-t border-slate-200 pt-1">
                                                    <span>Total BPJS Karyawan</span>
                                                    <span>{{ fmt(run.component_breakdown.bpjs.total_employee) }}</span>
                                                </div>
                                            </div>

                                            <!-- PPh 21 -->
                                            <div v-if="run.component_breakdown.tax" class="space-y-2">
                                                <h4 class="font-medium text-purple-700">PPh 21</h4>
                                                <template v-if="run.component_breakdown.tax.method === 'ter_december'">
                                                    <div class="flex justify-between text-sm">
                                                        <span>Bruto setahun</span>
                                                        <span>{{ fmt(run.component_breakdown.tax.annual_gross ?? 0) }}</span>
                                                    </div>
                                                    <div class="flex justify-between text-sm">
                                                        <span>Biaya jabatan</span>
                                                        <span>−{{ fmt(run.component_breakdown.tax.biaya_jabatan ?? 0) }}</span>
                                                    </div>
                                                    <div class="flex justify-between text-sm">
                                                        <span>Iuran JHT + JP karyawan</span>
                                                        <span>−{{ fmt(run.component_breakdown.tax.pension_deduction ?? 0) }}</span>
                                                    </div>
                                                    <div class="flex justify-between text-sm">
                                                        <span>PTKP</span>
                                                        <span>−{{ fmt(run.component_breakdown.tax.ptwp ?? 0) }}</span>
                                                    </div>
                                                    <div class="flex justify-between text-sm">
                                                        <span>PKP</span>
                                                        <span>{{ fmt(run.component_breakdown.tax.pkp ?? 0) }}</span>
                                                    </div>
                                                    <div class="flex justify-between text-sm">
                                                        <span>PPh 21 setahun</span>
                                                        <span>{{ fmt(run.component_breakdown.tax.annual_tax ?? 0) }}</span>
                                                    </div>
                                                    <div class="flex justify-between text-sm">
                                                        <span>Sudah dipotong (TER)</span>
                                                        <span>−{{ fmt(run.component_breakdown.tax.accumulated_ter ?? 0) }}</span>
                                                    </div>
                                                    <div class="flex justify-between text-sm font-medium border-t border-slate-200 pt-1">
                                                        <span>PPh 21 Desember</span>
                                                        <span class="text-red-600">{{ fmt(run.component_breakdown.tax.monthly_tax) }}</span>
                                                    </div>
                                                    <div v-if="run.component_breakdown.tax.refund" class="flex justify-between text-sm font-medium">
                                                        <span>Kelebihan potong dikembalikan</span>
                                                        <span class="text-emerald-700">+{{ fmt(run.component_breakdown.tax.refund) }}</span>
                                                    </div>
                                                </template>
                                                <template v-else>
                                                    <div class="flex justify-between text-sm">
                                                        <span>Bruto kena pajak</span>
                                                        <span>{{ fmt(run.component_breakdown.tax.monthly_gross ?? 0) }}</span>
                                                    </div>
                                                    <div class="flex justify-between text-sm">
                                                        <span>Tarif TER {{ run.component_breakdown.tax.category }}</span>
                                                        <span>{{ run.component_breakdown.tax.rate_percent }}%</span>
                                                    </div>
                                                    <div class="flex justify-between text-sm font-medium border-t border-slate-200 pt-1">
                                                        <span>PPh 21 bulan ini</span>
                                                        <span class="text-red-600">{{ fmt(run.component_breakdown.tax.monthly_tax) }}</span>
                                                    </div>
                                                    <p class="text-xs text-slate-500">Bruto kena pajak termasuk premi BPJS Kesehatan, JKK, dan JKM yang ditanggung perusahaan.</p>
                                                </template>
                                            </div>
                                            <p v-if="run.component_breakdown.shortfall" class="text-xs text-red-700 sm:col-span-2 lg:col-span-3">
                                                Potongan melebihi penghasilan sebesar {{ fmt(run.component_breakdown.shortfall) }}. Gaji bersih ditetapkan Rp 0 —
                                                sisa potongan perlu diselesaikan manual.
                                            </p>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                            <tr v-if="!runs.length">
                                <td colspan="7" class="py-6 text-center text-slate-400">
                                    Belum ada slip gaji. Kembali ke daftar periode untuk menghitung gaji.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </CardContent>
            </Card>

            <!-- Dialog pembayaran -->
            <div v-if="showPay" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                <div class="absolute inset-0 bg-black/50" @click="showPay = false"></div>
                <div class="relative w-full max-w-sm space-y-4 rounded-2xl bg-white p-6 shadow-xl">
                    <div>
                        <h3 class="text-lg font-bold">Tandai gaji dibayar</h3>
                        <p class="mt-1 text-sm text-slate-500">
                            Total {{ fmt(totals.net_salary) }} untuk {{ runs.length }} karyawan. Setelah dibayar, periode final dan tidak bisa diubah.
                        </p>
                    </div>
                    <div class="space-y-1">
                        <label class="text-sm font-medium">Dibayar dari</label>
                        <select v-model="payForm.payment_method" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm">
                            <option value="cash">Kas tunai</option>
                            <option value="bank">Bank / transfer</option>
                        </select>
                    </div>
                    <div class="space-y-1">
                        <label class="text-sm font-medium">Tanggal bayar</label>
                        <input v-model="payForm.payment_date" type="date" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                        <p v-if="payForm.errors.payment_date" class="text-xs text-red-600">{{ payForm.errors.payment_date }}</p>
                    </div>
                    <p v-if="accounting_enabled" class="rounded-lg bg-indigo-50 p-2 text-xs text-indigo-800">
                        Jurnal otomatis: beban gaji & BPJS perusahaan; utang PPh 21 & BPJS; kas/bank sebesar gaji bersih.
                    </p>
                    <div class="flex gap-2">
                        <Button type="button" variant="outline" class="flex-1 rounded-xl" @click="showPay = false">Batal</Button>
                        <Button type="button" class="flex-1 rounded-xl" :disabled="payForm.processing" @click="pay">
                            {{ payForm.processing ? 'Menyimpan...' : 'Konfirmasi bayar' }}
                        </Button>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
