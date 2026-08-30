<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { ArrowLeft, Ban, CheckCircle2 } from 'lucide-vue-next';

interface LineRow {
    id: number;
    system_quantity: string;
    counted_quantity: string;
    variance_quantity: string;
    unit_cost: string;
    variance_value: string;
    line_notes: string | null;
    product: { id: number; name: string; sku: string | null; unit: string | null } | null;
    product_variant: { id: number; name: string; sku: string | null } | null;
}

interface AdjustmentDetail {
    id: number;
    adjustment_number: string;
    type: string;
    reason_code: string;
    status: string;
    notes: string | null;
    counted_at: string | null;
    posted_at: string | null;
    cancelled_at: string | null;
    cancellation_reason: string | null;
    total_variance_value: string;
    lines: LineRow[];
    created_by: { id: number; name: string } | null;
    posted_by: { id: number; name: string } | null;
    cancelled_by: { id: number; name: string } | null;
}

const props = defineProps<{
    adjustment: AdjustmentDetail;
    reasonLabel: string;
    statusLabel: string;
    canCancel: boolean;
    canApprove?: boolean;
    segregationBlocked?: boolean;
}>();

const showCancel = ref(false);

const cancelForm = useForm({ cancellation_reason: '' });
const approveForm = useForm({});

const isPending = computed(() => props.adjustment.status === 'pending_approval');

function submitApprove() {
    approveForm.post(`/stock-adjustments/${props.adjustment.id}/approve`, { preserveScroll: true });
}

const statusClass: Record<string, string> = {
    draft: 'bg-slate-100 text-slate-700',
    pending_approval: 'bg-amber-100 text-amber-800',
    posted: 'bg-emerald-100 text-emerald-800',
    cancelled: 'bg-red-100 text-red-700',
};

const formatWhen = (iso: string | null) =>
    iso
        ? new Date(iso).toLocaleString('id-ID', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
              hour: '2-digit',
              minute: '2-digit',
          })
        : '—';

const formatQty = (v: string | number) => {
    const n = Number(v ?? 0);
    const rounded = Math.round(n * 10000) / 10000;
    return Number.isInteger(rounded) ? String(rounded) : rounded.toLocaleString('id-ID', { maximumFractionDigits: 4 });
};

const formatRupiah = (v: string | number) => {
    const n = Number(v ?? 0);
    const sign = n < 0 ? '-' : n > 0 ? '+' : '';
    return `${sign}Rp${Math.abs(Math.round(n)).toLocaleString('id-ID')}`;
};

const valueClass = (v: string | number) => {
    const n = Number(v ?? 0);
    if (n < 0) return 'text-red-600';
    if (n > 0) return 'text-emerald-600';
    return 'text-slate-400';
};

function submitCancel() {
    cancelForm.post(`/stock-adjustments/${props.adjustment.id}/cancel`, {
        preserveScroll: true,
        onSuccess: () => {
            showCancel.value = false;
        },
    });
}
</script>

