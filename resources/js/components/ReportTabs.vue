<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useAllowsAdvancedRetail } from '@/composables/usePlanLimits';

defineProps<{
    activeTab: string;
}>();

const allowsAdvancedRetail = useAllowsAdvancedRetail();
const page = usePage();
const pharmacyEnabled = computed(() => Boolean((page.props as { pharmacy_enabled?: boolean }).pharmacy_enabled));
const accountingEnabled = computed(() => Boolean((page.props as { accounting_enabled?: boolean }).accounting_enabled));

type Tab = { label: string; href: string; key: string; financial?: boolean; advanced?: boolean; pharmacy?: boolean };

const tabs: Tab[] = [
    { label: 'Ringkasan', href: '/reports', key: 'ringkasan' },
    { label: 'Penjualan', href: '/reports/sales', key: 'penjualan' },
    { label: 'Pembelian', href: '/reports/purchases', key: 'pembelian' },
    { label: 'Stok', href: '/reports/inventory', key: 'stok' },
    { label: 'Pemusnahan', href: '/reports/destructions', key: 'pemusnahan', pharmacy: true },
    { label: 'Selisih stok', href: '/reports/stock-variance', key: 'selisih-stok', advanced: true },
    { label: 'Kartu stok', href: '/reports/stock-card', key: 'kartu-stok', advanced: true },
    { label: 'Produksi', href: '/reports/production', key: 'produksi' },
    { label: 'Shift kasir', href: '/reports/cash-shifts', key: 'shift', advanced: true },
    { label: 'Neraca', href: '/financial/balance-sheet', key: 'neraca', financial: true },
    { label: 'Rekonsiliasi', href: '/financial/reconciliation', key: 'rekonsiliasi', financial: true },
    { label: 'Perubahan Ekuitas', href: '/financial/statement-of-equity-changes', key: 'perubahan-ekuitas', financial: true },
    { label: 'Arus Kas Akuntansi', href: '/financial/cash-flow', key: 'arus-kas-akuntansi', financial: true },
    { label: 'Periode', href: '/financial/accounting-periods', key: 'periode-akuntansi', financial: true },
    { label: 'Jurnal', href: '/financial/journal', key: 'jurnal', financial: true },
    { label: 'Neraca saldo', href: '/financial/trial-balance', key: 'neraca-saldo', financial: true },
    { label: 'Laba rugi', href: '/financial/income-statement', key: 'laba-rugi', financial: true },
    { label: 'Rekap PPN', href: '/reports/tax/ppn-summary', key: 'ppn', financial: true },
    { label: 'CaLK', href: '/financial/calk', key: 'calk', financial: true },
    { label: 'Arus Kas', href: '/reports/cash-flow', key: 'cash-flow', financial: true },
    { label: 'Kas Masuk/Keluar', href: '/reports/cash-transactions', key: 'cash-transactions', advanced: true },
    { label: 'Utang Usaha', href: '/reports/aging-payables', key: 'aging-payables', financial: true },
];
</script>

<template>
    <div class="flex flex-wrap gap-2 mb-5">
        <template v-for="tab in tabs" :key="tab.key">
            <Link
                v-if="(!tab.advanced || allowsAdvancedRetail) && (!tab.pharmacy || pharmacyEnabled) && (!tab.financial || accountingEnabled)"
                :href="tab.href"
                class="inline-flex items-center rounded-xl px-4 py-2 text-sm font-semibold"
                :class="activeTab === tab.key
                    ? 'bg-slate-900 text-white'
                    : tab.financial
                        ? 'border border-violet-200 bg-violet-50 text-violet-900 hover:bg-violet-100'
                        : 'border border-slate-200 bg-white text-slate-700 hover:bg-slate-50'"
            >
                {{ tab.label }}
            </Link>
        </template>
    </div>
</template>
