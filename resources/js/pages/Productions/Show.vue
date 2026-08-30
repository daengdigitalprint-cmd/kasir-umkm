<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ArrowLeft, Factory, CheckCircle, XCircle } from 'lucide-vue-next';
import { ref } from 'vue';
import { modalError } from '@/lib/swal';

interface ProductTiny {
    id: number;
    name: string;
    sku: string | null;
    base_unit: string | null;
    average_cost: string | null;
}

interface BatchLine {
    id: number;
    quantity_required: string;
    quantity_consumed: string;
    base_unit: string | null;
    unit_cost_snapshot: string | null;
    total_cost: string | null;
    product: ProductTiny | null;
}

interface BatchDetail {
    id: number;
    batch_number: string;
    status: string;
    multiplier: string;
    output_quantity_initial: string;
    output_quantity_remaining: string;
    total_cost: string;
    unit_cost: string;
    produced_at: string | null;
    cancelled_at: string | null;
    cancel_reason: string | null;
    recipe_name: string | null;
    recipe_code: string | null;
    recipe_version: number | null;
    recipe_output_quantity: string | null;
    recipe_output_unit: string | null;
    product: ProductTiny | null;
    produced_by: { id: number; name: string } | null;
    cancelled_by: { id: number; name: string } | null;
    lines: BatchLine[];
    created_at: string;
}

