<script setup lang="ts">
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Package } from 'lucide-vue-next';

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
</script>

<template>
    <Card class="border-0 shadow-lg">
        <CardHeader class="pb-4">
            <div class="flex items-center justify-between">
                <div>
                    <CardTitle class="text-xl font-bold text-gray-900">Stok Rendah</CardTitle>
                    <CardDescription class="text-gray-600">Produk yang perlu di-restock</CardDescription>
                </div>
                <Badge v-if="lowStockCount" variant="destructive">
                    {{ lowStockCount }}
                </Badge>
            </div>
        </CardHeader>
        <CardContent>
            <div v-if="products && products.length > 0" class="space-y-4">
                <div
                    v-for="product in products"
                    :key="product.id"
                    class="flex items-center justify-between p-3 bg-orange-50 rounded-2xl border border-orange-200"
                >
                    <div>
                        <p class="font-medium text-gray-900">{{ product.name }}</p>
                        <p class="text-sm text-gray-500">
                            Stok: {{ fmtQty(product.stock_quantity) }} / Min: {{ fmtQty(product.min_stock_level) }}
                        </p>
                    </div>
                    <Badge variant="destructive" class="text-xs">Stok Rendah</Badge>
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
