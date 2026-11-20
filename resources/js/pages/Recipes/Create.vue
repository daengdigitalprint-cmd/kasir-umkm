<script setup lang="ts">
import type { RecipeProductOpt } from '@/components/recipes/RecipeForm.vue';
import RecipeForm from '@/components/recipes/RecipeForm.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft } from 'lucide-vue-next';

defineProps<{
    store: { id: number; name: string };
    ingredients: RecipeProductOpt[];
    outputs: RecipeProductOpt[];
    units: Record<string, string>;
}>();
</script>

<template>
    <Head title="Buat resep produksi" />

    <AdminLayout title="Resep produksi">
        <div class="mx-auto max-w-4xl space-y-4 pb-8">
            <Link href="/recipes" class="inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-slate-900">
                <ArrowLeft class="h-4 w-4" />
                Resep produksi
            </Link>
            <div>
                <h1 class="text-xl font-bold text-slate-900">Buat resep produksi</h1>
                <p class="mt-1 text-sm text-slate-600">
                    Tentukan bahan apa saja yang diolah untuk menghasilkan satu produk setengah jadi atau siap jual.
                </p>
            </div>
            <RecipeForm :ingredients="ingredients" :outputs="outputs" :units="units" />
        </div>
    </AdminLayout>
</template>
