<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ArrowLeft, Truck, Undo2, CreditCard, CheckCircle } from 'lucide-vue-next';
import { ref } from 'vue';
import { toastSuccess } from '@/lib/swal';

interface LineRow {
    id: number;
    product_id: number;
    product_name: string | null;
    product_variant_id: number | null;
    variant_name: string | null;
    quantity_input: number | string;
    input_unit: string;
    qty_base: number | string;
    base_unit: string;
    unit_cost_input: number | string | null;
    unit_cost_base: number | string | null;
    subtotal: number | string | null;
    batch_expiry_date: string | null;
    batch_code: string | null;
}

interface ChargeRow {
    id: number;
    name: string;
    amount: number | string;
    notes: string | null;
}

const props = defineProps<{
    purchase: {
        id: number;
        purchase_number: string;
        purchased_at: string | null;
        created_at: string | null;
        notes: string | null;
        dpp_amount: number | string | null;
        charges_amount: number | string | null;
        ppn_amount: number | string | null;
        total_amount: number | string | null;
        supplier_tax_invoice_no: string | null;
        is_paid: boolean;
        amount_paid: number;
        payment_method: string | null;
        due_date: string | null;
        supplier: { id: number; name: string; phone: string | null } | null;
        user_name: string | null;
        lines: LineRow[];
        charges: ChargeRow[];
    };
    store: { id: number; name: string; currency: string };
}>();

