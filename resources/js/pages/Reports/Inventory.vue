<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import ReportTabs from '@/components/ReportTabs.vue';
import ReportExportButton from '@/components/ReportExportButton.vue';
import { Package, Layers, AlertTriangle, CheckCircle, XCircle, BarChart3, ShoppingCart, PackageX, BoxSelect, DollarSign } from 'lucide-vue-next';

interface ProductRow {
    id: number;
    name: string;
    sku?: string | null;
    stock_quantity: number | string;
    min_stock_level: number | string;
    track_stock?: boolean;
    total_sold?: number | string | null;
    unit?: string | null;
}

interface BatchRow {
    id: number;
    product_id: number;
    product_name: string;
    unit: string;
    batch_code: string | null;
    quantity_remaining: number | string;
    expiry_date: string | null;
    expiry_status: 'no_expiry' | 'expired' | 'warning' | 'ok';
}

interface Paginated<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
}

interface StockStats {
    total_products: number | string;
    in_stock: number | string;
    out_of_stock: number | string;
    low_stock: number | string;
    untracked: number | string;
    total_stock_qty: number | string;
    total_stock_value: number | string;
}

interface BatchExpiryStats {
    total_batches: number | string;
    expired: number | string;
    expiring_soon: number | string;
    no_expiry: number | string;
}

const props = defineProps<{
    store: { id: number; name: string; currency: string };
    products: Paginated<ProductRow>;
    batch_tracking_enabled: boolean;
    batch_expiry_warn_days: number;
    batches: Paginated<BatchRow> | null;
    stockStats: StockStats;
    batchExpiryStats: BatchExpiryStats | null;
}>();

const statusLabel = (s: BatchRow['expiry_status']) => {
    switch (s) {
        case 'expired':
            return 'Kadaluarsa';
        case 'warning':
            return `≤ ${props.batch_expiry_warn_days} hari`;
        case 'ok':
            return 'Aman';
        default:
            return 'Tanpa ED';
    }
};

const statusVariant = (s: BatchRow['expiry_status']) => {
    switch (s) {
        case 'expired':
            return 'destructive' as const;
        case 'warning':
            return 'secondary' as const;
        case 'ok':
            return 'default' as const;
        default:
            return 'outline' as const;
    }
};

const formatQty = (q: number | string | null | undefined) => {
    if (q === null || q === undefined) return '0';
    const n = typeof q === 'string' ? parseFloat(q) : q;
    if (Number.isNaN(n)) return String(q);
    return new Intl.NumberFormat('id-ID', { maximumFractionDigits: 4 }).format(n);
};

