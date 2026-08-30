<script setup lang="ts">
import { Head, useForm, Link } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ArrowLeft, Plus, Trash2, ChefHat } from 'lucide-vue-next';
import { modalError } from '@/lib/swal';

interface ProductOpt {
    id: number;
    name: string;
    sku: string | null;
    inventory_type: string;
    base_unit: string | null;
}

interface IngredientRow {
    id?: number;
    product_id: number | '';
    quantity: number | string;
    base_unit: string | null;
    sort_order: number;
}

const props = defineProps<{
    recipe: {
        id: number;
        name: string;
        code: string | null;
        output_quantity: string;
        output_unit: string | null;
        is_active: boolean;
        version: number;
        product_id: number;
        ingredients: {
            id: number;
            product_id: number;
            quantity: string;
            base_unit: string | null;
            sort_order: number;
        }[];
    };
    ingredients: ProductOpt[];
    outputs: ProductOpt[];
    units: Record<string, string>;
}>();

const form = useForm({
    name: props.recipe.name,
    code: props.recipe.code ?? '',
    product_id: props.recipe.product_id as number | '',
    output_quantity: Number(props.recipe.output_quantity),
    output_unit: props.recipe.output_unit as string | null,
    is_active: props.recipe.is_active,
    ingredients: props.recipe.ingredients.map((ing) => ({
        product_id: ing.product_id as number | '',
        quantity: Number(ing.quantity),
        base_unit: ing.base_unit,
        sort_order: ing.sort_order,
    })) as IngredientRow[],
});

function productById(id: number | ''): ProductOpt | undefined {
    if (id === '' || id === null) return undefined;
    return [...props.outputs, ...props.ingredients].find((p) => p.id === id);
}

function addIngredient() {
    form.ingredients.push({ product_id: '', quantity: '', base_unit: null, sort_order: form.ingredients.length });
}

function removeIngredient(idx: number) {
    if (form.ingredients.length <= 1) return;
    form.ingredients.splice(idx, 1);
}

function onOutputChange() {
    const p = productById(form.product_id);
    if (p) form.output_unit = p.base_unit;
}

function submit() {
    if (form.name.trim().length < 2) {
        void modalError('Data belum lengkap', 'Nama recipe wajib diisi.');
        return;
    }
    if (!form.product_id) {
        void modalError('Data belum lengkap', 'Pilih produk output.');
        return;
    }
    if (!form.output_quantity || Number(form.output_quantity) <= 0) {
        void modalError('Data belum lengkap', 'Kuantitas output harus lebih dari 0.');
        return;
    }
    for (let i = 0; i < form.ingredients.length; i++) {
        const ing = form.ingredients[i];
        if (!ing.product_id) {
            void modalError('Data belum lengkap', `Pilih bahan pada baris ${i + 1}.`);
            return;
        }
        if (!ing.quantity || Number(ing.quantity) <= 0) {
            void modalError('Data belum lengkap', `Jumlah bahan baris ${i + 1} harus lebih dari 0.`);
            return;
        }
    }

    form.transform((data) => ({
        ...data,
        product_id: Number(data.product_id),
        is_active: data.is_active,
        ingredients: data.ingredients.map((ing, idx) => ({
            product_id: Number(ing.product_id),
            quantity: ing.quantity,
            base_unit: ing.base_unit,
            sort_order: ing.sort_order ?? idx,
        })),
    })).put(`/recipes/${props.recipe.id}`, {
        preserveScroll: true,
        onError: async (errors) => {
            const first = Object.values(errors)[0];
            const msg = Array.isArray(first) ? first[0] : typeof first === 'string' ? first : 'Periksa kembali formulir.';
            await modalError('Gagal memperbarui', msg);
        },
    });
}
</script>

