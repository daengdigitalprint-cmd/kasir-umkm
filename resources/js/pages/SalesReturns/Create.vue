<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { toastError, toastWarning } from '@/lib/swal';

type Line = {
    item_id: number;
    product_id: number;
    product_variant_id: number | null;
    product_name: string;
    variant_label: string | null;
    quantity: number;
    free_quantity: number;
    unit_price: number;
    line_total: number;
    max_returnable: number;
    already_returned: number;
};

const props = defineProps<{
    store: { id: number; name: string; currency: string };
    transaction: {
        id: number;
        transaction_number: string;
        completed_at: string | null;
        payment_method: string;
        customer: { id: number; name: string; points_balance: number } | null;
    };
    lines: Line[];
}>();

const drafts = ref(
    props.lines.map((l) => ({
        ...l,
        return_qty: 0 as number,
        include: false as boolean,
    })),
);

const refundMethod = ref<'cash' | 'original' | 'store_credit'>('cash');
const notes = ref('');
const submitting = ref(false);

const totalRefund = computed(() =>
    drafts.value
        .filter((d) => d.include && d.return_qty > 0)
        .reduce((sum, d) => sum + d.return_qty * d.unit_price, 0),
);

const fmt = (v: number) => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(v);

watch(
    () => drafts.value.map((d) => d.return_qty),
    () => {
        drafts.value.forEach((d) => {
            if (d.return_qty > d.max_returnable) {
                d.return_qty = d.max_returnable;
                toastWarning(`Maksimum retur untuk ${d.product_name}: ${d.max_returnable}`);
            }
        });
    },
);

const submit = () => {
    const payloadLines = drafts.value
        .filter((d) => d.include && d.return_qty > 0)
        .map((d) => ({
            item_id: d.item_id,
            quantity: d.return_qty,
            unit_price: d.unit_price,
            refund_amount: d.return_qty * d.unit_price,
        }));

    if (!payloadLines.length) {
        toastError('Pilih minimal satu item untuk diretur.');
        return;
    }

    submitting.value = true;
    useForm({}).transform(() => ({
        transaction_id: props.transaction.id,
        refund_method: refundMethod.value,
        notes: notes.value || null,
        lines: payloadLines,
    })).post('/sales-returns', {
        preserveScroll: true,
        onFinish: () => { submitting.value = false; },
    });
};
</script>

<template>
    <Head title="Buat retur penjualan" />
    <AdminLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-slate-900">Buat Retur Penjualan</h2>
                    <p class="text-sm text-slate-500">Transaksi {{ transaction.transaction_number }} — {{ transaction.completed_at ? new Date(transaction.completed_at).toLocaleString('id-ID') : '' }}</p>
                </div>
                <Link href="/sales-returns"><Button variant="ghost" size="sm">Riwayat</Button></Link>
            </div>
        </template>

        <div class="mt-4 grid gap-4 lg:grid-cols-3">
            <Card class="lg:col-span-2">
                <CardHeader>
                    <CardTitle>Item yang diretur</CardTitle>
                </CardHeader>
                <CardContent class="space-y-3">
                    <div
                        v-for="d in drafts"
                        :key="d.item_id"
                        class="flex flex-wrap items-center gap-3 rounded-xl border px-4 py-3"
                        :class="d.include ? 'border-slate-400 bg-slate-50' : 'border-slate-200'"
                    >
                        <input
                            v-model="d.include"
                            type="checkbox"
                            class="h-4 w-4 rounded border-slate-300"
                            :disabled="d.max_returnable <= 0"
                        />
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-slate-900">{{ d.product_name }}</p>
                            <p class="text-xs text-slate-500">
                                {{ d.variant_label ? `${d.variant_label} · ` : '' }}Harga {{ fmt(d.unit_price) }} ·
                                Terjual {{ d.quantity + d.free_quantity }} ·
                                <span :class="d.already_returned > 0 ? 'text-amber-600' : ''">Sudah diretur {{ d.already_returned }}</span>
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            <Label class="text-xs">Qty retur</Label>
                            <Input
                                v-model.number="d.return_qty"
                                type="number"
                                step="0.0001"
                                min="0"
                                :max="d.max_returnable"
                                class="w-24"
                                :disabled="!d.include || d.max_returnable <= 0"
                            />
                            <span class="text-xs text-slate-400">/ {{ d.max_returnable }}</span>
                        </div>
                        <p class="w-28 text-right text-sm font-semibold" :class="d.include && d.return_qty > 0 ? 'text-red-600' : 'text-slate-400'">
                            {{ fmt(d.include && d.return_qty > 0 ? d.return_qty * d.unit_price : 0) }}
                        </p>
                    </div>
                    <p v-if="!drafts.length" class="rounded-xl bg-slate-50 px-4 py-8 text-center text-sm text-slate-500">
                        Semua item pada transaksi ini sudah pernah diretur atau transaksi tidak memiliki item.
                    </p>
                </CardContent>
            </Card>

            <div class="space-y-4">
                <Card>
                    <CardHeader><CardTitle>Metode refund</CardTitle></CardHeader>
                    <CardContent class="space-y-3">
                        <div class="space-y-2">
                            <Label class="text-xs">Pengembalian dana</Label>
                            <select v-model="refundMethod" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                <option value="cash">Tunai (dari laci kas)</option>
                                <option value="original">Metode asli ({{ transaction.payment_method }})</option>
                                <option value="store_credit">Saldo toko (tanpa uang keluar)</option>
                            </select>
                        </div>
                        <div class="space-y-2">
                            <Label class="text-xs">Catatan (opsional)</Label>
                            <Textarea v-model="notes" rows="3" placeholder="Alasan retur, kondisi barang, dsb." />
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardContent class="space-y-3 pt-6">
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-slate-500">Total refund</span>
                            <span class="text-lg font-bold text-red-600">{{ fmt(totalRefund) }}</span>
                        </div>
                        <p v-if="transaction.customer" class="text-xs text-slate-500">
                            Poin pelanggan {{ transaction.customer.name }} akan dikurangi proporsional.
                        </p>
                        <Button class="w-full" :disabled="submitting || totalRefund <= 0" @click="submit">
                            {{ submitting ? 'Memproses...' : 'Proses Retur' }}
                        </Button>
                        <p class="text-center text-[11px] leading-4 text-slate-400">
                            Stok akan dimasukkan kembali dan jurnal retur dicatat pada periode berjalan.
                            Transaksi asli tidak diubah.
                        </p>
                    </CardContent>
                </Card>
            </div>
        </div>
    </AdminLayout>
</template>
