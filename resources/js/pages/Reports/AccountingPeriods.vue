<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import ReportTabs from '@/components/ReportTabs.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { confirmDialog, toastError } from '@/lib/swal';

type ChecklistItem = { label: string; detail: string };
type Checklist = {
    can_close: boolean;
    blocking: ChecklistItem[];
    warnings: ChecklistItem[];
};

const props = defineProps<{
    store: { id: number; name: string; currency: string };
    is_owner: boolean;
    periods: Array<{
        id: number;
        name: string;
        start_date: string;
        end_date: string;
        status: 'open' | 'closed';
        closed_at: string | null;
        closed_by: { name: string } | null;
        reopened_at: string | null;
        reopened_by: { name: string } | null;
        reopen_reason: string | null;
    }>;
}>();

const form = ref({ name: '', start_date: '', end_date: '' });

const create = () => router.post('/financial/accounting-periods', form.value, {
    onSuccess: () => { form.value = { name: '', start_date: '', end_date: '' }; },
});

// ── Modal checklist sebelum tutup buku ──
const checklist = ref<Checklist | null>(null);
const checklistTarget = ref<{ id: number; name: string } | null>(null);
const closing = ref(false);

const fetchChecklist = async (id: number) => {
    try {
        const res = await fetch(`/financial/accounting-periods/${id}/checklist`, {
            headers: { Accept: 'application/json' },
        });
        if (!res.ok) throw new Error();
        return (await res.json()) as Checklist;
    } catch {
        toastError('Gagal memuat checklist penutupan.');
        return null;
    }
};

const openChecklist = async (period: { id: number; name: string }) => {
    const data = await fetchChecklist(period.id);
    if (!data) return;
    checklist.value = data;
    checklistTarget.value = { id: period.id, name: period.name };
};

const closeChecklist = () => {
    checklist.value = null;
    checklistTarget.value = null;
};

const confirmClosePeriod = async () => {
    if (!checklistTarget.value || !checklist.value) return;
    if (!checklist.value.can_close) return;

    const ok = await confirmDialog(
        'Tutup periode ini?',
        'Jurnal penutup akan dibuat dan seluruh jurnal pada rentang tanggal ini tidak dapat diubah.',
        { confirmText: 'Tutup Buku', icon: 'warning' },
    );
    if (!ok) return;

    closing.value = true;
    router.post(`/financial/accounting-periods/${checklistTarget.value.id}/close`, {}, {
        onFinish: () => {
            closing.value = false;
            closeChecklist();
        },
    });
};

const reopenReason = ref('');
const reopenTargetId = ref<number | null>(null);

const startReopen = (id: number) => {
    reopenTargetId.value = id;
    reopenReason.value = '';
};

const cancelReopen = () => {
    reopenTargetId.value = null;
    reopenReason.value = '';
};

const confirmReopen = async () => {
    if (!reopenTargetId.value) return;
    if (!reopenReason.value.trim()) return;

    const ok = await confirmDialog(
        'Buka ulang periode ini?',
        'Jurnal penutup periode ini akan dihapus permanen. Anda dapat melakukan penutupan ulang setelah koreksi jurnal selesai.',
        { confirmText: 'Buka Ulang', icon: 'warning' },
    );
    if (!ok) return;

    router.post(`/financial/accounting-periods/${reopenTargetId.value}/reopen`, {
        reason: reopenReason.value.trim(),
    }, {
        onSuccess: () => cancelReopen(),
    });
};
</script>

