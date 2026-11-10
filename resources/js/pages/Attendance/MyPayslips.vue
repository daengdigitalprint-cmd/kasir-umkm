<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import AttendanceNav from '@/components/AttendanceNav.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { FileDown, Wallet } from 'lucide-vue-next';

interface Slip {
    id: number;
    period: string;
    period_start: string;
    period_end: string;
    status: 'approved' | 'paid' | string;
    payment_date: string | null;
    gross_salary: number;
    deductions: number;
    net_salary: number;
}

defineProps<{ slips: Slip[] }>();

const fmt = (n: number) =>
    new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(n);

const formatDate = (date: string) =>
    new Date(`${date}T00:00:00`).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' });
</script>

<template>
    <Head title="Slip Gaji Saya" />

    <AdminLayout title="Slip Gaji Saya">
        <div class="space-y-6 pb-8">
            <AttendanceNav />

            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <CardTitle class="flex items-center gap-2"><Wallet class="h-5 w-5" /> Slip Gaji Saya</CardTitle>
                    <CardDescription>Slip muncul setelah gaji periode itu disetujui pemilik toko.</CardDescription>
                </CardHeader>
                <CardContent class="space-y-3">
                    <div
                        v-for="slip in slips"
                        :key="slip.id"
                        class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 p-4"
                    >
                        <div>
                            <p class="font-semibold text-slate-800">{{ slip.period }}</p>
                            <p class="text-xs text-slate-500">
                                {{ formatDate(slip.period_start) }} – {{ formatDate(slip.period_end) }} ·
                                <span :class="slip.status === 'paid' ? 'text-emerald-600' : 'text-blue-600'">
                                    {{ slip.status === 'paid' ? `Dibayar ${slip.payment_date ? formatDate(slip.payment_date) : ''}` : 'Disetujui, menunggu pembayaran' }}
                                </span>
                            </p>
                        </div>
                        <div class="flex items-center gap-4">
                            <div class="text-right">
                                <p class="text-xs text-slate-500">Gaji bersih</p>
                                <p class="text-lg font-bold text-slate-900">{{ fmt(slip.net_salary) }}</p>
                            </div>
                            <a
                                :href="`/attendance/my-payslips/${slip.id}`"
                                class="inline-flex items-center gap-1 rounded-xl border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
                            >
                                <FileDown class="h-4 w-4" /> PDF
                            </a>
                        </div>
                    </div>
                    <p v-if="!slips.length" class="py-8 text-center text-sm text-slate-400">Belum ada slip gaji.</p>
                </CardContent>
            </Card>
        </div>
    </AdminLayout>
</template>
