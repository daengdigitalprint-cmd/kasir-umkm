<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { ArrowLeft, Ban, Eye, EyeOff, Save, Search, CheckCircle2 } from 'lucide-vue-next';

interface LineRow {
    id: number;
    system_quantity: string;
    counted_quantity: string;
    variance_quantity: string;
    unit_cost: string;
    variance_value: string;
    line_notes: string | null;
    product: {
        id: number;
        name: string;
        sku: string | null;
        unit: string | null;
        allows_decimal: boolean;
    } | null;
    product_variant: { id: number; name: string; sku: string | null } | null;
}

interface OpnameDetail {
    id: number;
    adjustment_number: string;
    status: string;
    notes: string | null;
    counted_at: string | null;
    posted_at: string | null;
    cancelled_at: string | null;
    cancellation_reason: string | null;
    total_variance_value: string;
    lines: LineRow[];
    created_by: { id: number; name: string } | null;
    posted_by: { id: number; name: string } | null;
    cancelled_by: { id: number; name: string } | null;
}

const props = defineProps<{
    opname: OpnameDetail;
    statusLabel: string;
    canPost: boolean;
    canCancel: boolean;
    canApprove?: boolean;
    segregationBlocked?: boolean;
}>();

const isPendingApproval = computed(() => props.opname.status === 'pending_approval');
const approveForm = useForm({});

function submitApprove() {
    approveForm.post(`/stock-opnames/${props.opname.id}/approve`, { preserveScroll: true });
}

const isDraft = computed(() => props.opname.status === 'draft');

// Blind count aktif secara bawaan selama sesi masih berjalan.
const showSystemQty = ref(!isDraft.value);
const search = ref('');
const showCancel = ref(false);

const draftForm = useForm({
    lines: props.opname.lines.map((l) => ({
        id: l.id,
        counted_quantity: String(Number(l.counted_quantity)),
        line_notes: l.line_notes ?? '',
    })),
});

const postForm = useForm({});
const cancelForm = useForm({ cancellation_reason: '' });

const lineById = (id: number) => props.opname.lines.find((l) => l.id === id);

const visibleRows = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (!q) return draftForm.lines;
    return draftForm.lines.filter((row) => {
        const line = lineById(row.id);
        if (!line?.product) return false;
        const haystack = [
            line.product.name,
            line.product.sku ?? '',
            line.product_variant?.name ?? '',
            line.product_variant?.sku ?? '',
        ]
            .join(' ')
            .toLowerCase();
        return haystack.includes(q);
    });
});

function variance(rowId: number, counted: string) {
    const line = lineById(rowId);
    if (!line) return 0;
    const n = Number(counted);
    if (Number.isNaN(n)) return 0;
    return n - Number(line.system_quantity);
}

function varianceValue(rowId: number, counted: string) {
    const line = lineById(rowId);
    if (!line) return 0;
    return variance(rowId, counted) * Number(line.unit_cost);
}

const affectedCount = computed(
    () => draftForm.lines.filter((r) => Math.abs(variance(r.id, r.counted_quantity)) > 0.00001).length,
);

const totalValue = computed(() => draftForm.lines.reduce((s, r) => s + varianceValue(r.id, r.counted_quantity), 0));

// Akurasi inventori: seberapa dekat hitungan fisik dengan catatan sistem.
const accuracy = computed(() => {
    const totalSystem = props.opname.lines.reduce((s, l) => s + Math.abs(Number(l.system_quantity)), 0);
    if (totalSystem === 0) return 100;
    const totalVariance = draftForm.lines.reduce((s, r) => s + Math.abs(variance(r.id, r.counted_quantity)), 0);
    return Math.max(0, (1 - totalVariance / totalSystem) * 100);
});

const formatQty = (n: number | string) => {
    const v = Number(n ?? 0);
    const rounded = Math.round(v * 10000) / 10000;
    return Number.isInteger(rounded) ? String(rounded) : rounded.toLocaleString('id-ID', { maximumFractionDigits: 4 });
};

