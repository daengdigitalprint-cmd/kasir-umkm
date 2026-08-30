<script setup lang="ts">
import { Head, useForm, Link } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ArrowLeft, Factory, FlaskConical } from 'lucide-vue-next';
import { ref, computed } from 'vue';
import { modalError } from '@/lib/swal';

interface ProductTiny {
    id: number;
    name: string;
    sku: string | null;
    stock_quantity: string;
    base_unit: string | null;
    average_cost: string | null;
}

interface RecipeIngredient {
    id: number;
    quantity: string;
    base_unit: string | null;
    product: ProductTiny | null;
}

interface RecipeRow {
    id: number;
    name: string;
    code: string | null;
    output_quantity: string;
    output_unit: string | null;
    product: ProductTiny | null;
    ingredients: RecipeIngredient[];
}

const props = defineProps<{
    store: { id: number; name: string };
    recipes: RecipeRow[];
    products: ProductTiny[];
}>();

const selectedRecipeId = ref<number | ''>('');
const multiplier = ref(1);

const selectedRecipe = computed(() =>
    props.recipes.find((r) => r.id === selectedRecipeId.value) ?? null,
);

const estimatedIngredients = computed(() => {
    if (!selectedRecipe.value) return [];
    return selectedRecipe.value.ingredients.map((ing) => {
        const required = ing.product
            ? Number((Number(ing.quantity) * multiplier.value).toPrecision(10))
            : 0;
        const stock = ing.product ? Number(ing.product.stock_quantity) : 0;
        const ok = Number(required) <= stock;
        return { ...ing, required, ok, stock };
    });
});

const estimatedOutput = computed(() => {
    if (!selectedRecipe.value) return '0';
    return Number((Number(selectedRecipe.value.output_quantity) * multiplier.value).toPrecision(10));
});

const form = useForm({
    recipe_id: '' as number | '',
    multiplier: 1,
});

function submit() {
    if (!selectedRecipeId.value) {
        void modalError('Data belum lengkap', 'Pilih recipe terlebih dahulu.');
        return;
    }
    if (multiplier.value <= 0) {
        void modalError('Data belum lengkap', 'Multiplier harus lebih dari 0.');
        return;
    }
    // Validasi stok di frontend
    for (const ing of estimatedIngredients.value) {
        if (!ing.ok) {
            void modalError(
                'Stok tidak cukup',
                `Bahan "${ing.product?.name}" memerlukan ${ing.required} ${ing.base_unit ?? ''}, stok tersedia ${ing.stock}.`,
            );
            return;
        }
    }

    form.recipe_id = selectedRecipeId.value;
    form.multiplier = multiplier.value;
    form.post('/productions', {
        preserveScroll: true,
        onError: async (errors) => {
            const first = Object.values(errors)[0];
            const msg = Array.isArray(first) ? first[0] : typeof first === 'string' ? first : 'Periksa kembali formulir.';
            await modalError('Gagal membuat batch', msg);
        },
    });
}
</script>

<template>
    <Head title="Produksi Baru" />

    <AdminLayout title="Produksi">
        <div class="mx-auto space-y-6 pb-8" style="max-width: 90rem">
            <Button as-child variant="outline" size="sm" class="gap-2 rounded-xl mb-2">
                <Link href="/productions">
                    <ArrowLeft class="h-4 w-4" />
                    Daftar Produksi
                </Link>
            </Button>

            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <div class="flex items-center gap-2">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-orange-500/10">
                            <Factory class="h-5 w-5 text-orange-700" />
                        </div>
                        <div>
                            <CardTitle>Produksi Baru</CardTitle>
                            <CardDescription>
                                Pilih recipe dan multiplier. Stok bahan akan dikurangi saat diselesaikan.
                            </CardDescription>
                        </div>
                    </div>
                </CardHeader>
                <CardContent class="space-y-6">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="space-y-2 sm:col-span-2">
                            <Label for="recipe">Recipe</Label>
                            <select
                                id="recipe"
                                v-model="selectedRecipeId"
                                class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm"
                                required
                            >
                                <option value="" disabled>Pilih recipe</option>
                                <option v-for="r in recipes" :key="r.id" :value="r.id">
                                    {{ r.name }} <template v-if="r.code"> ({{ r.code }})</template>
                                    → {{ r.product?.name ?? '—' }}
                                </option>
                            </select>
                            <p v-if="recipes.length === 0" class="text-xs text-amber-700">
                                Tidak ada recipe aktif. Buat recipe terlebih dahulu.
                            </p>
                        </div>
                        <div class="space-y-2">
                            <Label for="multiplier">Multiplier</Label>
                            <Input
                                id="multiplier"
                                v-model.number="multiplier"
                                type="number"
                                step="0.5"
                                min="0"
                                class="rounded-xl"
                                required
                            />
                            <p class="text-xs text-slate-500">×2 menggandakan bahan dan output.</p>
                        </div>
                    </div>

                    <!-- Estimasi -->
                    <div v-if="selectedRecipe" class="space-y-4">
                        <div class="rounded-xl bg-orange-50 border border-orange-100 p-4">
                            <h3 class="text-xs font-bold text-orange-800 uppercase mb-2">Estimasi Output</h3>
                            <p class="text-sm">
                                <strong class="text-slate-900">{{ selectedRecipe.product?.name }}</strong>
                                <span class="font-mono font-bold text-orange-700 ml-2">
                                    {{ estimatedOutput }} {{ selectedRecipe.output_unit ?? '' }}
                                </span>
                            </p>
                        </div>

                        <div class="rounded-xl border border-slate-100 p-4">
                            <h3 class="text-xs font-bold text-slate-500 uppercase mb-2">Kebutuhan Bahan</h3>
                            <div class="space-y-2">
                                <div
                                    v-for="ing in estimatedIngredients"
                                    :key="ing.id"
                                    class="flex items-center justify-between gap-2 text-sm"
                                >
                                    <div class="min-w-0">
                                        <span class="text-slate-800">{{ ing.product?.name ?? '—' }}</span>
                                        <span class="text-xs text-slate-500 ml-1">{{ ing.base_unit ?? '' }}</span>
                                    </div>
                                    <div class="flex items-center gap-2 shrink-0">
                                        <span class="font-mono font-semibold" :class="ing.ok ? 'text-slate-900' : 'text-red-600'">
                                            {{ ing.required }}
                                        </span>
                                        <span class="text-xs text-slate-400">/ {{ ing.stock }}</span>
                                        <FlaskConical v-if="!ing.ok" class="h-4 w-4 text-red-500" />
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-3 pt-2">
                        <Button type="button" class="rounded-xl" :disabled="form.processing || !selectedRecipe" @click="submit">
                            Buat Batch Draft
                        </Button>
                        <Button as-child variant="outline" class="rounded-xl">
                            <Link href="/productions">Batal</Link>
                        </Button>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AdminLayout>
</template>
