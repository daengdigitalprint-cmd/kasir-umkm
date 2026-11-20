<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { ArrowDown, ArrowLeft, Boxes, Search, X } from 'lucide-vue-next';

interface ProductRow {
    id: number;
    name: string;
    sku: string | null;
    barcode: string | null;
    unit: string | null;
    stock_quantity: string;
    average_cost: string | null;
    cost_price: string | null;
    allows_decimal: boolean;
}

interface RecentPair {
    source_product_id: number;
    output_product_id: number;
    ratio: number;
    last_at: string;
}

const props = defineProps<{
    products: ProductRow[];
    recent: RecentPair[];
}>();

const form = useForm({
    source_product_id: null as number | null,
    source_quantity: '1',
    output_product_id: null as number | null,
    output_quantity: '',
    notes: '',
});

const productMap = computed(() => new Map(props.products.map((p) => [p.id, p])));
const source = computed(() => (form.source_product_id ? productMap.value.get(form.source_product_id) : undefined));
const output = computed(() => (form.output_product_id ? productMap.value.get(form.output_product_id) : undefined));

// ── Pencari produk (asal & hasil) ──
type Slot = 'source' | 'output';
const query = reactive<Record<Slot, string>>({ source: '', output: '' });
const open = reactive<Record<Slot, boolean>>({ source: false, output: false });

function optionsFor(slot: Slot) {
    const q = query[slot].trim().toLowerCase();
    const exclude = slot === 'source' ? form.output_product_id : form.source_product_id;
    return props.products
        .filter((p) => p.id !== exclude)
        .filter((p) => !q || [p.name, p.sku, p.barcode].some((t) => (t ?? '').toLowerCase().includes(q)))
        .slice(0, 30);
}

function pick(slot: Slot, p: ProductRow) {
    if (slot === 'source') form.source_product_id = p.id;
    else form.output_product_id = p.id;
    query[slot] = '';
    open[slot] = false;
    applyRememberedRatio();
}

function clearPick(slot: Slot) {
    if (slot === 'source') form.source_product_id = null;
    else form.output_product_id = null;
}

function closeLater(slot: Slot) {
    setTimeout(() => (open[slot] = false), 150);
}

// ── Perbandingan yang pernah dipakai ──
const remembered = computed(() =>
    props.recent.find((r) => r.source_product_id === form.source_product_id && r.output_product_id === form.output_product_id),
);
const outputTouched = ref(false);

function applyRememberedRatio() {
    if (remembered.value && !outputTouched.value) {
        form.output_quantity = String(Math.round(Number(form.source_quantity || 0) * remembered.value.ratio * 10000) / 10000);
    }
}

watch(() => form.source_quantity, applyRememberedRatio);

function useRecent(r: RecentPair) {
    form.source_product_id = r.source_product_id;
    form.output_product_id = r.output_product_id;
    form.source_quantity = '1';
    outputTouched.value = false;
    applyRememberedRatio();
}

const page = usePage();
const canViewCost = computed(() => Boolean((page.props as { abilities?: { view_cost?: boolean } }).abilities?.view_cost));

// ── Pratinjau ──
const sourceQty = computed(() => Number(form.source_quantity) || 0);
const outputQty = computed(() => Number(form.output_quantity) || 0);
const sourceCost = computed(() => Number(source.value?.average_cost ?? source.value?.cost_price ?? 0));
const transferValue = computed(() => sourceQty.value * sourceCost.value);
const outputUnitCost = computed(() => (outputQty.value > 0 ? transferValue.value / outputQty.value : 0));

const problem = computed(() => {
    if (!source.value) return 'Pilih produk yang dipecah.';
    if (!output.value) return 'Pilih produk hasil.';
    if (sourceQty.value <= 0) return 'Isi jumlah yang dipecah.';
    if (outputQty.value <= 0) return 'Isi jumlah hasil.';
    if (sourceQty.value > Number(source.value.stock_quantity)) {
        return `Stok ${source.value.name} hanya ${formatQty(Number(source.value.stock_quantity))} ${source.value.unit ?? ''}.`;
    }
    return null;
});

const formatQty = (n: number) => {
    const r = Math.round(n * 10000) / 10000;
    return Number.isInteger(r) ? String(r) : r.toLocaleString('id-ID', { maximumFractionDigits: 4 });
};
const formatRupiah = (n: number, digits = 0) =>
    `Rp${n.toLocaleString('id-ID', { minimumFractionDigits: digits, maximumFractionDigits: digits })}`;

const nameOf = (id: number) => productMap.value.get(id)?.name ?? '—';

function submit() {
    form.post('/stock-repacks', { preserveScroll: true });
}
</script>

