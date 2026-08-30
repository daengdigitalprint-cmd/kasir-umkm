<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ArrowLeft, ArrowRightLeft, Send, CheckCircle, XCircle } from 'lucide-vue-next';
import { ref } from 'vue';
import { modalError } from '@/lib/swal';

interface ProductTiny {
    id: number;
    name: string;
    sku: string | null;
    unit: string | null;
    base_unit: string | null;
}

interface Line {
    id: number;
    quantity: string;
    base_unit: string | null;
    unit_cost_snapshot: string | null;
    total_cost_snapshot: string | null;
    from_product: ProductTiny;
    to_product: ProductTiny;
}

interface Transfer {
    id: number;
    transfer_number: string;
    status: string;
    notes: string | null;
    created_at: string;
    sent_at: string | null;
    received_at: string | null;
    cancelled_at: string | null;
    cancellation_reason: string | null;
    from_store: { id: number; name: string };
    to_store: { id: number; name: string };
    user: { id: number; name: string } | null;
    sent_by: { id: number; name: string } | null;
    received_by: { id: number; name: string } | null;
    cancelled_by: { id: number; name: string } | null;
    lines: Line[];
}

const props = defineProps<{
    transfer: Transfer;
    canSend: boolean;
    canReceive: boolean;
    canCancel: boolean;
}>();

const showCancelForm = ref(false);
const cancelReason = ref('');
const processing = ref(false);

const formatWhen = (iso: string) =>
    new Date(iso).toLocaleString('id-ID', {
        day: '2-digit',
        month: 'long',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });

const statusConfig: Record<string, { label: string; class: string }> = {
    draft: { label: 'Draft', class: 'bg-slate-100 text-slate-700' },
    sent: { label: 'Dikirim', class: 'bg-amber-100 text-amber-800' },
    received: { label: 'Diterima', class: 'bg-emerald-100 text-emerald-800' },
    cancelled: { label: 'Dibatalkan', class: 'bg-red-100 text-red-700' },
};

function sendTransfer() {
    processing.value = true;
    router.post(
        `/stock-transfers/${props.transfer.id}/send`,
        {},
        {
            preserveScroll: true,
            onFinish: () => { processing.value = false; },
            onError: async (errors) => {
                const first = Object.values(errors)[0];
                const msg = Array.isArray(first) ? first[0] : typeof first === 'string' ? first : 'Gagal mengirim transfer.';
                await modalError('Transfer gagal', msg);
            },
        },
    );
}

function receiveTransfer() {
    processing.value = true;
    router.post(
        `/stock-transfers/${props.transfer.id}/receive`,
        {},
        {
            preserveScroll: true,
            onFinish: () => { processing.value = false; },
            onError: async (errors) => {
                const first = Object.values(errors)[0];
                const msg = Array.isArray(first) ? first[0] : typeof first === 'string' ? first : 'Gagal menerima transfer.';
                await modalError('Transfer gagal', msg);
            },
        },
    );
}

async function cancelTransfer() {
    if (!cancelReason.value || cancelReason.value.trim().length < 3) {
        await modalError('Data belum lengkap', 'Alasan pembatalan minimal 3 karakter.');
        return;
    }
    processing.value = true;
    router.post(
        `/stock-transfers/${props.transfer.id}/cancel`,
        { cancellation_reason: cancelReason.value },
        {
            preserveScroll: true,
            onFinish: () => { processing.value = false; showCancelForm.value = false; },
            onError: async (errors) => {
                const first = Object.values(errors)[0];
                const msg = Array.isArray(first) ? first[0] : typeof first === 'string' ? first : 'Gagal membatalkan transfer.';
                await modalError('Pembatalan gagal', msg);
            },
        },
    );
}

