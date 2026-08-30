<script setup lang="ts">
import { Head, useForm, Link } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ArrowLeft, Plus, Trash2, ClipboardList } from 'lucide-vue-next';
import { ref } from 'vue';
import { modalError } from '@/lib/swal';

interface CustomerOpt {
    id: number;
    name: string;
}

interface VariantOpt {
    id: number;
    name: string;
    price: number | string | null;
}

interface ProductOpt {
    id: number;
    name: string;
    sku: string | null;
    unit: string | null;
    price: number;
    allows_decimal?: boolean;
    has_variants?: boolean;
    is_bundle?: boolean;
    variants?: VariantOpt[];
}

const props = defineProps<{
    customers: CustomerOpt[];
    products: ProductOpt[];
    store: { id: number; name: string; currency: string };
}>();

interface LineDraft {
    product_id: number | string | '';
    product_variant_id: number | string | '';
    quantity: number;
}

const lineDrafts = ref<LineDraft[]>([
    { product_id: '', product_variant_id: '', quantity: 1 },
]);

const form = useForm({
    customer_id: null as number | null,
    expected_pickup_at: '' as string,
    deposit_required: 0 as number,
    deposit_received: 0 as number,
    notes: '',
});

function productById(id: number | string | ''): ProductOpt | undefined {
    if (id === '' || id === null) return undefined;
    const n = Number(id);
    return props.products.find((p) => p.id === n);
}

function onProductChange(row: LineDraft) {
    row.product_variant_id = '';
}

function addLine() {
    lineDrafts.value.push({ product_id: '', product_variant_id: '', quantity: 1 });
}

function removeLine(i: number) {
    if (lineDrafts.value.length <= 1) return;
    lineDrafts.value.splice(i, 1);
}

function buildLines():
    | Array<{ product_id: number; product_variant_id?: number | null; quantity: number }>
    | null {
    const out: Array<{ product_id: number; product_variant_id?: number | null; quantity: number }> = [];
    for (const row of lineDrafts.value) {
        if (row.product_id === '' || row.product_id === null) continue;
        const p = productById(row.product_id);
        if (!p) continue;
        let vid: number | null = null;
        if (p.has_variants) {
            if (row.product_variant_id === '' || row.product_variant_id === null) return null;
            vid = Number(row.product_variant_id);
        } else if (row.product_variant_id !== '' && row.product_variant_id !== null) {
            return null;
        }
        const q = Number(row.quantity);
        if (!Number.isFinite(q) || q <= 0) return null;
        out.push({
            product_id: Number(String(row.product_id)),
            product_variant_id: vid,
            quantity: q,
        });
    }
    const cleaned = out.map(({ product_id, product_variant_id, quantity }) => ({
        product_id,
        product_variant_id,
        quantity,
    }));
    return cleaned.length ? cleaned : null;
}

function submit() {
    const lines = buildLines();
    if (!lines) {
        void modalError('Baris tidak lengkap', 'Pilih produk, varian (jika ada), dan qty valid untuk setiap baris.');
        return;
    }
    form.transform((data) => ({
        ...data,
        lines,
        customer_id: data.customer_id || null,
        expected_pickup_at: data.expected_pickup_at || null,
    })).post('/pre-orders', {
        preserveScroll: true,
    });
}

function formatMoney(n: number) {
    return new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(n);
}
</script>

