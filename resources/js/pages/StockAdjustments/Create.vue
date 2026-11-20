<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref } from 'vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { ArrowLeft, ArrowRight, Boxes, Plus, Search, Trash2 } from 'lucide-vue-next';

interface VariantRow {
    id: number;
    name: string;
    sku: string | null;
    stock_quantity: string;
}

interface ProductRow {
    id: number;
    name: string;
    sku: string | null;
    barcode: string | null;
    unit: string | null;
    stock_quantity: string;
    average_cost: string | null;
    cost_price: string | null;
    allows_decimal: boolean;
    has_variants: boolean;
    variants: VariantRow[];
}

type Direction = 'decrease' | 'increase' | 'set';

const props = defineProps<{
    products: ProductRow[];
    reasonLabels: Record<string, string>;
    reasonDirections: Record<string, Direction>;
    batchTrackingEnabled?: boolean;
    pharmacyFieldsEnabled?: boolean;
    /** Pembuat bukan admin/manajer/pemilik: dokumen menunggu persetujuan. */
    needsReview?: boolean;
}>();

interface LineDraft {
    product_id: number;
    product_variant_id: number | null;
    reason_code: string;
    quantity: string;
    batch_code: string;
    expiry_date: string;
    line_notes: string;
}

const form = useForm({
    reason_code: '',
    notes: '',
    lines: [] as LineDraft[],
});

const reasonHints: Record<Direction, string> = {
    decrease: 'stok berkurang',
    increase: 'stok bertambah',
    set: 'isi stok sebenarnya',
};

const directionOf = (reason: string): Direction => props.reasonDirections[reason] ?? 'decrease';

function chooseReason(code: string) {
    const previous = form.reason_code;
    form.reason_code = code;
    // Baris yang masih memakai alasan sebelumnya ikut berganti.
    for (const line of form.lines) {
        if (!line.reason_code || line.reason_code === previous) line.reason_code = code;
    }
    nextTick(() => searchInput.value?.focus());
}

// ── Pilih produk ──
interface PickOption {
    key: string;
    product: ProductRow;
    variant: VariantRow | null;
    label: string;
    code: string | null;
    stock: string;
}

const pickOptions = computed((): PickOption[] => {
    const out: PickOption[] = [];
    for (const p of props.products) {
        if (p.has_variants) {
            for (const v of p.variants ?? []) {
                out.push({ key: `${p.id}:${v.id}`, product: p, variant: v, label: `${p.name} — ${v.name}`, code: v.sku ?? p.sku, stock: v.stock_quantity });
            }
        } else {
            out.push({ key: `${p.id}:0`, product: p, variant: null, label: p.name, code: p.sku ?? p.barcode, stock: p.stock_quantity });
        }
    }
    return out;
});

const optionMap = computed(() => new Map(pickOptions.value.map((o) => [o.key, o])));
const keyOf = (l: { product_id: number; product_variant_id: number | null }) => `${l.product_id}:${l.product_variant_id ?? 0}`;
const optionOf = (line: LineDraft) => optionMap.value.get(keyOf(line));

const search = ref('');
const searchInput = ref<HTMLInputElement | null>(null);
const pickerOpen = ref(false);
const scanMessage = ref('');

const filteredOptions = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (!q) return pickOptions.value.slice(0, 30);
    return pickOptions.value
        .filter((o) =>
            [o.label, o.product.sku, o.product.barcode, o.variant?.sku].some((t) => (t ?? '').toLowerCase().includes(q)),
        )
        .slice(0, 30);
});

const qtyInputs = new Map<string, HTMLInputElement>();
function setQtyInput(key: string, el: unknown) {
    if (el instanceof HTMLInputElement) qtyInputs.set(key, el);
    else qtyInputs.delete(key);
}

