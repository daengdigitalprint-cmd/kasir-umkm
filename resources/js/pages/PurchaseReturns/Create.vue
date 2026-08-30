<script setup lang="ts">
import { Head, useForm, Link } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ArrowLeft, Plus, Trash2, Undo2 } from 'lucide-vue-next';
import { ref } from 'vue';
import { modalError, toastSuccess } from '@/lib/swal';

interface SupplierOpt {
    id: number;
    name: string;
    phone: string | null;
}

interface ProductVariantOpt {
    id: number;
    name: string;
    stock_quantity: number;
}

interface BatchOpt {
    id: number;
    expiry_date: string | null;
    batch_code: string | null;
    quantity_remaining: number | string;
}

interface ProductOpt {
    id: number;
    name: string;
    sku: string | null;
    unit: string | null;
    stock_quantity: number;
    allows_decimal?: boolean;
    has_variants?: boolean;
    variants?: ProductVariantOpt[];
    batches?: BatchOpt[];
}

interface LineGroup {
    product_id: number;
    product_variant_id: number | null;
    product_name: string;
    variant_name: string | null;
    has_variants: boolean;
    allows_decimal: boolean;
    qty_purchased: string;
    qty_already_returned: string;
    qty_max_returnable: string;
}

interface LinkedPurchase {
    id: number;
    purchase_number: string;
    purchased_at: string | null;
    supplier_id: number | null;
    line_groups: LineGroup[];
}

const props = defineProps<{
    suppliers: SupplierOpt[];
    products: ProductOpt[];
    batchTrackingEnabled: boolean;
    linkedPurchase?: LinkedPurchase | null;
    store: { id: number; name: string; currency: string };
}>();

const supplierList = ref<SupplierOpt[]>([...props.suppliers]);

const quickName = ref('');
const quickPhone = ref('');

interface LineDraft {
    product_id: number | '';
    product_variant_id: number | '';
    product_batch_id: number | '';
    quantity: number;
}

function buildInitialLineDrafts(): LineDraft[] {
    const groups =
        props.linkedPurchase?.line_groups?.filter((g) => parseFloat(String(g.qty_max_returnable)) > 0) ?? [];
    if (groups.length === 0) {
        return [{ product_id: '', product_variant_id: '', product_batch_id: '', quantity: 1 }];
    }
    return groups.map((row) => {
        const maxR = parseFloat(String(row.qty_max_returnable));
        const qty = row.allows_decimal
            ? Math.max(0.0001, Math.round(Math.min(1, maxR) * 10000) / 10000)
            : Math.max(1, Math.min(Math.floor(maxR), 1));
        return {
            product_id: row.product_id,
            product_variant_id: row.product_variant_id ?? '',
            product_batch_id: '',
            quantity: qty,
        };
    });
}

const lineDrafts = ref<LineDraft[]>(buildInitialLineDrafts());

const form = useForm({
    purchase_id: (props.linkedPurchase?.id ?? null) as number | null,
    supplier_id: (props.linkedPurchase?.supplier_id ?? null) as number | null,
    returned_at: new Date().toISOString().slice(0, 10),
    notes: '',
});

function lineGroupForRow(row: LineDraft): LineGroup | undefined {
    if (!props.linkedPurchase?.line_groups) {
        return undefined;
    }
    const vid = row.product_variant_id === '' || row.product_variant_id === null ? null : Number(row.product_variant_id);
    return props.linkedPurchase.line_groups.find(
        (g) => g.product_id === row.product_id && (g.product_variant_id ?? null) === vid,
    );
}

function addLine() {
    lineDrafts.value.push({
        product_id: '',
        product_variant_id: '',
        product_batch_id: '',
        quantity: 1,
    });
}

function productById(id: number | ''): ProductOpt | undefined {
    if (id === '' || id === null) {
        return undefined;
    }
    return props.products.find((p) => p.id === id);
}

function variantsForProduct(productId: number | ''): ProductVariantOpt[] {
    return productById(productId)?.variants ?? [];
}

function batchesForProduct(productId: number | ''): BatchOpt[] {
    return productById(productId)?.batches ?? [];
}

function onLineProductChange(row: LineDraft) {
    row.product_variant_id = '';
    row.product_batch_id = '';
}

function onLineVariantChange(row: LineDraft) {
    row.product_batch_id = '';
}

function removeLine(idx: number) {
    if (lineDrafts.value.length <= 1) return;
    lineDrafts.value.splice(idx, 1);
}

