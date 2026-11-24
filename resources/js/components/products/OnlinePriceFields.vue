<script setup lang="ts">
import { computed } from 'vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ONLINE_PRICE_CHANNELS, type OnlinePriceChannel, type OnlinePriceForm } from '@/lib/onlinePrices';

const props = defineProps<{
    /** Harga online per aplikasi; kosong = pakai harga toko. */
    modelValue: OnlinePriceForm;
    /** Harga jual toko, dipakai sebagai placeholder dan pembanding selisih. */
    storePrice: number;
    hasVariants?: boolean;
    errors?: Record<string, string | undefined>;
}>();

const emit = defineEmits<{ 'update:modelValue': [value: OnlinePriceForm] }>();

const formatRupiah = (n: number) => n.toLocaleString('id-ID', { maximumFractionDigits: 0 });

const storePriceNumber = computed(() => Math.max(0, Number(props.storePrice) || 0));

function display(channel: OnlinePriceChannel): string {
    const n = Number(props.modelValue[channel]);
    return Number.isFinite(n) && n > 0 ? formatRupiah(n) : '';
}

function update(channel: OnlinePriceChannel, raw: string | number) {
    const digits = String(raw).replace(/[^\d]/g, '');
    emit('update:modelValue', { ...props.modelValue, [channel]: digits ? Number(digits) : '' });
}

/** Selisih terhadap harga toko, mis. "+20% dari harga toko". */
function markupHint(channel: OnlinePriceChannel): string | null {
    const online = Number(props.modelValue[channel]);
    const base = storePriceNumber.value;
    if (!(online > 0) || !(base > 0)) return null;
    const percent = Math.round(((online - base) / base) * 1000) / 10;
    if (percent === 0) return 'Sama dengan harga toko';
    return `${percent > 0 ? '+' : ''}${percent.toLocaleString('id-ID')}% dari harga toko`;
}
</script>

<template>
    <div class="space-y-3 rounded-xl border border-orange-200 bg-orange-50/50 p-4">
        <div>
            <p class="text-sm font-semibold text-orange-900">Harga toko online</p>
            <p class="mt-0.5 text-xs text-orange-900/80">
                Harga yang dipakai kasir saat Sumber Pesanan-nya GoFood, GrabFood, atau ShopeeFood. Kosongkan bila sama dengan harga toko.
            </p>
        </div>
        <div class="grid gap-4 sm:grid-cols-3">
            <div v-for="channel in ONLINE_PRICE_CHANNELS" :key="channel.value" class="space-y-1.5">
                <Label :for="`online_price_${channel.value}`">{{ channel.label }}</Label>
                <Input
                    :id="`online_price_${channel.value}`"
                    :model-value="display(channel.value)"
                    type="text"
                    inputmode="numeric"
                    class="bg-white"
                    :placeholder="storePriceNumber > 0 ? formatRupiah(storePriceNumber) : 'Harga toko'"
                    @update:model-value="update(channel.value, $event)"
                />
                <p v-if="markupHint(channel.value)" class="text-xs text-orange-800">{{ markupHint(channel.value) }}</p>
                <p v-if="errors?.[`online_prices.${channel.value}`]" class="text-sm text-red-600">
                    {{ errors[`online_prices.${channel.value}`] }}
                </p>
            </div>
        </div>
        <p v-if="hasVariants" class="text-xs text-orange-900/80">
            Produk bervarian: harga online tiap varian diisi di tabel Varian. Harga di sini hanya berlaku untuk varian yang kolom Harga-nya dikosongkan.
        </p>
    </div>
</template>