// Timeline items
const timeline = [
    props.transfer.user
        ? { label: 'Dibuat', who: props.transfer.user.name, when: props.transfer.created_at, color: 'bg-slate-400' }
        : null,
    props.transfer.sent_by
        ? { label: 'Dikirim', who: props.transfer.sent_by.name, when: props.transfer.sent_at!, color: 'bg-amber-500' }
        : null,
    props.transfer.received_by
        ? { label: 'Diterima', who: props.transfer.received_by.name, when: props.transfer.received_at!, color: 'bg-emerald-500' }
        : null,
    props.transfer.cancelled_by
        ? { label: 'Dibatalkan', who: props.transfer.cancelled_by.name, when: props.transfer.cancelled_at!, color: 'bg-red-500' }
        : null,
].filter(Boolean) as { label: string; who: string; when: string; color: string }[];

/** Hilangkan trailing .0000 dari angka desimal database. */
const fmtQty = (val: string | number) => {
    const num = Number(val);
    return Number.isInteger(num) ? num.toString() : num.toFixed(4).replace(/\.?0+$/, '');
};
</script>

<template>
    <Head :title="transfer.transfer_number" />

    <AdminLayout title="Detail transfer">
        <div class="mx-auto space-y-6 pb-8" style="max-width: 90rem">
            <Button as-child variant="outline" size="sm" class="gap-2 rounded-xl">
                <Link href="/stock-transfers">
                    <ArrowLeft class="h-4 w-4" />
                    Kembali
                </Link>
            </Button>

            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <div class="flex items-center gap-2">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-violet-500/10">
                            <ArrowRightLeft class="h-5 w-5 text-violet-700" />
                        </div>
                        <div>
                            <CardTitle class="font-mono flex items-center gap-2">
                                {{ transfer.transfer_number }}
                                <span
                                    class="inline-flex rounded-lg px-2 py-0.5 text-xs font-bold"
                                    :class="statusConfig[transfer.status]?.class ?? 'bg-slate-100 text-slate-600'"
                                >
                                    {{ statusConfig[transfer.status]?.label ?? transfer.status }}
                                </span>
                            </CardTitle>
                            <CardDescription>{{ formatWhen(transfer.created_at) }}</CardDescription>
                        </div>
                    </div>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div class="rounded-xl bg-slate-50 border border-slate-100 p-4 text-sm">
                        <p>
                            <span class="text-slate-500">Dari</span>
                            <strong class="text-slate-900 ml-2">{{ transfer.from_store.name }}</strong>
                        </p>
                        <p class="mt-2">
                            <span class="text-slate-500">Ke</span>
                            <strong class="text-slate-900 ml-2">{{ transfer.to_store.name }}</strong>
                        </p>
                        <p v-if="transfer.user" class="mt-2 text-slate-600">Dibuat oleh {{ transfer.user.name }}</p>
                        <p v-if="transfer.notes" class="mt-3 text-slate-700 whitespace-pre-wrap">{{ transfer.notes }}</p>
                    </div>

                    <!-- Timeline -->
                    <div v-if="timeline.length > 1" class="space-y-2">
                        <h3 class="text-xs font-bold text-slate-500 uppercase">Riwayat Status</h3>
                        <div class="flex flex-col gap-2">
                            <div v-for="(item, idx) in timeline" :key="idx" class="flex items-start gap-3 text-sm">
                                <div class="mt-1 flex h-2.5 w-2.5 shrink-0 rounded-full" :class="item.color" />
                                <div>
                                    <span class="font-semibold text-slate-800">{{ item.label }}</span>
                                    <span class="text-slate-600">oleh {{ item.who }}</span>
                                    <span class="text-slate-400 ml-1">· {{ formatWhen(item.when) }}</span>
                                </div>
                            </div>
                        </div>
                        <p v-if="transfer.cancellation_reason" class="mt-1 text-sm text-red-700 bg-red-50 rounded-lg p-2">
                            Alasan pembatalan: {{ transfer.cancellation_reason }}
                        </p>
                    </div>

                    <!-- Action buttons -->
                    <div v-if="canSend || canReceive || canCancel" class="flex flex-wrap gap-2">
                        <Button
                            v-if="canSend"
                            class="rounded-xl gap-1"
                            :disabled="processing"
                            @click="sendTransfer"
                        >
                            <Send class="h-4 w-4" />
                            Kirim
                        </Button>
                        <Button
                            v-if="canReceive"
                            class="rounded-xl gap-1"
                            :disabled="processing"
                            @click="receiveTransfer"
                        >
                            <CheckCircle class="h-4 w-4" />
                            Terima
                        </Button>
                        <Button
                            v-if="canCancel && !showCancelForm"
                            variant="destructive"
                            class="rounded-xl gap-1"
                            @click="showCancelForm = true"
                        >
                            <XCircle class="h-4 w-4" />
                            Batalkan
                        </Button>
                    </div>

                    <!-- Cancel form -->
                    <div v-if="showCancelForm" class="rounded-xl border border-red-200 bg-red-50 p-4 space-y-3">
                        <Label for="cancel-reason" class="text-sm font-semibold text-red-800">Alasan pembatalan (wajib)</Label>
                        <Input
                            id="cancel-reason"
                            v-model="cancelReason"
                            class="rounded-xl"
                            placeholder="Tuliskan alasan pembatalan..."
                            :disabled="processing"
                        />
                        <div class="flex gap-2">
                            <Button variant="destructive" class="rounded-xl" :disabled="processing" @click="cancelTransfer">
                                Ya, batalkan
                            </Button>
                            <Button variant="outline" class="rounded-xl" :disabled="processing" @click="showCancelForm = false">
                                Tidak
                            </Button>
                        </div>
                    </div>

                    <!-- Lines table -->
                    <div class="overflow-x-auto rounded-xl border border-slate-100">
                        <table class="w-full text-sm text-left">
                            <thead class="bg-slate-50 text-xs font-bold uppercase text-slate-500">
                                <tr>
                                    <th class="px-3 py-2">Produk asal</th>
                                    <th class="px-3 py-2">Produk tujuan</th>
                                    <th class="px-3 py-2 text-right">Qty</th>
                                    <th class="px-3 py-2 text-right">HPP/unit</th>
                                    <th class="px-3 py-2 text-right">Total</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr v-for="ln in transfer.lines" :key="ln.id">
                                    <td class="px-3 py-2">
                                        <div class="font-medium text-slate-900">{{ ln.from_product.name }}</div>
                                        <div class="text-xs text-slate-500">
                                            <span v-if="ln.from_product.sku">SKU {{ ln.from_product.sku }}</span>
                                            <span v-if="ln.base_unit"> · {{ ln.base_unit }}</span>
                                        </div>
                                    </td>
                                    <td class="px-3 py-2">
                                        <div class="font-medium text-slate-900">{{ ln.to_product.name }}</div>
                                        <div class="text-xs text-slate-500">
                                            <span v-if="ln.to_product.sku">SKU {{ ln.to_product.sku }}</span>
                                            <span v-if="ln.to_product.base_unit"> · {{ ln.to_product.base_unit }}</span>
                                        </div>
                                    </td>
                                    <td class="px-3 py-2 text-right font-mono font-semibold">
                                        {{ fmtQty(ln.quantity) }} {{ ln.base_unit ?? '' }}
                                    </td>
                                    <td class="px-3 py-2 text-right font-mono text-xs text-slate-600">
                                        {{ ln.unit_cost_snapshot ? `Rp${Number(ln.unit_cost_snapshot).toLocaleString('id-ID')}` : '—' }}
                                    </td>
                                    <td class="px-3 py-2 text-right font-mono text-xs text-slate-600">
                                        {{ ln.total_cost_snapshot ? `Rp${Number(ln.total_cost_snapshot).toLocaleString('id-ID')}` : '—' }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <p class="text-xs text-slate-500">
                        Mutasi stok tercatat di
                        <Link href="/stock-movements" class="text-violet-700 hover:underline">Riwayat stok</Link>
                        (transfer keluar / transfer masuk / pembalik).
                    </p>
                </CardContent>
            </Card>
        </div>
    </AdminLayout>
</template>
