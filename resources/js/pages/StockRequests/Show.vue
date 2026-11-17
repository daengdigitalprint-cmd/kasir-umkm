<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { ArrowLeft, ArrowRightLeft, CheckCircle, XCircle } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { modalError } from '@/lib/swal';

interface RequestLine {
    id: number;
    name: string | null;
    sku: string | null;
    unit: string | null;
    allows_decimal: boolean;
    quantity_requested: number;
    quantity_approved: number | null;
    stock_at_request: number;
    min_stock_at_request: number;
    supplier_stock: number | null;
}

const props = defineProps<{
    request: {
        id: number;
        request_number: string;
        status: string;
        status_label: string;
        notes: string | null;
        from_store: { id: number; name: string } | null;
        to_store: { id: number; name: string } | null;
        requester: string | null;
        approver: string | null;
        rejecter: string | null;
        rejected_reason: string | null;
        created_at: string | null;
        approved_at: string | null;
        rejected_at: string | null;
        cancelled_at: string | null;
        completed_at: string | null;
        transfer: { id: number; transfer_number: string; status: string } | null;
        lines: RequestLine[];
    };
    canApprove: boolean;
    canCancel: boolean;
}>();

const statusClass: Record<string, string> = {
    requested: 'bg-amber-100 text-amber-800',
    approved: 'bg-blue-100 text-blue-800',
    completed: 'bg-emerald-100 text-emerald-800',
    rejected: 'bg-red-100 text-red-700',
    cancelled: 'bg-slate-100 text-slate-600',
};