async function addOption(option: PickOption) {
    search.value = '';
    pickerOpen.value = false;
    scanMessage.value = '';

    const existing = form.lines.find((l) => keyOf(l) === option.key && l.reason_code === form.reason_code);
    if (!existing) {
        form.lines.push({
            product_id: option.product.id,
            product_variant_id: option.variant?.id ?? null,
            reason_code: form.reason_code,
            quantity: '',
            batch_code: '',
            expiry_date: '',
            line_notes: '',
        });
    } else if (directionOf(existing.reason_code) !== 'set') {
        // Pindai produk yang sama lagi = tambah 1.
        existing.quantity = String((Number(existing.quantity) || 0) + 1);
    }

    await nextTick();
    const input = qtyInputs.get(option.key);
    input?.focus();
    input?.select();
}

/** Enter di kolom cari: barcode/SKU persis → langsung ditambahkan. */
function onSearchEnter() {
    const q = search.value.trim().toLowerCase();
    if (!q) return;
    const exact = pickOptions.value.filter((o) =>
        [o.product.barcode, o.product.sku, o.variant?.sku].some((c) => (c ?? '').toLowerCase() === q),
    );
    const target = exact.length === 1 ? exact[0] : filteredOptions.value.length === 1 ? filteredOptions.value[0] : null;
    if (target) addOption(target);
    else scanMessage.value = filteredOptions.value.length === 0 ? `Produk «${search.value.trim()}» tidak ditemukan.` : 'Pilih salah satu dari daftar.';
}

function onSearchBlur() {
    // Beri waktu klik pada pilihan sebelum daftar ditutup.
    setTimeout(() => (pickerOpen.value = false), 150);
}

function onQtyEnter() {
    searchInput.value?.focus();
}

function removeLine(index: number) {
    form.lines.splice(index, 1);
}

// ── Hitungan per baris ──
function newStock(line: LineDraft): number | null {
    const option = optionOf(line);
    if (!option || String(line.quantity).trim() === '') return null;
    const qty = Number(line.quantity);
    if (Number.isNaN(qty) || qty < 0) return null;
    const stock = Number(option.stock);
    const dir = directionOf(line.reason_code);
    if (dir === 'decrease') return stock - qty;
    if (dir === 'increase') return stock + qty;
    return qty;
}

function change(line: LineDraft): number {
    const option = optionOf(line);
    const next = newStock(line);
    if (!option || next === null) return 0;
    return next - Number(option.stock);
}

function unitCost(option: PickOption | undefined) {
    if (!option) return 0;
    return Number(option.product.average_cost ?? option.product.cost_price ?? 0);
}

const lineValue = (line: LineDraft) => change(line) * unitCost(optionOf(line));

function lineProblem(line: LineDraft): string | null {
    const next = newStock(line);
    if (String(line.quantity).trim() === '') return 'Isi jumlahnya';
    if (next === null) return 'Jumlah tidak valid';
    if (next < 0) return `Stok hanya ${formatQty(Number(optionOf(line)?.stock ?? 0))}`;
    if (Math.abs(change(line)) < 0.00001) return 'Tidak ada perubahan';
    return null;
}

const readyLines = computed(() => form.lines.filter((l) => lineProblem(l) === null));
const blockingLines = computed(() => form.lines.filter((l) => lineProblem(l) !== null));
const totalValue = computed(() => form.lines.reduce((s, l) => s + (lineProblem(l) ? 0 : lineValue(l)), 0));

const qtyLabel = (line: LineDraft) =>
    ({ decrease: 'Berkurang', increase: 'Bertambah', set: 'Stok sebenarnya' })[directionOf(line.reason_code)];

// ── Format ──
const formatQty = (n: number) => {
    const rounded = Math.round(n * 10000) / 10000;
    return Number.isInteger(rounded) ? String(rounded) : rounded.toLocaleString('id-ID', { maximumFractionDigits: 4 });
};

const formatRupiah = (n: number) => {
    const sign = n < 0 ? '-' : n > 0 ? '+' : '';
    return `${sign}Rp${Math.abs(Math.round(n)).toLocaleString('id-ID')}`;
};

const valueClass = (n: number) => (n < -0.00001 ? 'text-red-600' : n > 0.00001 ? 'text-emerald-600' : 'text-slate-400');

// ── Simpan ──
const showConfirm = ref(false);