<template>
    <Head title="Pecah kemasan" />

    <AdminLayout title="Pecah kemasan">
        <div class="mx-auto space-y-6 pb-8" style="max-width: 56rem">
            <div class="flex items-center gap-3">
                <Button as-child variant="ghost" size="icon" class="rounded-xl">
                    <Link href="/stock-adjustments/create"><ArrowLeft class="h-4 w-4" /></Link>
                </Button>
                <div>
                    <h1 class="flex items-center gap-2 text-2xl font-black text-slate-900"><Boxes class="h-6 w-6" /> Pecah kemasan</h1>
                    <p class="mt-1 text-sm text-slate-600">
                        Kemasan besar dipecah jadi kemasan kecil, mis. 1 kg kacang → 72 bungkus 15 gr.<template v-if="canViewCost"> HPP kemasan kecil
                        dihitung otomatis dari harga kemasan besar.</template>
                    </p>
                </div>
            </div>

            <div v-if="props.recent.length > 0" class="flex flex-wrap gap-2">
                <span class="self-center text-xs font-semibold uppercase tracking-wide text-slate-400">Pernah dipakai</span>
                <button
                    v-for="r in props.recent"
                    :key="`${r.source_product_id}-${r.output_product_id}`"
                    type="button"
                    class="rounded-full border border-slate-200 bg-white px-3 py-1 text-sm hover:border-slate-400"
                    @click="useRecent(r)"
                >
                    {{ nameOf(r.source_product_id) }} → {{ nameOf(r.output_product_id) }}
                    <span class="text-slate-400">(1 : {{ formatQty(r.ratio) }})</span>
                </button>
            </div>

            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <CardTitle class="text-base font-bold">Dipecah dari</CardTitle>
                    <CardDescription>Produk kemasan besar yang berkurang.</CardDescription>
                </CardHeader>
                <CardContent class="grid gap-3 md:grid-cols-[1fr_10rem]">
                    <div class="relative">
                        <div v-if="source" class="flex items-center justify-between rounded-xl border border-slate-300 px-3 py-2">
                            <span>
                                <span class="block font-semibold text-slate-900">{{ source.name }}</span>
                                <span class="text-xs text-slate-500">
                                    Stok {{ formatQty(Number(source.stock_quantity)) }} {{ source.unit }}<template v-if="canViewCost"> · HPP {{ formatRupiah(sourceCost) }}/{{ source.unit ?? 'satuan' }}</template>
                                </span>
                            </span>
                            <button type="button" class="rounded p-1 text-slate-400 hover:text-slate-700" @click="clearPick('source')"><X class="h-4 w-4" /></button>
                        </div>
                        <template v-else>
                            <Search class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                            <input
                                v-model="query.source"
                                type="text"
                                placeholder="Cari produk kemasan besar…"
                                class="w-full rounded-xl border border-slate-200 py-2 pl-9 pr-3 text-sm focus:border-slate-900 focus:outline-none"
                                @focus="open.source = true"
                                @input="open.source = true"
                                @blur="closeLater('source')"
                            />
                            <div v-if="open.source" class="absolute z-20 mt-1 max-h-64 w-full overflow-y-auto rounded-xl border border-slate-200 bg-white shadow-lg">
                                <button
                                    v-for="p in optionsFor('source')"
                                    :key="p.id"
                                    type="button"
                                    class="flex w-full justify-between px-4 py-2 text-left text-sm hover:bg-slate-50"
                                    @mousedown.prevent="pick('source', p)"
                                >
                                    <span class="font-semibold text-slate-900">{{ p.name }}</span>
                                    <span class="text-xs text-slate-500">Stok {{ formatQty(Number(p.stock_quantity)) }} {{ p.unit }}</span>
                                </button>
                            </div>
                        </template>
                    </div>
                    <label class="block">
                        <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-500">Jumlah dipecah</span>
                        <input
                            v-model="form.source_quantity"
                            type="number"
                            inputmode="decimal"
                            min="0"
                            :step="source?.allows_decimal ? '0.0001' : '1'"
                            class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-right text-sm tabular-nums focus:border-slate-900 focus:outline-none"
                        />
                    </label>
                </CardContent>
            </Card>

            <div class="flex justify-center"><ArrowDown class="h-6 w-6 text-slate-400" /></div>

            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <CardTitle class="text-base font-bold">Menjadi</CardTitle>
                    <CardDescription>Produk kemasan kecil yang bertambah.</CardDescription>
                </CardHeader>
                <CardContent class="grid gap-3 md:grid-cols-[1fr_10rem]">
                    <div class="relative">
                        <div v-if="output" class="flex items-center justify-between rounded-xl border border-slate-300 px-3 py-2">
                            <span>
                                <span class="block font-semibold text-slate-900">{{ output.name }}</span>
                                <span class="text-xs text-slate-500">Stok {{ formatQty(Number(output.stock_quantity)) }} {{ output.unit }}</span>
                            </span>
                            <button type="button" class="rounded p-1 text-slate-400 hover:text-slate-700" @click="clearPick('output')"><X class="h-4 w-4" /></button>
                        </div>
                        <template v-else>
                            <Search class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                            <input
                                v-model="query.output"
                                type="text"
                                placeholder="Cari produk kemasan kecil…"
                                class="w-full rounded-xl border border-slate-200 py-2 pl-9 pr-3 text-sm focus:border-slate-900 focus:outline-none"
                                @focus="open.output = true"
                                @input="open.output = true"
                                @blur="closeLater('output')"
                            />
                            <div v-if="open.output" class="absolute z-20 mt-1 max-h-64 w-full overflow-y-auto rounded-xl border border-slate-200 bg-white shadow-lg">
                                <button
                                    v-for="p in optionsFor('output')"
                                    :key="p.id"
                                    type="button"
                                    class="flex w-full justify-between px-4 py-2 text-left text-sm hover:bg-slate-50"
                                    @mousedown.prevent="pick('output', p)"
                                >
                                    <span class="font-semibold text-slate-900">{{ p.name }}</span>
                                    <span class="text-xs text-slate-500">Stok {{ formatQty(Number(p.stock_quantity)) }} {{ p.unit }}</span>
                                </button>
                            </div>
                        </template>
                    </div>
                    <label class="block">
                        <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-500">Jumlah hasil</span>
                        <input
                            v-model="form.output_quantity"
                            type="number"
                            inputmode="decimal"
                            min="0"
                            :step="output?.allows_decimal ? '0.0001' : '1'"
                            class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-right text-sm tabular-nums focus:border-slate-900 focus:outline-none"
                            @input="outputTouched = true"
                        />
                        <span v-if="remembered" class="text-[11px] text-slate-500">biasanya 1 : {{ formatQty(remembered.ratio) }}</span>
                    </label>
                </CardContent>
            </Card>

            <!-- Ringkasan -->
            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardContent class="space-y-3 pt-6">
                    <div v-if="source && output && !problem" class="grid gap-3 text-sm" :class="canViewCost ? 'md:grid-cols-3' : 'md:grid-cols-2'">
                        <div class="rounded-xl bg-slate-50 p-3">
                            <p class="text-xs text-slate-500">{{ source.name }}</p>
                            <p class="font-bold tabular-nums text-slate-900">
                                {{ formatQty(Number(source.stock_quantity)) }} → {{ formatQty(Number(source.stock_quantity) - sourceQty) }} {{ source.unit }}
                            </p>
                        </div>
                        <div class="rounded-xl bg-slate-50 p-3">
                            <p class="text-xs text-slate-500">{{ output.name }}</p>
                            <p class="font-bold tabular-nums text-slate-900">
                                {{ formatQty(Number(output.stock_quantity)) }} → {{ formatQty(Number(output.stock_quantity) + outputQty) }} {{ output.unit }}
                            </p>
                        </div>
                        <div v-if="canViewCost" class="rounded-xl bg-cyan-50 p-3">
                            <p class="text-xs text-cyan-800">HPP hasil</p>
                            <p class="font-bold tabular-nums text-cyan-900">{{ formatRupiah(outputUnitCost, outputUnitCost < 100 ? 2 : 0) }} / {{ output.unit ?? 'satuan' }}</p>
                            <p class="text-[11px] text-cyan-700">nilai berpindah {{ formatRupiah(transferValue) }}</p>
                        </div>
                    </div>
                    <p v-else-if="problem" class="text-sm font-semibold text-amber-700">{{ problem }}</p>
                    <p v-if="canViewCost && sourceCost === 0 && source" class="text-xs text-amber-700">
                        HPP {{ source.name }} masih 0, sehingga HPP hasilnya juga 0. Isi harga modal produk itu dulu bila perlu.
                    </p>

                    <input
                        v-model="form.notes"
                        type="text"
                        placeholder="Catatan (opsional)"
                        class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none"
                    />

                    <p v-if="Object.keys(form.errors).length" class="text-sm font-semibold text-red-700">
                        {{ Object.values(form.errors)[0] }}
                    </p>

                    <div class="flex justify-end gap-2">
                        <Button as-child variant="outline" class="rounded-xl"><Link href="/stock-adjustments">Batal</Link></Button>
                        <Button class="rounded-xl" :disabled="!!problem || form.processing" @click="submit">
                            {{ form.processing ? 'Menyimpan…' : 'Pecah kemasan' }}
                        </Button>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AdminLayout>
</template>