const fmt = (n: number) => new Intl.NumberFormat('id-ID', { maximumFractionDigits: 2 }).format(n);
const when = (iso: string | null) =>
    iso ? new Date(iso).toLocaleString('id-ID', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '';

// Revisi jumlah per baris (default = jumlah diminta, dibatasi stok pemasok saat ini).
const approvedQty = ref<Record<number, number>>(
    Object.fromEntries(props.request.lines.map((l) => [l.id, Math.min(l.quantity_requested, Math.max(l.supplier_stock ?? 0, 0))])),
);
const overStock = (l: RequestLine) => (approvedQty.value[l.id] ?? 0) > (l.supplier_stock ?? 0);
const canSubmitApprove = computed(
    () =>
        props.request.lines.some((l) => (approvedQty.value[l.id] ?? 0) > 0) &&
        props.request.lines.every((l) => (approvedQty.value[l.id] ?? 0) >= 0 && !overStock(l)),
);

const processing = ref(false);
const showReject = ref(false);
const rejectReason = ref('');

const onError = (title: string) => async (errors: Record<string, string>) => {
    const first = Object.values(errors)[0];
    await modalError(title, typeof first === 'string' ? first : 'Terjadi kesalahan.');
};

function approve() {
    processing.value = true;
    router.post(
        `/stock-requests/${props.request.id}/approve`,
        { lines: props.request.lines.map((l) => ({ id: l.id, quantity_approved: approvedQty.value[l.id] ?? 0 })) },
        { preserveScroll: true, onError: onError('Gagal menyetujui'), onFinish: () => (processing.value = false) },
    );
}

function reject() {
    processing.value = true;
    router.post(
        `/stock-requests/${props.request.id}/reject`,
        { reason: rejectReason.value },
        { preserveScroll: true, onError: onError('Gagal menolak'), onFinish: () => (processing.value = false) },
    );
}

function cancel() {
    processing.value = true;
    router.post(`/stock-requests/${props.request.id}/cancel`, {}, {
        preserveScroll: true,
        onError: onError('Gagal membatalkan'),
        onFinish: () => (processing.value = false),
    });
}

const isPending = computed(() => props.request.status === 'requested');
</script>

<template>
    <Head :title="request.request_number" />

    <AdminLayout title="Detail permintaan stok">
        <div class="mx-auto max-w-5xl space-y-6 pb-8">
            <Button as-child variant="outline" size="sm" class="gap-2 rounded-xl">
                <Link href="/stock-requests">
                    <ArrowLeft class="h-4 w-4" />
                    Kembali
                </Link>
            </Button>

            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <CardTitle class="font-mono">{{ request.request_number }}</CardTitle>
                            <p class="mt-1 text-sm text-slate-600">
                                <strong>{{ request.from_store?.name }}</strong> meminta ke <strong>{{ request.to_store?.name }}</strong>
                            </p>
                        </div>
                        <span class="rounded-full px-3 py-1 text-xs font-semibold" :class="statusClass[request.status] ?? 'bg-slate-100'">
                            {{ request.status_label }}
                        </span>
                    </div>
                </CardHeader>
                <CardContent class="space-y-5">
                    <ul class="space-y-1 text-sm text-slate-600">
                        <li>Diminta {{ when(request.created_at) }}<span v-if="request.requester"> oleh {{ request.requester }}</span></li>
                        <li v-if="request.approved_at">Disetujui {{ when(request.approved_at) }}<span v-if="request.approver"> oleh {{ request.approver }}</span></li>
                        <li v-if="request.completed_at" class="text-emerald-700">Diterima {{ when(request.completed_at) }}</li>
                        <li v-if="request.rejected_at" class="text-red-700">
                            Ditolak {{ when(request.rejected_at) }}<span v-if="request.rejecter"> oleh {{ request.rejecter }}</span>: {{ request.rejected_reason }}
                        </li>
                        <li v-if="request.cancelled_at">Dibatalkan {{ when(request.cancelled_at) }}</li>
                    </ul>

                    <p v-if="request.notes" class="rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-700">Catatan: {{ request.notes }}</p>

                    <Link
                        v-if="request.transfer"
                        :href="`/stock-transfers/${request.transfer.id}`"
                        class="inline-flex items-center gap-2 rounded-xl border border-violet-200 bg-violet-50 px-4 py-2 text-sm font-semibold text-violet-800 hover:bg-violet-100"
                    >
                        <ArrowRightLeft class="h-4 w-4" />
                        Lihat Transfer Stok {{ request.transfer.transfer_number }}
                    </Link>

                    <div class="overflow-x-auto rounded-xl border border-slate-100">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b bg-slate-50 text-left text-xs font-bold uppercase tracking-wide text-slate-600">
                                    <th class="p-3">Barang</th>
                                    <th class="p-3 text-right">Stok peminta / Min (saat diminta)</th>
                                    <th class="p-3 text-right">Diminta</th>
                                    <th v-if="canApprove" class="p-3 text-right">Stok {{ request.to_store?.name }}</th>
                                    <th v-if="canApprove || !isPending" class="p-3">Disetujui</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="l in request.lines" :key="l.id" class="border-b border-slate-100 last:border-0">
                                    <td class="p-3">
                                        <p class="font-medium text-slate-900">{{ l.name }}</p>
                                        <p v-if="l.sku" class="font-mono text-xs text-slate-400">{{ l.sku }}</p>
                                    </td>
                                    <td class="p-3 text-right tabular-nums text-slate-600">{{ fmt(l.stock_at_request) }} / {{ fmt(l.min_stock_at_request) }}</td>
                                    <td class="p-3 text-right font-semibold tabular-nums">{{ fmt(l.quantity_requested) }} {{ l.unit }}</td>
                                    <td v-if="canApprove" class="p-3 text-right tabular-nums" :class="(l.supplier_stock ?? 0) < l.quantity_requested ? 'text-amber-700' : 'text-slate-700'">
                                        {{ l.supplier_stock === null ? '—' : fmt(l.supplier_stock) }}
                                    </td>
                                    <td v-if="canApprove" class="p-3">
                                        <input
                                            v-model.number="approvedQty[l.id]"
                                            type="number"
                                            min="0"
                                            :step="l.allows_decimal ? 0.01 : 1"
                                            class="h-9 w-24 rounded-md border px-2 text-right"
                                            :class="overStock(l) ? 'border-red-400 bg-red-50' : 'border-slate-200'"
                                            :aria-label="`Jumlah disetujui ${l.name}`"
                                        />
                                        <span class="ml-1 text-slate-500">{{ l.unit }}</span>
                                        <p v-if="overStock(l)" class="text-xs text-red-600">Melebihi stok</p>
                                    </td>
                                    <td v-else-if="!isPending" class="p-3 tabular-nums">
                                        <span v-if="l.quantity_approved === null" class="text-slate-400">—</span>
                                        <span v-else :class="l.quantity_approved < l.quantity_requested ? 'font-semibold text-amber-700' : 'text-slate-700'">
                                            {{ fmt(l.quantity_approved) }} {{ l.unit }}
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-if="canApprove" class="space-y-3">
                        <p class="text-xs text-slate-500">
                            Ubah jumlah bila stok terbatas; isi 0 untuk barang yang tidak dikirim. Menyetujui langsung membuat Transfer Stok yang terkirim dan memotong stok lokasi ini.
                        </p>
                        <div v-if="!showReject" class="flex flex-wrap gap-2">
                            <Button class="gap-1 rounded-xl" :disabled="processing || !canSubmitApprove" @click="approve">
                                <CheckCircle class="h-4 w-4" />
                                Setujui &amp; kirim
                            </Button>
                            <Button variant="destructive" class="gap-1 rounded-xl" :disabled="processing" @click="showReject = true">
                                <XCircle class="h-4 w-4" />
                                Tolak
                            </Button>
                        </div>
                        <div v-else class="space-y-2 rounded-xl border border-red-200 bg-red-50 p-4">
                            <label class="text-sm font-semibold text-red-800" for="reject-reason">Alasan penolakan</label>
                            <input
                                id="reject-reason"
                                v-model="rejectReason"
                                type="text"
                                maxlength="500"
                                class="h-10 w-full rounded-lg border border-red-200 px-3"
                                placeholder="Mis. stok dipakai untuk pesanan katering"
                            />
                            <div class="flex gap-2">
                                <Button variant="destructive" class="rounded-xl" :disabled="processing || rejectReason.trim().length < 3" @click="reject">
                                    Ya, tolak
                                </Button>
                                <Button variant="outline" class="rounded-xl" :disabled="processing" @click="showReject = false">Batal</Button>
                            </div>
                        </div>
                    </div>

                    <div v-if="canCancel">
                        <Button variant="outline" class="rounded-xl text-red-600" :disabled="processing" @click="cancel">Batalkan permintaan</Button>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AdminLayout>
</template>
