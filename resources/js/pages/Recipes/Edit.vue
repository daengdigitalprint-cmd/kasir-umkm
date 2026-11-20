<script setup lang="ts">
import type { RecipeInitial, RecipeProductOpt } from '@/components/recipes/RecipeForm.vue';
import RecipeForm from '@/components/recipes/RecipeForm.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft } from 'lucide-vue-next';

defineProps<{
    recipe: RecipeInitial;
    ingredients: RecipeProductOpt[];
    outputs: RecipeProductOpt[];
    units: Record<string, string>;
}>();
</script>

<template>
    <Head :title="`Ubah ${recipe.name}`" />

    <AdminLayout title="Resep produksi">
        <div class="mx-auto max-w-4xl space-y-4 pb-8">
            <Link :href="`/recipes/${recipe.id}`" class="inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-slate-900">
                <ArrowLeft class="h-4 w-4" />
                Detail resep
            </Link>
            <div>
                <h1 class="text-xl font-bold text-slate-900">Ubah resep · v{{ recipe.version }}</h1>
                <p class="mt-1 text-sm text-slate-600">Menyimpan perubahan akan menaikkan versi resep.</p>
            </div>
            <RecipeForm :recipe="recipe" :ingredients="ingredients" :outputs="outputs" :units="units" />
        </div>
    </AdminLayout>
</template>