const formatRupiah = (n: number | string) => {
    const v = Number(n ?? 0);
    const sign = v < 0 ? '-' : v > 0 ? '+' : '';
    return `${sign}Rp${Math.abs(Math.round(v)).toLocaleString('id-ID')}`;
};

const valueClass = (n: number | string) => {
    const v = Number(n ?? 0);
    if (v < -0.00001) return 'text-red-600';
    if (v > 0.00001) return 'text-emerald-600';
    return 'text-slate-400';
};

const statusClass: Record<string, string> = {
    draft: 'bg-amber-100 text-amber-800',
    posted: 'bg-emerald-100 text-emerald-800',
    cancelled: 'bg-red-100 text-red-700',
};

const formatWhen = (iso: string | null) =>
    iso
        ? new Date(iso).toLocaleString('id-ID', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
              hour: '2-digit',
              minute: '2-digit',
          })
        : '—';

function saveDraft() {
    draftForm.post(`/stock-opnames/${props.opname.id}/draft`, { preserveScroll: true });
}

function postOpname() {
    postForm.post(`/stock-opnames/${props.opname.id}/post`, { preserveScroll: true });
}

function submitCancel() {
    cancelForm.post(`/stock-opnames/${props.opname.id}/cancel`, {
        preserveScroll: true,
        onSuccess: () => {
            showCancel.value = false;
        },
    });
}
</script>

