<script setup lang="ts">
import { Button } from '@/components/ui/button';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { modalError } from '@/lib/swal';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { AlertTriangle, ArrowLeft, Info, Loader2, Package, ScanBarcode, Search, Send, Trash2 } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

interface ProductOpt {
    id: number;
    name: string;
    sku: string | null;
    barcode: string | null;
    unit: string | null;
    stock_quantity: string | number;
    allows_decimal: boolean;
}

const props = withDefaults(
    defineProps<{
        sourceStore: { id: number; name: string; organization_name?: string | null };
        destStores: Array<{ id: number; name: string }>;
        products: ProductOpt[];
        canSendNow?: boolean;
    }>(),
    { canSendNow: false },
);

const fmtQty = (val: string | number) => {
    const num = Number(val);
    return Number.isInteger(num) ? num.toLocaleString('id-ID') : num.toLocaleString('id-ID', { maximumFractionDigits: 4 });
};

interface LineDraft {
    from_product_id: number;
    quantity: number | '';
}

const form = useForm({
    to_store_id: (props.destStores[0]?.id ?? '') as number | '',
    notes: '',
    lines: [] as LineDraft[],
    send_now: false,
});

const productById = (id: number) => props.products.find((p) => p.id === id);

// ── Cari / scan produk ──────────────────────────────────────────────────
const search = ref('');
const searchRef = ref<HTMLInputElement | null>(null);
const results = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (!q) return [];
    return props.products
        .filter((p) => p.name.toLowerCase().includes(q) || (p.sku ?? '').toLowerCase().includes(q) || (p.barcode ?? '').toLowerCase() === q)
        .slice(0, 8);
});

function addProduct(p: ProductOpt) {
    const existing = form.lines.find((l) => l.from_product_id === p.id);
    if (existing) {
        existing.quantity = (Number(existing.quantity) || 0) + 1;
    } else {
        form.lines.push({ from_product_id: p.id, quantity: 1 });
    }
    search.value = '';
    searchRef.value?.focus();
}

/** Enter: cocok persis dengan barcode/SKU (scanner USB) → langsung tambah; selain itu ambil hasil teratas. */
function onSearchEnter() {
    const q = search.value.trim().toLowerCase();
    if (!q) return;
    const exact = props.products.find((p) => (p.barcode ?? '').toLowerCase() === q || (p.sku ?? '').toLowerCase() === q);
    const pick = exact ?? results.value[0];
    if (pick) addProduct(pick);
}

function removeLine(idx: number) {
    form.lines.splice(idx, 1);
}

const overStock = (l: LineDraft) => {
    const p = productById(l.from_product_id);
    return p ? Number(l.quantity) > Number(p.stock_quantity) : false;
};
const hasOverStock = computed(() => form.lines.some(overStock));

