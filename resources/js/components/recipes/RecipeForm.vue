<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { modalError, toastSuccess } from '@/lib/swal';
import { Link, useForm } from '@inertiajs/vue3';
import { Loader2, Plus, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';

export interface RecipeProductOpt {
    id: number;
    name: string;
    sku: string | null;
    inventory_type: string;
    base_unit: string | null;
    unit?: string | null;
    average_cost?: string | number | null;
    cost_price?: string | number | null;
}

export interface RecipeInitial {
    id: number;
    name: string;
    code: string | null;
    product_id: number;
    output_quantity: string | number;
    output_unit: string | null;
    is_active: boolean;
    version: number;
    ingredients: { product_id: number; quantity: string | number; base_unit: string | null; sort_order: number }[];
}

const props = defineProps<{
    ingredients: RecipeProductOpt[];
    outputs: RecipeProductOpt[];
    units: Record<string, string>;
    recipe?: RecipeInitial;
}>();

const isEdit = computed(() => !!props.recipe);
const outputList = ref<RecipeProductOpt[]>([...props.outputs]);

const form = useForm({
    name: props.recipe?.name ?? '',
    code: props.recipe?.code ?? '',
    product_id: (props.recipe?.product_id ?? '') as number | '',
    output_quantity: (props.recipe ? Number(props.recipe.output_quantity) : '') as number | '',
    output_unit: (props.recipe?.output_unit ?? null) as string | null,
    is_active: props.recipe?.is_active ?? true,
    ingredients: props.recipe?.ingredients.length
        ? props.recipe.ingredients.map((i) => ({
              product_id: i.product_id as number | '',
              quantity: Number(i.quantity) as number | '',
              base_unit: i.base_unit,
              sort_order: i.sort_order,
          }))
        : [{ product_id: '' as number | '', quantity: '' as number | '', base_unit: null as string | null, sort_order: 0 }],
});

// Nominal besar dibulatkan; harga per gram/ml yang kecil tetap tampil 2 desimal.
const rupiah = (v: number) => `Rp${v.toLocaleString('id-ID', { maximumFractionDigits: Math.abs(v) >= 100 ? 0 : 2 })}`;
const fmtQty = (v: number) => v.toLocaleString('id-ID', { maximumFractionDigits: 2 });

function ingredientById(id: number | ''): RecipeProductOpt | undefined {
    return id === '' ? undefined : props.ingredients.find((p) => p.id === id);
}

function unitCostOf(p?: RecipeProductOpt): number {
    if (!p) return 0;
    const avg = p.average_cost;
    if (avg !== null && avg !== undefined && avg !== '' && !Number.isNaN(Number(avg))) return Number(avg);
    return Number(p.cost_price ?? 0) || 0;
}

const outputProduct = computed(() => outputList.value.find((p) => p.id === form.product_id));
const outputUnit = computed(() => outputProduct.value?.base_unit ?? form.output_unit ?? '');

const lines = computed(() =>
    form.ingredients.map((ing) => {
        const p = ingredientById(ing.product_id);
        const cost = unitCostOf(p);
        const qty = Number(ing.quantity) || 0;
        return { product: p, unit: p?.base_unit ?? '', unitCost: cost, lineCost: qty * cost, missingCost: !!p && cost <= 0 };
    }),
);
const totalCost = computed(() => lines.value.reduce((s, l) => s + l.lineCost, 0));
const unitCost = computed(() => {
    const q = Number(form.output_quantity) || 0;
    return q > 0 ? totalCost.value / q : 0;
});
const missingCostCount = computed(() => lines.value.filter((l) => l.missingCost).length);

const errorAt = (key: string): string | undefined => (form.errors as Record<string, string | undefined>)[key];

function onOutputChange() {
    form.output_unit = outputProduct.value?.base_unit ?? null;
}

function addIngredient() {
    form.ingredients.push({ product_id: '', quantity: '', base_unit: null, sort_order: form.ingredients.length });
}

function removeIngredient(idx: number) {
    if (form.ingredients.length > 1) form.ingredients.splice(idx, 1);
}

// ── Buat produk hasil produksi langsung dari sini ──
const showNewOutput = ref(false);
const newOutput = ref({ name: '', base_unit: 'gram' });
const creatingOutput = ref(false);

function xsrfHeader(): Record<string, string> {
    const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return m ? { 'X-XSRF-TOKEN': decodeURIComponent(m[1]) } : {};
}

async function createOutputProduct() {
    if (newOutput.value.name.trim().length < 2) {
        void modalError('Data belum lengkap', 'Isi nama produk hasil produksi.');
        return;
    }
    creatingOutput.value = true;
    try {
        const res = await fetch('/recipes/output-product', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...xsrfHeader() },
            body: JSON.stringify(newOutput.value),
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) {
            const first = data?.errors ? (Object.values(data.errors)[0] as string[])[0] : (data?.message ?? 'Gagal membuat produk.');
            await modalError('Gagal membuat produk', first);
            return;
        }
        outputList.value.push(data as RecipeProductOpt);
        form.product_id = data.id;
        form.output_unit = data.base_unit;
        showNewOutput.value = false;
        newOutput.value = { name: '', base_unit: 'gram' };
        void toastSuccess('Produk hasil produksi dibuat.');
    } finally {
        creatingOutput.value = false;
    }
}