<template>
    <AdminLayout title="Periode akuntansi">
        <Head title="Periode akuntansi" />
        <ReportTabs active-tab="periode-akuntansi" />

        <p class="mb-4 text-sm text-slate-600">
            Tutup periode membuat jurnal penutup ke saldo laba dan mengunci jurnal pada tanggal periode tersebut.
        </p>

        <Card class="mb-6 rounded-2xl border-slate-200 shadow-sm">
            <CardHeader><CardTitle>Buat periode</CardTitle></CardHeader>
            <CardContent>
                <form class="flex flex-wrap items-end gap-3" @submit.prevent="create">
                    <div class="space-y-1.5"><Label>Nama</Label><Input v-model="form.name" required placeholder="Agustus 2026" /></div>
                    <div class="space-y-1.5"><Label>Dari</Label><Input v-model="form.start_date" required type="date" /></div>
                    <div class="space-y-1.5"><Label>Sampai</Label><Input v-model="form.end_date" required type="date" /></div>
                    <Button type="submit">Buat periode</Button>
                </form>
            </CardContent>
        </Card>

        <Card class="rounded-2xl border-slate-200 shadow-sm overflow-hidden">
            <CardContent class="p-0">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead><tr class="border-b bg-slate-50 text-left text-slate-600"><th class="px-4 py-3">Periode</th><th class="px-4 py-3">Rentang</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">Penutup</th><th class="px-4 py-3"></th></tr></thead>
                        <tbody>
                        <template v-for="period in props.periods" :key="period.id">
                            <tr class="border-b">
                                <td class="px-4 py-3 font-medium">{{ period.name }}</td>
                                <td class="px-4 py-3">{{ period.start_date }} s.d. {{ period.end_date }}</td>
                                <td class="px-4 py-3">
                                    <span :class="period.status === 'closed' ? 'bg-slate-200 text-slate-700' : 'bg-emerald-100 text-emerald-700'" class="rounded-full px-2.5 py-1 text-xs font-semibold">
                                        {{ period.status === 'closed' ? 'Ditutup' : 'Terbuka' }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-slate-600">{{ period.closed_by?.name ?? '-' }}</td>
                                <td class="px-4 py-3 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <Button v-if="period.status === 'open'" variant="destructive" size="sm" @click="openChecklist(period)">Tutup buku</Button>
                                        <Button
                                            v-if="period.status === 'closed' && props.is_owner && reopenTargetId !== period.id"
                                            variant="outline"
                                            size="sm"
                                            @click="startReopen(period.id)"
                                        >
                                            Buka ulang
                                        </Button>
                                    </div>
                                </td>
                            </tr>
                            <!-- Form alasan buka ulang -->
                            <tr v-if="reopenTargetId === period.id" class="bg-amber-50 border-b">
                                <td colspan="5" class="px-4 py-3">
                                    <p class="text-xs font-semibold text-amber-700 mb-2">Alasan membuka ulang periode "{{ period.name }}"</p>
                                    <div class="flex flex-wrap items-end gap-2">
                                        <div class="flex-1 min-w-[240px] space-y-1">
                                            <Label class="text-xs">Alasan (wajib)</Label>
                                            <Input
                                                v-model="reopenReason"
                                                placeholder="Contoh: koreksi jurnal penjualan tgl 5"
                                                class="text-sm"
                                            />
                                        </div>
                                        <Button size="sm" :disabled="!reopenReason.trim()" @click="confirmReopen">Konfirmasi</Button>
                                        <Button size="sm" variant="ghost" @click="cancelReopen">Batal</Button>
                                    </div>
                                </td>
                            </tr>
                            <!-- Info buka ulang bila pernah dibuka ulang -->
                            <tr v-if="period.reopen_reason" class="bg-slate-50 border-b text-xs text-slate-500">
                                <td colspan="5" class="px-4 py-2">
                                    Dibuka ulang oleh <span class="font-medium">{{ period.reopened_by?.name ?? '-' }}</span>
                                    <template v-if="period.reopened_at"> pada {{ period.reopened_at }}</template>
                                    — alasan: <em>{{ period.reopen_reason }}</em>
                                </td>
                            </tr>
                        </template>
                        <tr v-if="!props.periods.length"><td colspan="5" class="px-4 py-8 text-center text-slate-500">Belum ada periode.</td></tr>
                        </tbody>
                    </table>
                </div>
            </CardContent>
        </Card>

        <!-- Modal checklist pra-penutupan -->
        <div v-if="checklist && checklistTarget" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4" @click.self="closeChecklist">
            <div class="w-full max-w-lg rounded-2xl bg-white shadow-xl">
                <div class="flex items-start justify-between border-b px-6 py-4">
                    <div>
                        <h3 class="text-base font-semibold text-slate-900">Checklist Sebelum Tutup Buku</h3>
                        <p class="text-sm text-slate-500">Periode: {{ checklistTarget.name }}</p>
                    </div>
                    <button class="text-slate-400 hover:text-slate-600" @click="closeChecklist">×</button>
                </div>

                <div class="max-h-[60vh] space-y-4 overflow-y-auto px-6 py-5">
                    <!-- Item pemblokir -->
                    <div v-if="checklist.blocking.length" class="space-y-2">
                        <p class="text-xs font-semibold uppercase tracking-wide text-red-600">Perlu diselesaikan</p>
                        <div v-for="(item, i) in checklist.blocking" :key="'b' + i" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3">
                            <p class="text-sm font-semibold text-red-800">{{ item.label }}</p>
                            <p class="mt-1 text-xs text-red-700">{{ item.detail }}</p>
                        </div>
                    </div>

                    <!-- Peringatan -->
                    <div v-if="checklist.warnings.length" class="space-y-2">
                        <p class="text-xs font-semibold uppercase tracking-wide text-amber-600">Perhatian</p>
                        <div v-for="(item, i) in checklist.warnings" :key="'w' + i" class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3">
                            <p class="text-sm font-semibold text-amber-800">{{ item.label }}</p>
                            <p class="mt-1 text-xs text-amber-700">{{ item.detail }}</p>
                        </div>
                    </div>

                    <!-- Aman -->
                    <div v-if="checklist.can_close && !checklist.warnings.length" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3">
                        <p class="text-sm font-semibold text-emerald-800">Semua pemeriksaan lolos</p>
                        <p class="mt-1 text-xs text-emerald-700">Tidak ada shift terbuka atau data yang belum lengkap pada periode ini. Periode dapat ditutup dengan aman.</p>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2 border-t px-6 py-4">
                    <Button variant="ghost" @click="closeChecklist">Batal</Button>
                    <Button
                        variant="destructive"
                        :disabled="!checklist.can_close || closing"
                        @click="confirmClosePeriod"
                    >
                        {{ closing ? 'Menutup...' : 'Tutup Buku' }}
                    </Button>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