<template>
    <Head :title="props.opname.adjustment_number" />

    <AdminLayout :title="props.opname.adjustment_number">
        <div class="mx-auto space-y-6 pb-8" style="max-width: 90rem">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <Button as-child variant="ghost" size="icon" class="rounded-xl">
                        <Link href="/stock-opnames"><ArrowLeft class="h-4 w-4" /></Link>
                    </Button>
                    <div>
                        <div class="flex items-center gap-3">
                            <h1 class="text-2xl font-black text-slate-900">{{ props.opname.adjustment_number }}</h1>
                            <span
                                class="rounded-full px-2.5 py-1 text-xs font-bold"
                                :class="statusClass[props.opname.status] ?? 'bg-slate-100 text-slate-700'"
                            >
                                {{ props.statusLabel }}
                            </span>
                        </div>
                        <p class="mt-1 text-sm text-slate-600">
                            {{ props.opname.notes || 'Sesi hitung fisik' }} · dibuka {{ formatWhen(props.opname.counted_at) }}
                        </p>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <Button
                        v-if="isDraft"
                        variant="outline"
                        class="rounded-xl"
                        @click="showSystemQty = !showSystemQty"
                    >
                        <component :is="showSystemQty ? EyeOff : Eye" class="mr-2 h-4 w-4" />
                        {{ showSystemQty ? 'Sembunyikan stok sistem' : 'Tampilkan stok sistem' }}
                    </Button>
                    <Button v-if="props.canCancel" variant="outline" class="rounded-xl text-red-600" @click="showCancel = !showCancel">
                        <Ban class="mr-2 h-4 w-4" />
                        {{ isPendingApproval ? 'Tolak' : 'Batalkan' }}
                    </Button>
                </div>
            </div>

            <div v-if="isPendingApproval" class="rounded-2xl border border-amber-200 bg-amber-50 p-5">
                <p class="font-bold text-amber-900">Sesi menunggu persetujuan</p>
                <p class="mt-1 text-sm text-amber-800">
                    Stok belum berubah. Selisih baru diterapkan setelah sesi ini disetujui.
                </p>

                <p v-if="props.segregationBlocked" class="mt-3 text-sm font-semibold text-amber-900">
                    Anda adalah pembuat sesi ini, sehingga persetujuan harus dilakukan pengguna lain.
                </p>

                <p v-if="approveForm.errors.approval" class="mt-2 text-sm font-semibold text-red-700">
                    {{ approveForm.errors.approval }}
                </p>

                <Button v-if="props.canApprove" class="mt-3 rounded-xl" :disabled="approveForm.processing" @click="submitApprove">
                    <CheckCircle2 class="mr-2 h-4 w-4" />
                    {{ approveForm.processing ? 'Memproses…' : 'Setujui dan posting' }}
                </Button>
            </div>

            <div v-if="showCancel" class="rounded-2xl border border-red-200 bg-red-50 p-5">
                <p class="font-bold text-red-900">Batalkan sesi opname ini?</p>
                <p class="mt-1 text-sm text-red-800">
                    Bila sesi sudah diposting, stok akan dikembalikan lewat mutasi pembalik.
                </p>
                <textarea
                    v-model="cancelForm.cancellation_reason"
                    rows="2"
                    placeholder="Alasan pembatalan (wajib)"
                    class="mt-3 w-full rounded-xl border border-red-200 px-3 py-2 text-sm focus:border-red-500 focus:outline-none"
                ></textarea>
                <p v-if="cancelForm.errors.cancellation_reason" class="mt-1 text-sm font-semibold text-red-700">
                    {{ cancelForm.errors.cancellation_reason }}
                </p>
                <div class="mt-3 flex gap-2">
                    <Button
                        class="rounded-xl bg-red-600 hover:bg-red-700"
                        :disabled="cancelForm.processing || !cancelForm.cancellation_reason"
                        @click="submitCancel"
                    >
                        Ya, batalkan
                    </Button>
                    <Button variant="outline" class="rounded-xl" @click="showCancel = false">Tidak jadi</Button>
                </div>
            </div>

            <div
                v-if="props.opname.cancellation_reason"
                class="rounded-2xl border border-red-200 bg-red-50 px-5 py-4 text-sm text-red-900"
            >
                <p class="font-bold">
                    Dibatalkan oleh {{ props.opname.cancelled_by?.name ?? '—' }} pada {{ formatWhen(props.opname.cancelled_at) }}
                </p>
                <p class="mt-1">{{ props.opname.cancellation_reason }}</p>
            </div>

            <div class="grid gap-4 md:grid-cols-4">
                <Card class="rounded-2xl border-slate-200 shadow-sm">
                    <CardContent class="pt-6">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Produk dihitung</p>
                        <p class="mt-1 text-xl font-black text-slate-900">{{ props.opname.lines.length }}</p>
                    </CardContent>
                </Card>
                <Card class="rounded-2xl border-slate-200 shadow-sm">
                    <CardContent class="pt-6">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Item selisih</p>
                        <p class="mt-1 text-xl font-black text-slate-900">{{ affectedCount }}</p>
                    </CardContent>
                </Card>
                <Card class="rounded-2xl border-slate-200 shadow-sm">
                    <CardContent class="pt-6">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Nilai selisih</p>
                        <p class="mt-1 text-xl font-black tabular-nums" :class="valueClass(totalValue)">
                            {{ formatRupiah(totalValue) }}
                        </p>
                    </CardContent>
                </Card>
                <Card class="rounded-2xl border-slate-200 shadow-sm">
                    <CardContent class="pt-6">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Akurasi inventori</p>
                        <p class="mt-1 text-xl font-black tabular-nums text-slate-900">{{ accuracy.toFixed(1) }}%</p>
                    </CardContent>
                </Card>
            </div>

            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <CardTitle class="text-base font-bold">Lembar hitung</CardTitle>
                    <CardDescription v-if="isDraft && !showSystemQty">
                        Stok sistem disembunyikan agar hitungan Anda tidak terpengaruh angka catatan.
                    </CardDescription>
                    <CardDescription v-else-if="isDraft">
                        Stok sistem terlihat. Pastikan Anda tetap menghitung barang secara fisik.
                    </CardDescription>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div class="relative">
                        <Search class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                        <input
                            v-model="search"
                            type="text"
                            placeholder="Cari atau pindai barcode produk…"
                            class="w-full rounded-xl border border-slate-200 py-2 pl-9 pr-3 text-sm focus:border-slate-900 focus:outline-none"
                        />
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500">
                                    <th class="py-3 pr-4 font-semibold">Produk</th>
                                    <th v-if="showSystemQty" class="py-3 pr-4 text-right font-semibold">Stok sistem</th>
                                    <th class="py-3 pr-4 text-right font-semibold">Jumlah fisik</th>
                                    <th v-if="showSystemQty" class="py-3 pr-4 text-right font-semibold">Selisih</th>
                                    <th v-if="showSystemQty" class="py-3 pr-4 text-right font-semibold">Nilai</th>
                                    <th class="py-3 font-semibold">Catatan</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="row in visibleRows"
                                    :key="row.id"
                                    class="border-b border-slate-100"
                                    :class="
                                        showSystemQty && Math.abs(variance(row.id, row.counted_quantity)) > 0.00001
                                            ? 'bg-amber-50/50'
                                            : ''
                                    "
                                >
                                    <td class="py-3 pr-4">
                                        <p class="font-semibold text-slate-900">
                                            {{ lineById(row.id)?.product?.name ?? '—'
                                            }}<span v-if="lineById(row.id)?.product_variant" class="text-slate-500">
                                                — {{ lineById(row.id)?.product_variant?.name }}</span
                                            >
                                        </p>
                                        <p class="text-xs text-slate-400">
                                            {{ lineById(row.id)?.product_variant?.sku ?? lineById(row.id)?.product?.sku ?? '—' }}
                                        </p>
                                    </td>
                                    <td v-if="showSystemQty" class="py-3 pr-4 text-right tabular-nums text-slate-600">
                                        {{ formatQty(lineById(row.id)?.system_quantity ?? 0) }}
                                    </td>
                                    <td class="py-3 pr-4 text-right">
                                        <input
                                            v-model="row.counted_quantity"
                                            type="number"
                                            min="0"
                                            :step="lineById(row.id)?.product?.allows_decimal ? '0.0001' : '1'"
                                            :disabled="!isDraft"
                                            class="w-28 rounded-lg border border-slate-200 px-2 py-1.5 text-right text-sm tabular-nums focus:border-slate-900 focus:outline-none disabled:bg-slate-50 disabled:opacity-70"
                                        />
                                        <span class="ml-1 text-xs text-slate-400">{{ lineById(row.id)?.product?.unit }}</span>
                                    </td>
                                    <td
                                        v-if="showSystemQty"
                                        class="py-3 pr-4 text-right font-bold tabular-nums"
                                        :class="valueClass(variance(row.id, row.counted_quantity))"
                                    >
                                        {{ variance(row.id, row.counted_quantity) > 0 ? '+' : ''
                                        }}{{ formatQty(variance(row.id, row.counted_quantity)) }}
                                    </td>
                                    <td
                                        v-if="showSystemQty"
                                        class="py-3 pr-4 text-right font-semibold tabular-nums"
                                        :class="valueClass(varianceValue(row.id, row.counted_quantity))"
                                    >
                                        {{ formatRupiah(varianceValue(row.id, row.counted_quantity)) }}
                                    </td>
                                    <td class="py-3">
                                        <input
                                            v-model="row.line_notes"
                                            type="text"
                                            placeholder="opsional"
                                            :disabled="!isDraft"
                                            class="w-full min-w-36 rounded-lg border border-slate-200 px-2 py-1.5 text-sm focus:border-slate-900 focus:outline-none disabled:bg-slate-50"
                                        />
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </CardContent>
            </Card>

            <div
                v-if="isDraft"
                class="flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"
            >
                <p class="text-sm text-slate-600">
                    Simpan draft bila hitungan belum selesai. Sesi dapat dilanjutkan kapan saja tanpa kehilangan data.
                </p>
                <div class="flex items-center gap-3">
                    <Button variant="outline" class="rounded-xl" :disabled="draftForm.processing" @click="saveDraft">
                        <Save class="mr-2 h-4 w-4" />
                        {{ draftForm.processing ? 'Menyimpan…' : 'Simpan draft' }}
                    </Button>
                    <Button v-if="props.canPost" class="rounded-xl" :disabled="postForm.processing" @click="postOpname">
                        <CheckCircle2 class="mr-2 h-4 w-4" />
                        {{ postForm.processing ? 'Memproses…' : 'Posting opname' }}
                    </Button>
                </div>
            </div>

            <p v-if="isDraft" class="text-center text-xs text-slate-500">
                Simpan draft dahulu sebelum memposting agar hasil hitung terbaru ikut terpakai.
            </p>
        </div>
    </AdminLayout>
</template>
