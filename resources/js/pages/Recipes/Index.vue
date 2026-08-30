<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Plus, ChefHat, Eye, Edit, Trash2 } from 'lucide-vue-next';
import { confirmDelete, toastSuccess, modalError } from '@/lib/swal';

interface ProductTiny {
    id: number;
    name: string;
    sku: string | null;
    inventory_type: string;
    base_unit: string | null;
}

interface RecipeRow {
    id: number;
    name: string;
    code: string | null;
    output_quantity: string;
    output_unit: string | null;
    is_active: boolean;
    version: number;
    product: ProductTiny | null;
    ingredients_count: number;
    ingredients: { product: ProductTiny | null }[];
    created_at: string;
}

interface Paginated<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
}

defineProps<{
    recipes: Paginated<RecipeRow>;
    currentStore: { id: number; name: string };
}>();

const formatWhen = (iso: string) =>
    new Date(iso).toLocaleString('id-ID', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });

async function deleteRecipe(recipe: RecipeRow) {
    if (!(await confirmDelete('Hapus recipe?', `Recipe "${recipe.name}" akan dihapus permanen. Bahan tidak berubah.`))) return;

    router.delete(`/recipes/${recipe.id}`, {
        preserveScroll: true,
        onSuccess: async () => { await toastSuccess('Recipe dihapus.'); },
        onError: async (errors) => {
            const msg = Object.values(errors)[0];
            await modalError('Gagal menghapus', Array.isArray(msg) ? msg[0] : String(msg ?? 'Error'));
        },
    });
}
</script>

<template>
    <Head title="Recipe" />

    <AdminLayout title="Recipe">
        <div class="mx-auto space-y-6 pb-8" style="max-width: 90rem">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-black text-slate-900">Master Recipe</h1>
                    <p class="text-sm text-slate-600 mt-1">
                        Komposisi bahan untuk setiap produk <strong>{{ currentStore.name }}</strong>.
                    </p>
                </div>
                <Button as-child class="rounded-xl">
                    <Link href="/recipes/create" class="inline-flex items-center gap-2">
                        <Plus class="h-4 w-4" />
                        Tambah Recipe
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
                            <CardTitle>Daftar Recipe</CardTitle>
                            <CardDescription>{{ recipes.data.length }} recipe</CardDescription>
                        </div>
                    </div>
                </CardHeader>
                <CardContent>
                    <div v-if="recipes.data.length === 0" class="text-center py-12 text-slate-500">
                        <p>Belum ada recipe.</p>
                        <Button as-child variant="outline" class="mt-4 rounded-xl">
                            <Link href="/recipes/create">Buat recipe pertama</Link>
                        </Button>
                    </div>
                    <div v-else class="space-y-3">
                        <div
                            v-for="r in recipes.data"
                            :key="r.id"
                            class="rounded-xl border border-slate-100 p-4 hover:bg-slate-50/80 transition-colors"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <Link :href="`/recipes/${r.id}`" class="text-sm font-bold text-slate-900 hover:underline">
                                            {{ r.name }}
                                        </Link>
                                        <span v-if="r.code" class="text-xs font-mono text-slate-500">{{ r.code }}</span>
                                        <Badge :class="r.is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600'" class="rounded-lg text-xs">
                                            {{ r.is_active ? 'Aktif' : 'Nonaktif' }}
                                        </Badge>
                                        <span class="text-xs text-slate-400">v{{ r.version }}</span>
                                    </div>
                                    <div class="mt-1 text-xs text-slate-600">
                                        Output:
                                        <strong>{{ r.product?.name ?? '—' }}</strong>
                                        {{ Number(r.output_quantity) }} {{ r.output_unit ?? '' }}
                                        · {{ r.ingredients_count }} bahan
                                    </div>
                                    <div class="mt-1 text-xs text-slate-400">
                                        Dibuat {{ formatWhen(r.created_at) }}
                                    </div>
                                </div>
                                <div class="flex gap-1 shrink-0">
                                    <Button as-child variant="ghost" size="sm" class="h-8 w-8 p-0">
                                        <Link :href="`/recipes/${r.id}`"><Eye class="h-4 w-4" /></Link>
                                    </Button>
                                    <Button as-child variant="ghost" size="sm" class="h-8 w-8 p-0">
                                        <Link :href="`/recipes/${r.id}/edit`"><Edit class="h-4 w-4" /></Link>
                                    </Button>
                                    <Button variant="ghost" size="sm" class="h-8 w-8 p-0 text-red-600" @click="deleteRecipe(r)">
                                        <Trash2 class="h-4 w-4" />
                                    </Button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div v-if="recipes.links?.length > 3" class="flex flex-wrap justify-center gap-1 pt-4">
                        <Link
                            v-for="(l, i) in recipes.links"
                            :key="i"
                            :href="l.url || '#'"
                            class="min-w-9 rounded-lg px-3 py-1.5 text-sm"
                            :class="
                                l.active
                                    ? 'bg-primary text-white'
                                    : l.url
                                      ? 'bg-slate-100 text-slate-700 hover:bg-slate-200'
                                      : 'cursor-not-allowed text-slate-300'
                            "
                            preserve-state
                        >
                            <span v-html="l.label" />
                        </Link>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AdminLayout>
</template>
