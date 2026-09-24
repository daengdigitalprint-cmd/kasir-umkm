<script setup lang="ts">
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Package } from 'lucide-vue-next';
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

interface TopProduct {
    id: number;
    name: string;
    quantity: number;
    revenue: number;
}

const props = defineProps<{
    data: TopProduct[];
}>();

const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('id-ID', {
        style: 'decimal',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(amount);
};

const fmtQty = (val: number) => {
    return Number.isInteger(val) ? val.toString() : val.toFixed(4).replace(/\.?0+$/, '');
};

const maxQty = computed(() => Math.max(1, ...props.data.map((d) => d.quantity)));

function barWidth(qty: number): string {
    const pct = (qty / maxQty.value) * 100;
    return `${Math.max(pct, 3)}%`;
}

const rankColors = [
    'bg-primary text-primary-foreground',
    'bg-violet-300 text-violet-900',
    'bg-violet-200 text-violet-700',
    'bg-violet-100 text-violet-600',
    'bg-violet-50 text-violet-500',
];
</script>

<template>
    <Card class="border-0 shadow-lg">
        <CardHeader class="pb-4">
            <div class="flex items-center justify-between">
                <div>
                    <CardTitle class="text-xl font-bold text-gray-900">Produk Terlaris Bulan Ini</CardTitle>
                    <CardDescription class="text-gray-600">Top 5 produk berdasarkan jumlah terjual</CardDescription>
                </div>
            </div>
        </CardHeader>
        <CardContent>
            <div v-if="data.length > 0" class="space-y-3">
                <div
                    v-for="(product, index) in data"
                    :key="product.id"
                    class="flex items-center gap-3"
                >
                    <div
                        class="flex items-center justify-center w-7 h-7 rounded-full text-xs font-bold shrink-0"
                        :class="rankColors[index] || 'bg-gray-200 text-gray-600'"
                    >
                        {{ index + 1 }}
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center justify-between gap-2 mb-1">
                            <p class="font-medium text-gray-900 text-sm truncate">{{ product.name }}</p>
                            <span class="text-xs font-bold text-gray-600 shrink-0">{{ fmtQty(product.quantity) }} pcs</span>
                        </div>
                        <div class="h-2 bg-gray-100 rounded-full overflow-hidden">
                            <div
                                class="h-full rounded-full bg-gradient-to-r from-violet-500 to-violet-400 transition-all duration-300"
                                :style="{ width: barWidth(product.quantity) }"
                            />
                        </div>
                    </div>
                    <p class="text-xs font-semibold text-gray-500 shrink-0 w-20 text-right">
                        Rp {{ formatCurrency(product.revenue) }}
                    </p>
                </div>
                <Link
                    href="/reports"
                    class="inline-flex items-center justify-center w-full px-3 py-2 text-xs font-medium text-primary bg-white border border-blue-200 rounded-lg hover:bg-blue-50 transition-colors"
                >
                    Lihat detail laporan
                </Link>
            </div>
            <div v-else class="text-center py-8">
                <div class="p-3 bg-gray-100 rounded-full w-12 h-12 mx-auto mb-3 flex items-center justify-center">
                    <Package class="h-6 w-6 text-gray-400" />
                </div>
                <p class="text-gray-500 text-sm">Belum ada penjualan bulan ini</p>
            </div>
        </CardContent>
    </Card>
</template>
