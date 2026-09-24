<script setup lang="ts">
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Clock } from 'lucide-vue-next';
import { computed } from 'vue';

interface PeakHour {
    hour: number;
    label: string;
    count: number;
    revenue: number;
}

const props = defineProps<{
    data: PeakHour[];
}>();

const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('id-ID', {
        style: 'decimal',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(amount);
};

const maxCount = computed(() => Math.max(1, ...props.data.map((d) => d.count)));

// Find peak hour(s)
const peakHour = computed(() => {
    const max = Math.max(...props.data.map((d) => d.count));
    if (max === 0) return null;
    return props.data.find((d) => d.count === max) ?? null;
});

const totalMonth = computed(() => {
    const count = props.data.reduce((sum, d) => sum + d.count, 0);
    const revenue = props.data.reduce((sum, d) => sum + d.revenue, 0);
    return { count, revenue };
});

function barWidth(count: number): string {
    const pct = (count / maxCount.value) * 100;
    return `${Math.max(pct, 2)}%`;
}
</script>

<template>
    <Card class="border-0 shadow-lg">
        <CardHeader class="pb-4">
            <div class="flex items-center justify-between">
                <div>
                    <CardTitle class="text-xl font-bold text-gray-900">Jam Ramai Bulan Ini</CardTitle>
                    <CardDescription class="text-gray-600">
                        {{ totalMonth.count }} transaksi &middot; Rp {{ formatCurrency(totalMonth.revenue) }}
                    </CardDescription>
                </div>
                <Badge
                    v-if="peakHour"
                    class="bg-orange-100 text-orange-700 border-0 flex items-center gap-1"
                >
                    <Clock class="h-3 w-3" />
                    {{ peakHour.label }}
                </Badge>
            </div>
        </CardHeader>
        <CardContent>
            <div v-if="peakHour" class="space-y-1.5 max-h-[240px] overflow-y-auto pr-1">
                <div
                    v-for="item in data"
                    :key="item.hour"
                    class="flex items-center gap-2"
                    :class="{ 'opacity-40': item.count === 0 }"
                >
                    <span class="text-xs text-gray-500 w-12 shrink-0 text-right">{{ item.label }}</span>
                    <div class="flex-1 h-7 bg-gray-100 rounded-lg overflow-hidden relative">
                        <div
                            class="h-full rounded-lg transition-all duration-300"
                            :class="item.hour === peakHour?.hour
                                ? 'bg-gradient-to-r from-orange-400 to-amber-400'
                                : 'bg-gradient-to-r from-blue-300 to-blue-400'"
                            :style="{ width: barWidth(item.count) }"
                        >
                            <span
                                v-if="item.count > 0"
                                class="absolute inset-0 flex items-center justify-end pr-2 text-[10px] font-bold text-white"
                            >
                                {{ item.count }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            <div v-else class="text-center py-8">
                <div class="p-3 bg-gray-100 rounded-full w-12 h-12 mx-auto mb-3 flex items-center justify-center">
                    <Clock class="h-6 w-6 text-gray-400" />
                </div>
                <p class="text-gray-500 text-sm">Belum ada transaksi bulan ini</p>
            </div>
        </CardContent>
    </Card>
</template>
