<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { confirmDelete, modalError, toastSuccess } from '@/lib/swal';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ChefHat, Factory, Info, MoreVertical, Pencil, Plus, Trash2, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface ProductTiny {
    id: number;
    name: string;
    sku: string | null;
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
    total_cost: number;
    unit_cost: number;
}

interface Paginated<T> {
    data: T[];
    total?: number;
    links: { url: string | null; label: string; active: boolean }[];
}

defineProps<{
    recipes: Paginated<RecipeRow>;
    currentStore: { id: number; name: string };
}>();

const page = usePage();
const canViewCost = computed(() => Boolean((page.props as { abilities?: { view_cost?: boolean } }).abilities?.view_cost));

const rupiah = (v: number) => `Rp${Number(v).toLocaleString('id-ID', { maximumFractionDigits: Math.abs(v) >= 100 ? 0 : 2 })}`;
const fmtQty = (v: string | number) => Number(v).toLocaleString('id-ID', { maximumFractionDigits: 2 });

const ingredientNames = (r: RecipeRow) => {
    const names = r.ingredients.map((i) => i.product?.name).filter(Boolean) as string[];
    return names.length > 3 ? `${names.slice(0, 3).join(', ')} +${names.length - 3}` : names.join(', ');
};

const INFO_KEY = 'recipes-info-hidden';
const showInfo = ref(true);
try {
    showInfo.value = localStorage.getItem(INFO_KEY) !== '1';
} catch {
    /* abaikan */
}
function hideInfo() {
    showInfo.value = false;
    try {
        localStorage.setItem(INFO_KEY, '1');
    } catch {
        /* abaikan */
    }
}

async function deleteRecipe(recipe: RecipeRow) {
    if (!(await confirmDelete('Hapus resep?', `Resep "${recipe.name}" akan dihapus. Stok bahan tidak berubah.`))) return;

    router.delete(`/recipes/${recipe.id}`, {
        preserveScroll: true,
        onSuccess: () => void toastSuccess('Resep dihapus.'),
        onError: async (errors) => {
            const msg = Object.values(errors)[0];
            await modalError('Gagal menghapus', Array.isArray(msg) ? msg[0] : String(msg ?? 'Terjadi kesalahan.'));
        },
    });
}
</script>

<template>
    <Head title="Resep produksi" />

    <AdminLayout title="Resep produksi">
        <div class="mx-auto max-w-5xl space-y-4 pb-8">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 class="text-xl font-bold text-slate-900">Resep produksi</h1>
                    <p class="mt-1 text-sm text-slate-600">Bahan yang diolah menjadi produk setengah jadi di {{ currentStore.name }}.</p>
                </div>
                <Button as-child class="rounded-xl">
                    <Link href="/recipes/create" class="inline-flex items-center gap-2">
                        <Plus class="h-4 w-4" />
                        Buat resep
                    </Link>
                </Button>
            </div>

            <div v-if="showInfo" class="flex gap-3 rounded-2xl border border-sky-100 bg-sky-50 p-4 text-sm text-sky-900">
                <Info class="mt-0.5 h-4 w-4 shrink-0" />
                <div class="flex-1 space-y-1">
                    <p>
                        <strong>Resep produksi</strong> dipakai saat Anda memasak/mengolah dalam jumlah banyak, misalnya membuat biang teh atau sambal
                        satu panci. Stok bahan berkurang dan stok hasil bertambah ketika produksi dicatat.
                    </p>
                    <p class="text-sky-800">
                        Kalau ingin stok bahan berkurang otomatis setiap kali menu terjual di kasir, atur di
                        <Link href="/products" class="font-semibold underline">Produk → Bahan yang terpakai saat terjual</Link>.
                    </p>
                </div>
                <button type="button" class="h-6 w-6 shrink-0 rounded text-sky-700 hover:bg-sky-100" aria-label="Tutup info" @click="hideInfo">
                    <X class="mx-auto h-4 w-4" />
                </button>
            </div>

            <div v-if="recipes.data.length === 0" class="rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-12 text-center">
                <ChefHat class="mx-auto h-10 w-10 text-slate-300" />
                <p class="mt-3 font-semibold text-slate-800">Belum ada resep produksi</p>
                <p class="mt-1 text-sm text-slate-500">Buat resep pertama, lalu catat produksinya agar stok{{ canViewCost ? ' dan HPP' : '' }} terhitung.</p>
                <Button as-child class="mt-4 rounded-xl">
                    <Link href="/recipes/create">Buat resep pertama</Link>
                </Button>
            </div>

            <div v-else class="divide-y divide-slate-100 overflow-hidden rounded-2xl border border-slate-200 bg-white">
                <div v-for="r in recipes.data" :key="r.id" class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center">
                    <Link :href="`/recipes/${r.id}`" class="flex min-w-0 flex-1 items-start gap-3">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-amber-50 text-amber-700">
                            <ChefHat class="h-5 w-5" />
                        </div>
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                <span class="font-semibold text-slate-900">{{ r.name }}</span>
                                <span v-if="r.code" class="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[11px] text-slate-600">{{
                                    r.code
                                }}</span>
                                <span v-if="!r.is_active" class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-600"
                                    >Nonaktif</span
                                >
                                <span class="text-[11px] text-slate-400">v{{ r.version }}</span>
                            </div>
                            <p class="mt-0.5 text-sm text-slate-600">
                                Hasil <strong class="font-medium text-slate-800">{{ fmtQty(r.output_quantity) }} {{ r.output_unit ?? '' }}</strong>
                                {{ r.product?.name ?? '—' }}
                            </p>
                            <p class="mt-0.5 truncate text-xs text-slate-500">{{ r.ingredients_count }} bahan · {{ ingredientNames(r) }}</p>
                        </div>
                    </Link>

                    <div class="flex items-center gap-3 pl-13 sm:pl-0">
                        <div v-if="canViewCost" class="flex-1 text-sm sm:w-36 sm:flex-none sm:text-right">
                            <div class="font-semibold text-slate-900 tabular-nums">
                                {{ rupiah(r.unit_cost) }}<span class="text-xs font-normal text-slate-500">/{{ r.output_unit ?? 'satuan' }}</span>
                            </div>
                            <div class="text-xs text-slate-500">Modal {{ rupiah(r.total_cost) }}</div>
                        </div>
                        <Button v-if="r.is_active" as-child variant="outline" size="sm" class="gap-1.5 rounded-xl">
                            <Link :href="`/productions/create?recipe_id=${r.id}`">
                                <Factory class="h-4 w-4" />
                                Produksi
                            </Link>
                        </Button>
                        <DropdownMenu>
                            <DropdownMenuTrigger as-child>
                                <button
                                    type="button"
                                    class="flex h-9 w-9 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100"
                                    :aria-label="`Menu ${r.name}`"
                                >
                                    <MoreVertical class="h-4 w-4" />
                                </button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuItem as-child>
                                    <Link :href="`/recipes/${r.id}/edit`" class="flex items-center gap-2"><Pencil class="h-4 w-4" /> Ubah</Link>
                                </DropdownMenuItem>
                                <DropdownMenuItem class="text-red-600" @click="deleteRecipe(r)"> <Trash2 class="h-4 w-4" /> Hapus </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                </div>
            </div>

            <div v-if="recipes.links?.length > 3" class="flex flex-wrap justify-center gap-1">
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
        </div>
    </AdminLayout>
</template>
