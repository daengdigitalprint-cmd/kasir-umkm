<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { ArrowLeft, ChefHat, Edit, Package } from 'lucide-vue-next';

interface ProductTiny {
    id: number;
    name: string;
    sku: string | null;
    inventory_type: string;
    base_unit: string | null;
    average_cost: string | null;
}

interface Ingredient {
    id: number;
    quantity: string;
    base_unit: string | null;
    sort_order: number;
    product: ProductTiny | null;
}

interface RecipeDetail {
    id: number;
    name: string;
    code: string | null;
    output_quantity: string;
    output_unit: string | null;
    is_active: boolean;
    version: number;
    product: ProductTiny | null;
    ingredients: Ingredient[];
    created_by: { id: number; name: string } | null;
    updated_by: { id: number; name: string } | null;
    created_at: string;
    updated_at: string;
}

const props = defineProps<{
    recipe: RecipeDetail;
    totalIngredientCost: string;
    unitCostPerOutput: string;
}>();

const fmtQty = (v: string | number) => {
    const n = Number(v);
    if (Number.isInteger(n)) return n.toLocaleString('id-ID');
    return n.toLocaleString('id-ID', { maximumFractionDigits: 2 });
};

const formatRp = (val: string | number) =>
    `Rp${Number(val).toLocaleString('id-ID', { maximumFractionDigits: 2 })}`;

const formatWhen = (iso: string) =>
    new Date(iso).toLocaleString('id-ID', {
        day: '2-digit',
        month: 'long',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });

const unitLabels: Record<string, string> = {
    gram: 'gram',
    ml: 'ml',
    pcs: 'pcs',
};

const inventoryTypeLabels: Record<string, string> = {
    raw_material: 'Bahan mentah',
    production_output: 'Hasil produksi',
    sellable: 'Produk jual',
    packaging: 'Kemasan',
};
</script>

<template>
    <Head :title="recipe.name" />

    <AdminLayout title="Recipe">
        <div class="mx-auto space-y-6 pb-8" style="max-width: 90rem">
            <div class="flex items-center gap-2">
                <Button as-child variant="outline" size="sm" class="gap-2 rounded-xl">
                    <Link href="/recipes">
                        <ArrowLeft class="h-4 w-4" />
                        Kembali
                    </Link>
                </Button>
                <Button as-child variant="outline" size="sm" class="gap-2 rounded-xl">
                    <Link :href="`/recipes/${recipe.id}/edit`">
                        <Edit class="h-4 w-4" />
                        Edit
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
                            <CardTitle class="flex items-center gap-2">
                                {{ recipe.name }}
                                <Badge :class="recipe.is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600'" class="rounded-lg text-xs">
                                    {{ recipe.is_active ? 'Aktif' : 'Nonaktif' }}
                                </Badge>
                                <span class="text-xs font-normal text-slate-400">v{{ recipe.version }}</span>
                            </CardTitle>
                            <CardDescription v-if="recipe.code" class="font-mono text-xs">{{ recipe.code }}</CardDescription>
                        </div>
                    </div>
                </CardHeader>
                <CardContent class="space-y-4">
                    <!-- Output -->
                    <div class="rounded-xl bg-amber-50 border border-amber-100 p-4 space-y-2">
                        <h3 class="text-xs font-bold text-amber-800 uppercase">Output Produksi</h3>
                        <div class="text-sm">
                            <strong class="text-slate-900">{{ recipe.product?.name ?? '—' }}</strong>
                            <span class="text-slate-600 ml-2">
                                {{ fmtQty(recipe.output_quantity) }} {{ recipe.output_unit ?? '' }}
                            </span>
                        </div>
                    </div>

                    <!-- Ingredients -->
                    <div class="space-y-2">
                        <h3 class="text-xs font-bold text-slate-500 uppercase">Bahan ({{ recipe.ingredients.length }})</h3>
                        <div class="overflow-x-auto rounded-xl border border-slate-100">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b bg-slate-50 text-left text-xs font-bold text-slate-600 uppercase tracking-wide">
                                        <th class="p-3">#</th>
                                        <th class="p-3">Bahan</th>
                                        <th class="p-3 text-right">Jumlah</th>
                                        <th class="p-3 text-right">HPP/unit</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(ing, idx) in recipe.ingredients" :key="ing.id" class="border-b border-slate-100 last:border-0">
                                        <td class="p-3 text-slate-400">{{ idx + 1 }}</td>
                                        <td class="p-3">
                                            <div class="font-medium text-slate-900">{{ ing.product?.name ?? '—' }}</div>
                                            <div class="text-xs text-slate-500">
                                                {{ inventoryTypeLabels[ing.product?.inventory_type ?? ''] ?? ing.product?.inventory_type }}
                                                <template v-if="ing.product?.sku"> · SKU {{ ing.product.sku }}</template>
                                            </div>
                                        </td>
                                        <td class="p-3 text-right font-mono font-semibold">
                                            {{ fmtQty(ing.quantity) }} {{ ing.base_unit ?? '' }}
                                        </td>
                                        <td class="p-3 text-right font-mono text-xs text-slate-600">
                                            {{ ing.product?.average_cost ? formatRp(ing.product.average_cost) : '—' }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Cost summary -->
                    <div class="rounded-xl bg-slate-50 border border-slate-100 p-4 space-y-2 text-sm">
                        <div class="flex justify-between">
                            <span class="text-slate-600">Total biaya bahan</span>
                            <strong class="font-mono text-slate-900">{{ formatRp(totalIngredientCost) }}</strong>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-600">HPP per satuan output</span>
                            <strong class="font-mono text-amber-700">{{ formatRp(unitCostPerOutput) }}/{{ recipe.output_unit ?? '' }}</strong>
                        </div>
                    </div>

                    <!-- Audit -->
                    <div class="text-xs text-slate-400 space-y-1">
                        <p>Dibuat oleh {{ recipe.created_by?.name ?? '—' }} · {{ formatWhen(recipe.created_at) }}</p>
                        <p v-if="recipe.updated_by">Diperbarui oleh {{ recipe.updated_by.name }} · {{ formatWhen(recipe.updated_at) }}</p>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AdminLayout>
</template>