const formatDateTime = (iso: string | null) => {
    if (!iso) return '—';
    return new Date(iso).toLocaleString('id-ID', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};

function fmtNum(n: number | string | null): string {
    if (n === null || n === undefined || n === '') return '—';
    const num = Number(n);
    if (Number.isNaN(num)) return String(n);
    if (num % 1 === 0) {
        return num.toLocaleString('id-ID');
    }
    // Max 4 desimal, hapus trailing zeros
    return num.toFixed(4).replace(/\.?0+$/, '').replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
}

function fmtPrice(n: number | string | null): string {
    if (n === null || n === undefined || n === '') return '—';
    const num = Number(n);
    if (Number.isNaN(num)) return String(n);
    return num.toLocaleString('id-ID');
}

const totalAmount = Number(props.purchase.total_amount) || 0;
const amountPaid = Number(props.purchase.amount_paid) || 0;
const remaining = Math.max(0, totalAmount - amountPaid);
const paidPercent = totalAmount > 0 ? Math.min(100, Math.round((amountPaid / totalAmount) * 100)) : 100;

const showPaymentForm = ref(false);
const paymentForm = useForm({
    purchase_id: props.purchase.id,
    amount: remaining > 0 ? String(remaining) : '',
    payment_method: 'cash',
    payment_date: new Date().toISOString().slice(0, 10),
});

const submitPayment = () => {
    paymentForm.post('/purchase-payments', {
        preserveScroll: true,
        onSuccess: () => {
            showPaymentForm.value = false;
            toastSuccess('Pembayaran berhasil dicatat.');
        },
    });
};
</script>

<template>
    <Head :title="`Pembelian ${purchase.purchase_number}`" />

    <AdminLayout :title="`Pembelian ${purchase.purchase_number}`">
        <div class="mx-auto space-y-6 pb-8" style="max-width: 90rem">
            <div class="flex flex-wrap items-center gap-3">
                <Button variant="outline" size="sm" as-child class="rounded-xl">
                    <Link href="/purchases" class="inline-flex items-center gap-2">
                        <ArrowLeft class="h-4 w-4" />
                        Daftar pembelian
                    </Link>
                </Button>
                <Button as-child class="rounded-xl bg-orange-600 hover:bg-orange-700">
                    <Link
                        :href="`/purchase-returns/create?purchase_id=${purchase.id}`"
                        class="inline-flex items-center gap-2"
                    >
                        <Undo2 class="h-4 w-4" />
                        Retur untuk pembelian ini
                    </Link>
                </Button>
            </div>

            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <div class="flex items-start gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-teal-500/10">
                            <Truck class="h-5 w-5 text-teal-700" />
                        </div>
                        <div>
                            <CardTitle class="font-mono">{{ purchase.purchase_number }}</CardTitle>
                            <CardDescription>{{ formatDateTime(purchase.purchased_at) }}</CardDescription>
                        </div>
                    </div>
                </CardHeader>
                <CardContent class="space-y-4 text-sm">
                    <div class="grid gap-2 sm:grid-cols-2">
                        <div>
                            <p class="text-xs font-bold text-slate-500 uppercase">Supplier</p>
                            <p class="text-slate-900">{{ purchase.supplier?.name || '—' }}</p>
                            <p v-if="purchase.supplier?.phone" class="text-slate-600">{{ purchase.supplier.phone }}</p>
                        </div>
                        <div>
                            <p class="text-xs font-bold text-slate-500 uppercase">Dicatat oleh</p>
                            <p class="text-slate-900">{{ purchase.user_name || '—' }}</p>
                        </div>
                    </div>
                    <div v-if="purchase.notes" class="rounded-xl bg-slate-50 p-3 text-slate-700">
                        <p class="text-xs font-bold text-slate-500 uppercase mb-1">Catatan</p>
                        {{ purchase.notes }}
                    </div>
                </CardContent>
            </Card>

            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <CardTitle class="text-base">Baris pembelian</CardTitle>
                    <CardDescription>Qty masuk stok per produk / varian</CardDescription>
                </CardHeader>
                <CardContent class="overflow-x-auto rounded-xl border border-slate-100">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b bg-slate-50 text-left text-xs font-bold text-slate-600 uppercase">
                                <th class="p-3">Produk</th>
                                <th class="p-3">Varian</th>
                                <th class="p-3 w-28 text-right">Qty</th>
                                <th class="p-3">Batch / ED</th>
                                <th class="p-3 w-40 text-right">Harga beli</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="line in purchase.lines"
                                :key="line.id"
                                class="border-b border-slate-100 last:border-0"
                            >
                                <td class="p-3 font-medium text-slate-900">{{ line.product_name || '—' }}</td>
                                <td class="p-3 text-slate-600">{{ line.variant_name || '—' }}</td>
                                <td class="p-3 tabular-nums whitespace-nowrap text-right">
                                    {{ fmtNum(line.quantity_input) }} {{ line.input_unit }}
                                </td>
                                <td class="p-3 text-slate-600">
                                    <span v-if="line.batch_code || line.batch_expiry_date">
                                        {{ line.batch_code || '—' }}
                                        <span v-if="line.batch_expiry_date" class="block text-xs"
                                            >ED {{ line.batch_expiry_date }}</span
                                        >
                                    </span>
                                    <span v-else>—</span>
                                </td>
                                <td class="p-3 tabular-nums whitespace-nowrap text-right">
                                    <template v-if="line.unit_cost_input !== null">
                                        Rp{{ fmtPrice(line.unit_cost_input) }}
                                        <span class="text-xs text-slate-500">/ {{ line.input_unit }}</span>
                                        <span class="block text-xs text-slate-500">
                                            = Rp{{ fmtPrice(line.subtotal) }}
                                        </span>
                                    </template>
                                    <span v-else>—</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </CardContent>
            </Card>

            <Card v-if="purchase.charges.length > 0" class="rounded-2xl border-amber-200 shadow-sm">
                <CardHeader>
                    <CardTitle class="text-base">Biaya &amp; pengeluaran</CardTitle>
                    <CardDescription>Biaya tambahan yang tidak menambah stok (dicatat ke beban operasional).</CardDescription>
                </CardHeader>
                <CardContent class="overflow-x-auto rounded-xl border border-amber-100">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b bg-amber-50 text-left text-xs font-bold text-amber-900 uppercase">
                                <th class="p-3">Nama biaya</th>
                                <th class="p-3 text-right w-44">Nominal</th>
                                <th class="p-3">Keterangan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="c in purchase.charges" :key="c.id" class="border-b border-amber-100 last:border-0">
                                <td class="p-3 font-medium text-slate-900">{{ c.name }}</td>
                                <td class="p-3 tabular-nums whitespace-nowrap text-right">Rp{{ fmtPrice(c.amount) }}</td>
                                <td class="p-3 text-slate-600">{{ c.notes || '—' }}</td>
                            </tr>
                            <tr class="bg-amber-50/60">
                                <td class="p-3 font-bold text-slate-900">Total biaya</td>
                                <td class="p-3 tabular-nums whitespace-nowrap text-right font-bold text-amber-800">
                                    Rp{{ fmtPrice(purchase.charges_amount) }}
                                </td>
                                <td class="p-3" />
                            </tr>
                        </tbody>
                    </table>
                </CardContent>
            </Card>

            <!-- Payment Status Card -->
            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <div class="flex h-10 w-10 items-center justify-center rounded-xl" :class="purchase.is_paid ? 'bg-emerald-500/10' : 'bg-amber-500/10'">
                                <CheckCircle v-if="purchase.is_paid" class="h-5 w-5 text-emerald-600" />
                                <CreditCard v-else class="h-5 w-5 text-amber-600" />
                            </div>
                            <div>
                                <CardTitle class="text-base">Status Pembayaran</CardTitle>
                                <CardDescription>
                                    {{ purchase.is_paid ? 'Pembelian telah lunas' : 'Masih ada sisa utang' }}
                                </CardDescription>
                            </div>
                        </div>
                        <span
                            :class="[
                                'inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold',
                                purchase.is_paid ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800',
                            ]"
                        >
                            {{ purchase.is_paid ? 'LUNAS' : 'BELUM LUNAS' }}
                        </span>
                    </div>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
                        <div class="rounded-xl bg-slate-50 p-3">
                            <p class="text-xs text-slate-500">Total Pembelian</p>
                            <p class="font-bold text-slate-900">Rp {{ fmtPrice(totalAmount) }}</p>
                        </div>
                        <div class="rounded-xl bg-slate-50 p-3">
                            <p class="text-xs text-slate-500">Sudah Dibayar</p>
                            <p class="font-bold text-emerald-600">Rp {{ fmtPrice(amountPaid) }}</p>
                        </div>
                        <div class="rounded-xl bg-slate-50 p-3">
                            <p class="text-xs text-slate-500">Sisa Utang</p>
                            <p class="font-bold" :class="remaining > 0 ? 'text-red-600' : 'text-emerald-600'">Rp {{ fmtPrice(remaining) }}</p>
                        </div>
                        <div class="rounded-xl bg-slate-50 p-3">
                            <p class="text-xs text-slate-500">Jatuh Tempo</p>
                            <p class="font-bold text-slate-900">{{ purchase.due_date ?? '—' }}</p>
                        </div>
                    </div>

                    <!-- Progress bar -->
                    <div class="w-full bg-slate-100 rounded-full h-2">
                        <div
                            class="h-2 rounded-full transition-all duration-300"
                            :class="paidPercent === 100 ? 'bg-emerald-500' : 'bg-amber-500'"
                            :style="{ width: paidPercent + '%' }"
                        />
                    </div>
                    <p class="text-xs text-slate-500 text-right">{{ paidPercent }}% terbayar</p>

                    <!-- Payment Form -->
                    <div v-if="!purchase.is_paid" class="pt-2 border-t border-slate-100">
                        <Button v-if="!showPaymentForm" @click="showPaymentForm = true" class="inline-flex items-center gap-2">
                            <CreditCard class="h-4 w-4" />
                            Catat Pembayaran
                        </Button>

                        <form v-else @submit.prevent="submitPayment" class="space-y-3 rounded-xl bg-slate-50 p-4">
                            <p class="text-sm font-semibold text-slate-700">Catat Pembayaran Cicilan</p>
                            <div class="grid grid-cols-2 gap-3">
                                <div class="space-y-1.5">
                                    <Label>Jumlah Bayar (Rp) <span class="text-red-500">*</span></Label>
                                    <Input v-model="paymentForm.amount" type="number" :min="1" :max="remaining" step="1000" required />
                                    <p v-if="paymentForm.errors.amount" class="text-xs text-red-500">{{ paymentForm.errors.amount }}</p>
                                </div>
                                <div class="space-y-1.5">
                                    <Label>Tanggal Bayar <span class="text-red-500">*</span></Label>
                                    <Input v-model="paymentForm.payment_date" type="date" required />
                                </div>
                            </div>
                            <div class="space-y-1.5">
                                <Label>Metode Pembayaran</Label>
                                <select v-model="paymentForm.payment_method" class="flex h-9 w-full items-center rounded-md border border-slate-200 bg-white px-3 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20">
                                    <option value="cash">Tunai</option>
                                    <option value="transfer">Transfer</option>
                                    <option value="ewallet">E-Wallet</option>
                                </select>
                            </div>
                            <div class="flex gap-2">
                                <Button type="submit" :disabled="paymentForm.processing">
                                    {{ paymentForm.processing ? 'Menyimpan...' : 'Bayar Sekarang' }}
                                </Button>
                                <Button type="button" variant="outline" @click="showPaymentForm = false">Batal</Button>
                            </div>
                        </form>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AdminLayout>
</template>
