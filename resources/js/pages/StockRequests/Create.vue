<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { ArrowLeft, Search, Send, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface ProductOpt {
    id: number;
    name: string;
    sku: string | null;
    unit: string;
    allows_decimal: boolean;
    stock: number;
    min_stock: number;
}

const props = defineProps<{
    store: { id: number; name: string };
    suppliers: { id: number; name: string; is_central_warehouse: boolean }[];
    products: ProductOpt[];
    /** supplierId → productId → stok pemasok (null = pemasok tidak punya barang ini) */
    availability: Record<string, Record<string, number | null>>;
    prefill: { product_id: number; quantity: number }[];
}>();

const form = useForm({
    to_store_id: props.suppliers[0]?.id ?? null,
    notes: '',
    items: props.prefill.map((p) => ({ product_id: p.product_id, quantity: p.quantity })),
});

const productById = (id: number) => props.products.find((p) => p.id === id);
const supplierStock = (productId: number): number | null => {
    if (!form.to_store_id) return null;
    return props.availability[String(form.to_store_id)]?.[String(productId)] ?? null;
};
const supplierName = computed(() => props.suppliers.find((s) => s.id === form.to_store_id)?.name ?? 'pemasok');

// Barang yang tidak dimiliki pemasok terpilih tidak bisa diminta.
const unavailableItems = computed(() => form.items.filter((i) => supplierStock(i.product_id) === null));

const search = ref('');
const suggestions = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (!q) return [];
    const chosen = new Set(form.items.map((i) => i.product_id));
    return props.products
        .filter((p) => !chosen.has(p.id) && (p.name.toLowerCase().includes(q) || (p.sku ?? '').toLowerCase().includes(q)))
        .slice(0, 8);
});

function addProduct(p: ProductOpt) {
    // Saran awal: isi kembali sampai 2 × Stok Minimum (sama dengan Daftar Belanja), minimal 1.
    const need = p.min_stock > 0 ? Math.max(p.min_stock * 2 - p.stock, 1) : 1;
    form.items.push({ product_id: p.id, quantity: p.allows_decimal ? Math.round(need * 100) / 100 : Math.ceil(need) });
    search.value = '';
}

function removeItem(index: number) {
    form.items.splice(index, 1);
}

const fmt = (n: number) => new Intl.NumberFormat('id-ID', { maximumFractionDigits: 2 }).format(n);

const canSubmit = computed(
    () => form.to_store_id !== null && form.items.length > 0 && unavailableItems.value.length === 0 && form.items.every((i) => Number(i.quantity) > 0),
);

function submit() {
    form.post('/stock-requests');
}
</script>

