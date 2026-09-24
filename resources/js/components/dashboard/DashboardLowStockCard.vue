<script setup lang="ts">
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Package, AlertTriangle } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    products?: Array<{
        id: number;
        name: string;
        stock_quantity: number;
        min_stock_level: number;
    }>;
    lowStockCount?: number;
}>();

const fmtQty = (val: string | number) => {
    const num = Number(val);
    return Number.isInteger(num) ? num.toString() : num.toFixed(4).replace(/\.?0+$/, '');
};

// Show up to 8 items max
const visibleProducts = computed(() => (props.products ?? []).slice(0, 8));
const hiddenCount = computed(() => {
    const total = props.lowStockCount ?? 0;
    const shown = visibleProducts.value.length;
    return Math.max(0, total - shown);
});
</script>

<template>
    <Card class="border-0 shadow-lg">
        <CardHeader class="pb-3">
            <div class="flex items-center gap-3">
                <div class="flex items-center justify-center w-10 h-10 bg-orange-500/10 rounded-full shrink-0">
                    <AlertTriangle class="h-5 w-5 text-orange-600" />
                </div>
                <div class="flex-1 min-w-0">
                    <CardTitle class="text-base font-bold text-gray-900">Stok Rendah</CardTitle>
                    <CardDescription class="text-gray-500 text-xs">Produk yang perlu di-restock</CardDescription>
                </div>
                <Badge
                    v-if="lowStockCount"
                    variant="destructive"
                    class="h-6 min-w-6 flex items-center justify-center rounded-full px-1.5 text-xs"
                >
                    {{ lowStockCount }}
                </Badge>
            </div>
        </CardHeader>
        <CardContent class="pt-0">
            <div v-if="visibleProducts.length > 0" class="space-y-1.5">
                <div
                    v-for="product in visibleProducts"
                    :key="product.id"
                    class="flex items-center gap-3 p-2.5 bg-orange-50/70 rounded-xl border border-orange-100"
                >
                    <div class="min-w-0 flex-1">
                        <p class="font-medium text-gray-900 text-sm leading-snug truncate">{{ product.name }}</p>
                        <p class="text-xs text-gray-500 mt-0.5">
                            Stok: <span class="font-semibold text-orange-700">{{ fmtQty(product.stock_quantity) }}</span>
                            <span class="text-gray-400 mx-0.5">/</span>
                            Min: {{ fmtQty(product.min_stock_level) }}
                        </p>
                    </div>
                </div>
                <div v-if="hiddenCount > 0" class="text-center pt-2">
                    <a href="/products" class="text-xs font-medium text-primary hover:underline">
                        +{{ hiddenCount }} produk lainnya
                    </a>
                </div>
            </div>
            <div v-else class="text-center py-8">
                <div class="p-3 bg-green-100 rounded-full w-12 h-12 mx-auto mb-3 flex items-center justify-center">
                    <Package class="h-6 w-6 text-green-600" />
                </div>
                <p class="text-gray-500">Semua produk stoknya aman</p>
            </div>
        </CardContent>
    </Card>
</template>
