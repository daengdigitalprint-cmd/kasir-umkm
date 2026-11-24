<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import SignaturePad from '@/components/SignaturePad.vue';
import { ArrowLeft, ArrowRightLeft, Send, CheckCircle, XCircle, FileText, AlertTriangle } from 'lucide-vue-next';
import { computed, nextTick, ref } from 'vue';
import { modalError } from '@/lib/swal';

interface ProductTiny {
    id: number;
    name: string;
    sku: string | null;
    unit: string | null;
    base_unit: string | null;
}

type DiscrepancyType = 'short' | 'damaged' | 'over';
type Resolution = 'returned' | 'loss' | 'accepted' | 'rejected';

interface Line {
    id: number;
    quantity: string;
    quantity_received: string | null;
    discrepancy_type: DiscrepancyType | null;
    quantity_over: string | null;
    discrepancy_reason: string | null;
    resolution: Resolution | null;
    resolution_note: string | null;
    resolved_at: string | null;
    resolved_by: { id: number; name: string } | null;
    base_unit: string | null;
    unit_cost_snapshot: string | null;
    total_cost_snapshot: string | null;
    from_product: ProductTiny;
    to_product: ProductTiny;
}

interface Transfer {
    id: number;
    transfer_number: string;
    status: string;
    notes: string | null;
    created_at: string;
    sent_at: string | null;
    received_at: string | null;
    cancelled_at: string | null;
    cancellation_reason: string | null;
    receiver_name: string | null;
    receiver_signature: string | null;
    from_store: { id: number; name: string };
    to_store: { id: number; name: string };
    user: { id: number; name: string } | null;
    sent_by: { id: number; name: string } | null;
    received_by: { id: number; name: string } | null;
    cancelled_by: { id: number; name: string } | null;
    followup_of: { id: number; transfer_number: string } | null;
    followups: { id: number; transfer_number: string; status: string }[];
    lines: Line[];
}

const props = withDefaults(
    defineProps<{
        transfer: Transfer;
        canSend: boolean;
        canReceive: boolean;
        canCancel: boolean;
        canResolve?: boolean;
    }>(),
    { canResolve: false },
);

const page = usePage();
const canViewCost = computed(() => Boolean((page.props as { abilities?: { view_cost?: boolean } }).abilities?.view_cost));
const currentUserName = String((page.props as { auth?: { user?: { name?: string } } }).auth?.user?.name ?? '');

const showCancelForm = ref(false);
const cancelReason = ref('');
const processing = ref(false);

const formatWhen = (iso: string) =>
    new Date(iso).toLocaleString('id-ID', {
        day: '2-digit',
        month: 'long',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });

const statusConfig: Record<string, { label: string; class: string }> = {
    draft: { label: 'Draft', class: 'bg-slate-100 text-slate-700' },
    sent: { label: 'Dikirim', class: 'bg-amber-100 text-amber-800' },
    received: { label: 'Diterima', class: 'bg-emerald-100 text-emerald-800' },
    cancelled: { label: 'Dibatalkan', class: 'bg-red-100 text-red-700' },
};

const DISCREPANCY_LABEL: Record<DiscrepancyType, string> = { short: 'Kurang', damaged: 'Rusak', over: 'Lebih' };
const RESOLUTION_LABEL: Record<Resolution, string> = {
    returned: 'Dikembalikan ke stok pengirim',
    loss: 'Dicatat sebagai kerugian',
    accepted: 'Kelebihan diakui pengirim',
    rejected: 'Kelebihan dikembalikan ke pengirim',
};

/** Ambil pesan pertama dari galat validasi Inertia. */
function firstError(errors: Record<string, unknown>, fallback: string): string {
    const first = Object.values(errors)[0];
    return Array.isArray(first) ? String(first[0]) : typeof first === 'string' ? first : fallback;
}

function sendTransfer() {
    processing.value = true;
    router.post(
        `/stock-transfers/${props.transfer.id}/send`,
        {},
        {
            preserveScroll: true,
            onFinish: () => { processing.value = false; },
            onError: async (errors) => {
                await modalError('Transfer gagal', firstError(errors, 'Gagal mengirim transfer.'));
            },
        },
    );
}

