<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { AlertTriangle, ArrowRight, Copy, ListChecks, PackageCheck, Truck, Warehouse } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { toastError, toastSuccess } from '@/lib/swal';

interface ShoppingItem {
    key: string;
    product_id: number;
    product_variant_id: number | null;
    name: string;
    variant_name: string | null;
    sku: string | null;
    unit: string;
    inventory_type: string;
    allows_decimal: boolean;
    stock: number;
    min_stock: number;
    suggested_quantity: number;
    unit_cost: number;
    last_supplier_id: number | null;
    last_supplier_name: string | null;
}

const props = defineProps<{
    items: ShoppingItem[];
    canPurchase: boolean;
    store: { id: number; name: string; currency: string; location_label?: string };
}>();

// Jumlah beli & centang bisa diubah sebelum dijadikan pembelian.
const quantities = ref<Record<string, number>>(Object.fromEntries(props.items.map((i) => [i.key, i.suggested_quantity])));
const selected = ref<Record<string, boolean>>(Object.fromEntries(props.items.map((i) => [i.key, true])));

// Pilah per supplier terakhir: 'all', 'none' (belum pernah dibeli) atau id supplier.
const supplierFilter = ref<string>('all');

const supplierOptions = computed(() => {
    const map = new Map<number, string>();
    for (const item of props.items) {
        if (item.last_supplier_id && item.last_supplier_name) map.set(item.last_supplier_id, item.last_supplier_name);
    }
    return [...map.entries()].map(([id, name]) => ({ id, name })).sort((a, b) => a.name.localeCompare(b.name));
});

const visibleItems = computed(() =>
    props.items.filter((item) => {
        if (supplierFilter.value === 'all') return true;
        if (supplierFilter.value === 'none') return !item.last_supplier_id;
        return String(item.last_supplier_id) === supplierFilter.value;
    }),
);

const chosenItems = computed(() => visibleItems.value.filter((i) => selected.value[i.key] && (quantities.value[i.key] ?? 0) > 0));

const allVisibleChecked = computed(() => visibleItems.value.length > 0 && visibleItems.value.every((i) => selected.value[i.key]));

function toggleAll(checked: boolean) {
    for (const item of visibleItems.value) selected.value[item.key] = checked;
}

const estimatedTotal = computed(() =>
    chosenItems.value.reduce((sum, i) => sum + (quantities.value[i.key] ?? 0) * i.unit_cost, 0),
);

const outOfStockCount = computed(() => props.items.filter((i) => i.stock <= 0).length);

const fmtQty = (n: number) => new Intl.NumberFormat('id-ID', { maximumFractionDigits: 2 }).format(n);
const fmtMoney = (n: number) =>
    new Intl.NumberFormat('id-ID', { style: 'currency', currency: props.store.currency || 'IDR', maximumFractionDigits: 0 }).format(n);

function stockPercent(item: ShoppingItem) {
    if (item.min_stock <= 0) return 0;
    return Math.max(0, Math.min(100, (item.stock / item.min_stock) * 100));
}

function itemLabel(item: ShoppingItem) {
    return item.variant_name ? `${item.name} (${item.variant_name})` : item.name;
}

function makePurchase() {
    if (!props.canPurchase || chosenItems.value.length === 0) return;

    const items = chosenItems.value.map((i) => `${i.product_id}:${i.product_variant_id ?? ''}:${quantities.value[i.key]}`);
    const supplierId = /^\d+$/.test(supplierFilter.value) ? Number(supplierFilter.value) : undefined;

    router.get('/purchases/create', { items, ...(supplierId ? { supplier_id: supplierId } : {}) });
}

