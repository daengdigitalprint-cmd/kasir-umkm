<script setup lang="ts">
import ReportTabs from '@/components/ReportTabs.vue';
import { Card, CardContent } from '@/components/ui/card';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps<{
    movements: { data: any[]; links: any[] };
    summary: { quantity: number; count: number };
    filters: { date_from: string; date_to: string };
}>();
</script>
<template>
    <AdminLayout title="Laporan pemusnahan">
        <Head title="Laporan pemusnahan" />
        <ReportTabs active-tab="pemusnahan" />

        <div class="mb-6 grid grid-cols-2 gap-3 md:gap-4">
            <Card class="border-0 shadow-lg">
                <CardContent class="p-4 md:p-5">
                    <p class="text-xs font-medium text-slate-500">Total pemusnahan</p>
                    <p class="mt-1 text-lg font-black text-slate-900 tabular-nums md:text-2xl">{{ summary.count }}</p>
                </CardContent>
            </Card>
            <Card class="border-0 shadow-lg">
                <CardContent class="p-4 md:p-5">
                    <p class="text-xs font-medium text-slate-500">Jumlah unit</p>
                    <p class="mt-1 text-lg font-black text-slate-900 tabular-nums md:text-2xl">{{ summary.quantity }}</p>
                </CardContent>
            </Card>
        </div>

        <Card class="border-0 shadow-lg">
            <CardContent class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b text-left text-slate-500">
                            <th class="pr-4 pb-3 font-medium">Tanggal</th>
                            <th class="pr-4 pb-3 font-medium">Produk</th>
                            <th class="pr-4 pb-3 font-medium">Qty</th>
                            <th class="pb-3 font-medium">Catatan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in movements.data" :key="row.id" class="border-b border-slate-100">
                            <td class="py-3 pr-4">{{ row.created_at }}</td>
                            <td class="py-3 pr-4">{{ row.product?.name || '—' }}</td>
                            <td class="py-3 pr-4 tabular-nums">{{ Math.abs(Number(row.quantity_change)) }}</td>
                            <td class="py-3">{{ row.notes || '—' }}</td>
                        </tr>
                        <tr v-if="!movements.data.length">
                            <td colspan="4" class="py-10 text-center text-slate-500">Belum ada pemusnahan.</td>
                        </tr>
                    </tbody>
                </table>

                <div v-if="movements.links.length > 3" class="mt-6 flex flex-wrap justify-center gap-1">
                    <Link
                        v-for="link in movements.links"
                        :key="link.label"
                        :href="link.url || '#'"
                        class="rounded-xl px-3 py-2 text-sm font-semibold"
                        :class="link.active ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100'"
                        v-html="link.label"
                    />
                </div>
            </CardContent>
        </Card>
    </AdminLayout>
</template>
