<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Plus, Trash2, Search, ArrowLeft } from 'lucide-vue-next';

interface VariantRow {
    id: number;
    name: string;
    sku: string | null;
    stock_quantity: string;
}

interface ProductRow {
    id: number;
    name: string;
    sku: string | null;
    unit: string | null;
    stock_quantity: string;
    average_cost: string | null;
    cost_price: string | null;
    allows_decimal: boolean;
    has_variants: boolean;
    variants: VariantRow[];
}

const props = defineProps<{
    products: ProductRow[];
    reasonLabels: Record<string, string>;
    batchTrackingEnabled?: boolean;
    pharmacyFieldsEnabled?: boolean;
}>();

interface LineDraft {
    product_id: number | null;
    product_variant_id: number | null;
    counted_quantity: string;
    batch_code: string;
    expiry_date: string;
    line_notes: string;
}

const form = useForm({
    reason_code: 'damage',
    notes: '',
    counted_at: '',
    lines: [] as LineDraft[],
});

const search = ref('');
const pickerOpen = ref(false);

/** Kunci unik per sasaran: produk biasa atau varian tertentu. */
const chosenKeys = computed(() => new Set(form.lines.map((l) => `${l.product_id}:${l.product_variant_id ?? 0}`)));

/** Daftar sasaran yang dapat dipilih; produk bervarian dipecah menjadi baris per varian. */
interface PickOption {
    key: string;
    product: ProductRow;
    variant: VariantRow | null;
    label: string;
    sku: string | null;
    stock: string;
}

const pickOptions = computed((): PickOption[] => {
    const out: PickOption[] = [];
    for (const p of props.products) {
        if (p.has_variants) {
            for (const v of p.variants ?? []) {
                out.push({
                    key: `${p.id}:${v.id}`,
                    product: p,
                    variant: v,
                    label: `${p.name} — ${v.name}`,
                    sku: v.sku ?? p.sku,
                    stock: v.stock_quantity,
                });
            }
        } else {
            out.push({
                key: `${p.id}:0`,
                product: p,
                variant: null,
                label: p.name,
                sku: p.sku,
                stock: p.stock_quantity,
            });
        }
    }
    return out;
});

const filteredOptions = computed(() => {
    const q = search.value.trim().toLowerCase();
    return pickOptions.value
        .filter((o) => !chosenKeys.value.has(o.key))
        .filter((o) => !q || o.label.toLowerCase().includes(q) || (o.sku ?? '').toLowerCase().includes(q))
        .slice(0, 50);
});

const optionOf = (line: LineDraft) =>
    pickOptions.value.find((o) => o.key === `${line.product_id}:${line.product_variant_id ?? 0}`);

function addOption(option: PickOption) {
    form.lines.push({
        product_id: option.product.id,
        product_variant_id: option.variant?.id ?? null,
        counted_quantity: String(Number(option.stock)),
        batch_code: '',
        expiry_date: '',
        line_notes: '',
    });
    search.value = '';
    pickerOpen.value = false;
}

function removeLine(index: number) {
    form.lines.splice(index, 1);
}

function unitCost(option: PickOption | undefined) {
    if (!option) return 0;
    return Number(option.product.average_cost ?? option.product.cost_price ?? 0);
}

function variance(line: LineDraft) {
    const option = optionOf(line);
    if (!option) return 0;
    const counted = Number(line.counted_quantity);
    if (Number.isNaN(counted)) return 0;
    return counted - Number(option.stock);
}

function varianceValue(line: LineDraft) {
    return variance(line) * unitCost(optionOf(line));
}

const totalValue = computed(() => form.lines.reduce((sum, l) => sum + varianceValue(l), 0));

const affectedCount = computed(() => form.lines.filter((l) => Math.abs(variance(l)) > 0.00001).length);

const hasNegative = computed(() =>
    form.lines.some((l) => {
        const counted = Number(l.counted_quantity);
        return Number.isNaN(counted) || counted < 0;
    }),
);

const formatQty = (n: number) => {
    const rounded = Math.round(n * 10000) / 10000;
    return Number.isInteger(rounded) ? String(rounded) : rounded.toLocaleString('id-ID', { maximumFractionDigits: 4 });
};

const formatRupiah = (n: number) => {
    const sign = n < 0 ? '-' : n > 0 ? '+' : '';
    return `${sign}Rp${Math.abs(Math.round(n)).toLocaleString('id-ID')}`;
};

