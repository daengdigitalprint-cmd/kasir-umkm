<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import PayrollNav from '@/components/PayrollNav.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Eye, ChevronDown, ChevronUp } from 'lucide-vue-next';

interface ComponentBreakdown {
    earnings: { name: string; amount: number }[];
    deductions: { name: string; amount: number }[];
    late_penalty: {
        late_days: number;
        late_minutes: number;
        penalty_per_day: number;
        penalty_per_minute: number;
        total: number;
    };
    overtime: {
        hours: number;
        rate_multiplier: number;
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
    tax: {
        annual_income: number;
        ptwp: number;
        pkp: number;
        annual_tax: number;
        monthly_tax: number;
    } | null;
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

defineProps<{
    period: {
        id: number;
        name: string;
        period_start: string;
        period_end: string;
        status: string;
    };
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
</script>

<template>
    <Head :title="period.name" />

    <AdminLayout title="Detail Payroll">
        <div class="space-y-6 pb-8">
            <PayrollNav />

            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <CardTitle>{{ period.name }}</CardTitle>
                    <CardDescription>
                        {{ period.period_start }} s.d. {{ period.period_end }} ·
                        {{ period.status === 'locked' ? 'Terkunci' : 'Draft' }}
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-5">
                        <div class="rounded-xl bg-slate-50 p-3">
                            <p class="text-slate-500">Gaji Pokok</p>
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
                                <th class="py-2 pr-3">Gaji Pokok</th>
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
                                        <Button type="button" variant="ghost" size="sm" @click="toggleExpand(run.id)">
                                            <ChevronDown v-if="expandedRun !== run.id" class="h-4 w-4" />
                                            <ChevronUp v-else class="h-4 w-4" />
                                        </Button>
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
                                                    <span>Lembur ({{ run.component_breakdown.overtime.hours }} jam × {{ run.component_breakdown.overtime.rate_multiplier }}×)</span>
                                                    <span>{{ fmt(run.component_breakdown.overtime.total) }}</span>
                                                </div>
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
                                                <div class="flex justify-between text-sm">
                                                    <span>Penghasilan Bruto/tahun</span>
                                                    <span>{{ fmt(run.component_breakdown.tax.annual_income) }}</span>
                                                </div>
                                                <div class="flex justify-between text-sm">
                                                    <span>PTKP</span>
                                                    <span>{{ fmt(run.component_breakdown.tax.ptwp) }}</span>
                                                </div>
                                                <div class="flex justify-between text-sm">
                                                    <span>PKP</span>
                                                    <span>{{ fmt(run.component_breakdown.tax.pkp) }}</span>
                                                </div>
                                                <div class="flex justify-between text-sm">
                                                    <span>PPh 21/tahun</span>
                                                    <span>{{ fmt(run.component_breakdown.tax.annual_tax) }}</span>
                                                </div>
                                                <div class="flex justify-between text-sm font-medium border-t border-slate-200 pt-1">
                                                    <span>PPh 21/bulan</span>
                                                    <span class="text-red-600">{{ fmt(run.component_breakdown.tax.monthly_tax) }}</span>
                                                </div>
                                            </div>
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
        </div>
    </AdminLayout>
</template>