function csrfToken(): string {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

async function quickAddSupplier() {
    const name = quickName.value.trim();
    if (!name) {
        void modalError('Nama wajib', 'Isi nama supplier.');
        return;
    }
    try {
        const res = await fetch('/purchases/suppliers', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({ name, phone: quickPhone.value.trim() || null }),
        });
        const data = await res.json();
        if (!res.ok) {
            throw new Error((data as { message?: string }).message || 'Gagal menyimpan');
        }
        const s = data.supplier as SupplierOpt;
        supplierList.value = [...supplierList.value, s].sort((a, b) => a.name.localeCompare(b.name));
        form.supplier_id = s.id;
        quickName.value = '';
        quickPhone.value = '';
        void toastSuccess('Supplier ditambahkan');
    } catch (e) {
        void modalError('Gagal', e instanceof Error ? e.message : 'Tidak dapat menambah supplier');
    }
}

function batchLabel(b: BatchOpt): string {
    const ed = b.expiry_date || '—';
    const code = b.batch_code?.trim() ? ` · ${b.batch_code}` : '';
    const q = b.quantity_remaining;
    return `#${b.id} · ED ${ed}${code} · sisa ${q}`;
}

function buildServerLines():
    | Array<{
          product_id: number;
          product_variant_id?: number | null;
          product_batch_id?: number | null;
          quantity: number;
      }>
    | null {
    const out: Array<{
        product_id: number;
        product_variant_id?: number | null;
        product_batch_id?: number | null;
        quantity: number;
    }> = [];
    for (const row of lineDrafts.value) {
        if (row.product_id === '' || row.product_id === null) {
            continue;
        }
        const pr = productById(row.product_id);
        if (!pr) {
            continue;
        }
        if (props.batchTrackingEnabled && pr.has_variants) {
            void modalError(
                'Tidak didukung',
                'Mode batch aktif: retur untuk produk ber-varian belum didukung. Pilih produk tanpa varian.',
            );
            return null;
        }
        if (pr.has_variants && (row.product_variant_id === '' || row.product_variant_id === null)) {
            void modalError('Varian wajib', `Pilih varian untuk «${pr.name}».`);
            return null;
        }
        if (props.batchTrackingEnabled && !pr.has_variants) {
            if (row.product_batch_id === '' || row.product_batch_id === null) {
                void modalError('Batch wajib', `Pilih batch untuk «${pr.name}».`);
                return null;
            }
        }
        const rawQ = Number(row.quantity) || 0;
        const qty = pr.allows_decimal
            ? Math.max(0.0001, Math.round(rawQ * 10000) / 10000)
            : Math.max(1, Math.round(rawQ));
        const pvid =
            pr.has_variants && row.product_variant_id !== '' && row.product_variant_id != null
                ? Number(row.product_variant_id)
                : null;
        const bid =
            props.batchTrackingEnabled && !pr.has_variants && row.product_batch_id !== '' && row.product_batch_id != null
                ? Number(row.product_batch_id)
                : null;
        const grp = lineGroupForRow(row);
        if (
            grp &&
            parseFloat(String(qty)) > parseFloat(String(grp.qty_max_returnable)) + 1e-8
        ) {
            void modalError(
                'Melebihi pembelian',
                `«${pr.name}»: maks masih boleh retur ${grp.qty_max_returnable} dari pembelian ${props.linkedPurchase?.purchase_number ?? ''}.`,
            );
            return null;
        }
        out.push({
            product_id: Number(row.product_id),
            product_variant_id: pvid,
            product_batch_id: bid,
            quantity: qty,
        });
    }
    if (out.length === 0) {
        return null;
    }
    return out;
}

function lineQtyStep(row: LineDraft): string {
    const pr = props.products.find((p) => p.id === row.product_id);
    return pr?.allows_decimal ? '0.0001' : '1';
}

function lineQtyMin(row: LineDraft): number {
    const pr = props.products.find((p) => p.id === row.product_id);
    return pr?.allows_decimal ? 0.0001 : 1;
}