const formatDate = (d: string | null) => {
    if (!d) return '—';
    return new Date(d + 'T12:00:00').toLocaleDateString('id-ID', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
};

const fmtCurrency = (n: number | string | null | undefined) => {
    if (n === null || n === undefined) return 'Rp 0';
    const num = Number(n);
    if (Number.isNaN(num)) return 'Rp 0';
    return 'Rp ' + new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(num);
};

const stockQty = (q: number | string): number => {
    const n = typeof q === 'string' ? parseFloat(q) : q;
    return Number.isNaN(n) ? 0 : n;
};

const stockClass = (q: number | string): string => {
    const n = stockQty(q);
    if (n < 0) {
        return 'text-red-600 font-bold';
    }
    if (n === 0) {
        return 'text-amber-600 font-semibold';
    }
    return 'text-emerald-600';
};

const stockBadge = (q: number | string): { text: string; class: string } | null => {
    const n = stockQty(q);
    if (n < 0) {
        return { text: 'Stok negatif', class: 'bg-red-100 text-red-700' };
    }
    if (n === 0) {
        return { text: 'Stok habis', class: 'bg-amber-100 text-amber-700' };
    }
    return null;
};
</script>

<template>
    <AdminLayout title="Laporan stok">
        <Head title="Laporan stok" />

        <div class="flex items-start justify-between gap-3">
            <ReportTabs active-tab="stok" />
            <ReportExportButton report="stok" />
        </div>

        <!-- Ringkasan statistik stok -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 md:gap-4 mb-6">
            <Card class="border-0 shadow-lg">
                <CardContent class="p-4 md:p-5">
                    <div class="flex items-center gap-3 mb-2">
                        <div class="p-2 bg-primary/10 rounded-xl">
                            <Package class="h-5 w-5 text-primary" />
                        </div>
                    </div>
                    <p class="text-xs font-medium text-slate-500">Total produk</p>
                    <p class="text-lg md:text-2xl font-black text-slate-900 tabular-nums">{{ formatQty(stockStats.total_products) }}</p>
                    <p class="text-[11px] text-slate-500 mt-1">{{ formatQty(stockStats.untracked) }} tanpa pelacakan stok</p>
                </CardContent>
            </Card>

            <Card class="border-0 shadow-lg">
                <CardContent class="p-4 md:p-5">
                    <div class="flex items-center gap-3 mb-2">
                        <div class="p-2 bg-emerald-50 rounded-xl">
                            <CheckCircle class="h-5 w-5 text-emerald-600" />
                        </div>
                    </div>
                    <p class="text-xs font-medium text-slate-500">Stok tersedia</p>
                    <p class="text-lg md:text-2xl font-black text-emerald-600 tabular-nums">{{ formatQty(stockStats.in_stock) }} produk</p>
                    <p class="text-[11px] text-slate-500 mt-1">{{ fmtCurrency(stockStats.total_stock_value) }} total nilai</p>
                </CardContent>
            </Card>

            <Card class="border-0 shadow-lg">
                <CardContent class="p-4 md:p-5">
                    <div class="flex items-center gap-3 mb-2">
                        <div class="p-2 bg-red-50 rounded-xl">
                            <PackageX class="h-5 w-5 text-red-500" />
                        </div>
                    </div>
                    <p class="text-xs font-medium text-slate-500">Stok habis</p>
                    <p class="text-lg md:text-2xl font-black text-red-600 tabular-nums">{{ formatQty(stockStats.out_of_stock) }} produk</p>
                </CardContent>
            </Card>

            <Card class="border-0 shadow-lg">
                <CardContent class="p-4 md:p-5">
                    <div class="flex items-center gap-3 mb-2">
                        <div class="p-2 bg-amber-50 rounded-xl">
                            <AlertTriangle class="h-5 w-5 text-amber-600" />
                        </div>
                    </div>
                    <p class="text-xs font-medium text-slate-500">Stok menipis</p>
                    <p class="text-lg md:text-2xl font-black text-amber-600 tabular-nums">{{ formatQty(stockStats.low_stock) }} produk</p>
                    <p class="text-[11px] text-slate-500 mt-1">Di bawah ambang minimum</p>
                </CardContent>
            </Card>
        </div>

        <!-- Batch expiry stats (jika batch tracking aktif) -->
        <div v-if="batch_tracking_enabled && batchExpiryStats" class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
            <Card class="border-0 shadow-md">
                <CardContent class="p-3.5">
                    <div class="flex items-center gap-2 mb-1">
                        <Layers class="h-4 w-4 text-slate-500" />
                        <p class="text-xs font-medium text-slate-500">Total batch</p>
                    </div>
                    <p class="text-xl font-black text-slate-900 tabular-nums">{{ formatQty(batchExpiryStats.total_batches) }}</p>
                </CardContent>
            </Card>
            <Card class="border-0 shadow-md border-red-100">
                <CardContent class="p-3.5">
                    <div class="flex items-center gap-2 mb-1">
                        <XCircle class="h-4 w-4 text-red-500" />
                        <p class="text-xs font-medium text-red-600">Kadaluarsa</p>
                    </div>
                    <p class="text-xl font-black text-red-600 tabular-nums">{{ formatQty(batchExpiryStats.expired) }}</p>
                </CardContent>
            </Card>
            <Card class="border-0 shadow-md border-amber-100">
                <CardContent class="p-3.5">
                    <div class="flex items-center gap-2 mb-1">
                        <AlertTriangle class="h-4 w-4 text-amber-500" />
                        <p class="text-xs font-medium text-amber-600">Akan kadaluarsa</p>
                    </div>
                    <p class="text-xl font-black text-amber-600 tabular-nums">{{ formatQty(batchExpiryStats.expiring_soon) }}</p>
                    <p class="text-[10px] text-slate-500 mt-0.5">≤ {{ batch_expiry_warn_days }} hari</p>
                </CardContent>
            </Card>
            <Card class="border-0 shadow-md border-emerald-100">
                <CardContent class="p-3.5">
                    <div class="flex items-center gap-2 mb-1">
                        <CheckCircle class="h-4 w-4 text-emerald-500" />
                        <p class="text-xs font-medium text-emerald-600">Tanpa ED</p>
                    </div>
                    <p class="text-xl font-black text-emerald-600 tabular-nums">{{ formatQty(batchExpiryStats.no_expiry) }}</p>
                </CardContent>
            </Card>
        </div>

        <Card class="border-0 shadow-lg mb-6">
            <CardHeader>
                <CardTitle class="flex items-center gap-2 text-lg">
                    <Package class="h-5 w-5 text-slate-600" />
                    Stok per produk
                </CardTitle>
                <CardDescription>
                    {{ store.name }} — akumulasi stok dan total terjual (semua waktu, transaksi selesai).
                </CardDescription>
            </CardHeader>
            <CardContent class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b text-left text-slate-500">
                            <th class="pb-3 pr-4 font-medium">Produk</th>
                            <th class="pb-3 pr-4 font-medium">SKU</th>
                            <th class="pb-3 pr-4 font-medium text-right">Stok</th>
                            <th class="pb-3 pr-4 font-medium text-right">Min</th>
                            <th class="pb-3 pr-4 font-medium text-right">Terjual (qty)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="p in products.data"
                            :key="p.id"
                            class="border-b border-slate-100"
                            :class="{
                                'bg-red-50/60': stockQty(p.stock_quantity) < 0,
                                'bg-amber-50/40': stockQty(p.stock_quantity) === 0,
                            }"
                        >
                            <td class="py-3 pr-4">
                                <span class="font-medium text-slate-900">{{ p.name }}</span>
                                <span v-if="p.unit" class="text-xs text-slate-500"> · {{ p.unit }}</span>
                            </td>
                            <td class="py-3 pr-4 font-mono text-xs text-slate-600">{{ p.sku || '—' }}</td>
                            <td class="py-3 pr-4 text-right tabular-nums">
                                <span :class="stockClass(p.stock_quantity)">{{ formatQty(p.stock_quantity) }}</span>
                                <Badge
                                    v-if="stockBadge(p.stock_quantity)"
                                    :class="['ml-2 text-[10px] font-bold', stockBadge(p.stock_quantity)!.class]"
                                >
                                    {{ stockBadge(p.stock_quantity)!.text }}
                                </Badge>
                            </td>
                            <td class="py-3 pr-4 text-right tabular-nums text-slate-600">{{ formatQty(p.min_stock_level) }}</td>
                            <td class="py-3 pr-4 text-right tabular-nums font-medium">{{ formatQty(p.total_sold) }}</td>
                        </tr>
                        <tr v-if="!products.data?.length">
                            <td colspan="5" class="py-10 text-center text-slate-500">Belum ada produk.</td>
                        </tr>
                    </tbody>
                </table>

                <div v-if="products.links && products.links.length > 3" class="mt-6 flex justify-center">
                    <nav class="flex flex-wrap items-center justify-center gap-1">
                        <Link
                            v-for="link in products.links"
                            :key="`${link.label}-${link.active}`"
                            :href="link.url || '#'"
                            :class="[
                                'px-3 py-2 text-sm rounded-xl font-semibold transition-colors',
                                link.active ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100',
                            ]"
                            v-html="link.label"
                        />
                    </nav>
                </div>
            </CardContent>
        </Card>

        <Card v-if="batch_tracking_enabled && batches" class="border-0 shadow-lg border-amber-100">
            <CardHeader>
                <CardTitle class="flex items-center gap-2 text-lg">
                    <Layers class="h-5 w-5 text-amber-700" />
                    Stok per batch / lot
                </CardTitle>
                <CardDescription>
                    Hanya batch dengan sisa stok &gt; 0. Status peringatan memakai ambang
                    <strong>{{ batch_expiry_warn_days }} hari</strong> dari pengaturan toko.
                </CardDescription>
            </CardHeader>
            <CardContent class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b text-left text-slate-500">
                            <th class="pb-3 pr-4 font-medium">Produk</th>
                            <th class="pb-3 pr-4 font-medium">Kode batch</th>
                            <th class="pb-3 pr-4 font-medium text-right">Sisa</th>
                            <th class="pb-3 pr-4 font-medium">ED</th>
                            <th class="pb-3 pr-4 font-medium">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="b in batches.data"
                            :key="b.id"
                            class="border-b border-slate-100"
                            :class="{ 'bg-red-50/60': stockQty(b.quantity_remaining) < 0 }"
                        >
                            <td class="py-3 pr-4">
                                <span class="font-medium text-slate-900">{{ b.product_name }}</span>
                                <span v-if="b.unit" class="text-xs text-slate-500"> · {{ b.unit }}</span>
                            </td>
                            <td class="py-3 pr-4 font-mono text-xs">{{ b.batch_code || '—' }}</td>
                            <td class="py-3 pr-4 text-right tabular-nums">
                                <span :class="stockClass(b.quantity_remaining)">{{ formatQty(b.quantity_remaining) }}</span>
                                <Badge
                                    v-if="stockQty(b.quantity_remaining) < 0"
                                    class="ml-2 text-[10px] font-bold bg-red-100 text-red-700"
                                >
                                    Stok negatif
                                </Badge>
                            </td>
                            <td class="py-3 pr-4 whitespace-nowrap">{{ formatDate(b.expiry_date) }}</td>
                            <td class="py-3 pr-4">
                                <Badge :variant="statusVariant(b.expiry_status)" class="text-xs">
                                    {{ statusLabel(b.expiry_status) }}
                                </Badge>
                            </td>
                        </tr>
                        <tr v-if="!batches.data?.length">
                            <td colspan="5" class="py-10 text-center text-slate-500">
                                Tidak ada batch berstok. Tambah batch dari pembelian atau produk.
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div v-if="batches.links && batches.links.length > 3" class="mt-6 flex justify-center">
                    <nav class="flex flex-wrap items-center justify-center gap-1">
                        <Link
                            v-for="link in batches.links"
                            :key="`${link.label}-${link.active}`"
                            :href="link.url || '#'"
                            :class="[
                                'px-3 py-2 text-sm rounded-xl font-semibold transition-colors',
                                link.active ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100',
                            ]"
                            v-html="link.label"
                        />
                    </nav>
                </div>

                <Button as="a" href="/products" variant="outline" class="mt-6 w-full sm:w-auto rounded-xl">
                    Kelola produk &amp; batch
                </Button>
            </CardContent>
        </Card>

        <Card v-else-if="!batch_tracking_enabled" class="border border-dashed border-slate-200 bg-slate-50/50">
            <CardContent class="py-8 px-6 text-center text-sm text-slate-600">
                <p class="font-medium text-slate-800">Laporan per batch belum aktif</p>
                <p class="mt-2 max-w-md mx-auto">
                    Aktifkan “lacak batch &amp; tanggal kedaluwarsa” di
                    <Link href="/settings" class="text-primary font-semibold underline underline-offset-2">Pengaturan toko</Link>
                    untuk melihat stok per lot dan status ED.
                </p>
            </CardContent>
        </Card>
    </AdminLayout>
</template>
