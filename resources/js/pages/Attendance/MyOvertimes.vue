<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import AttendanceNav from '@/components/AttendanceNav.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { CheckCircle2, XCircle, Clock } from 'lucide-vue-next';

interface Overtime {
    id: number;
    work_date: string;
    hours: number;
    reason: string | null;
    status: string;
    reviewed_by: string | null;
    review_note: string | null;
}

const props = defineProps<{
    overtimes: Overtime[];
}>();

const form = useForm({
    work_date: '',
    hours: '',
    reason: '',
});

const submit = () => {
    form.post('/attendance/my-overtimes', {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
};

const statusBadge = (status: string) =>
    ({
        pending: { label: 'Menunggu', class: 'bg-yellow-100 text-yellow-700', icon: Clock },
        approved: { label: 'Disetujui', class: 'bg-green-100 text-green-700', icon: CheckCircle2 },
        rejected: { label: 'Ditolak', class: 'bg-red-100 text-red-700', icon: XCircle },
    }[status] ?? { label: status, class: 'bg-slate-100 text-slate-600', icon: Clock });

const formatDate = (d: string) =>
    new Date(d + 'T00:00:00').toLocaleDateString('id-ID', { weekday: 'short', day: '2-digit', month: 'short', year: 'numeric' });
</script>

<template>
    <Head title="Pengajuan Lembur" />

    <AdminLayout title="Pengajuan Lembur">
        <div class="space-y-6 pb-8">
            <AttendanceNav />

            <!-- Form Pengajuan -->
            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <CardTitle>Ajukan Lembur Baru</CardTitle>
                    <CardDescription>Isi form di bawah untuk mengajukan lembur.</CardDescription>
                </CardHeader>
                <CardContent>
                    <form @submit.prevent="submit" class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label class="text-xs font-semibold text-slate-500">Tanggal Kerja *</Label>
                            <Input type="date" v-model="form.work_date" class="rounded-xl" required />
                        </div>
                        <div class="grid gap-2">
                            <Label class="text-xs font-semibold text-slate-500">Durasi (jam) *</Label>
                            <Input
                                type="number"
                                v-model="form.hours"
                                class="rounded-xl"
                                min="0.5"
                                max="24"
                                step="0.5"
                                placeholder="Contoh: 2.5"
                                required
                            />
                        </div>
                        <div class="grid gap-2 sm:col-span-2">
                            <Label class="text-xs font-semibold text-slate-500">Keterangan</Label>
                            <textarea
                                v-model="form.reason"
                                rows="2"
                                class="flex w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800 placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/40"
                                placeholder="Alasan lembur (opsional)"
                            />
                        </div>
                        <div class="sm:col-span-2 flex justify-end">
                            <Button type="submit" class="rounded-xl bg-indigo-600 hover:bg-indigo-700" :disabled="form.processing">
                                {{ form.processing ? 'Mengirim...' : 'Ajukan Lembur' }}
                            </Button>
                        </div>
                    </form>
                </CardContent>
            </Card>

            <!-- Riwayat Pengajuan -->
            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <CardTitle>Riwayat Pengajuan Lembur</CardTitle>
                    <CardDescription>{{ overtimes.length }} pengajuan.</CardDescription>
                </CardHeader>
                <CardContent>
                    <!-- Desktop table -->
                    <div class="hidden overflow-x-auto md:block">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="border-b border-slate-100 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">
                                    <th class="px-4 py-3">Tanggal</th>
                                    <th class="px-4 py-3 text-right">Durasi</th>
                                    <th class="px-4 py-3">Keterangan</th>
                                    <th class="px-4 py-3">Status</th>
                                    <th class="px-4 py-3">Ditinjau Oleh</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr v-for="o in overtimes" :key="o.id" class="hover:bg-slate-50/60 transition-colors">
                                    <td class="whitespace-nowrap px-4 py-3 font-medium text-slate-800">{{ formatDate(o.work_date) }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right font-mono text-slate-700">{{ o.hours }} jam</td>
                                    <td class="px-4 py-3 text-slate-600 max-w-[200px] truncate">{{ o.reason ?? '—' }}</td>
                                    <td class="whitespace-nowrap px-4 py-3">
                                        <Badge :class="[statusBadge(o.status).class, 'rounded-lg text-xs font-semibold']">
                                            {{ statusBadge(o.status).label }}
                                        </Badge>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-slate-600">
                                        {{ o.reviewed_by ?? '—' }}
                                        <span v-if="o.review_note" class="block text-xs text-slate-400">{{ o.review_note }}</span>
                                    </td>
                                </tr>
                                <tr v-if="overtimes.length === 0">
                                    <td colspan="5" class="px-4 py-12 text-center text-slate-400">
                                        Belum ada pengajuan lembur.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Mobile cards -->
                    <div class="space-y-3 md:hidden">
                        <div
                            v-for="o in overtimes"
                            :key="o.id"
                            class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm"
                        >
                            <div class="mb-2 flex items-center justify-between">
                                <span class="text-sm font-semibold text-slate-800">{{ formatDate(o.work_date) }}</span>
                                <Badge :class="[statusBadge(o.status).class, 'rounded-lg text-xs font-semibold']">
                                    {{ statusBadge(o.status).label }}
                                </Badge>
                            </div>
                            <div class="space-y-1 text-xs text-slate-600">
                                <div>Durasi: {{ o.hours }} jam</div>
                                <div v-if="o.reason" class="text-slate-500">{{ o.reason }}</div>
                                <div v-if="o.reviewed_by" class="text-slate-400">Ditinjau: {{ o.reviewed_by }} — {{ o.review_note ?? '-' }}</div>
                            </div>
                        </div>
                        <div v-if="overtimes.length === 0" class="py-12 text-center text-sm text-slate-400">
                            Belum ada pengajuan lembur.
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AdminLayout>
</template>