function submit() {
    if (form.name.trim().length < 2) return void modalError('Data belum lengkap', 'Isi nama resep.');
    if (!form.product_id) return void modalError('Data belum lengkap', 'Pilih produk hasil produksi.');
    if (!form.output_quantity || Number(form.output_quantity) <= 0) return void modalError('Data belum lengkap', 'Jumlah hasil harus lebih dari 0.');
    for (let i = 0; i < form.ingredients.length; i++) {
        const ing = form.ingredients[i];
        if (!ing.product_id) return void modalError('Data belum lengkap', `Pilih bahan pada baris ${i + 1}.`);
        if (!ing.quantity || Number(ing.quantity) <= 0)
            return void modalError('Data belum lengkap', `Jumlah bahan baris ${i + 1} harus lebih dari 0.`);
    }

    const tf = form.transform((data) => ({
        ...data,
        product_id: Number(data.product_id),
        output_unit: outputUnit.value || null,
        ingredients: data.ingredients.map((ing, idx) => ({
            product_id: Number(ing.product_id),
            quantity: ing.quantity,
            base_unit: ingredientById(ing.product_id)?.base_unit ?? ing.base_unit,
            sort_order: idx,
        })),
    }));
    const opts = {
        preserveScroll: true,
        onError: async (errors: Record<string, string>) => {
            await modalError('Gagal menyimpan', Object.values(errors)[0] ?? 'Periksa kembali formulir.');
        },
    };
    if (props.recipe) tf.put(`/recipes/${props.recipe.id}`, opts);
    else tf.post('/recipes', opts);
}

const selectClass = 'h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm';
</script>

