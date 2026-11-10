<script setup lang="ts">
import { Head, useForm, router } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import AttendanceNav from '@/components/AttendanceNav.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { FileText, CheckCircle2, XCircle, Clock } from 'lucide-vue-next';

interface Leave {
    id: number;
    type: string;
    start_date: string;
    end_date: string;
    reason: string | null;
    status: string;
    reviewed_by: string | null;
    review_note: string | null;
}

const props = defineProps<{
    leaves: Leave[];
}>();

const form = useForm({
    type: '',
    start_date: '',
    end_date: '',
    reason: '',
});

const submit = () => {
    form.post('/attendance/my-leaves', {
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

const leaveTypes = [
    { value: 'annual', label: 'Cuti tahunan' },
    { value: 'sick', label: 'Sakit' },
    { value: 'maternity', label: 'Cuti melahirkan' },
    { value: 'marriage', label: 'Cuti menikah' },
    { value: 'bereavement', label: 'Cuti duka' },
    { value: 'unpaid', label: 'Tanpa gaji' },
    { value: 'other', label: 'Lainnya' },
];

const typeLabel = (value: string) => leaveTypes.find((t) => t.value === value)?.label ?? value;

const formatRange = (start: string, end: string) => {
    const s = new Date(start + 'T00:00:00');
    const e = new Date(end + 'T00:00:00');
    const opts = { day: '2-digit', month: 'short', year: 'numeric' } as const;
    if (start === end) return s.toLocaleDateString('id-ID', opts);
    return `${s.toLocaleDateString('id-ID', opts)} — ${e.toLocaleDateString('id-ID', opts)}`;
};
</script>

<template>
    <Head title="Pengajuan Cuti" />

    <AdminLayout title="Pengajuan Cuti">
        <div class="space-y-6 pb-8">
            <AttendanceNav />

            <!-- Form Pengajuan -->
            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <CardTitle>Ajukan Cuti Baru</CardTitle>
                    <CardDescription>Isi form di bawah untuk mengajukan cuti.</CardDescription>
                </CardHeader>
                <CardContent>
                    <form @submit.prevent="submit" class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2 sm:col-span-2">
                            <Label class="text-xs font-semibold text-slate-500">Jenis Cuti *</Label>
                            <select
                                v-model="form.type"
                                class="flex h-10 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-500/40"
                                required
                            >
                                <option value="" disabled>Pilih jenis cuti</option>
                                <option v-for="t in leaveTypes" :key="t.value" :value="t.value">{{ t.label }}</option>
                            </select>
                        </div>
                        <div class="grid gap-2">
                            <Label class="text-xs font-semibold text-slate-500">Dari Tanggal *</Label>
                            <Input type="date" v-model="form.start_date" class="rounded-xl" required />
                        </div>
                        <div class="grid gap-2">
                            <Label class="text-xs font-semibold text-slate-500">Sampai Tanggal *</Label>
                            <Input type="date" v-model="form.end_date" class="rounded-xl" required />
                        </div>
                        <div class="grid gap-2 sm:col-span-2">
                            <Label class="text-xs font-semibold text-slate-500">Keterangan</Label>
                            <textarea
                                v-model="form.reason"
                                rows="2"
                                class="flex w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800 placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/40"
                                placeholder="Alasan cuti (opsional)"
                            />
                        </div>
                        <div class="sm:col-span-2 flex justify-end">
                            <Button type="submit" class="rounded-xl bg-indigo-600 hover:bg-indigo-700" :disabled="form.processing">
                                {{ form.processing ? 'Mengirim...' : 'Ajukan Cuti' }}
                            </Button>
                        </div>
                    </form>
                </CardContent>
            </Card>

            <!-- Riwayat Pengajuan -->
            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <CardTitle>Riwayat Pengajuan Cuti</CardTitle>
                    <CardDescription>{{ leaves.length }} pengajuan.</CardDescription>
                </CardHeader>
                <CardContent>
                    <!-- Desktop table -->
                    <div class="hidden overflow-x-auto md:block">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="border-b border-slate-100 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">
                                    <th class="px-4 py-3">Jenis</th>
                                    <th class="px-4 py-3">Periode</th>
                                    <th class="px-4 py-3">Keterangan</th>
                                    <th class="px-4 py-3">Status</th>
                                    <th class="px-4 py-3">Ditinjau Oleh</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr v-for="l in leaves" :key="l.id" class="hover:bg-slate-50/60 transition-colors">
                                    <td class="whitespace-nowrap px-4 py-3 font-medium text-slate-800">{{ typeLabel(l.type) }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-slate-600">{{ formatRange(l.start_date, l.end_date) }}</td>
                                    <td class="px-4 py-3 text-slate-600 max-w-[200px] truncate">{{ l.reason ?? '—' }}</td>
                                    <td class="whitespace-nowrap px-4 py-3">
                                        <Badge :class="[statusBadge(l.status).class, 'rounded-lg text-xs font-semibold']">
                                            {{ statusBadge(l.status).label }}
                                        </Badge>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-slate-600">
                                        {{ l.reviewed_by ?? '—' }}
                                        <span v-if="l.review_note" class="block text-xs text-slate-400">{{ l.review_note }}</span>
                                    </td>
                                </tr>
                                <tr v-if="leaves.length === 0">
                                    <td colspan="5" class="px-4 py-12 text-center text-slate-400">
                                        Belum ada pengajuan cuti.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Mobile cards -->
                    <div class="space-y-3 md:hidden">
                        <div
                            v-for="l in leaves"
                            :key="l.id"
                            class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm"
                        >
                            <div class="mb-2 flex items-center justify-between">
                                <span class="text-sm font-semibold text-slate-800">{{ typeLabel(l.type) }}</span>
                                <Badge :class="[statusBadge(l.status).class, 'rounded-lg text-xs font-semibold']">
                                    {{ statusBadge(l.status).label }}
                                </Badge>
                            </div>
                            <div class="space-y-1 text-xs text-slate-600">
                                <div>{{ formatRange(l.start_date, l.end_date) }}</div>
                                <div v-if="l.reason" class="text-slate-500">{{ l.reason }}</div>
                                <div v-if="l.reviewed_by" class="text-slate-400">Ditinjau: {{ l.reviewed_by }} — {{ l.review_note ?? '-' }}</div>
                            </div>
                        </div>
                        <div v-if="leaves.length === 0" class="py-12 text-center text-sm text-slate-400">
                            Belum ada pengajuan cuti.
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AdminLayout>
</template>