<template>
    <AdminLayout title="Buat pre-order">
        <Head title="Buat pre-order" />

        <div class="mb-6">
            <Button as="a" href="/pre-orders" variant="ghost" class="rounded-xl gap-2 mb-4">
                <ArrowLeft class="h-4 w-4" />
                Kembali
            </Button>
            <h1 class="text-2xl font-bold text-slate-900 flex items-center gap-2">
                <ClipboardList class="h-7 w-7 text-amber-600" />
                Buat pre-order
            </h1>
            <p class="text-sm text-slate-600 mt-1">{{ store.name }} — total estimasi dihitung dari harga saat ini.</p>
        </div>

        <form @submit.prevent="submit" class="space-y-6">
            <Card class="border-0 shadow-lg">
                <CardHeader>
                    <CardTitle>Detail pesanan</CardTitle>
                    <CardDescription>DP hanya pencatatan; pelunasan dilakukan saat transaksi kasir.</CardDescription>
                </CardHeader>
                <CardContent class="grid gap-4 md:grid-cols-2">
                    <div class="space-y-2 md:col-span-2">
                        <Label>Pelanggan (opsional)</Label>
                        <select
                            v-model.number="form.customer_id"
                            class="flex h-12 w-full max-w-md rounded-md border border-gray-300 bg-white px-3 text-sm"
                        >
                            <option :value="null">—</option>
                            <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                    </div>
                    <div class="space-y-2">
                        <Label>Tanggal ambil / siap (opsional)</Label>
                        <Input v-model="form.expected_pickup_at" type="date" class="h-12 max-w-xs" />
                    </div>
                    <div class="space-y-2">
                        <Label>Catatan</Label>
                        <Input v-model="form.notes" type="text" placeholder="Mis. tulisan di kue" class="h-12" />
                    </div>
                    <div class="space-y-2">
                        <Label>Target DP (Rp)</Label>
                        <Input v-model.number="form.deposit_required" type="number" min="0" step="1000" class="h-12 max-w-xs" />
                    </div>
                    <div class="space-y-2">
                        <Label>DP diterima (Rp)</Label>
                        <Input v-model.number="form.deposit_received" type="number" min="0" step="1000" class="h-12 max-w-xs" />
                    </div>
                </CardContent>
            </Card>

            <Card class="border-0 shadow-lg">
                <CardHeader class="flex flex-row items-center justify-between">
                    <div>
                        <CardTitle>Item</CardTitle>
                        <CardDescription>Pilih produk seperti di pembelian; paket &amp; varian didukung.</CardDescription>
                    </div>
                    <Button type="button" variant="outline" class="rounded-xl gap-1" @click="addLine">
                        <Plus class="h-4 w-4" />
                        Baris
                    </Button>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div v-for="(row, idx) in lineDrafts" :key="idx" class="flex flex-col gap-3 rounded-xl border border-slate-200 p-4 md:flex-row md:flex-wrap md:items-end">
                        <div class="space-y-2 flex-1 min-w-[200px]">
                            <Label>Produk</Label>
                            <select
                                v-model="row.product_id"
                                class="flex h-11 w-full rounded-md border border-gray-300 bg-white px-3 text-sm"
                                @change="onProductChange(row)"
                            >
                                <option value="">— Pilih —</option>
                                <option v-for="p in products" :key="p.id" :value="p.id">
                                    {{ p.name }}
                                    <template v-if="p.unit"> ({{ p.unit }})</template>
                                </option>
                            </select>
                        </div>
                        <div v-if="productById(row.product_id)?.has_variants" class="space-y-2 flex-1 min-w-[160px]">
                            <Label>Varian</Label>
                            <select v-model="row.product_variant_id" class="flex h-11 w-full rounded-md border border-gray-300 bg-white px-3 text-sm" required>
                                <option value="">—</option>
                                <option v-for="v in productById(row.product_id)?.variants ?? []" :key="v.id" :value="v.id">
                                    {{ v.name }} — Rp {{ formatMoney(Number(v.price ?? 0)) }}
                                </option>
                            </select>
                        </div>
                        <div class="space-y-2 w-full md:w-28">
                            <Label>Qty</Label>
                            <Input
                                v-model.number="row.quantity"
                                type="number"
                                :step="productById(row.product_id)?.allows_decimal ? 0.25 : 1"
                                :min="productById(row.product_id)?.allows_decimal ? 0.0001 : 1"
                                class="h-11"
                            />
                        </div>
                        <Button
                            v-if="lineDrafts.length > 1"
                            type="button"
                            variant="ghost"
                            class="text-red-600 shrink-0"
                            @click="removeLine(idx)"
                        >
                            <Trash2 class="h-4 w-4" />
                        </Button>
                    </div>
                    <p v-if="form.errors.lines" class="text-sm text-red-600">{{ form.errors.lines as string }}</p>
                </CardContent>
            </Card>

            <div class="flex gap-3">
                <Button type="submit" :disabled="form.processing" class="rounded-xl">
                    {{ form.processing ? 'Menyimpan…' : 'Simpan pre-order' }}
                </Button>
                <Button as="a" href="/pre-orders" type="button" variant="outline" class="rounded-xl">Batal</Button>
            </div>
        </form>
    </AdminLayout>
</template>