<template>
    <Head title="Buat Permintaan Stok" />

    <AdminLayout title="Buat Permintaan Stok">
        <div class="mx-auto max-w-5xl space-y-6 pb-8">
            <Button as-child variant="outline" size="sm" class="gap-2 rounded-xl">
                <Link href="/stock-requests">
                    <ArrowLeft class="h-4 w-4" />
                    Kembali
                </Link>
            </Button>

            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <CardTitle>Minta stok untuk {{ store.name }}</CardTitle>
                </CardHeader>
                <CardContent class="space-y-5">
                    <div class="grid gap-4 md:grid-cols-2">
                        <label class="space-y-1.5 text-sm">
                            <span class="font-semibold text-slate-700">Minta ke</span>
                            <select v-model="form.to_store_id" class="h-10 w-full rounded-lg border border-slate-200 px-3">
                                <option v-for="s in suppliers" :key="s.id" :value="s.id">
                                    {{ s.name }}{{ s.is_central_warehouse ? ' (Gudang Pusat)' : '' }}
                                </option>
                            </select>
                            <span v-if="form.errors.to_store_id" class="text-xs text-red-600">{{ form.errors.to_store_id }}</span>
                        </label>
                        <label class="space-y-1.5 text-sm">
                            <span class="font-semibold text-slate-700">Catatan (opsional)</span>
                            <input
                                v-model="form.notes"
                                type="text"
                                maxlength="2000"
                                placeholder="Mis. untuk stok akhir pekan"
                                class="h-10 w-full rounded-lg border border-slate-200 px-3"
                            />
                        </label>
                    </div>

                    <div class="relative">
                        <Search class="absolute left-3 top-3 h-4 w-4 text-slate-400" />
                        <input
                            v-model="search"
                            type="text"
                            placeholder="Cari barang untuk ditambahkan…"
                            class="h-10 w-full rounded-lg border border-slate-200 pl-9 pr-3 text-sm"
                            aria-label="Cari barang"
                        />
                        <div v-if="suggestions.length" class="absolute z-10 mt-1 w-full overflow-hidden rounded-xl border border-slate-200 bg-white shadow-lg">
                            <button
                                v-for="p in suggestions"
                                :key="p.id"
                                type="button"
                                class="flex w-full items-center justify-between px-4 py-2.5 text-left text-sm hover:bg-slate-50"
                                @click="addProduct(p)"
                            >
                                <span>
                                    <span class="font-medium text-slate-900">{{ p.name }}</span>
                                    <span v-if="p.sku" class="ml-2 font-mono text-xs text-slate-400">{{ p.sku }}</span>
                                </span>
                                <span class="text-xs" :class="supplierStock(p.id) === null ? 'text-red-500' : 'text-slate-500'">
                                    {{ supplierStock(p.id) === null ? `Tidak ada di ${supplierName}` : `Stok ${supplierName}: ${fmt(supplierStock(p.id)!)}` }}
                                </span>
                            </button>
                        </div>
                    </div>

                    <div v-if="form.items.length === 0" class="rounded-xl border border-dashed border-slate-200 py-10 text-center text-sm text-slate-500">
                        Belum ada barang. Cari barang di atas, atau buka dari Daftar Belanja.
                    </div>
                    <div v-else class="overflow-x-auto rounded-xl border border-slate-100">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b bg-slate-50 text-left text-xs font-bold uppercase tracking-wide text-slate-600">
                                    <th class="p-3">Barang</th>
                                    <th class="p-3 text-right">Stok di sini / Minimum</th>
                                    <th class="p-3 text-right">Stok {{ supplierName }}</th>
                                    <th class="p-3">Jumlah diminta</th>
                                    <th class="p-3"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(item, i) in form.items" :key="item.product_id" class="border-b border-slate-100 last:border-0">
                                    <td class="p-3 font-medium text-slate-900">{{ productById(item.product_id)?.name }}</td>
                                    <td class="p-3 text-right tabular-nums text-slate-600">
                                        {{ fmt(productById(item.product_id)?.stock ?? 0) }} / {{ fmt(productById(item.product_id)?.min_stock ?? 0) }}
                                    </td>
                                    <td class="p-3 text-right tabular-nums">
                                        <span v-if="supplierStock(item.product_id) === null" class="text-xs font-semibold text-red-600">Tidak ada</span>
                                        <span v-else :class="supplierStock(item.product_id)! < Number(item.quantity) ? 'text-amber-700' : 'text-slate-700'">
                                            {{ fmt(supplierStock(item.product_id)!) }}
                                        </span>
                                    </td>
                                    <td class="p-3">
                                        <div class="flex items-center gap-2">
                                            <input
                                                v-model.number="item.quantity"
                                                type="number"
                                                :min="productById(item.product_id)?.allows_decimal ? 0.01 : 1"
                                                :step="productById(item.product_id)?.allows_decimal ? 0.01 : 1"
                                                class="h-9 w-24 rounded-md border border-slate-200 px-2 text-right"
                                                :aria-label="`Jumlah ${productById(item.product_id)?.name}`"
                                            />
                                            <span class="text-slate-500">{{ productById(item.product_id)?.unit }}</span>
                                        </div>
                                        <span v-if="form.errors[`items.${i}.quantity`]" class="text-xs text-red-600">{{ form.errors[`items.${i}.quantity`] }}</span>
                                        <span v-if="form.errors[`items.${i}.product_id`]" class="text-xs text-red-600">{{ form.errors[`items.${i}.product_id`] }}</span>
                                    </td>
                                    <td class="p-3 text-right">
                                        <button type="button" class="rounded-md p-1.5 text-red-600 hover:bg-red-50" @click="removeItem(i)">
                                            <Trash2 class="h-4 w-4" />
                                            <span class="sr-only">Hapus</span>
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <p v-if="unavailableItems.length" class="rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700">
                        {{ unavailableItems.length }} barang tidak ada di {{ supplierName }} (SKU/barcode dan satuan dasar harus sama). Hapus barang itu atau pilih lokasi lain.
                    </p>
                    <p v-if="form.errors.items" class="text-xs text-red-600">{{ form.errors.items }}</p>

                    <div class="flex justify-end">
                        <Button class="rounded-xl" :disabled="!canSubmit || form.processing" @click="submit">
                            <Send class="mr-2 h-4 w-4" />
                            Kirim permintaan
                        </Button>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AdminLayout>
</template>
