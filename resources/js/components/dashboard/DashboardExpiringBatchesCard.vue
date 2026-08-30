<script setup lang="ts">
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Hourglass } from 'lucide-vue-next';

defineProps<{
    batches?: Array<{
        id: number;
        product_id: number;
        product_name: string;
        batch_code: string | null;
        quantity_remaining: number;
        expiry_date: string;
        expiry_status?: 'expired' | 'warning';
    }>;
    batchExpiryWarnDays?: number | null;
    description?: string;
}>();

const formatDateShort = (dateString: string) => {
    return new Date(dateString).toLocaleDateString('id-ID', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
};

const daysUntilExpiry = (dateString: string) => {
    const d = new Date(dateString);
    d.setHours(0, 0, 0, 0);
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    return Math.ceil((d.getTime() - today.getTime()) / (1000 * 60 * 60 * 24));
};
</script>

<template>
    <Card v-if="batches && batches.length > 0" class="shadow-lg border border-amber-100">
        <CardHeader class="pb-4">
            <div class="flex items-center justify-between gap-2">
                <div>
                    <CardTitle class="text-xl font-bold text-gray-900 flex items-center gap-2">
                        <Hourglass class="h-5 w-5 text-amber-600" />
                        Batch mendekati ED
                    </CardTitle>
                    <CardDescription class="text-gray-600">
                        {{ description ?? `ED dalam ${batchExpiryWarnDays ?? 30} hari atau sudah kadaluarsa` }}
                    </CardDescription>
                </div>
                <Badge variant="destructive" class="shrink-0">{{ batches.length }}</Badge>
            </div>
        </CardHeader>
        <CardContent class="space-y-3">
            <div
                v-for="row in batches"
                :key="row.id"
                :class="[
                    'flex flex-wrap items-start justify-between gap-2 p-3 rounded-2xl border',
                    row.expiry_status === 'expired'
                        ? 'border-red-200 bg-red-50/90'
                        : 'border-amber-200 bg-amber-50/80',
                ]"
            >
                <div class="min-w-0">
                    <p class="font-medium text-gray-900 truncate">{{ row.product_name }}</p>
                    <p class="text-xs text-gray-600 mt-0.5">
                        <span v-if="row.batch_code">Kode: {{ row.batch_code }} · </span>
                        Sisa: {{ row.quantity_remaining }}
                    </p>
                </div>
                <div class="text-right shrink-0">
                    <p class="text-sm font-semibold text-amber-900">{{ formatDateShort(row.expiry_date) }}</p>
                    <p class="text-xs text-amber-700">
                        {{ daysUntilExpiry(row.expiry_date) <= 0 ? 'Kadaluarsa' : `${daysUntilExpiry(row.expiry_date)} hari lagi` }}
                    </p>
                </div>
            </div>
            <slot />
        </CardContent>
    </Card>
</template>