const valueClass = (n: number) => {
    if (n < -0.00001) return 'text-red-600';
    if (n > 0.00001) return 'text-emerald-600';
    return 'text-slate-400';
};

function submit() {
    form.post('/stock-adjustments', { preserveScroll: true });
}
</script>

<template>
    <Head title="Penyesuaian stok baru" />

    <AdminLayout title="Penyesuaian stok baru">
        <div class="mx-auto space-y-6 pb-8" style="max-width: 80rem">
            <div class="flex items-center gap-3">
                <Button as-child variant="ghost" size="icon" class="rounded-xl">
                    <Link href="/stock-adjustments"><ArrowLeft class="h-4 w-4" /></Link>
                </Button>
                <div>
                    <h1 class="text-2xl font-black text-slate-900">Penyesuaian stok baru</h1>
                    <p class="mt-1 text-sm text-slate-600">
                        Masukkan jumlah fisik yang sebenarnya. Sistem menghitung selisihnya terhadap stok tercatat.
                    </p>
                </div>
            </div>

            <div
                v-if="form.errors.lines || form.errors.reason_code"
                class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-800"
            >
                {{ form.errors.lines || form.errors.reason_code }}
            </div>

            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <CardTitle class="text-base font-bold">Keterangan dokumen</CardTitle>
                    <CardDescription>Alasan wajib dipilih agar koreksi dapat ditelusuri di laporan.</CardDescription>
                </CardHeader>
                <CardContent class="grid gap-4 md:grid-cols-3">
                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-slate-700">Alasan koreksi</label>
                        <select
                            v-model="form.reason_code"
                            class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none"
                        >
                            <option v-for="(label, code) in props.reasonLabels" :key="code" :value="code">{{ label }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-slate-700">Waktu hitung fisik</label>
                        <input
                            v-model="form.counted_at"
                            type="datetime-local"
                            class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none"
                        />
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-slate-700">Catatan</label>
                        <input
                            v-model="form.notes"
                            type="text"
                            placeholder="Contoh: kardus jatuh di gudang"
                            class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none"
                        />
                    </div>
                </CardContent>
            </Card>

            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <CardTitle class="text-base font-bold">Produk yang dikoreksi</CardTitle>
                    <CardDescription>
                        Produk bervarian dipilih per varian.
                        <template v-if="props.batchTrackingEnabled">
                            Pada mode batch, pengurangan memotong batch dengan kedaluwarsa terdekat lebih dulu.
                        </template>
                        Produk paket tidak dapat dikoreksi di sini; koreksi dilakukan pada komponennya.
                    </CardDescription>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div class="relative">
                        <div class="relative">
                            <Search class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                            <input
                                v-model="search"
                                type="text"
                                placeholder="Cari produk atau varian berdasarkan nama atau SKU…"
                                class="w-full rounded-xl border border-slate-200 py-2 pl-9 pr-3 text-sm focus:border-slate-900 focus:outline-none"
                                @focus="pickerOpen = true"
                            />
                        </div>

                        <div
                            v-if="pickerOpen && filteredOptions.length > 0"
                            class="absolute z-20 mt-1 max-h-72 w-full overflow-y-auto rounded-xl border border-slate-200 bg-white shadow-lg"
                        >
                            <button
                                v-for="o in filteredOptions"
                                :key="o.key"
                                type="button"
                                class="flex w-full items-center justify-between px-4 py-2.5 text-left text-sm transition hover:bg-slate-50"
                                @click="addOption(o)"
                            >
                                <span>
                                    <span class="font-semibold text-slate-900">{{ o.label }}</span>
                                    <span v-if="o.sku" class="ml-2 text-xs text-slate-400">{{ o.sku }}</span>
                                </span>
                                <span class="text-xs font-semibold text-slate-500">
                                    Stok {{ formatQty(Number(o.stock)) }} {{ o.product.unit ?? '' }}
                                </span>
                            </button>
                        </div>
                    </div>

                    <div v-if="form.lines.length === 0" class="rounded-xl border border-dashed border-slate-300 py-10 text-center">
                        <Plus class="mx-auto h-8 w-8 text-slate-300" />
                        <p class="mt-2 text-sm font-semibold text-slate-600">Belum ada produk dipilih</p>
                        <p class="mt-1 text-xs text-slate-500">Cari produk di kolom pencarian di atas.</p>
                    </div>

                    <div v-else class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500">
                                    <th class="py-3 pr-4 font-semibold">Produk</th>
                                    <th class="py-3 pr-4 text-right font-semibold">Stok sistem</th>
                                    <th class="py-3 pr-4 text-right font-semibold">Jumlah fisik</th>
                                    <th class="py-3 pr-4 text-right font-semibold">Selisih</th>
                                    <th class="py-3 pr-4 text-right font-semibold">Nilai</th>
                                    <th v-if="props.pharmacyFieldsEnabled" class="py-3 pr-4 font-semibold">Batch / ED</th>
                                    <th class="py-3 pr-4 font-semibold">Catatan baris</th>
                                    <th class="py-3"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="(line, i) in form.lines"
                                    :key="`${line.product_id}:${line.product_variant_id ?? 0}`"
                                    class="border-b border-slate-100"
                                >
                                    <td class="py-3 pr-4">
                                        <p class="font-semibold text-slate-900">{{ optionOf(line)?.label }}</p>
                                        <p class="text-xs text-slate-400">{{ optionOf(line)?.sku ?? '—' }}</p>
                                    </td>
                                    <td class="py-3 pr-4 text-right tabular-nums text-slate-600">
                                        {{ formatQty(Number(optionOf(line)?.stock ?? 0)) }}
                                        <span class="text-xs text-slate-400">{{ optionOf(line)?.product.unit }}</span>
                                    </td>
                                    <td class="py-3 pr-4 text-right">
                                        <input
                                            v-model="line.counted_quantity"
                                            type="number"
                                            min="0"
                                            :step="optionOf(line)?.product.allows_decimal ? '0.0001' : '1'"
                                            class="w-28 rounded-lg border border-slate-200 px-2 py-1.5 text-right text-sm tabular-nums focus:border-slate-900 focus:outline-none"
                                        />
                                    </td>
                                    <td class="py-3 pr-4 text-right font-bold tabular-nums" :class="valueClass(variance(line))">
                                        {{ variance(line) > 0 ? '+' : '' }}{{ formatQty(variance(line)) }}
                                    </td>
                                    <td class="py-3 pr-4 text-right font-semibold tabular-nums" :class="valueClass(varianceValue(line))">
                                        {{ formatRupiah(varianceValue(line)) }}
                                    </td>
                                    <td v-if="props.pharmacyFieldsEnabled" class="py-3 pr-4">
                                        <input v-model="line.batch_code" type="text" placeholder="Batch" class="mb-1 w-28 rounded-lg border border-slate-200 px-2 py-1.5 text-sm focus:border-slate-900 focus:outline-none" />
                                        <input v-model="line.expiry_date" type="date" class="w-36 rounded-lg border border-slate-200 px-2 py-1.5 text-sm focus:border-slate-900 focus:outline-none" />
                                    </td>
                                    <td class="py-3 pr-4">
                                        <input
                                            v-model="line.line_notes"
                                            type="text"
                                            placeholder="opsional"
                                            class="w-full min-w-40 rounded-lg border border-slate-200 px-2 py-1.5 text-sm focus:border-slate-900 focus:outline-none"
                                        />
                                    </td>
                                    <td class="py-3">
                                        <button
                                            type="button"
                                            class="rounded-lg p-1.5 text-slate-400 transition hover:bg-red-50 hover:text-red-600"
                                            @click="removeLine(i)"
                                        >
                                            <Trash2 class="h-4 w-4" />
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </CardContent>
            </Card>

            <div class="flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="space-y-1">
                    <p class="text-sm text-slate-600">
                        <strong class="text-slate-900">{{ affectedCount }}</strong> produk mengalami selisih
                    </p>
                    <p class="text-lg font-black tabular-nums" :class="valueClass(totalValue)">
                        Nilai selisih {{ formatRupiah(totalValue) }}
                    </p>
                </div>
                <div class="flex items-center gap-3">
                    <Button as-child variant="outline" class="rounded-xl">
                        <Link href="/stock-adjustments">Batal</Link>
                    </Button>
                    <Button
                        class="rounded-xl"
                        :disabled="form.processing || form.lines.length === 0 || affectedCount === 0 || hasNegative"
                        @click="submit"
                    >
                        {{ form.processing ? 'Memproses…' : 'Posting penyesuaian' }}
                    </Button>
                </div>
            </div>

            <p class="text-center text-xs text-slate-500">
                Dokumen yang sudah diposting tidak dapat diubah. Pembatalan dilakukan lewat mutasi pembalik.
            </p>
        </div>
    </AdminLayout>
</template>