<template>
    <Head :title="props.adjustment.adjustment_number" />

    <AdminLayout :title="props.adjustment.adjustment_number">
        <div class="mx-auto space-y-6 pb-8" style="max-width: 80rem">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <Button as-child variant="ghost" size="icon" class="rounded-xl">
                        <Link href="/stock-adjustments"><ArrowLeft class="h-4 w-4" /></Link>
                    </Button>
                    <div>
                        <div class="flex items-center gap-3">
                            <h1 class="text-2xl font-black text-slate-900">{{ props.adjustment.adjustment_number }}</h1>
                            <span
                                class="rounded-full px-2.5 py-1 text-xs font-bold"
                                :class="statusClass[props.adjustment.status] ?? 'bg-slate-100 text-slate-700'"
                            >
                                {{ props.statusLabel }}
                            </span>
                        </div>
                        <p class="mt-1 text-sm text-slate-600">{{ props.reasonLabel }}</p>
                    </div>
                </div>

                <Button v-if="props.canCancel" variant="outline" class="rounded-xl text-red-600" @click="showCancel = !showCancel">
                    <Ban class="mr-2 h-4 w-4" />
                    {{ isPending ? 'Tolak dokumen' : 'Batalkan dokumen' }}
                </Button>
            </div>

            <div
                v-if="isPending"
                class="rounded-2xl border border-amber-200 bg-amber-50 p-5"
            >
                <p class="font-bold text-amber-900">Dokumen menunggu persetujuan</p>
                <p class="mt-1 text-sm text-amber-800">
                    Stok belum berubah. Selisih baru diterapkan setelah dokumen ini disetujui.
                </p>

                <p v-if="props.segregationBlocked" class="mt-3 text-sm font-semibold text-amber-900">
                    Anda adalah pembuat dokumen ini, sehingga persetujuan harus dilakukan pengguna lain.
                </p>

                <p v-if="approveForm.errors.approval" class="mt-2 text-sm font-semibold text-red-700">
                    {{ approveForm.errors.approval }}
                </p>

                <Button
                    v-if="props.canApprove"
                    class="mt-3 rounded-xl"
                    :disabled="approveForm.processing"
                    @click="submitApprove"
                >
                    <CheckCircle2 class="mr-2 h-4 w-4" />
                    {{ approveForm.processing ? 'Memproses…' : 'Setujui dan posting' }}
                </Button>
            </div>

            <div v-if="showCancel" class="rounded-2xl border border-red-200 bg-red-50 p-5">
                <p class="font-bold text-red-900">Batalkan penyesuaian ini?</p>
                <p class="mt-1 text-sm text-red-800">
                    Stok akan dikembalikan ke nilai sebelum koreksi melalui mutasi pembalik. Tindakan ini tercatat permanen.
                </p>
                <textarea
                    v-model="cancelForm.cancellation_reason"
                    rows="2"
                    placeholder="Alasan pembatalan (wajib)"
                    class="mt-3 w-full rounded-xl border border-red-200 px-3 py-2 text-sm focus:border-red-500 focus:outline-none"
                ></textarea>
                <p v-if="cancelForm.errors.cancellation_reason" class="mt-1 text-sm font-semibold text-red-700">
                    {{ cancelForm.errors.cancellation_reason }}
                </p>
                <div class="mt-3 flex gap-2">
                    <Button
                        class="rounded-xl bg-red-600 hover:bg-red-700"
                        :disabled="cancelForm.processing || !cancelForm.cancellation_reason"
                        @click="submitCancel"
                    >
                        {{ cancelForm.processing ? 'Memproses…' : 'Ya, batalkan' }}
                    </Button>
                    <Button variant="outline" class="rounded-xl" @click="showCancel = false">Tidak jadi</Button>
                </div>
            </div>

            <div class="grid gap-4 md:grid-cols-4">
                <Card class="rounded-2xl border-slate-200 shadow-sm">
                    <CardContent class="pt-6">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Nilai selisih</p>
                        <p class="mt-1 text-xl font-black tabular-nums" :class="valueClass(props.adjustment.total_variance_value)">
                            {{ formatRupiah(props.adjustment.total_variance_value) }}
                        </p>
                    </CardContent>
                </Card>
                <Card class="rounded-2xl border-slate-200 shadow-sm">
                    <CardContent class="pt-6">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Jumlah produk</p>
                        <p class="mt-1 text-xl font-black text-slate-900">{{ props.adjustment.lines.length }}</p>
                    </CardContent>
                </Card>
                <Card class="rounded-2xl border-slate-200 shadow-sm">
                    <CardContent class="pt-6">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Waktu hitung</p>
                        <p class="mt-1 text-sm font-bold text-slate-900">{{ formatWhen(props.adjustment.counted_at) }}</p>
                    </CardContent>
                </Card>
                <Card class="rounded-2xl border-slate-200 shadow-sm">
                    <CardContent class="pt-6">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Diposting oleh</p>
                        <p class="mt-1 text-sm font-bold text-slate-900">{{ props.adjustment.posted_by?.name ?? '—' }}</p>
                        <p class="text-xs text-slate-500">{{ formatWhen(props.adjustment.posted_at) }}</p>
                    </CardContent>
                </Card>
            </div>

            <div
                v-if="props.adjustment.cancellation_reason"
                class="rounded-2xl border border-red-200 bg-red-50 px-5 py-4 text-sm text-red-900"
            >
                <p class="font-bold">
                    Dibatalkan oleh {{ props.adjustment.cancelled_by?.name ?? '—' }} pada {{ formatWhen(props.adjustment.cancelled_at) }}
                </p>
                <p class="mt-1">{{ props.adjustment.cancellation_reason }}</p>
            </div>

            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <CardTitle class="text-base font-bold">Rincian koreksi</CardTitle>
                    <CardDescription v-if="props.adjustment.notes">{{ props.adjustment.notes }}</CardDescription>
                </CardHeader>
                <CardContent class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500">
                                <th class="py-3 pr-4 font-semibold">Produk</th>
                                <th class="py-3 pr-4 text-right font-semibold">Stok sistem</th>
                                <th class="py-3 pr-4 text-right font-semibold">Jumlah fisik</th>
                                <th class="py-3 pr-4 text-right font-semibold">Selisih</th>
                                <th class="py-3 pr-4 text-right font-semibold">HPP satuan</th>
                                <th class="py-3 pr-4 text-right font-semibold">Nilai</th>
                                <th class="py-3 font-semibold">Catatan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="line in props.adjustment.lines" :key="line.id" class="border-b border-slate-100">
                                <td class="py-3 pr-4">
                                    <p class="font-semibold text-slate-900">
                                        {{ line.product?.name ?? '—'
                                        }}<span v-if="line.product_variant" class="text-slate-500">
                                            — {{ line.product_variant.name }}</span
                                        >
                                    </p>
                                    <p class="text-xs text-slate-400">
                                        {{ line.product_variant?.sku ?? line.product?.sku ?? '—' }}
                                    </p>
                                </td>
                                <td class="py-3 pr-4 text-right tabular-nums text-slate-600">{{ formatQty(line.system_quantity) }}</td>
                                <td class="py-3 pr-4 text-right tabular-nums text-slate-600">{{ formatQty(line.counted_quantity) }}</td>
                                <td class="py-3 pr-4 text-right font-bold tabular-nums" :class="valueClass(line.variance_quantity)">
                                    {{ Number(line.variance_quantity) > 0 ? '+' : '' }}{{ formatQty(line.variance_quantity) }}
                                    <span class="text-xs font-normal text-slate-400">{{ line.product?.unit }}</span>
                                </td>
                                <td class="py-3 pr-4 text-right tabular-nums text-slate-500">
                                    Rp{{ Math.round(Number(line.unit_cost)).toLocaleString('id-ID') }}
                                </td>
                                <td class="py-3 pr-4 text-right font-semibold tabular-nums" :class="valueClass(line.variance_value)">
                                    {{ formatRupiah(line.variance_value) }}
                                </td>
                                <td class="py-3 text-slate-500">{{ line.line_notes ?? '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </CardContent>
            </Card>
        </div>
    </AdminLayout>
</template>
