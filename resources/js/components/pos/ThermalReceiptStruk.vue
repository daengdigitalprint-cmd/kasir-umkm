<script setup lang="ts">
import { computed } from 'vue';
import {
    buildThermalReceiptInnerHtml,
    mergeReceiptConfig,
    type ReceiptConfig,
} from '@/lib/thermalReceipt';
import './thermal-struk-preview.css';

const props = defineProps<{
    transaction: Record<string, unknown>;
    /** Dari props toko (POS) bila transaksi belum punya receipt lengkap */
    storeReceiptFallback?: Partial<ReceiptConfig> | null;
    taxNameFallback?: string;
    pharmacyEnabled?: boolean;
}>();

const receiptMerged = computed(() =>
    mergeReceiptConfig(
        (props.transaction.receipt as Partial<ReceiptConfig> | undefined) ?? null,
        props.storeReceiptFallback ?? null,
    ),
);

const innerHtml = computed(() =>
    buildThermalReceiptInnerHtml(props.transaction, receiptMerged.value, {
        taxName: props.taxNameFallback ?? 'PPN',
        pharmacyEnabled: props.pharmacyEnabled ?? false,
    }),
);

const previewClass = computed(() =>
    receiptMerged.value.paper_width_mm === 58
        ? 'thermal-receipt-preview thermal-receipt-preview--58'
        : 'thermal-receipt-preview thermal-receipt-preview--80',
);
</script>

<template>
    <div :class="previewClass" v-html="innerHtml" />
</template>