<template>
    <div class="space-y-5">
        <!-- Info dasar -->
        <section class="space-y-4 rounded-2xl border border-slate-200 bg-white p-4 sm:p-5">
            <div class="grid gap-4 sm:grid-cols-3">
                <div class="space-y-1.5 sm:col-span-2">
                    <Label for="name">Nama resep</Label>
                    <Input id="name" v-model="form.name" class="rounded-xl" placeholder="Contoh: Biang Teh Tarik" />
                    <p v-if="form.errors.name" class="text-sm text-red-600">{{ form.errors.name }}</p>
                </div>
                <div class="space-y-1.5">
                    <Label for="code">Kode <span class="font-normal text-slate-400">(opsional)</span></Label>
                    <Input id="code" v-model="form.code" class="rounded-xl" placeholder="Contoh: RSP-01" />
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <div class="space-y-1.5 sm:col-span-2">
                    <div class="flex items-center justify-between gap-2">
                        <Label for="product_id">Menghasilkan produk</Label>
                        <button type="button" class="text-xs font-semibold text-primary hover:underline" @click="showNewOutput = !showNewOutput">
                            {{ showNewOutput ? 'Batal' : '+ Buat produk hasil produksi' }}
                        </button>
                    </div>
                    <select id="product_id" v-model="form.product_id" :class="selectClass" @change="onOutputChange">
                        <option value="" disabled>Pilih produk hasil produksi</option>
                        <option v-for="p in outputList" :key="p.id" :value="p.id">
                            {{ p.name }}<template v-if="p.sku"> ({{ p.sku }})</template>
                        </option>
                    </select>
                    <p v-if="form.errors.product_id" class="text-sm text-red-600">{{ form.errors.product_id }}</p>
                    <p v-if="outputList.length === 0 && !showNewOutput" class="text-xs text-amber-700">
                        Belum ada produk bertipe "Hasil produksi". Klik "Buat produk hasil produksi" di atas.
                    </p>

                    <div v-if="showNewOutput" class="mt-2 space-y-3 rounded-xl border border-dashed border-slate-300 bg-slate-50 p-3">
                        <p class="text-xs text-slate-600">Cukup nama dan satuan. Detail lain bisa dilengkapi nanti di halaman Produk.</p>
                        <div class="grid gap-2 sm:grid-cols-[1fr_10rem_auto]">
                            <Input
                                v-model="newOutput.name"
                                class="rounded-xl bg-white"
                                placeholder="Contoh: Biang Teh Tarik"
                                @keydown.enter.prevent="createOutputProduct"
                            />
                            <select v-model="newOutput.base_unit" :class="selectClass">
                                <option v-for="(label, key) in units" :key="key" :value="key">{{ label }}</option>
                            </select>
                            <Button type="button" class="gap-1 rounded-xl" :disabled="creatingOutput" @click="createOutputProduct">
                                <Loader2 v-if="creatingOutput" class="h-4 w-4 animate-spin" />
                                Buat & pilih
                            </Button>
                        </div>
                    </div>
                </div>
                <div class="space-y-1.5">
                    <Label for="output_qty">Jumlah hasil sekali produksi</Label>
                    <div class="flex items-center rounded-xl border border-slate-200 bg-white focus-within:ring-2 focus-within:ring-primary/30">
                        <input
                            id="output_qty"
                            v-model.number="form.output_quantity"
                            type="number"
                            step="any"
                            min="0"
                            class="h-11 w-full min-w-0 rounded-xl bg-transparent px-3 text-sm outline-none"
                            placeholder="Contoh: 18000"
                        />
                        <span class="shrink-0 pr-3 text-sm text-slate-500">{{ outputUnit || 'satuan' }}</span>
                    </div>
                    <p v-if="form.errors.output_quantity" class="text-sm text-red-600">{{ form.errors.output_quantity }}</p>
                </div>
            </div>

            <label v-if="isEdit" class="flex items-center gap-3 text-sm">
                <button
                    type="button"
                    role="switch"
                    :aria-checked="form.is_active"
                    class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors"
                    :class="form.is_active ? 'bg-emerald-500' : 'bg-slate-300'"
                    @click="form.is_active = !form.is_active"
                >
                    <span
                        class="inline-block h-4 w-4 rounded-full bg-white transition-transform"
                        :class="form.is_active ? 'translate-x-6' : 'translate-x-1'"
                    />
                </button>
                <span class="text-slate-700">{{ form.is_active ? 'Aktif, bisa dipakai produksi' : 'Nonaktif' }}</span>
            </label>
        </section>

        <!-- Bahan -->
        <section class="space-y-3 rounded-2xl border border-slate-200 bg-white p-4 sm:p-5">
            <div class="flex items-center justify-between gap-2">
                <div>
                    <h3 class="text-sm font-bold text-slate-900">Bahan yang dipakai</h3>
                    <p class="text-xs text-slate-500">Jumlah untuk sekali produksi, dalam satuan dasar bahan.</p>
                </div>
                <Button type="button" variant="outline" size="sm" class="gap-1 rounded-xl" @click="addIngredient">
                    <Plus class="h-4 w-4" />
                    Bahan
                </Button>
            </div>
            <p v-if="form.errors.ingredients" class="text-sm text-red-600">{{ form.errors.ingredients }}</p>

            <div class="divide-y divide-slate-100 rounded-xl border border-slate-100">
                <div v-for="(ing, idx) in form.ingredients" :key="idx" class="grid gap-2 p-3 sm:grid-cols-[1fr_11rem_8rem_2.25rem] sm:items-center">
                    <div>
                        <select v-model="ing.product_id" :class="selectClass" :aria-label="`Bahan baris ${idx + 1}`">
                            <option value="" disabled>Pilih bahan</option>
                            <option v-for="p in ingredients" :key="p.id" :value="p.id">
                                {{ p.name }}<template v-if="p.sku"> ({{ p.sku }})</template> ·
                                {{ p.inventory_type === 'raw_material' ? 'Bahan mentah' : 'Hasil produksi' }}
                            </option>
                        </select>
                        <p v-if="errorAt(`ingredients.${idx}.product_id`)" class="mt-1 text-xs text-red-600">
                            {{ errorAt(`ingredients.${idx}.product_id`) }}
                        </p>
                    </div>
                    <div class="flex items-center gap-2 sm:contents">
                        <div
                            class="flex flex-1 items-center rounded-xl border border-slate-200 bg-white focus-within:ring-2 focus-within:ring-primary/30"
                        >
                            <input
                                v-model.number="ing.quantity"
                                type="number"
                                step="any"
                                min="0"
                                class="h-11 w-full min-w-0 rounded-xl bg-transparent px-3 text-sm outline-none"
                                placeholder="Jumlah"
                                :aria-label="`Jumlah bahan baris ${idx + 1}`"
                            />
                            <span class="shrink-0 pr-3 text-xs text-slate-500">{{ lines[idx]?.unit || '' }}</span>
                        </div>
                        <div class="w-28 text-right text-sm sm:w-auto">
                            <div class="font-semibold text-slate-900 tabular-nums">{{ rupiah(lines[idx]?.lineCost ?? 0) }}</div>
                            <div v-if="lines[idx]?.missingCost" class="text-[11px] text-amber-700">Harga beli kosong</div>
                            <div v-else-if="lines[idx]?.product" class="text-[11px] text-slate-400">
                                {{ rupiah(lines[idx].unitCost) }}/{{ lines[idx].unit }}
                            </div>
                        </div>
                        <button
                            type="button"
                            class="flex h-9 w-9 items-center justify-center rounded-lg text-slate-400 hover:bg-red-50 hover:text-red-600 disabled:invisible"
                            :disabled="form.ingredients.length <= 1"
                            :aria-label="`Hapus bahan baris ${idx + 1}`"
                            @click="removeIngredient(idx)"
                        >
                            <Trash2 class="h-4 w-4" />
                        </button>
                    </div>
                </div>
            </div>

            <!-- Ringkasan biaya -->
            <div class="grid gap-2 rounded-xl bg-slate-50 p-3 text-sm sm:grid-cols-2">
                <div class="flex items-center justify-between sm:block">
                    <div class="text-xs text-slate-500">Modal bahan sekali produksi</div>
                    <div class="font-bold text-slate-900 tabular-nums">{{ rupiah(totalCost) }}</div>
                </div>
                <div class="flex items-center justify-between sm:block">
                    <div class="text-xs text-slate-500">HPP per {{ outputUnit || 'satuan' }} hasil</div>
                    <div class="font-bold text-primary tabular-nums">
                        {{ Number(form.output_quantity) > 0 ? rupiah(unitCost) : '—' }}
                        <span v-if="Number(form.output_quantity) > 0" class="text-xs font-normal text-slate-500"
                            >dari {{ fmtQty(Number(form.output_quantity)) }} {{ outputUnit }}</span
                        >
                    </div>
                </div>
                <p v-if="missingCostCount > 0" class="text-xs text-amber-700 sm:col-span-2">
                    {{ missingCostCount }} bahan belum punya harga beli, jadi modal di atas belum lengkap. Isi harga beli di halaman Produk atau lewat
                    pembelian.
                </p>
            </div>
        </section>

        <div class="flex flex-wrap gap-3">
            <Button type="button" class="rounded-xl" :disabled="form.processing" @click="submit">
                {{ isEdit ? `Simpan perubahan (v${(recipe?.version ?? 0) + 1})` : 'Simpan resep' }}
            </Button>
            <Button as-child variant="outline" class="rounded-xl">
                <Link :href="recipe ? `/recipes/${recipe.id}` : '/recipes'">Batal</Link>
            </Button>
        </div>
    </div>
</template>