<template>
    <Head :title="`Edit ${recipe.name}`" />

    <AdminLayout title="Recipe">
        <div class="mx-auto space-y-6 pb-8" style="max-width: 90rem">
            <div class="flex items-center gap-2">
                <Button as-child variant="outline" size="sm" class="gap-2 rounded-xl">
                    <Link :href="`/recipes/${recipe.id}`">
                        <ArrowLeft class="h-4 w-4" />
                        Detail
                    </Link>
                </Button>
            </div>

            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <div class="flex items-center gap-2">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-amber-500/10">
                            <ChefHat class="h-5 w-5 text-amber-700" />
                        </div>
                        <div>
                            <CardTitle>Edit Recipe — v{{ recipe.version }}</CardTitle>
                            <CardDescription>
                                Menyimpan perubahan akan membuat versi baru.
                            </CardDescription>
                        </div>
                    </div>
                </CardHeader>
                <CardContent class="space-y-6">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="space-y-2 sm:col-span-2">
                            <Label for="name">Nama recipe</Label>
                            <Input id="name" v-model="form.name" class="rounded-xl" />
                            <p v-if="form.errors.name" class="text-sm text-red-600">{{ form.errors.name }}</p>
                        </div>
                        <div class="space-y-2">
                            <Label for="code">Kode (opsional)</Label>
                            <Input id="code" v-model="form.code" class="rounded-xl" />
                            <p v-if="form.errors.code" class="text-sm text-red-600">{{ form.errors.code }}</p>
                        </div>
                        <div class="space-y-2">
                            <Label for="product_id">Produk output</Label>
                            <select
                                id="product_id"
                                v-model="form.product_id"
                                class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm"
                                required
                                @change="onOutputChange"
                            >
                                <option value="" disabled>Pilih produk output</option>
                                <option v-for="p in outputs" :key="p.id" :value="p.id">
                                    {{ p.name }} <template v-if="p.sku"> ({{ p.sku }})</template>
                                </option>
                            </select>
                            <p v-if="form.errors.product_id" class="text-sm text-red-600">{{ form.errors.product_id }}</p>
                        </div>
                        <div class="space-y-2">
                            <Label for="output_qty">Kuantitas output</Label>
                            <Input id="output_qty" v-model.number="form.output_quantity" type="number" step="any" min="0" class="rounded-xl" />
                            <p v-if="form.errors.output_quantity" class="text-sm text-red-600">{{ form.errors.output_quantity }}</p>
                        </div>
                        <div class="space-y-2">
                            <Label>Satuan output</Label>
                            <Input :model-value="form.output_unit ?? ''" disabled class="rounded-xl bg-slate-50" />
                        </div>
                        <div class="space-y-2 sm:col-span-2">
                            <div class="flex items-center gap-3">
                                <Label>Status</Label>
                                <button
                                    type="button"
                                    class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors"
                                    :class="form.is_active ? 'bg-emerald-500' : 'bg-slate-300'"
                                    @click="form.is_active = !form.is_active"
                                >
                                    <span
                                        class="inline-block h-4 w-4 transform rounded-full bg-white transition-transform"
                                        :class="form.is_active ? 'translate-x-6' : 'translate-x-1'"
                                    />
                                </button>
                                <span class="text-sm text-slate-600">{{ form.is_active ? 'Aktif' : 'Nonaktif' }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="space-y-4">
                        <div class="flex items-center justify-between">
                            <Label class="text-base font-bold">Bahan / Ingredients</Label>
                            <Button type="button" variant="outline" size="sm" class="rounded-xl gap-1" @click="addIngredient">
                                <Plus class="h-4 w-4" />
                                Bahan
                            </Button>
                        </div>
                        <p v-if="form.errors.ingredients" class="text-sm text-red-600">{{ form.errors.ingredients }}</p>

                        <div
                            v-for="(ing, idx) in form.ingredients"
                            :key="idx"
                            class="rounded-xl border border-slate-200 p-4 space-y-3 bg-slate-50/50"
                        >
                            <div class="flex justify-between items-center gap-2">
                                <span class="text-xs font-bold text-slate-500">#{{ idx + 1 }}</span>
                                <Button
                                    v-if="form.ingredients.length > 1"
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    class="text-red-600 h-8"
                                    @click="removeIngredient(idx)"
                                >
                                    <Trash2 class="h-4 w-4" />
                                </Button>
                            </div>
                            <div class="grid gap-3 sm:grid-cols-3">
                                <div class="space-y-1 sm:col-span-2">
                                    <Label :for="'ing-prod-' + idx">Produk bahan</Label>
                                    <select
                                        :id="'ing-prod-' + idx"
                                        v-model="ing.product_id"
                                        class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm"
                                        required
                                    >
                                        <option value="" disabled>Pilih bahan</option>
                                        <option v-for="p in ingredients" :key="p.id" :value="p.id">
                                            {{ p.name }} <template v-if="p.sku"> ({{ p.sku }})</template> — {{ p.inventory_type === 'raw_material' ? 'Bahan mentah' : 'Hasil produksi' }}
                                        </option>
                                    </select>
                                    <p v-if="form.errors[`ingredients.${idx}.product_id`]" class="text-sm text-red-600">
                                        {{ form.errors[`ingredients.${idx}.product_id`] }}
                                    </p>
                                </div>
                                <div class="space-y-1">
                                    <Label :for="'ing-qty-' + idx">Jumlah (satuan dasar)</Label>
                                    <Input
                                        :id="'ing-qty-' + idx"
                                        v-model.number="ing.quantity"
                                        type="number"
                                        step="any"
                                        min="0"
                                        class="rounded-xl"
                                        required
                                    />
                                    <p v-if="form.errors[`ingredients.${idx}.quantity`]" class="text-sm text-red-600">
                                        {{ form.errors[`ingredients.${idx}.quantity`] }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-3 pt-2">
                        <Button type="button" class="rounded-xl" :disabled="form.processing" @click="submit">
                            Simpan Perubahan (v{{ recipe.version + 1 }})
                        </Button>
                        <Button as-child variant="outline" class="rounded-xl">
                            <Link :href="`/recipes/${recipe.id}`">Batal</Link>
                        </Button>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AdminLayout>
</template>