function submit() {
    const lines = buildServerLines();
    if (!lines) {
        void modalError('Baris kosong', 'Pilih minimal satu produk dan jumlah.');
        return;
    }
    form.transform(() => ({
        purchase_id: form.purchase_id,
        supplier_id: form.supplier_id,
        returned_at: form.returned_at,
        notes: form.notes,
        lines,
    }));
    form.post('/purchase-returns', {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="Retur ke supplier" />

    <AdminLayout title="Retur ke supplier">
        <div class="mx-auto space-y-6 pb-8" style="max-width: 90rem">
            <div class="flex flex-wrap items-center gap-3">
                <Button variant="outline" size="sm" as-child class="rounded-xl">
                    <Link href="/purchase-returns" class="inline-flex items-center gap-2">
                        <ArrowLeft class="h-4 w-4" />
                        Kembali
                    </Link>
                </Button>
                <div class="flex items-center gap-2">
                    <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-orange-500/10">
                        <Undo2 class="h-4 w-4 text-orange-800" />
                    </div>
                    <div>
                        <h1 class="text-xl font-black text-slate-900">Catat retur ke pemasok</h1>
                        <p class="text-xs text-slate-600">Kurangi stok sesuai batch (FEFO / pilihan batch) atau varian.</p>
                    </div>
                </div>
            </div>

            <form class="space-y-6" @submit.prevent="submit">
                <Card v-if="linkedPurchase" class="rounded-2xl border-amber-200 bg-amber-50/50 shadow-sm">
                    <CardHeader>
                        <CardTitle class="text-base">Terhubung ke pembelian</CardTitle>
                        <CardDescription>
                            {{ linkedPurchase.purchase_number }} — jumlah retur tiap produk dibatasi oleh sisa yang belum
                            dikembalikan dari pembelian ini.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Button type="button" variant="outline" size="sm" as-child class="rounded-xl">
                            <Link :href="`/purchases/${linkedPurchase.id}`">Lihat detail pembelian</Link>
                        </Button>
                    </CardContent>
                </Card>

                <Card class="rounded-2xl border-slate-200 shadow-sm">
                    <CardHeader>
                        <CardTitle>Supplier &amp; tanggal</CardTitle>
                        <CardDescription>Opsional — untuk arsip hubungan dengan pemasok.</CardDescription>
                    </CardHeader>
                    <CardContent class="grid gap-4 md:grid-cols-2">
                        <div class="space-y-2 md:col-span-2">
                            <Label for="supplier_id">Supplier</Label>
                            <select
                                id="supplier_id"
                                v-model="form.supplier_id"
                                class="flex h-11 w-full rounded-md border border-gray-300 bg-white px-3 text-sm disabled:opacity-70"
                                :disabled="!!linkedPurchase?.supplier_id"
                            >
                                <option :value="null">— Tanpa supplier —</option>
                                <option v-for="s in supplierList" :key="s.id" :value="s.id">
                                    {{ s.name }}{{ s.phone ? ` · ${s.phone}` : '' }}
                                </option>
                            </select>
                            <p v-if="linkedPurchase?.supplier_id" class="text-xs text-slate-600">
                                Supplier mengikuti pembelian sumber (tidak dapat diubah).
                            </p>
                            <div v-if="form.errors.supplier_id" class="text-sm text-red-600">{{ form.errors.supplier_id }}</div>
                        </div>
                        <div
                            v-if="!linkedPurchase?.supplier_id"
                            class="rounded-xl border border-dashed border-slate-200 p-4 space-y-2 md:col-span-2"
                        >
                            <p class="text-xs font-semibold text-slate-700">Tambah supplier cepat</p>
                            <div class="flex flex-wrap gap-2">
                                <Input v-model="quickName" type="text" placeholder="Nama" class="h-10 flex-1 min-w-[140px]" />
                                <Input v-model="quickPhone" type="text" placeholder="Telepon" class="h-10 w-40" />
                                <Button type="button" variant="secondary" class="h-10" @click="quickAddSupplier">Simpan</Button>
                            </div>
                        </div>
                        <div class="space-y-2">
                            <Label for="returned_at">Tanggal retur</Label>
                            <Input id="returned_at" v-model="form.returned_at" type="date" class="h-11" />
                            <div v-if="form.errors.returned_at" class="text-sm text-red-600">{{ form.errors.returned_at }}</div>
                        </div>
                        <div class="space-y-2 md:col-span-2">
                            <Label for="notes">Catatan</Label>
                            <Input id="notes" v-model="form.notes" type="text" placeholder="Alasan / referensi" class="h-11" />
                            <div v-if="form.errors.notes" class="text-sm text-red-600">{{ form.errors.notes }}</div>
                        </div>
                    </CardContent>
                </Card>

                <Card class="rounded-2xl border-slate-200 shadow-sm">
                    <CardHeader>
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <CardTitle>Barang dikembalikan</CardTitle>
                                <CardDescription>
                                    Stok tidak boleh melebihi saldo. Mode batch: pilih batch yang dikurangi.
                                </CardDescription>
                            </div>
                            <Button type="button" variant="outline" size="sm" class="rounded-xl" @click="addLine">
                                <Plus class="h-4 w-4 mr-1" />
                                Baris
                            </Button>
                        </div>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <div v-if="form.errors.lines" class="text-sm text-red-600 font-medium">{{ form.errors.lines }}</div>
                        <div
                            v-for="(row, idx) in lineDrafts"
                            :key="idx"
                            class="rounded-xl border border-slate-100 bg-slate-50/40 p-4 space-y-3"
                        >
                            <div class="grid gap-3 md:grid-cols-2">
                                <div class="space-y-2 md:col-span-2">
                                    <Label>Produk</Label>
                                    <select
                                        v-model="row.product_id"
                                        class="flex h-11 w-full rounded-md border border-gray-300 bg-white px-3 text-sm"
                                        @change="onLineProductChange(row)"
                                    >
                                        <option value="">— Pilih —</option>
                                        <option v-for="p in products" :key="p.id" :value="p.id">
                                            {{ p.name }} (stok {{ p.stock_quantity }})
                                        </option>
                                    </select>
                                </div>
                                <div v-if="variantsForProduct(row.product_id).length > 0" class="space-y-2 md:col-span-2">
                                    <Label>Varian</Label>
                                    <select
                                        v-model="row.product_variant_id"
                                        class="flex h-11 w-full rounded-md border border-gray-300 bg-white px-3 text-sm"
                                        @change="onLineVariantChange(row)"
                                    >
                                        <option value="">— Pilih —</option>
                                        <option v-for="v in variantsForProduct(row.product_id)" :key="v.id" :value="v.id">
                                            {{ v.name }} ({{ v.stock_quantity }})
                                        </option>
                                    </select>
                                </div>
                                <div
                                    v-if="batchTrackingEnabled && productById(row.product_id) && !productById(row.product_id)?.has_variants"
                                    class="space-y-2 md:col-span-2"
                                >
                                    <Label>Batch</Label>
                                    <select
                                        v-model="row.product_batch_id"
                                        class="flex h-11 w-full rounded-md border border-gray-300 bg-white px-3 text-sm"
                                    >
                                        <option value="">— Pilih batch —</option>
                                        <option v-for="b in batchesForProduct(row.product_id)" :key="b.id" :value="b.id">
                                            {{ batchLabel(b) }}
                                        </option>
                                    </select>
                                </div>
                                <div class="space-y-2">
                                    <Label>Jumlah dikembalikan</Label>
                                    <Input
                                        v-model.number="row.quantity"
                                        type="number"
                                        :min="lineQtyMin(row)"
                                        :step="lineQtyStep(row)"
                                        class="h-11"
                                    />
                                </div>
                                <div class="flex items-end justify-end">
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        class="text-destructive"
                                        :disabled="lineDrafts.length <= 1"
                                        @click="removeLine(idx)"
                                    >
                                        <Trash2 class="h-4 w-4 mr-1" />
                                        Hapus baris
                                    </Button>
                                </div>
                            </div>
                            <p
                                v-if="lineGroupForRow(row)"
                                class="text-xs text-amber-950 font-medium bg-amber-100/90 rounded-lg px-2 py-1.5"
                            >
                                Batas retur dari pembelian:
                                <span class="tabular-nums">{{ lineGroupForRow(row)!.qty_max_returnable }}</span>
                                · dibeli
                                <span class="tabular-nums">{{ lineGroupForRow(row)!.qty_purchased }}</span>
                                · sudah retur
                                <span class="tabular-nums">{{ lineGroupForRow(row)!.qty_already_returned }}</span>
                            </p>
                        </div>
                    </CardContent>
                </Card>

                <div class="flex flex-wrap gap-3 justify-end">
                    <Button type="button" variant="outline" as-child class="rounded-xl">
                        <Link href="/purchase-returns">Batal</Link>
                    </Button>
                    <Button type="submit" class="rounded-xl" :disabled="form.processing"> Simpan retur </Button>
                </div>
            </form>
        </div>
    </AdminLayout>
</template>