// ── F5/F8: terima barang ───────────────────────────────────────────────────
// Jumlah diterima per baris (default = jumlah dikirim). Kurang → pilih jenis + alasan;
// lebih → dicatat dan menunggu keputusan pengirim. Ditutup dengan nama & tanda tangan penerima.
const showReceiveForm = ref(false);
const receiveFormRef = ref<HTMLElement | null>(null);
function openReceiveForm() {
    showReceiveForm.value = true;
    void nextTick(() => receiveFormRef.value?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
}
const receivedQty = ref<Record<number, number>>(
    Object.fromEntries(props.transfer.lines.map((ln) => [ln.id, Number(ln.quantity)])),
);
const receivedType = ref<Record<number, 'short' | 'damaged'>>(Object.fromEntries(props.transfer.lines.map((ln) => [ln.id, 'short'])));
const receivedReason = ref<Record<number, string>>(Object.fromEntries(props.transfer.lines.map((ln) => [ln.id, ''])));
const receiverName = ref(currentUserName);
const receiverSignature = ref('');

const qtyOf = (ln: Line) => Number(receivedQty.value[ln.id] ?? Number(ln.quantity));
const isShort = (ln: Line) => qtyOf(ln) < Number(ln.quantity);
const isOver = (ln: Line) => qtyOf(ln) > Number(ln.quantity);
const linesValid = computed(() =>
    props.transfer.lines.every((ln) => {
        const q = receivedQty.value[ln.id];
        if (q === undefined || q === null || (q as unknown) === '' || Number.isNaN(Number(q)) || q < 0) return false;
        return !isShort(ln) || (receivedReason.value[ln.id] ?? '').trim().length > 0;
    }),
);
const receiveBlocker = computed<string | null>(() => {
    if (!linesValid.value) return 'Lengkapi jumlah diterima, dan tulis alasan untuk barang yang kurang.';
    if (!receiverName.value.trim()) return 'Isi nama penerima.';
    if (!receiverSignature.value) return 'Penerima belum tanda tangan.';
    return null;
});
const pendingDiscrepancyCount = computed(() => props.transfer.lines.filter((ln) => isShort(ln) || isOver(ln)).length);

function receiveTransfer() {
    if (receiveBlocker.value) return;
    processing.value = true;
    router.post(
        `/stock-transfers/${props.transfer.id}/receive`,
        {
            receiver_name: receiverName.value.trim(),
            receiver_signature: receiverSignature.value,
            lines: props.transfer.lines.map((ln) => ({
                id: ln.id,
                quantity_received: receivedQty.value[ln.id],
                discrepancy_type: isShort(ln) ? receivedType.value[ln.id] : null,
                discrepancy_reason: isShort(ln) || isOver(ln) ? receivedReason.value[ln.id] || null : null,
            })),
        },
        {
            preserveScroll: true,
            onFinish: () => { processing.value = false; },
            onError: async (errors) => {
                await modalError('Penerimaan gagal', firstError(errors, 'Gagal menerima transfer.'));
            },
        },
    );
}

// ── Selisih yang sudah tercatat ────────────────────────────────────────────
/** Selisih lama (sebelum ada jenis selisih) tetap ditampilkan sebagai kurang. */
const lineShort = (ln: Line) => ln.quantity_received !== null && Number(ln.quantity_received) < Number(ln.quantity);
const lineDiscrepancy = (ln: Line): DiscrepancyType | null => ln.discrepancy_type ?? (lineShort(ln) ? 'short' : null);
const lineDiscrepancyQty = (ln: Line) =>
    ln.discrepancy_type === 'over' ? Number(ln.quantity_over ?? 0) : Number(ln.quantity) - Number(ln.quantity_received ?? ln.quantity);
const hasDiscrepancy = computed(() => props.transfer.lines.some((ln) => lineDiscrepancy(ln) !== null));
const openLines = computed(() => props.transfer.lines.filter((ln) => ln.discrepancy_type !== null && ln.resolution === null));

// ── F8: penyelesaian selisih oleh lokasi pengirim ──────────────────────────
const resolveChoice = ref<Record<number, Resolution | ''>>(Object.fromEntries(props.transfer.lines.map((ln) => [ln.id, ''])));
const resolveNote = ref<Record<number, string>>(Object.fromEntries(props.transfer.lines.map((ln) => [ln.id, ''])));
const resolveResend = ref<Record<number, boolean>>(Object.fromEntries(props.transfer.lines.map((ln) => [ln.id, false])));
const resolveOptions = (ln: Line): { value: Resolution; label: string; hint: string }[] =>
    ln.discrepancy_type === 'over'
        ? [
              { value: 'accepted', label: 'Akui kelebihan', hint: `Stok ${props.transfer.from_store.name} berkurang, stok ${props.transfer.to_store.name} bertambah.` },
              { value: 'rejected', label: 'Tarik kembali barangnya', hint: 'Barang lebih dikembalikan ke pengirim. Stok tidak berubah.' },
          ]
        : [
              { value: 'returned', label: 'Kembalikan ke stok', hint: `Barang memang tidak terkirim atau sudah kembali. Stok ${props.transfer.from_store.name} bertambah lagi.` },
              { value: 'loss', label: 'Catat sebagai rugi', hint: 'Barang hilang atau rusak. Stok tidak kembali.' },
          ];
const chosenLines = computed(() => openLines.value.filter((ln) => resolveChoice.value[ln.id] !== ''));

function resolveDiscrepancies() {
    if (chosenLines.value.length === 0) return;
    processing.value = true;
    router.post(
        `/stock-transfers/${props.transfer.id}/resolve-discrepancies`,
        {
            lines: chosenLines.value.map((ln) => ({
                id: ln.id,
                resolution: resolveChoice.value[ln.id],
                note: resolveNote.value[ln.id] || null,
                resend: ln.discrepancy_type !== 'over' && resolveResend.value[ln.id],
            })),
        },
        {
            preserveScroll: true,
            onFinish: () => { processing.value = false; },
            onError: async (errors) => {
                await modalError('Penyelesaian gagal', firstError(errors, 'Gagal menyimpan penyelesaian selisih.'));
            },
        },
    );
}

async function cancelTransfer() {
    if (!cancelReason.value || cancelReason.value.trim().length < 3) {
        await modalError('Data belum lengkap', 'Alasan pembatalan minimal 3 karakter.');
        return;
    }
    processing.value = true;
    router.post(
        `/stock-transfers/${props.transfer.id}/cancel`,
        { cancellation_reason: cancelReason.value },
        {
            preserveScroll: true,
            onFinish: () => { processing.value = false; showCancelForm.value = false; },
            onError: async (errors) => {
                await modalError('Pembatalan gagal', firstError(errors, 'Gagal membatalkan transfer.'));
            },
        },
    );
}

// Timeline items
const timeline = computed(
    () =>
        [
            props.transfer.user
                ? { label: 'Dibuat', who: props.transfer.user.name, when: props.transfer.created_at, color: 'bg-slate-400' }
                : null,
            props.transfer.sent_by
                ? { label: 'Dikirim', who: props.transfer.sent_by.name, when: props.transfer.sent_at!, color: 'bg-amber-500' }
                : null,
            props.transfer.received_by
                ? {
                      label: 'Diterima',
                      who: props.transfer.receiver_name || props.transfer.received_by.name,
                      when: props.transfer.received_at!,
                      color: 'bg-emerald-500',
                  }
                : null,
            props.transfer.cancelled_by
                ? { label: 'Dibatalkan', who: props.transfer.cancelled_by.name, when: props.transfer.cancelled_at!, color: 'bg-red-500' }
                : null,
        ].filter(Boolean) as { label: string; who: string; when: string; color: string }[],
);

/** Hilangkan trailing .0000 dari angka desimal database. */
const fmtQty = (val: string | number) => {
    const num = Number(val);
    return Number.isInteger(num) ? num.toString() : num.toFixed(4).replace(/\.?0+$/, '');
};
</script>

<template>
    <Head :title="transfer.transfer_number" />

    <AdminLayout title="Detail transfer">
        <div class="mx-auto space-y-6 pb-8" style="max-width: 90rem">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <Button as-child variant="outline" size="sm" class="gap-2 rounded-xl">
                    <Link href="/stock-transfers">
                        <ArrowLeft class="h-4 w-4" />
                        Kembali
                    </Link>
                </Button>
                <!-- F4/F8: surat jalan; setelah diterima menjadi bukti terima bertanda tangan (unduhan file, bukan navigasi Inertia) -->
                <Button
                    v-if="transfer.status === 'sent' || transfer.status === 'received'"
                    as-child
                    variant="outline"
                    size="sm"
                    class="gap-2 rounded-xl"
                >
                    <a :href="`/stock-transfers/${transfer.id}/delivery-note`">
                        <FileText class="h-4 w-4" />
                        {{ transfer.status === 'received' ? 'Cetak bukti terima' : 'Cetak surat jalan' }}
                    </a>
                </Button>
            </div>

            <!-- Langkah berikutnya, ditaruh paling atas agar tidak terlewat -->
            <div
                v-if="transfer.status === 'sent' || transfer.status === 'draft'"
                class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border px-4 py-3"
                :class="canReceive || canSend ? 'border-primary/30 bg-primary/5' : 'border-slate-200 bg-white'"
            >
                <p class="text-sm text-slate-700">
                    <template v-if="transfer.status === 'sent' && canReceive">
                        <strong class="text-slate-900">Barang sedang dikirim ke {{ transfer.to_store.name }}.</strong>
                        Cocokkan jumlah yang datang, tanda tangan, lalu terima agar stok bertambah.
                    </template>
                    <template v-else-if="transfer.status === 'sent'">
                        Menunggu diterima oleh <strong class="text-slate-900">{{ transfer.to_store.name }}</strong>. Stok tujuan bertambah setelah diterima.
                    </template>
                    <template v-else-if="canSend">
                        <strong class="text-slate-900">Masih draft.</strong> Stok belum berubah sampai transfer dikirim.
                    </template>
                    <template v-else>Masih draft. Pengelola {{ transfer.from_store.name }} yang akan mengirimnya.</template>
                </p>
                <Button
                    v-if="transfer.status === 'sent' && canReceive && !showReceiveForm"
                    class="rounded-xl"
                    :disabled="processing"
                    @click="openReceiveForm"
                >
                    Terima barang
                </Button>
                <Button v-else-if="transfer.status === 'draft' && canSend" class="rounded-xl" :disabled="processing" @click="sendTransfer">
                    Kirim sekarang
                </Button>
            </div>

            <!-- F8: selisih yang menunggu keputusan pengirim -->
            <div
                v-if="transfer.status === 'received' && openLines.length > 0"
                class="flex items-start gap-3 rounded-2xl border border-red-200 bg-red-50 px-4 py-3"
            >
                <AlertTriangle class="mt-0.5 h-5 w-5 shrink-0 text-red-600" />
                <p class="text-sm text-red-900">
                    <strong>{{ openLines.length }} barang punya selisih yang belum diputuskan.</strong>
                    <template v-if="canResolve"> Tentukan penyelesaiannya di bawah supaya stok kembali cocok.</template>
                    <template v-else> Menunggu keputusan {{ transfer.from_store.name }} sebagai pengirim.</template>
                </p>
            </div>

            <Card class="rounded-2xl border-slate-200 shadow-none">
                <CardHeader>
                    <div class="flex items-center gap-2">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-violet-500/10">
                            <ArrowRightLeft class="h-5 w-5 text-violet-700" />
                        </div>
                        <div>
                            <CardTitle class="font-mono flex items-center gap-2">
                                {{ transfer.transfer_number }}
                                <span
                                    class="inline-flex rounded-lg px-2 py-0.5 text-xs font-bold"
                                    :class="statusConfig[transfer.status]?.class ?? 'bg-slate-100 text-slate-600'"
                                >
                                    {{ statusConfig[transfer.status]?.label ?? transfer.status }}
                                </span>
                            </CardTitle>
                            <CardDescription>{{ formatWhen(transfer.created_at) }}</CardDescription>
                        </div>
                    </div>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div class="rounded-xl bg-slate-50 border border-slate-100 p-4 text-sm">
                        <p>
                            <span class="text-slate-500">Dari</span>
                            <strong class="text-slate-900 ml-2">{{ transfer.from_store.name }}</strong>
                        </p>
                        <p class="mt-2">
                            <span class="text-slate-500">Ke</span>
                            <strong class="text-slate-900 ml-2">{{ transfer.to_store.name }}</strong>
                        </p>
                        <p v-if="transfer.user" class="mt-2 text-slate-600">Dibuat oleh {{ transfer.user.name }}</p>
                        <p v-if="transfer.notes" class="mt-3 text-slate-700 whitespace-pre-wrap">{{ transfer.notes }}</p>
                        <p v-if="transfer.followup_of" class="mt-3 text-slate-700">
                            Kiriman susulan dari
                            <Link :href="`/stock-transfers/${transfer.followup_of.id}`" class="font-mono font-semibold text-violet-700 hover:underline">
                                {{ transfer.followup_of.transfer_number }}
                            </Link>
                        </p>
                        <p v-if="transfer.followups.length" class="mt-3 text-slate-700">
                            Kiriman susulan:
                            <template v-for="(f, idx) in transfer.followups" :key="f.id">
                                <Link :href="`/stock-transfers/${f.id}`" class="font-mono font-semibold text-violet-700 hover:underline">{{ f.transfer_number }}</Link>
                                <span class="text-slate-500"> ({{ statusConfig[f.status]?.label ?? f.status }})</span><span v-if="idx < transfer.followups.length - 1">, </span>
                            </template>
                        </p>
                    </div>

                    <!-- Timeline -->
                    <div v-if="timeline.length > 1" class="space-y-2">
                        <h3 class="text-xs font-bold text-slate-500 uppercase">Riwayat Status</h3>
                        <div class="flex flex-col gap-2">
                            <div v-for="(item, idx) in timeline" :key="idx" class="flex items-start gap-3 text-sm">
                                <div class="mt-1 flex h-2.5 w-2.5 shrink-0 rounded-full" :class="item.color" />
                                <div>
                                    <span class="font-semibold text-slate-800">{{ item.label }}</span>
                                    <span class="text-slate-600"> oleh {{ item.who }}</span>
                                    <span class="text-slate-400 ml-1">· {{ formatWhen(item.when) }}</span>
                                </div>
                            </div>
                        </div>
                        <p v-if="transfer.cancellation_reason" class="mt-1 text-sm text-red-700 bg-red-50 rounded-lg p-2">
                            Alasan pembatalan: {{ transfer.cancellation_reason }}
                        </p>
                    </div>

                    <!-- F8: bukti terima — nama & tanda tangan penerima -->
                    <div
                        v-if="transfer.status === 'received' && transfer.receiver_signature"
                        class="flex flex-wrap items-center gap-4 rounded-xl border border-emerald-200 bg-emerald-50/50 p-4"
                    >
                        <img
                            :src="transfer.receiver_signature"
                            :alt="`Tanda tangan ${transfer.receiver_name ?? 'penerima'}`"
                            class="h-20 w-auto rounded-lg border border-emerald-100 bg-white"
                        />
                        <div class="text-sm">
                            <p class="text-xs font-bold text-emerald-800 uppercase">Bukti terima</p>
                            <p class="font-semibold text-slate-900">{{ transfer.receiver_name }}</p>
                            <p class="text-slate-600">
                                Menandatangani saat barang diterima<span v-if="transfer.received_at"> · {{ formatWhen(transfer.received_at) }}</span>
                            </p>
                            <p
                                v-if="transfer.received_by && transfer.received_by.name !== transfer.receiver_name"
                                class="text-xs text-slate-500"
                            >
                                Dicatat lewat akun {{ transfer.received_by.name }}
                            </p>
                        </div>
                    </div>

                    <!-- Action buttons -->
                    <div v-if="canSend || canReceive || canCancel" class="flex flex-wrap gap-2">
                        <Button
                            v-if="canSend"
                            class="rounded-xl gap-1"
                            :disabled="processing"
                            @click="sendTransfer"
                        >
                            <Send class="h-4 w-4" />
                            Kirim
                        </Button>
                        <Button
                            v-if="canReceive && !showReceiveForm"
                            class="rounded-xl gap-1"
                            :disabled="processing"
                            @click="openReceiveForm"
                        >
                            <CheckCircle class="h-4 w-4" />
                            Terima
                        </Button>
                        <Button
                            v-if="canCancel && !showCancelForm"
                            variant="destructive"
                            class="rounded-xl gap-1"
                            @click="showCancelForm = true"
                        >
                            <XCircle class="h-4 w-4" />
                            Batalkan
                        </Button>
                    </div>

                    <!-- F5/F8: form terima — cek jumlah yang benar-benar tiba, lalu tanda tangan -->
                    <div v-if="canReceive && showReceiveForm" ref="receiveFormRef" class="space-y-4 rounded-xl border border-emerald-200 bg-emerald-50/60 p-4">
                        <div>
                            <p class="text-sm font-semibold text-emerald-900">1. Cek barang yang tiba</p>
                            <p class="text-xs text-emerald-800">
                                Isi jumlah yang datang dalam kondisi baik. Kalau kurang atau rusak, stok bertambah sesuai jumlah diterima dan
                                selisihnya diteruskan ke {{ transfer.from_store.name }} untuk diputuskan.
                            </p>
                        </div>
                        <div class="overflow-x-auto rounded-lg border border-emerald-100 bg-white">
                            <table class="w-full text-sm">
                                <thead class="bg-slate-50 text-left text-xs font-bold uppercase text-slate-500">
                                    <tr>
                                        <th class="px-3 py-2">Barang</th>
                                        <th class="px-3 py-2 text-right">Dikirim</th>
                                        <th class="px-3 py-2">Diterima</th>
                                        <th class="px-3 py-2">Selisih</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <tr v-for="ln in transfer.lines" :key="ln.id" class="align-top">
                                        <td class="px-3 py-2 font-medium text-slate-900">{{ ln.to_product.name }}</td>
                                        <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ fmtQty(ln.quantity) }} {{ ln.base_unit ?? '' }}</td>
                                        <td class="px-3 py-2">
                                            <input
                                                v-model.number="receivedQty[ln.id]"
                                                type="number"
                                                min="0"
                                                step="any"
                                                inputmode="decimal"
                                                class="h-9 w-28 rounded-md border px-2 text-right"
                                                :class="isShort(ln) ? 'border-amber-400 bg-amber-50' : isOver(ln) ? 'border-sky-400 bg-sky-50' : 'border-slate-200'"
                                                :aria-label="`Jumlah diterima ${ln.to_product.name}`"
                                            />
                                        </td>
                                        <td class="px-3 py-2">
                                            <div v-if="isShort(ln)" class="flex min-w-56 flex-col gap-1.5">
                                                <div class="flex gap-1.5">
                                                    <label
                                                        v-for="opt in (['short', 'damaged'] as const)"
                                                        :key="opt"
                                                        class="flex cursor-pointer items-center gap-1.5 rounded-md border px-2 py-1 text-xs font-semibold"
                                                        :class="receivedType[ln.id] === opt ? 'border-amber-500 bg-amber-100 text-amber-900' : 'border-slate-200 text-slate-600'"
                                                    >
                                                        <input v-model="receivedType[ln.id]" type="radio" :value="opt" class="sr-only" :name="`type-${ln.id}`" />
                                                        {{ DISCREPANCY_LABEL[opt] }} {{ fmtQty(Number(ln.quantity) - qtyOf(ln)) }}
                                                    </label>
                                                </div>
                                                <input
                                                    v-model="receivedReason[ln.id]"
                                                    type="text"
                                                    maxlength="255"
                                                    :placeholder="receivedType[ln.id] === 'damaged' ? 'Rusak karena apa? (wajib)' : 'Mis. tidak ada di kiriman (wajib)'"
                                                    class="h-9 w-full rounded-md border border-slate-200 px-2"
                                                    :aria-label="`Alasan selisih ${ln.to_product.name}`"
                                                />
                                            </div>
                                            <div v-else-if="isOver(ln)" class="flex min-w-56 flex-col gap-1.5">
                                                <span class="text-xs font-semibold text-sky-800">
                                                    Lebih {{ fmtQty(qtyOf(ln) - Number(ln.quantity)) }} {{ ln.base_unit ?? '' }}
                                                </span>
                                                <span class="text-xs text-slate-500">
                                                    Kelebihan belum masuk stok sampai {{ transfer.from_store.name }} mengakuinya.
                                                </span>
                                                <input
                                                    v-model="receivedReason[ln.id]"
                                                    type="text"
                                                    maxlength="255"
                                                    placeholder="Catatan (opsional)"
                                                    class="h-9 w-full rounded-md border border-slate-200 px-2"
                                                    :aria-label="`Catatan kelebihan ${ln.to_product.name}`"
                                                />
                                            </div>
                                            <span v-else class="text-xs text-slate-400">Sesuai</span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div>
                            <p class="text-sm font-semibold text-emerald-900">2. Tanda tangan penerima</p>
                            <p class="text-xs text-emerald-800">Ditandatangani oleh orang yang menerima barangnya, sebagai bukti serah terima.</p>
                        </div>
                        <div class="grid gap-4 rounded-lg border border-emerald-100 bg-white p-3 md:grid-cols-[minmax(0,16rem)_minmax(0,1fr)]">
                            <div class="space-y-1.5">
                                <Label for="receiver-name" class="text-sm font-semibold text-slate-800">Nama penerima</Label>
                                <Input id="receiver-name" v-model="receiverName" maxlength="120" class="rounded-lg" placeholder="Nama orang yang menerima" :disabled="processing" />
                                <p class="text-xs text-slate-500">Boleh berbeda dari akun yang sedang masuk.</p>
                            </div>
                            <SignaturePad v-model="receiverSignature" :disabled="processing" label="Tanda tangan" class="max-w-md" />
                        </div>

                        <p v-if="receiveBlocker" class="text-xs font-medium text-amber-800">{{ receiveBlocker }}</p>
                        <p v-else-if="pendingDiscrepancyCount > 0" class="text-xs font-medium text-amber-800">
                            {{ pendingDiscrepancyCount }} barang akan dicatat berselisih dan diteruskan ke {{ transfer.from_store.name }}.
                        </p>
                        <div class="flex gap-2">
                            <Button class="rounded-xl gap-1" :disabled="processing || receiveBlocker !== null" @click="receiveTransfer">
                                <CheckCircle class="h-4 w-4" />
                                Simpan penerimaan
                            </Button>
                            <Button variant="outline" class="rounded-xl" :disabled="processing" @click="showReceiveForm = false">
                                Batal
                            </Button>
                        </div>
                    </div>

                    <!-- F8: form penyelesaian selisih (lokasi pengirim) -->
                    <div v-if="canResolve && openLines.length > 0" class="space-y-3 rounded-xl border border-red-200 bg-red-50/50 p-4">
                        <div>
                            <p class="text-sm font-semibold text-red-900">Putuskan selisih penerimaan</p>
                            <p class="text-xs text-red-800">
                                Stok {{ transfer.from_store.name }} sudah berkurang saat dikirim. Pilih penyelesaian tiap barang; yang belum dipilih bisa diputuskan nanti.
                            </p>
                        </div>
                        <div v-for="ln in openLines" :key="ln.id" class="space-y-2 rounded-lg border border-red-100 bg-white p-3">
                            <div class="flex flex-wrap items-baseline justify-between gap-2">
                                <p class="text-sm font-semibold text-slate-900">{{ ln.from_product.name }}</p>
                                <p class="text-sm font-semibold" :class="ln.discrepancy_type === 'over' ? 'text-sky-700' : 'text-red-700'">
                                    {{ DISCREPANCY_LABEL[ln.discrepancy_type!] }} {{ fmtQty(lineDiscrepancyQty(ln)) }} {{ ln.base_unit ?? '' }}
                                    <span v-if="canViewCost && ln.unit_cost_snapshot" class="font-normal text-slate-500">
                                        · Rp{{ Math.round(lineDiscrepancyQty(ln) * Number(ln.unit_cost_snapshot)).toLocaleString('id-ID') }}
                                    </span>
                                </p>
                            </div>
                            <p v-if="ln.discrepancy_reason" class="text-xs text-slate-600">Catatan penerima: {{ ln.discrepancy_reason }}</p>
                            <div class="grid gap-2 sm:grid-cols-2">
                                <label
                                    v-for="opt in resolveOptions(ln)"
                                    :key="opt.value"
                                    class="flex cursor-pointer items-start gap-2 rounded-lg border p-2.5 text-sm"
                                    :class="resolveChoice[ln.id] === opt.value ? 'border-primary bg-primary/5' : 'border-slate-200 hover:bg-slate-50'"
                                >
                                    <input v-model="resolveChoice[ln.id]" type="radio" :value="opt.value" :name="`resolve-${ln.id}`" class="mt-0.5 h-4 w-4 text-primary" />
                                    <span>
                                        <span class="block font-semibold text-slate-900">{{ opt.label }}</span>
                                        <span class="block text-xs text-slate-500">{{ opt.hint }}</span>
                                    </span>
                                </label>
                            </div>
                            <template v-if="resolveChoice[ln.id] !== ''">
                                <label v-if="ln.discrepancy_type !== 'over'" class="flex items-start gap-2 text-sm text-slate-700">
                                    <input v-model="resolveResend[ln.id]" type="checkbox" class="mt-0.5 h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary" />
                                    <span>
                                        Buat kiriman susulan {{ fmtQty(lineDiscrepancyQty(ln)) }} {{ ln.base_unit ?? '' }}
                                        <span class="block text-xs text-slate-500">Dibuat sebagai draft transfer baru; stok baru berkurang saat draft itu dikirim.</span>
                                    </span>
                                </label>
                                <input
                                    v-model="resolveNote[ln.id]"
                                    type="text"
                                    maxlength="255"
                                    placeholder="Catatan penyelesaian (opsional)"
                                    class="h-9 w-full rounded-md border border-slate-200 px-2 text-sm"
                                    :aria-label="`Catatan penyelesaian ${ln.from_product.name}`"
                                />
                            </template>
                        </div>
                        <Button class="rounded-xl gap-1" :disabled="processing || chosenLines.length === 0" @click="resolveDiscrepancies">
                            <CheckCircle class="h-4 w-4" />
                            Simpan penyelesaian{{ chosenLines.length ? ` (${chosenLines.length} barang)` : '' }}
                        </Button>
                    </div>

                    <!-- Cancel form -->
                    <div v-if="showCancelForm" class="rounded-xl border border-red-200 bg-red-50 p-4 space-y-3">
                        <Label for="cancel-reason" class="text-sm font-semibold text-red-800">Alasan pembatalan (wajib)</Label>
                        <Input
                            id="cancel-reason"
                            v-model="cancelReason"
                            class="rounded-xl"
                            placeholder="Tuliskan alasan pembatalan..."
                            :disabled="processing"
                        />
                        <div class="flex gap-2">
                            <Button variant="destructive" class="rounded-xl" :disabled="processing" @click="cancelTransfer">
                                Ya, batalkan
                            </Button>
                            <Button variant="outline" class="rounded-xl" :disabled="processing" @click="showCancelForm = false">
                                Tidak
                            </Button>
                        </div>
                    </div>

                    <!-- Lines table -->
                    <div class="overflow-x-auto rounded-xl border border-slate-100">
                        <table class="w-full text-sm text-left">
                            <thead class="bg-slate-50 text-xs font-bold uppercase text-slate-500">
                                <tr>
                                    <th class="px-3 py-2">Produk asal</th>
                                    <th class="px-3 py-2">Produk tujuan</th>
                                    <th class="px-3 py-2 text-right">Qty</th>
                                    <th v-if="hasDiscrepancy" class="px-3 py-2 text-right">Diterima</th>
                                    <th v-if="canViewCost" class="px-3 py-2 text-right">HPP/unit</th>
                                    <th v-if="canViewCost" class="px-3 py-2 text-right">Total</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr v-for="ln in transfer.lines" :key="ln.id" class="align-top">
                                    <td class="px-3 py-2">
                                        <div class="font-medium text-slate-900">{{ ln.from_product.name }}</div>
                                        <div class="text-xs text-slate-500">
                                            <span v-if="ln.from_product.sku">SKU {{ ln.from_product.sku }}</span>
                                            <span v-if="ln.base_unit"> · {{ ln.base_unit }}</span>
                                        </div>
                                    </td>
                                    <td class="px-3 py-2">
                                        <div class="font-medium text-slate-900">{{ ln.to_product.name }}</div>
                                        <div class="text-xs text-slate-500">
                                            <span v-if="ln.to_product.sku">SKU {{ ln.to_product.sku }}</span>
                                            <span v-if="ln.to_product.base_unit"> · {{ ln.to_product.base_unit }}</span>
                                        </div>
                                    </td>
                                    <td class="px-3 py-2 text-right font-mono font-semibold whitespace-nowrap">
                                        {{ fmtQty(ln.quantity) }} {{ ln.base_unit ?? '' }}
                                    </td>
                                    <td v-if="hasDiscrepancy" class="px-3 py-2 text-right">
                                        <template v-if="lineDiscrepancy(ln)">
                                            <div class="font-mono font-semibold" :class="lineDiscrepancy(ln) === 'over' ? 'text-sky-700' : 'text-red-700'">
                                                {{ fmtQty(ln.quantity_received!) }} {{ ln.base_unit ?? '' }}
                                            </div>
                                            <div class="text-xs" :class="lineDiscrepancy(ln) === 'over' ? 'text-sky-700' : 'text-red-600'">
                                                {{ DISCREPANCY_LABEL[lineDiscrepancy(ln)!] }} {{ fmtQty(lineDiscrepancyQty(ln)) }}<span v-if="ln.discrepancy_reason">: {{ ln.discrepancy_reason }}</span>
                                            </div>
                                            <div v-if="ln.discrepancy_type && ln.resolution" class="mt-0.5 text-xs text-emerald-700">
                                                {{ RESOLUTION_LABEL[ln.resolution] }}<span v-if="ln.resolved_by"> · {{ ln.resolved_by.name }}</span>
                                                <span v-if="ln.resolution_note" class="block text-slate-500">{{ ln.resolution_note }}</span>
                                            </div>
                                            <div v-else-if="ln.discrepancy_type" class="mt-0.5 text-xs font-semibold text-amber-700">Belum diputuskan</div>
                                        </template>
                                        <span v-else class="text-xs text-slate-400">Sesuai</span>
                                    </td>
                                    <td v-if="canViewCost" class="px-3 py-2 text-right font-mono text-xs text-slate-600">
                                        {{ ln.unit_cost_snapshot ? `Rp${Number(ln.unit_cost_snapshot).toLocaleString('id-ID')}` : '—' }}
                                    </td>
                                    <td v-if="canViewCost" class="px-3 py-2 text-right font-mono text-xs text-slate-600">
                                        {{ ln.total_cost_snapshot ? `Rp${Number(ln.total_cost_snapshot).toLocaleString('id-ID')}` : '—' }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <p class="text-xs text-slate-500">
                        Mutasi stok tercatat di
                        <Link href="/stock-movements" class="text-violet-700 hover:underline">Riwayat stok</Link>
                        (transfer keluar / transfer masuk / pembalik).
                    </p>
                </CardContent>
            </Card>
        </div>
    </AdminLayout>
</template>