async function copyList() {
    if (chosenItems.value.length === 0) return;
    const lines = chosenItems.value.map((i, idx) => `${idx + 1}. ${itemLabel(i)}: ${fmtQty(quantities.value[i.key])} ${i.unit}`);
    const text = [`Daftar belanja ${props.store.name}`, ...lines].join('\n');
    try {
        await navigator.clipboard.writeText(text);
        toastSuccess('Daftar belanja disalin. Tinggal tempel di WhatsApp.');
    } catch {
        toastError('Gagal menyalin. Coba lagi dari browser lain.');
    }
}
</script>

<template>
    <Head title="Daftar Belanja" />

    <AdminLayout title="Daftar Belanja">
        <div class="mx-auto space-y-6 pb-28" style="max-width: 90rem">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-black text-slate-900">Daftar Belanja</h1>
                    <p class="mt-1 text-sm text-slate-600">
                        Barang yang stoknya sudah sampai Stok Minimum. Saran jumlah beli mengisi kembali sampai 2 × Stok Minimum dan bisa diubah.
                    </p>
                </div>
                <Button v-if="canPurchase" variant="outline" as-child class="rounded-xl">
                    <Link href="/purchases" class="inline-flex items-center gap-2">
                        <Truck class="h-4 w-4" />
                        Riwayat pembelian
                    </Link>
                </Button>
            </div>

            <div
                v-if="!canPurchase"
                class="flex items-start gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"
            >
                <Warehouse class="mt-0.5 h-5 w-5 shrink-0" />
                <p>
                    Pembelian outlet ini dialihkan ke Gudang Pusat, jadi daftar ini tidak bisa langsung dijadikan Pembelian.
                    Salin daftarnya lalu kirim ke Gudang Pusat, atau buat Transfer Stok dari Gudang Pusat.
                </p>
            </div>

            <div v-if="items.length === 0" class="rounded-2xl border border-slate-200 bg-white p-12 text-center shadow-sm">
                <PackageCheck class="mx-auto h-12 w-12 text-emerald-500" />
                <p class="mt-4 text-lg font-bold text-slate-900">Semua stok aman</p>
                <p class="mx-auto mt-1 max-w-md text-sm text-slate-600">
                    Belum ada barang yang stoknya sampai Stok Minimum. Pastikan Stok Minimum sudah diisi di halaman Produk supaya barang muncul di sini saat menipis.
                </p>
                <Button as-child variant="outline" class="mt-5 rounded-xl">
                    <Link href="/products">Atur Stok Minimum di Produk</Link>
                </Button>
            </div>

            <Card v-else class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="flex items-center gap-2">
                            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-amber-500/10">
                                <ListChecks class="h-5 w-5 text-amber-700" />
                            </div>
                            <div>
                                <CardTitle>{{ items.length }} barang perlu dibeli</CardTitle>
                                <CardDescription>
                                    <span v-if="outOfStockCount > 0" class="font-semibold text-red-600">{{ outOfStockCount }} sudah habis</span>
                                    <span v-else>Belum ada yang habis</span>
                                </CardDescription>
                            </div>
                        </div>
                        <label class="flex items-center gap-2 text-sm text-slate-600">
                            Supplier terakhir
                            <select v-model="supplierFilter" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
                                <option value="all">Semua</option>
                                <option v-for="s in supplierOptions" :key="s.id" :value="String(s.id)">{{ s.name }}</option>
                                <option value="none">Belum pernah dibeli</option>
                            </select>
                        </label>
                    </div>
                </CardHeader>
                <CardContent>
                    <div class="overflow-x-auto rounded-xl border border-slate-100">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b bg-slate-50 text-left text-xs font-bold uppercase tracking-wide text-slate-600">
                                    <th class="w-10 p-3">
                                        <input
                                            type="checkbox"
                                            class="h-4 w-4 rounded"
                                            :checked="allVisibleChecked"
                                            aria-label="Pilih semua"
                                            @change="toggleAll(($event.target as HTMLInputElement).checked)"
                                        />
                                    </th>
                                    <th class="p-3">Barang</th>
                                    <th class="p-3">Stok / Minimum</th>
                                    <th class="p-3">Jumlah beli</th>
                                    <th class="p-3 text-right">Perkiraan biaya</th>
                                    <th class="p-3">Supplier terakhir</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="item in visibleItems"
                                    :key="item.key"
                                    class="border-b border-slate-100 last:border-0"
                                    :class="selected[item.key] ? '' : 'opacity-50'"
                                >
                                    <td class="p-3">
                                        <input v-model="selected[item.key]" type="checkbox" class="h-4 w-4 rounded" :aria-label="`Pilih ${itemLabel(item)}`" />
                                    </td>
                                    <td class="p-3">
                                        <p class="font-semibold text-slate-900">{{ itemLabel(item) }}</p>
                                        <p v-if="item.sku" class="font-mono text-xs text-slate-500">{{ item.sku }}</p>
                                    </td>
                                    <td class="p-3">
                                        <div class="flex items-center gap-2">
                                            <span v-if="item.stock <= 0" class="inline-flex items-center gap-1 font-bold text-red-600">
                                                <AlertTriangle class="h-3.5 w-3.5" /> Habis
                                            </span>
                                            <span v-else class="font-semibold text-amber-700">{{ fmtQty(item.stock) }}</span>
                                            <span class="text-slate-400">/ {{ fmtQty(item.min_stock) }} {{ item.unit }}</span>
                                        </div>
                                        <div class="mt-1.5 h-1.5 w-28 overflow-hidden rounded-full bg-slate-100">
                                            <div
                                                class="h-full rounded-full"
                                                :class="item.stock <= 0 ? 'bg-red-500' : 'bg-amber-400'"
                                                :style="{ width: `${stockPercent(item)}%` }"
                                            />
                                        </div>
                                    </td>
                                    <td class="p-3">
                                        <div class="flex items-center gap-2">
                                            <input
                                                v-model.number="quantities[item.key]"
                                                type="number"
                                                :min="item.allows_decimal ? 0.01 : 1"
                                                :step="item.allows_decimal ? 0.01 : 1"
                                                class="w-24 rounded-lg border border-slate-200 px-2 py-1.5 text-right"
                                                :aria-label="`Jumlah beli ${itemLabel(item)}`"
                                            />
                                            <span class="text-slate-500">{{ item.unit }}</span>
                                        </div>
                                    </td>
                                    <td class="p-3 text-right tabular-nums text-slate-700">
                                        {{ item.unit_cost > 0 ? fmtMoney((quantities[item.key] ?? 0) * item.unit_cost) : '—' }}
                                    </td>
                                    <td class="p-3 text-slate-600">{{ item.last_supplier_name || '—' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="mt-3 text-xs text-slate-500">
                        Perkiraan biaya memakai HPP rata-rata saat ini. Harga sebenarnya diisi di form Pembelian.
                    </p>
                </CardContent>
            </Card>
        </div>

        <div
            v-if="items.length > 0"
            class="fixed inset-x-0 bottom-0 z-30 border-t border-slate-200 bg-white/95 px-4 py-3 shadow-[0_-4px_16px_rgba(0,0,0,0.06)] backdrop-blur lg:pl-72"
        >
            <div class="mx-auto flex flex-wrap items-center justify-between gap-3" style="max-width: 90rem">
                <div class="text-sm">
                    <span class="font-bold text-slate-900">{{ chosenItems.length }} barang dipilih</span>
                    <span class="ml-2 text-slate-600">Perkiraan {{ fmtMoney(estimatedTotal) }}</span>
                </div>
                <div class="flex gap-2">
                    <Button variant="outline" class="rounded-xl" :disabled="chosenItems.length === 0" @click="copyList">
                        <Copy class="mr-2 h-4 w-4" />
                        Salin daftar
                    </Button>
                    <Button v-if="canPurchase" class="rounded-xl" :disabled="chosenItems.length === 0" @click="makePurchase">
                        Jadikan Pembelian
                        <ArrowRight class="ml-2 h-4 w-4" />
                    </Button>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
