<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { PAYMENT_METHODS, formatDate, rupiah } from '@/composables/useReceivables';
import { useForm } from '@inertiajs/vue3';
import { Loader2 } from 'lucide-vue-next';
import { computed, watch } from 'vue';

/**
 * Form terima pembayaran piutang. Dipakai per bon (submitUrl /receivable-payments)
 * maupun per pelanggan (dialokasikan ke bon dengan jatuh tempo paling awal).
 */
interface Bill {
    id: number;
    transaction_number: string;
    outstanding: number;
    debt_due_at: string | null;
}

const props = defineProps<{
    open: boolean;
    title: string;
    outstanding: number;
    submitUrl: string;
    /** Data tambahan yang ikut dikirim (mis. transaction_id). */
    extra?: Record<string, unknown>;
    /** Bon yang belum lunas, urut alokasi — untuk pratinjau pembagian uang. */
    bills?: Bill[];
}>();
const emit = defineEmits<{ close: [] }>();

const today = () => new Date().toISOString().slice(0, 10);
const form = useForm({
    amount: 0 as number,
    payment_method: 'cash',
    received_at: today(),
    reference_number: '',
    notes: '',
});

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        form.reset();
        form.clearErrors();
        form.amount = Math.round(props.outstanding);
        form.received_at = today();
    },
);

/** Pratinjau: uang dibagi ke bon dengan jatuh tempo paling awal dulu. */
const allocation = computed(() => {
    let left = Number(form.amount) || 0;
    return (props.bills ?? []).map((b) => {
        const pay = Math.max(0, Math.min(left, b.outstanding));
        left -= pay;
        return { ...b, pay, settled: pay > 0 && pay >= b.outstanding - 0.009 };
    });
});
const overpay = computed(() => Math.max(0, (Number(form.amount) || 0) - props.outstanding));

function submit() {
    form.transform((data) => ({ ...data, ...(props.extra ?? {}), idempotency_key: `rp-${Date.now()}` })).post(props.submitUrl, {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    });
}
</script>

<template>
    <Dialog :open="open" @update:open="(v) => !v && !form.processing && emit('close')">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{ title }}</DialogTitle>
                <DialogDescription>Sisa utang {{ rupiah(outstanding) }}</DialogDescription>
            </DialogHeader>

            <form id="receivable-payment-form" class="space-y-4" @submit.prevent="submit">
                <div class="space-y-1.5">
                    <label for="rp_amount" class="text-sm font-semibold text-slate-700">Jumlah dibayar</label>
                    <input
                        id="rp_amount"
                        v-model.number="form.amount"
                        type="number"
                        min="1"
                        step="any"
                        required
                        class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-base font-semibold focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none"
                    />
                    <div class="flex flex-wrap gap-1.5">
                        <button
                            type="button"
                            class="rounded-lg border border-slate-200 px-2.5 py-1 text-xs font-semibold text-slate-600 hover:bg-slate-50"
                            @click="form.amount = Math.round(outstanding)"
                        >
                            Lunasi semua
                        </button>
                        <button
                            v-for="n in [50000, 100000, 200000].filter((v) => v < outstanding)"
                            :key="n"
                            type="button"
                            class="rounded-lg border border-slate-200 px-2.5 py-1 text-xs font-semibold text-slate-600 hover:bg-slate-50"
                            @click="form.amount = n"
                        >
                            {{ rupiah(n) }}
                        </button>
                    </div>
                    <p v-if="form.errors.amount" class="text-sm text-red-600">{{ form.errors.amount }}</p>
                    <p v-else-if="overpay > 0" class="text-xs text-amber-700">
                        Lebih {{ rupiah(overpay) }} dari sisa utang; kelebihannya tidak dicatat.
                    </p>
                </div>

                <div class="space-y-1.5">
                    <span class="text-sm font-semibold text-slate-700">Metode bayar</span>
                    <div class="grid grid-cols-3 gap-2" role="radiogroup" aria-label="Metode bayar">
                        <button
                            v-for="m in PAYMENT_METHODS"
                            :key="m.value"
                            type="button"
                            role="radio"
                            :aria-checked="form.payment_method === m.value"
                            class="rounded-xl border px-3 py-2 text-sm font-semibold transition"
                            :class="
                                form.payment_method === m.value
                                    ? 'border-primary bg-primary/5 text-primary'
                                    : 'border-slate-200 text-slate-600 hover:border-slate-300'
                            "
                            @click="form.payment_method = m.value"
                        >
                            {{ m.label }}
                        </button>
                    </div>
                    <p v-if="form.payment_method === 'cash'" class="text-xs text-slate-500">
                        Uang tunai masuk ke laci kasir shift Anda yang sedang buka.
                    </p>
                    <p v-if="form.errors.payment_method" class="text-sm text-red-600">{{ form.errors.payment_method }}</p>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="space-y-1.5">
                        <label for="rp_date" class="text-sm font-semibold text-slate-700">Tanggal bayar</label>
                        <input
                            id="rp_date"
                            v-model="form.received_at"
                            type="date"
                            required
                            class="h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none"
                        />
                    </div>
                    <div class="space-y-1.5">
                        <label for="rp_ref" class="text-sm font-semibold text-slate-700"
                            >No. referensi <span class="font-normal text-slate-400">(opsional)</span></label
                        >
                        <input
                            id="rp_ref"
                            v-model="form.reference_number"
                            type="text"
                            maxlength="255"
                            class="h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none"
                        />
                    </div>
                </div>

                <div class="space-y-1.5">
                    <label for="rp_notes" class="text-sm font-semibold text-slate-700"
                        >Catatan <span class="font-normal text-slate-400">(opsional)</span></label
                    >
                    <input
                        id="rp_notes"
                        v-model="form.notes"
                        type="text"
                        maxlength="1000"
                        class="h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none"
                    />
                </div>

                <div v-if="bills && bills.length > 1" class="rounded-xl bg-slate-50 p-3">
                    <p class="mb-2 text-xs font-semibold text-slate-600">Dibagi ke bon dengan jatuh tempo paling awal</p>
                    <ul class="space-y-1 text-xs">
                        <li
                            v-for="b in allocation"
                            :key="b.id"
                            class="flex items-center justify-between gap-2"
                            :class="b.pay > 0 ? 'text-slate-700' : 'text-slate-400'"
                        >
                            <span class="min-w-0 truncate">
                                {{ b.transaction_number }}
                                <span class="text-slate-400">· {{ b.debt_due_at ? formatDate(b.debt_due_at) : 'tanpa tempo' }}</span>
                            </span>
                            <span class="shrink-0 font-semibold">
                                {{ b.pay > 0 ? rupiah(b.pay) : '—' }}
                                <span v-if="b.settled" class="ml-1 rounded bg-emerald-100 px-1.5 py-0.5 text-[10px] text-emerald-700">lunas</span>
                            </span>
                        </li>
                    </ul>
                </div>
            </form>

            <DialogFooter class="gap-2">
                <Button type="button" variant="ghost" :disabled="form.processing" @click="emit('close')">Batal</Button>
                <Button type="submit" form="receivable-payment-form" :disabled="form.processing || !(Number(form.amount) > 0)">
                    <Loader2 v-if="form.processing" class="mr-1 h-4 w-4 animate-spin" />
                    Simpan pembayaran
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