// ── Pasangan produk di outlet tujuan (SKU / barcode sama) ───────────────
const matchHint = ref<Record<number, { ok: boolean; text: string }>>({});
async function refreshMatch(productId: number) {
    const toId = form.to_store_id;
    if (!toId) return;
    try {
        const q = new URLSearchParams({ to_store_id: String(toId), from_product_id: String(productId) });
        const res = await fetch(`/stock-transfers/match?${q.toString()}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!res.ok) throw new Error();
        const data = (await res.json()) as { matched: boolean; to_product?: { name: string; stock_quantity: string } };
        matchHint.value[productId] =
            data.matched && data.to_product
                ? { ok: true, text: `Masuk ke "${data.to_product.name}" · stok tujuan ${fmtQty(data.to_product.stock_quantity)}` }
                : { ok: false, text: 'Belum ada produk dengan SKU/barcode yang sama di outlet tujuan.' };
    } catch {
        matchHint.value[productId] = { ok: false, text: 'Gagal memeriksa produk di outlet tujuan.' };
    }
}
watch(
    () => [form.to_store_id, form.lines.map((l) => l.from_product_id).join(',')],
    () => {
        matchHint.value = {};
        form.lines.forEach((l) => void refreshMatch(l.from_product_id));
    },
);
const hasUnmatched = computed(() => form.lines.some((l) => matchHint.value[l.from_product_id]?.ok === false));

function submit(sendNow: boolean) {
    if (!form.to_store_id) {
        void modalError('Data belum lengkap', 'Pilih outlet tujuan.');
        return;
    }
    if (form.lines.length === 0) {
        void modalError('Belum ada barang', 'Cari atau scan barang yang akan dikirim.');
        return;
    }
    const bad = form.lines.findIndex((l) => !(Number(l.quantity) > 0));
    if (bad >= 0) {
        void modalError('Jumlah belum diisi', `Isi jumlah untuk ${productById(form.lines[bad].from_product_id)?.name ?? 'barang'}.`);
        return;
    }
    if (sendNow && hasOverStock.value) {
        void modalError('Stok tidak cukup', 'Kurangi jumlah barang yang melebihi stok, atau simpan sebagai draft dulu.');
        return;
    }
    form.send_now = sendNow;
    form.transform((data) => ({
        to_store_id: Number(data.to_store_id),
        notes: data.notes || null,
        send_now: sendNow,
        lines: data.lines.map((l) => ({ from_product_id: l.from_product_id, quantity: l.quantity })),
    })).post('/stock-transfers', {
        preserveScroll: true,
        onError: async (errors) => {
            const first = Object.values(errors)[0];
            await modalError('Transfer gagal', typeof first === 'string' ? first : 'Periksa kembali formulir.');
        },
    });
}

const inputClass =
    'h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none';
</script>

<template>
    <Head title="Transfer stok baru" />

    <AdminLayout title="Transfer stok baru">
        <div class="mx-auto max-w-3xl space-y-4">
            <Link href="/stock-transfers" class="inline-flex items-center gap-1.5 text-sm font-semibold text-slate-500 hover:text-slate-800">
                <ArrowLeft class="h-4 w-4" /> Kembali ke transfer stok
            </Link>

            <section class="rounded-2xl border border-slate-200 bg-white p-5">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1.5">
                        <span class="text-sm font-semibold text-slate-700">Dari</span>
                        <p class="flex h-10 items-center rounded-xl bg-slate-50 px-3 text-sm font-semibold text-slate-800">{{ sourceStore.name }}</p>
                    </div>
                    <div class="space-y-1.5">
                        <label for="to_store" class="text-sm font-semibold text-slate-700">Ke outlet</label>
                        <select id="to_store" v-model="form.to_store_id" :class="inputClass">
                            <option value="" disabled>Pilih outlet tujuan</option>
                            <option v-for="s in destStores" :key="s.id" :value="s.id">{{ s.name }}</option>
                        </select>
                        <p v-if="form.errors.to_store_id" class="text-sm text-red-600">{{ form.errors.to_store_id }}</p>
                    </div>
                </div>
                <div class="mt-4 space-y-1.5">
                    <label for="notes" class="text-sm font-semibold text-slate-700"
                        >Catatan <span class="font-normal text-slate-400">(opsional)</span></label
                    >
                    <input id="notes" v-model="form.notes" type="text" :class="inputClass" placeholder="Mis. restock akhir pekan" />
                </div>
            </section>

            <section class="rounded-2xl border border-slate-200 bg-white">
                <div class="border-b border-slate-100 p-4">
                    <h2 class="text-base font-bold text-slate-900">Barang yang dikirim</h2>
                    <div class="relative mt-3">
                        <Search class="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-slate-400" />
                        <input
                            ref="searchRef"
                            v-model="search"
                            type="search"
                            autocomplete="off"
                            data-barcode-input="true"
                            placeholder="Cari nama / SKU, atau scan barcode"
                            class="h-11 w-full rounded-xl border border-slate-200 bg-white pr-10 pl-9 text-sm focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none"
                            @keydown.enter.prevent="onSearchEnter"
                        />
                        <ScanBarcode class="pointer-events-none absolute top-1/2 right-3 h-4 w-4 -translate-y-1/2 text-slate-300" />
                        <ul
                            v-if="results.length"
                            class="absolute top-full right-0 left-0 z-20 mt-1 overflow-hidden rounded-xl border border-slate-200 bg-white"
                        >
                            <li v-for="p in results" :key="p.id">
                                <button
                                    type="button"
                                    class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm hover:bg-slate-50"
                                    @click="addProduct(p)"
                                >
                                    <span class="min-w-0">
                                        <span class="block truncate font-semibold text-slate-800">{{ p.name }}</span>
                                        <span v-if="p.sku" class="block text-xs text-slate-400">{{ p.sku }}</span>
                                    </span>
                                    <span class="shrink-0 text-xs" :class="Number(p.stock_quantity) > 0 ? 'text-slate-500' : 'text-red-600'">
                                        stok {{ fmtQty(p.stock_quantity) }} {{ p.unit }}
                                    </span>
                                </button>
                            </li>
                        </ul>
                        <p
                            v-else-if="search.trim()"
                            class="absolute top-full right-0 left-0 z-20 mt-1 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-500"
                        >
                            Tidak ditemukan.
                        </p>
                    </div>
                    <p class="mt-2 flex items-start gap-1.5 text-xs text-slate-500">
                        <Info class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                        Hanya produk yang dihitung stoknya. Produk bervarian dan paket belum bisa ditransfer.
                    </p>
                </div>

                <div v-if="!form.lines.length" class="px-4 py-10 text-center">
                    <Package class="mx-auto mb-2 h-8 w-8 text-slate-300" />
                    <p class="text-sm text-slate-500">Belum ada barang. Cari atau scan barang di atas.</p>
                </div>
                <ul v-else class="divide-y divide-slate-100">
                    <li v-for="(line, idx) in form.lines" :key="line.from_product_id" class="px-4 py-3">
                        <div class="flex items-start gap-3">
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-semibold text-slate-900">{{ productById(line.from_product_id)?.name }}</p>
                                <p class="text-xs text-slate-500">
                                    Stok {{ fmtQty(productById(line.from_product_id)?.stock_quantity ?? 0) }}
                                    {{ productById(line.from_product_id)?.unit }}
                                </p>
                                <p
                                    v-if="matchHint[line.from_product_id]?.text"
                                    class="mt-0.5 text-xs"
                                    :class="matchHint[line.from_product_id]?.ok ? 'text-emerald-700' : 'text-amber-700'"
                                >
                                    {{ matchHint[line.from_product_id]?.text }}
                                </p>
                            </div>
                            <div class="flex shrink-0 items-center gap-1.5">
                                <input
                                    v-model.number="line.quantity"
                                    type="number"
                                    min="0"
                                    step="any"
                                    :aria-label="`Jumlah ${productById(line.from_product_id)?.name}`"
                                    class="h-10 w-24 rounded-xl border bg-white px-2 text-right text-sm font-semibold focus:ring-2 focus:outline-none"
                                    :class="
                                        overStock(line)
                                            ? 'border-red-300 focus:ring-red-200'
                                            : 'border-slate-200 focus:border-primary focus:ring-primary/20'
                                    "
                                />
                                <span class="w-10 text-xs text-slate-500">{{ productById(line.from_product_id)?.unit }}</span>
                                <button
                                    type="button"
                                    class="rounded-lg p-2 text-slate-400 hover:bg-red-50 hover:text-red-600"
                                    :aria-label="`Hapus ${productById(line.from_product_id)?.name}`"
                                    @click="removeLine(idx)"
                                >
                                    <Trash2 class="h-4 w-4" />
                                </button>
                            </div>
                        </div>
                        <p v-if="overStock(line)" class="mt-1 flex items-center gap-1 text-xs font-semibold text-red-600">
                            <AlertTriangle class="h-3.5 w-3.5" /> Melebihi stok yang ada
                        </p>
                        <p v-if="form.errors[`lines.${idx}.quantity`]" class="text-xs text-red-600">{{ form.errors[`lines.${idx}.quantity`] }}</p>
                        <p v-if="form.errors[`lines.${idx}.from_product_id`]" class="text-xs text-red-600">
                            {{ form.errors[`lines.${idx}.from_product_id`] }}
                        </p>
                    </li>
                </ul>
                <p v-if="form.errors.lines" class="px-4 pb-3 text-sm text-red-600">{{ form.errors.lines }}</p>
            </section>

            <p v-if="hasUnmatched" class="rounded-xl bg-amber-50 px-4 py-3 text-xs text-amber-800">
                Ada barang yang belum punya pasangan di outlet tujuan. Samakan SKU atau barcode-nya di outlet tujuan agar transfer bisa disimpan.
            </p>

            <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <Button as-child variant="ghost"><Link href="/stock-transfers">Batal</Link></Button>
                <Button type="button" variant="outline" :disabled="form.processing" @click="submit(false)">
                    <Loader2 v-if="form.processing && !form.send_now" class="mr-1 h-4 w-4 animate-spin" />
                    Simpan draft
                </Button>
                <Button v-if="canSendNow" type="button" :disabled="form.processing" @click="submit(true)">
                    <Loader2 v-if="form.processing && form.send_now" class="mr-1 h-4 w-4 animate-spin" />
                    <Send v-else class="mr-1 h-4 w-4" />
                    Kirim sekarang
                </Button>
            </div>
            <p class="text-right text-xs text-slate-500">
                <template v-if="canSendNow">"Kirim sekarang" langsung mengurangi stok {{ sourceStore.name }}. Draft belum mengubah stok.</template>
                <template v-else>Draft belum mengubah stok; pengelola toko akan mengirimnya.</template>
            </p>
        </div>
    </AdminLayout>
</template>