function submit() {
    form
        .transform((data) => ({
            ...data,
            lines: data.lines.map((l) =>
                directionOf(l.reason_code) === 'set'
                    ? { ...l, counted_quantity: l.quantity, quantity: null }
                    : l,
            ),
        }))
        .post('/stock-adjustments', {
            preserveScroll: true,
            onFinish: () => (showConfirm.value = false),
        });
}
</script>

<template>
    <Head title="Penyesuaian stok" />

    <AdminLayout title="Penyesuaian stok">
        <div class="mx-auto space-y-6 pb-28" style="max-width: 80rem">
            <div class="flex items-center gap-3">
                <Button as-child variant="ghost" size="icon" class="rounded-xl">
                    <Link href="/stock-adjustments"><ArrowLeft class="h-4 w-4" /></Link>
                </Button>
                <div>
                    <h1 class="text-2xl font-black text-slate-900">Penyesuaian stok</h1>
                    <p class="mt-1 text-sm text-slate-600">Catat barang rusak, hilang, terpakai, atau stok yang perlu dibetulkan.</p>
                </div>
            </div>

            <div
                v-if="form.errors.lines || form.errors.reason_code"
                class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-800"
            >
                {{ form.errors.lines || form.errors.reason_code }}
            </div>

            <!-- Langkah 1: apa yang terjadi -->
            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <CardTitle class="text-base font-bold">1. Apa yang terjadi?</CardTitle>
                </CardHeader>
                <CardContent>
                    <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                        <button
                            v-for="(label, code) in props.reasonLabels"
                            :key="code"
                            type="button"
                            class="rounded-xl border p-3 text-left transition"
                            :class="form.reason_code === code
                                ? 'border-slate-900 bg-slate-900 text-white'
                                : 'border-slate-200 bg-white hover:border-slate-400'"
                            @click="chooseReason(String(code))"
                        >
                            <span class="block text-sm font-bold">{{ label }}</span>
                            <span class="block text-xs" :class="form.reason_code === code ? 'text-slate-300' : 'text-slate-500'">
                                {{ reasonHints[directionOf(String(code))] }}
                            </span>
                        </button>
                        <Link
                            href="/stock-repacks/create"
                            class="flex items-center justify-between rounded-xl border border-dashed border-cyan-300 bg-cyan-50 p-3 text-left transition hover:border-cyan-500"
                        >
                            <span>
                                <span class="flex items-center gap-1.5 text-sm font-bold text-cyan-900"><Boxes class="h-4 w-4" /> Pecah kemasan</span>
                                <span class="block text-xs text-cyan-700">mis. 1 kg → 72 bungkus</span>
                            </span>
                            <ArrowRight class="h-4 w-4 text-cyan-700" />
                        </Link>
                    </div>
                </CardContent>
            </Card>

            <!-- Langkah 2: produk -->
            <Card class="rounded-2xl border-slate-200 shadow-sm" :class="!form.reason_code ? 'opacity-50' : ''">
                <CardHeader>
                    <CardTitle class="text-base font-bold">2. Produk & jumlah</CardTitle>
                    <CardDescription>
                        Cari nama atau pindai barcode lalu Enter. Pindai produk yang sama lagi untuk menambah 1.
                        <template v-if="props.batchTrackingEnabled"> Pengurangan memotong batch dengan kedaluwarsa terdekat lebih dulu.</template>
                    </CardDescription>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div class="relative">
                        <Search class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                        <input
                            ref="searchInput"
                            v-model="search"
                            type="text"
                            :disabled="!form.reason_code"
                            :placeholder="form.reason_code ? 'Cari produk atau pindai barcode…' : 'Pilih dulu apa yang terjadi'"
                            class="w-full rounded-xl border border-slate-200 py-2 pl-9 pr-3 text-sm focus:border-slate-900 focus:outline-none disabled:bg-slate-50"
                            @focus="pickerOpen = true"
                            @input="pickerOpen = true; scanMessage = ''"
                            @blur="onSearchBlur"
                            @keydown.enter.prevent="onSearchEnter"
                            @keydown.esc="pickerOpen = false"
                        />
                        <div
                            v-if="pickerOpen && form.reason_code && filteredOptions.length > 0"
                            class="absolute z-20 mt-1 max-h-72 w-full overflow-y-auto rounded-xl border border-slate-200 bg-white shadow-lg"
                        >
                            <button
                                v-for="o in filteredOptions"
                                :key="o.key"
                                type="button"
                                class="flex w-full items-center justify-between px-4 py-2.5 text-left text-sm transition hover:bg-slate-50"
                                @mousedown.prevent="addOption(o)"
                            >
                                <span>
                                    <span class="font-semibold text-slate-900">{{ o.label }}</span>
                                    <span v-if="o.code" class="ml-2 text-xs text-slate-400">{{ o.code }}</span>
                                </span>
                                <span class="text-xs font-semibold text-slate-500">Stok {{ formatQty(Number(o.stock)) }} {{ o.product.unit ?? '' }}</span>
                            </button>
                        </div>
                    </div>
                    <p v-if="scanMessage" class="-mt-2 text-sm font-semibold text-amber-700">{{ scanMessage }}</p>

                    <div v-if="form.lines.length === 0" class="rounded-xl border border-dashed border-slate-300 py-10 text-center">
                        <Plus class="mx-auto h-8 w-8 text-slate-300" />
                        <p class="mt-2 text-sm font-semibold text-slate-600">Belum ada produk</p>
                    </div>

                    <div v-else class="space-y-2">
                        <div
                            v-for="(line, i) in form.lines"
                            :key="`${keyOf(line)}-${i}`"
                            class="grid items-center gap-3 rounded-xl border border-slate-200 p-3 md:grid-cols-[1.6fr_1fr_0.9fr_1fr_auto]"
                        >
                            <div class="min-w-0">
                                <p class="truncate font-semibold text-slate-900">{{ optionOf(line)?.label }}</p>
                                <p class="text-xs text-slate-500">
                                    Stok sekarang {{ formatQty(Number(optionOf(line)?.stock ?? 0)) }} {{ optionOf(line)?.product.unit }}
                                </p>
                            </div>
                            <select
                                v-model="line.reason_code"
                                class="h-9 rounded-lg border border-slate-200 bg-white px-2 text-sm"
                            >
                                <option v-for="(label, code) in props.reasonLabels" :key="code" :value="code">{{ label }}</option>
                            </select>
                            <label class="block">
                                <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ qtyLabel(line) }}</span>
                                <input
                                    :ref="(el) => setQtyInput(keyOf(line), el)"
                                    v-model="line.quantity"
                                    type="number"
                                    inputmode="decimal"
                                    min="0"
                                    :step="optionOf(line)?.product.allows_decimal ? '0.0001' : '1'"
                                    class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-right text-sm tabular-nums focus:border-slate-900 focus:outline-none"
                                    @keydown.enter.prevent="onQtyEnter"
                                />
                            </label>
                            <div class="text-sm">
                                <p v-if="lineProblem(line)" class="text-xs font-semibold text-amber-700">{{ lineProblem(line) }}</p>
                                <template v-else>
                                    <p class="tabular-nums text-slate-700">
                                        {{ formatQty(Number(optionOf(line)?.stock ?? 0)) }} → <b>{{ formatQty(newStock(line) ?? 0) }}</b>
                                    </p>
                                    <p class="text-xs font-semibold tabular-nums" :class="valueClass(lineValue(line))">{{ formatRupiah(lineValue(line)) }}</p>
                                </template>
                            </div>
                            <button
                                type="button"
                                class="justify-self-end rounded-lg p-1.5 text-slate-400 transition hover:bg-red-50 hover:text-red-600"
                                @click="removeLine(i)"
                            >
                                <Trash2 class="h-4 w-4" />
                            </button>
                            <div class="grid gap-2 md:col-span-5" :class="props.pharmacyFieldsEnabled ? 'md:grid-cols-3' : ''">
                                <input
                                    v-model="line.line_notes"
                                    type="text"
                                    placeholder="Catatan (opsional), mis. kardus basah"
                                    class="w-full rounded-lg border border-slate-200 px-2 py-1.5 text-sm focus:border-slate-900 focus:outline-none"
                                />
                                <template v-if="props.pharmacyFieldsEnabled">
                                    <input v-model="line.batch_code" type="text" placeholder="Batch" class="rounded-lg border border-slate-200 px-2 py-1.5 text-sm" />
                                    <input v-model="line.expiry_date" type="date" class="rounded-lg border border-slate-200 px-2 py-1.5 text-sm" />
                                </template>
                            </div>
                        </div>
                    </div>

                    <input
                        v-if="form.lines.length > 0"
                        v-model="form.notes"
                        type="text"
                        placeholder="Keterangan umum (opsional)"
                        class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none"
                    />
                </CardContent>
            </Card>
        </div>

        <!-- Bilah bawah -->
        <div class="sticky bottom-0 z-20 -mx-4 border-t border-slate-200 bg-white/95 px-4 py-3 backdrop-blur sm:mx-0 sm:rounded-2xl sm:border">
            <div class="mx-auto flex flex-wrap items-center justify-between gap-3" style="max-width: 80rem">
                <p class="text-sm text-slate-600">
                    <b class="text-slate-900">{{ readyLines.length }}</b> produk siap ·
                    <span class="font-bold tabular-nums" :class="valueClass(totalValue)">{{ formatRupiah(totalValue) }}</span>
                    <span v-if="blockingLines.length > 0" class="ml-2 font-semibold text-amber-700">· {{ blockingLines.length }} belum lengkap</span>
                </p>
                <div class="flex gap-2">
                    <Button as-child variant="outline" class="rounded-xl"><Link href="/stock-adjustments">Batal</Link></Button>
                    <Button
                        class="rounded-xl"
                        :disabled="form.processing || form.lines.length === 0 || blockingLines.length > 0"
                        @click="showConfirm = true"
                    >
                        Simpan
                    </Button>
                </div>
            </div>
        </div>
    </AdminLayout>

    <Dialog :open="showConfirm" @update:open="(v: boolean) => !v && (showConfirm = false)">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle class="text-lg font-bold">Simpan penyesuaian stok?</DialogTitle>
                <DialogDescription v-if="props.needsReview">
                    Dokumen dikirim ke admin, manajer, atau pemilik untuk disetujui. Stok baru berubah setelah disetujui.
                </DialogDescription>
                <DialogDescription v-else>Stok langsung berubah. Bila keliru, dokumen dapat dibatalkan lewat mutasi pembalik.</DialogDescription>
            </DialogHeader>
            <ul class="max-h-64 space-y-1.5 overflow-y-auto py-2 text-sm">
                <li v-for="(line, i) in form.lines" :key="i" class="flex justify-between gap-3 rounded-lg bg-slate-50 px-3 py-2">
                    <span class="min-w-0">
                        <span class="block truncate font-semibold text-slate-900">{{ optionOf(line)?.label }}</span>
                        <span class="text-xs text-slate-500">{{ props.reasonLabels[line.reason_code] }}</span>
                    </span>
                    <span class="shrink-0 text-right tabular-nums">
                        <span :class="valueClass(change(line))" class="font-bold">{{ change(line) > 0 ? '+' : '' }}{{ formatQty(change(line)) }}</span>
                        <span class="block text-xs text-slate-500">jadi {{ formatQty(newStock(line) ?? 0) }}</span>
                    </span>
                </li>
            </ul>
            <p v-if="new Set(form.lines.map((l) => l.reason_code)).size > 1" class="text-xs text-slate-500">
                Alasan berbeda disimpan sebagai dokumen terpisah per alasan.
            </p>
            <DialogFooter class="flex flex-wrap justify-end gap-2">
                <Button variant="outline" class="rounded-xl" :disabled="form.processing" @click="showConfirm = false">Periksa lagi</Button>
                <Button class="rounded-xl" :disabled="form.processing" @click="submit">
                    {{ form.processing ? 'Menyimpan…' : 'Ya, simpan' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
