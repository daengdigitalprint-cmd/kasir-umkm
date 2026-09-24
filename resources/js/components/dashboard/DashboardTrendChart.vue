<script setup lang="ts">
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { TrendingUp, TrendingDown } from 'lucide-vue-next';
import { computed } from 'vue';
import {
    Chart as ChartJS,
    CategoryScale,
    LinearScale,
    PointElement,
    BarElement,
    Title,
    Tooltip,
    Filler,
} from 'chart.js';
import { Bar } from 'vue-chartjs';
import type { ChartOptions, TooltipItem } from 'chart.js';

ChartJS.register(CategoryScale, LinearScale, PointElement, BarElement, Title, Tooltip, Filler);

interface TrendDay {
    date: string;
    revenue: number;
    count: number;
}

const props = defineProps<{
    data: TrendDay[];
}>();

const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('id-ID', {
        style: 'decimal',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(amount);
};

const labels = computed(() =>
    props.data.map((d) =>
        new Date(d.date).toLocaleDateString('id-ID', { day: 'numeric', month: 'short' }),
    ),
);

const revenues = computed(() => props.data.map((d) => d.revenue));

// Growth: today vs yesterday
const growthPercent = computed(() => {
    if (props.data.length < 2) return null;
    const today = props.data[props.data.length - 1]?.revenue ?? 0;
    const yesterday = props.data[props.data.length - 2]?.revenue ?? 0;
    if (yesterday === 0) return null;
    return ((today - yesterday) / yesterday) * 100;
});

const totalRevenue = computed(() => props.data.reduce((sum, d) => sum + d.revenue, 0));
const totalTransactions = computed(() => props.data.reduce((sum, d) => sum + d.count, 0));

const chartData = computed(() => ({
    labels: labels.value,
    datasets: [
        {
            label: 'Omset',
            data: revenues.value,
            backgroundColor: (ctx: { chart: { ctx: CanvasRenderingContext2D } }) => {
                const gradient = ctx.chart.ctx.createLinearGradient(0, 0, 0, 200);
                gradient.addColorStop(0, 'rgba(16, 185, 129, 0.5)');
                gradient.addColorStop(1, 'rgba(16, 185, 129, 0.05)');
                return gradient;
            },
            borderRadius: 6,
            borderSkipped: false as const,
        },
    ],
}));

const chartOptions: ChartOptions<'bar'> = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: { display: false },
        tooltip: {
            callbacks: {
                label: (tooltipItem: TooltipItem<'bar'>) => `Rp ${formatCurrency(tooltipItem.parsed.y ?? 0)}`,
            },
        },
    },
    scales: {
        x: {
            grid: { display: false },
            ticks: { font: { size: 10 } },
        },
        y: {
            grid: { color: 'rgba(0,0,0,0.05)' },
            ticks: {
                font: { size: 10 },
                callback: (value: number | string) => {
                    const num = Number(value);
                    if (num >= 1_000_000) return `${(num / 1_000_000).toFixed(1)}jt`;
                    if (num >= 1_000) return `${(num / 1_000).toFixed(0)}rb`;
                    return value;
                },
            },
        },
    },
};
</script>

<template>
    <Card class="border-0 shadow-lg">
        <CardHeader class="pb-4">
            <div class="flex items-center justify-between">
                <div>
                    <CardTitle class="text-xl font-bold text-gray-900">Tren Penjualan 7 Hari</CardTitle>
                    <CardDescription class="text-gray-600">
                        Total {{ formatCurrency(totalRevenue) }} &middot; {{ totalTransactions }} transaksi
                    </CardDescription>
                </div>
                <Badge
                    v-if="growthPercent !== null"
                    :class="growthPercent >= 0
                        ? 'bg-emerald-100 text-emerald-700 border-0'
                        : 'bg-red-100 text-red-700 border-0'"
                    class="flex items-center gap-1"
                >
                    <component :is="growthPercent >= 0 ? TrendingUp : TrendingDown" class="h-3 w-3" />
                    {{ growthPercent >= 0 ? '+' : '' }}{{ growthPercent.toFixed(1) }}%
                </Badge>
            </div>
        </CardHeader>
        <CardContent>
            <div v-if="totalTransactions > 0" style="height: 220px">
                <Bar :data="chartData" :options="chartOptions" />
            </div>
            <div v-else class="flex flex-col items-center justify-center py-12" style="height: 220px">
                <div class="p-3 bg-gray-100 rounded-full w-12 h-12 mx-auto mb-3 flex items-center justify-center">
                    <TrendingUp class="h-6 w-6 text-gray-400" />
                </div>
                <p class="text-gray-500 text-sm">Belum ada penjualan dalam 7 hari terakhir</p>
            </div>
        </CardContent>
    </Card>
</template>