const props = defineProps<{
    batch: BatchDetail;
    totalLinesCost: string;
    canComplete: boolean;
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

const formatRp = (val: string | number) =>
    `Rp${Number(val).toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const stripZeros = (val: string | number) =>
    parseFloat(String(val)).toString();

const statusConfig: Record<string, { label: string; class: string }> = {
    draft: { label: 'Draft', class: 'bg-slate-100 text-slate-700' },
    completed: { label: 'Selesai', class: 'bg-emerald-100 text-emerald-800' },
    cancelled: { label: 'Dibatalkan', class: 'bg-red-100 text-red-700' },
};

function completeBatch() {
    processing.value = true;
    router.post(
        `/productions/${props.batch.id}/complete`,
        {},
        {
            preserveScroll: true,
            onFinish: () => { processing.value = false; },
            onError: async (errors) => {
                const first = Object.values(errors)[0];
                const msg = Array.isArray(first) ? first[0] : typeof first === 'string' ? first : 'Gagal menyelesaikan produksi.';
                await modalError('Produksi gagal', msg);
            },
        },
    );
}

async function cancelBatch() {
    if (!cancelReason.value || cancelReason.value.trim().length < 3) {
        await modalError('Data belum lengkap', 'Alasan pembatalan minimal 3 karakter.');
        return;
    }
    processing.value = true;
    router.post(
        `/productions/${props.batch.id}/cancel`,
        { cancel_reason: cancelReason.value },
        {
            preserveScroll: true,
            onFinish: () => { processing.value = false; showCancelForm.value = false; },
            onError: async (errors) => {
                const first = Object.values(errors)[0];
                const msg = Array.isArray(first) ? first[0] : typeof first === 'string' ? first : 'Gagal membatalkan.';
                await modalError('Pembatalan gagal', msg);
            },
        },
    );
}
</script>

<template>
    <Head :title="batch.batch_number" />

    <AdminLayout title="Detail Batch">
        <div class="mx-auto space-y-6 pb-8" style="max-width: 90rem">
            <Button as-child variant="outline" size="sm" class="gap-2 rounded-xl">
                <Link href="/productions">
                    <ArrowLeft class="h-4 w-4" />
                    Kembali
                </Link>
            </Button>

            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <div class="flex items-center gap-2">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-orange-500/10">
                            <Factory class="h-5 w-5 text-orange-700" />
                        </div>
                        <div>
                            <CardTitle class="font-mono flex items-center gap-2">
                                {{ batch.batch_number }}
                                <Badge :class="statusConfig[batch.status]?.class ?? 'bg-slate-100'" class="rounded-lg text-xs">
                                    {{ statusConfig[batch.status]?.label ?? batch.status }}
                                </Badge>
                            </CardTitle>
                            <CardDescription>{{ formatWhen(batch.created_at) }}</CardDescription>
                        </div>
                    </div>
                </CardHeader>
                <CardContent class="space-y-4">
                    <!-- Info -->
                    <div class="rounded-xl bg-slate-50 border border-slate-100 p-4 text-sm space-y-2">
                        <div class="flex justify-between">
                            <span class="text-slate-500">Recipe</span>
                            <strong class="text-slate-900">{{ batch.recipe_name ?? '—' }} <span v-if="batch.recipe_code" class="text-xs text-slate-400">v{{ batch.recipe_version }} ({{ batch.recipe_code }})</span></strong>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-500">Output</span>
                            <strong class="text-slate-900">{{ batch.product?.name ?? '—' }}</strong>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-500">Multiplier</span>
                            <strong class="text-slate-900">×{{ stripZeros(batch.multiplier) }}</strong>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-500">Estimasi output</span>
                            <strong class="font-mono text-orange-700">{{ stripZeros(batch.output_quantity_initial) }} {{ batch.recipe_output_unit ?? '' }}</strong>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div v-if="canComplete || canCancel" class="flex flex-wrap gap-2">
                        <Button v-if="canComplete" class="rounded-xl gap-1" :disabled="processing" @click="completeBatch">
                            <CheckCircle class="h-4 w-4" />
                            Selesaikan Produksi
                        </Button>
                        <Button v-if="canCancel && !showCancelForm" variant="destructive" class="rounded-xl gap-1" @click="showCancelForm = true">
                            <XCircle class="h-4 w-4" />
                            Batalkan
                        </Button>
                    </div>
                    <div v-if="showCancelForm" class="rounded-xl border border-red-200 bg-red-50 p-4 space-y-3">
                        <Label for="cancel-reason" class="text-sm font-semibold text-red-800">Alasan pembatalan (wajib)</Label>
                        <Input id="cancel-reason" v-model="cancelReason" class="rounded-xl" placeholder="Tuliskan alasan..." :disabled="processing" />
                        <div class="flex gap-2">
                            <Button variant="destructive" class="rounded-xl" :disabled="processing" @click="cancelBatch">Ya, batalkan</Button>
                            <Button variant="outline" class="rounded-xl" :disabled="processing" @click="showCancelForm = false">Tidak</Button>
                        </div>
                    </div>

                    <!-- Lines -->
                    <div class="overflow-x-auto rounded-xl border border-slate-100">
                        <table class="w-full text-sm text-left">
                            <thead class="bg-slate-50 text-xs font-bold uppercase text-slate-500">
                                <tr>
                                    <th class="px-3 py-2">Bahan</th>
                                    <th class="px-3 py-2 text-right">Diperlukan</th>
                                    <th class="px-3 py-2 text-right">Dikonsumsi</th>
                                    <th class="px-3 py-2 text-right">HPP/unit</th>
                                    <th class="px-3 py-2 text-right">Total</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr v-for="ln in batch.lines" :key="ln.id">
                                    <td class="px-3 py-2">
                                        <div class="font-medium text-slate-900">{{ ln.product?.name ?? '—' }}</div>
                                        <div class="text-xs text-slate-500">
                                            <span v-if="ln.product?.sku">SKU {{ ln.product.sku }}</span>
                                            <span v-if="ln.base_unit"> · {{ ln.base_unit }}</span>
                                        </div>
                                    </td>
                                    <td class="px-3 py-2 text-right font-mono text-xs">{{ stripZeros(ln.quantity_required) }}</td>
                                    <td class="px-3 py-2 text-right font-mono text-xs font-semibold">{{ stripZeros(ln.quantity_consumed) }}</td>
                                    <td class="px-3 py-2 text-right font-mono text-xs text-slate-600">
                                        {{ ln.unit_cost_snapshot ? formatRp(ln.unit_cost_snapshot) : '—' }}
                                    </td>
                                    <td class="px-3 py-2 text-right font-mono text-xs text-slate-600">
                                        {{ ln.total_cost ? formatRp(ln.total_cost) : '—' }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Cost summary -->
                    <div class="rounded-xl bg-slate-50 border border-slate-100 p-4 space-y-2 text-sm">
                        <div class="flex justify-between">
                            <span class="text-slate-600">Total biaya bahan</span>
                            <strong class="font-mono text-slate-900">{{ formatRp(batch.total_cost || totalLinesCost) }}</strong>
                        </div>
                        <div v-if="batch.unit_cost && batch.status === 'completed'" class="flex justify-between">
                            <span class="text-slate-600">HPP per satuan output</span>
                            <strong class="font-mono text-orange-700">{{ formatRp(batch.unit_cost) }}/{{ batch.recipe_output_unit ?? '' }}</strong>
                        </div>
                    </div>

                    <!-- Audit -->
                    <div class="text-xs text-slate-400 space-y-1">
                        <p v-if="batch.produced_by">Diselesaikan oleh {{ batch.produced_by.name }} · {{ formatWhen(batch.produced_at!) }}</p>
                        <p v-if="batch.cancelled_by">Dibatalkan oleh {{ batch.cancelled_by.name }} · {{ formatWhen(batch.cancelled_at!) }}</p>
                        <p v-if="batch.cancel_reason" class="text-red-600">Alasan: {{ batch.cancel_reason }}</p>
                    </div>

                    <p class="text-xs text-slate-500">
                        Mutasi stok tercatat di
                        <Link href="/stock-movements" class="text-violet-700 hover:underline">Riwayat stok</Link>
                        (production_consumption / production_output).
                    </p>
                </CardContent>
            </Card>
        </div>
    </AdminLayout>
</template>
