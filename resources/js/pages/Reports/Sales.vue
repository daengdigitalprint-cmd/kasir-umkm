<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import ReportTabs from '@/components/ReportTabs.vue';
import ReportExportButton from '@/components/ReportExportButton.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Receipt, Trash2, FileText, Pencil, Ban, Store } from 'lucide-vue-next';
import { confirmDialog, toastSuccess, toastError } from '@/lib/swal';
import { ref, reactive, watch, onMounted, onUnmounted } from 'vue';
import InvoiceModal from '@/components/InvoiceModal.vue';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';

interface Customer {
    id: number;
    name: string;
}

interface TransactionItem {
    id: number;
    total_price: number;
    total_cost_snapshot?: number | null;
    gross_profit_snapshot?: number | null;
}

interface Transaction {
    id: number;
    transaction_number: string;
    total_amount: number;
    created_at: string;
    payment_method?: string | null;
    notes?: string | null;
    status?: string | null;
    is_void?: boolean;
    void_reason?: string | null;
    customer?: Customer | null;
    items?: TransactionItem[];
}

interface Paginated {
    data: Transaction[];
    links: { url: string | null; label: string; active: boolean }[];
}

const props = withDefaults(
    defineProps<{
        store: { id: number; name: string; currency: string };
        dateRange: { start_date: string; end_date: string };
        transactions: Paginated;
        customers?: { id: number; name: string }[];
        saleClass?: string | null;
        pharmacyEnabled?: boolean;
        hppSummary?: {
            total_revenue: string | number;
            total_cost: string | number;
            total_gross_profit: string | number;
            items_with_hpp: number;
        };
        channelBreakdown?: Array<{
            channel: string;
            label: string;
            group: string;
            is_aggregator: boolean;
            transaction_count: number;
            gross_revenue: number;
            commission_amount: number;
            net_revenue: number;
        }>;
        readonly?: boolean;
    }>(),
    {
        hppSummary: () => ({ total_revenue: 0, total_cost: 0, total_gross_profit: 0, items_with_hpp: 0 }),
        channelBreakdown: () => [],
        readonly: false,
    },
);

const filterStart = ref(props.dateRange.start_date);
const filterEnd = ref(props.dateRange.end_date);
const saleClass = ref(props.saleClass || '');

watch(
    () => props.dateRange,
    (dr) => {
        filterStart.value = dr.start_date;
        filterEnd.value = dr.end_date;
    },
);

const applyFilter = () => {
    const url = props.readonly ? '/pos/transactions' : '/reports/sales';
    const params = props.readonly
        ? { date_from: filterStart.value, date_to: filterEnd.value }
        : { start_date: filterStart.value, end_date: filterEnd.value, ...(saleClass.value ? { sale_class: saleClass.value } : {}) };
    router.get(url, params, { preserveState: true, preserveScroll: true });
};

// ── Polling untuk mode riwayat transaksi (readonly) ────────────────────────
// Otomatis refresh data transaksi setiap 10 detik supaya riwayat terbaru
// langsung muncul tanpa perlu reload manual.
let pollId: ReturnType<typeof setInterval> | null = null;

onMounted(() => {
    if (props.readonly) {
        pollId = setInterval(() => {
            router.reload({ only: ['transactions'], preserveScroll: true });
        }, 10_000);
    }
});

onUnmounted(() => {
    if (pollId !== null) {
        clearInterval(pollId);
    }
});

const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('id-ID', {
        style: 'decimal',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(amount);
};

const formatDate = (iso: string) =>
    new Date(iso).toLocaleString('id-ID', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });

const paymentLabel = (m: string | null | undefined) => {
    const map: Record<string, string> = {
        cash: 'Tunai',
        card: 'Kartu',
        ewallet: 'E-Wallet',
        transfer: 'Transfer',
        other: 'Lainnya',
    };
    return map[m || ''] || m || '—';
};

const transactionGrossProfit = (t: Transaction): number | null => {
    if (!t.items?.length) return null;
    const hasCost = t.items.some((i) => i.total_cost_snapshot != null);
    if (!hasCost) return null;
    return t.items.reduce((sum, i) => sum + (i.total_price - (i.total_cost_snapshot ?? 0)), 0);
};

const invoiceModalOpen = ref(false);
const invoiceTransactionId = ref<number | null>(null);

function openInvoiceModal(transactionId: number) {
    invoiceTransactionId.value = transactionId;
    invoiceModalOpen.value = true;
}

/* ── Void modal ── */
const voidModalOpen = ref(false);
const voidSaving = ref(false);
const voidForm = reactive({
    id: 0,
    transaction_number: '',
    total_amount: 0,
    reason: '',
});

function openVoidModal(t: Transaction) {
    voidForm.id = t.id;
    voidForm.transaction_number = t.transaction_number;
    voidForm.total_amount = Number(t.total_amount);
    voidForm.reason = '';
    voidModalOpen.value = true;
}

async function submitVoid() {
    if (!voidForm.reason.trim()) {
        await toastError('Alasan pembatalan wajib diisi.');
        return;
    }

    voidSaving.value = true;
    try {
        const res = await fetch(`/transactions/${voidForm.id}/void`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                'Accept': 'application/json',
            },
            body: JSON.stringify({ void_reason: voidForm.reason.trim() }),
        });

        if (!res.ok) {
            let errorMsg = 'Gagal membatalkan transaksi';
            try {
                const data = await res.json();
                errorMsg = data.message || errorMsg;
            } catch {}
            await toastError(errorMsg);
            return;
        }

        await toastSuccess('Transaksi berhasil dibatalkan (void). Stok, diskon, dan poin telah dikembalikan.', 2500);
        voidModalOpen.value = false;
        router.reload();
    } catch {
        await toastError('Terjadi kesalahan saat membatalkan transaksi');
    } finally {
        voidSaving.value = false;
    }
}

async function deleteTransaction(transaction: Transaction) {
    const confirmed = await confirmDialog(
        'Hapus Transaksi Permanen?',
        [
            `Nomor: ${transaction.transaction_number}`,
            `Total: Rp ${formatCurrency(transaction.total_amount)}`,
            '',
            'Tindakan ini akan menghapus transaksi secara permanen dari seluruh sistem:',
            '- Stok produk akan dikembalikan',
            '- Diskon & poin loyalitas akan dibatalkan',
            '- Entri jurnal akuntansi akan dihapus',
            '',
            'Data tidak dapat dikembalikan setelah dihapus.',
        ].join('\n'),
        {
            confirmText: 'Hapus Permanen',
            icon: 'warning',
            confirmColor: '#dc2626',
        },
    );

    if (!confirmed) return;

    try {
        const res = await fetch(`/transactions/${transaction.id}`, {
            method: 'DELETE',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
            },
        });

        if (!res.ok) {
            let errorMsg = 'Gagal menghapus transaksi';
            try {
                const data = await res.json();
                errorMsg = data.message || errorMsg;
            } catch {}
            await toastError(errorMsg);
            return;
        }

        await toastSuccess('Transaksi berhasil dihapus permanen. Stok, diskon, dan poin telah dikembalikan.', 2500);
        router.reload();
    } catch {
        await toastError('Terjadi kesalahan saat menghapus transaksi');
    }
}

/* ── Edit modal ── */
const editModalOpen = ref(false);
const editSaving = ref(false);
const editForm = reactive({
    id: 0,
    transaction_number: '',
    customer_id: null as number | null,
    payment_method: 'cash',
    notes: '',
});

function openEditModal(t: Transaction) {
    editForm.id = t.id;
    editForm.transaction_number = t.transaction_number;
    editForm.customer_id = t.customer?.id ?? null;
    editForm.payment_method = t.payment_method || 'cash';
    editForm.notes = t.notes || '';
    editModalOpen.value = true;
}

async function saveEditTransaction() {
    editSaving.value = true;
    try {
        const res = await fetch(`/transactions/${editForm.id}`, {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                'Accept': 'application/json',
            },
            body: JSON.stringify({
                customer_id: editForm.customer_id || null,
                payment_method: editForm.payment_method,
                notes: editForm.notes,
            }),
        });

        if (!res.ok) {
            let errorMsg = 'Gagal menyimpan perubahan';
            try {
                const data = await res.json();
                errorMsg = data.message || errorMsg;
            } catch {}
            await toastError(errorMsg);
            return;
        }

        await toastSuccess('Transaksi berhasil diperbarui.', 2000);
        editModalOpen.value = false;
        router.reload();
    } catch {
        await toastError('Terjadi kesalahan saat menyimpan perubahan');
    } finally {
        editSaving.value = false;
    }
}
</script>

<template>
    <AdminLayout :title="readonly ? 'Riwayat Transaksi' : 'Laporan penjualan'">
        <Head :title="readonly ? 'Riwayat Transaksi' : 'Laporan penjualan'" />

        <div v-if="!readonly" class="flex items-start justify-between gap-3">
            <ReportTabs active-tab="penjualan" />
            <ReportExportButton report="penjualan" :params="{ start_date: filterStart, end_date: filterEnd }" />
        </div>


        <Card class="border-0 shadow-md mb-6">
            <CardContent class="p-4 md:p-5">
                <div class="flex flex-col gap-4 md:flex-row md:items-end md:flex-wrap">
                    <div class="space-y-2">
                        <Label>Dari</Label>
                        <Input v-model="filterStart" type="date" class="max-w-xs" />
                    </div>
                    <div class="space-y-2">
                        <Label>Sampai</Label>
                        <Input v-model="filterEnd" type="date" class="max-w-xs" />
                    </div>
                    <select v-if="!readonly && props.pharmacyEnabled" v-model="saleClass" class="h-10 rounded-xl border px-3 text-sm"><option value="">Semua penjualan</option><option value="prescription">Penjualan resep</option><option value="non_prescription">Non-resep</option></select>
                    <Button type="button" class="rounded-xl" @click="applyFilter">Terapkan</Button>
                </div>
            </CardContent>
        </Card>

        <Card v-if="hppSummary.items_with_hpp > 0" class="border-0 shadow-md mb-6">
            <CardContent class="p-4 md:p-5">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div class="rounded-xl bg-slate-50 p-4">
                        <p class="text-xs text-slate-500 mb-1">Total Omzet</p>
                        <p class="text-lg font-semibold text-slate-800">Rp {{ formatCurrency(Number(hppSummary.total_revenue)) }}</p>
                    </div>
                    <div class="rounded-xl bg-slate-50 p-4">
                        <p class="text-xs text-slate-500 mb-1">Total HPP</p>
                        <p class="text-lg font-semibold text-slate-800">Rp {{ formatCurrency(Number(hppSummary.total_cost)) }}</p>
                    </div>
                    <div class="rounded-xl bg-slate-50 p-4">
                        <p class="text-xs text-slate-500 mb-1">Laba Kotor</p>
                        <p class="text-lg font-semibold text-emerald-700">Rp {{ formatCurrency(Number(hppSummary.total_gross_profit)) }}</p>
                    </div>
                </div>
            </CardContent>
        </Card>

        <Card v-if="channelBreakdown.length > 0" class="border-0 shadow-md mb-6">
            <CardHeader>
                <CardTitle class="flex items-center gap-2">
                    <Store class="h-5 w-5 text-slate-600" />
                    Ringkasan per Channel
                </CardTitle>
                <CardDescription>Distribusi omzet berdasarkan asal channel (offline vs online)</CardDescription>
            </CardHeader>
            <CardContent class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b text-left text-slate-500">
                            <th class="pb-3 pr-4 font-medium">Channel</th>
                            <th class="pb-3 pr-4 font-medium">Tipe</th>
                            <th class="pb-3 pr-4 text-right font-medium">Transaksi</th>
                            <th class="pb-3 pr-4 text-right font-medium">Omzet Kotor</th>
                            <th class="pb-3 pr-4 text-right font-medium">Komisi</th>
                            <th class="pb-3 text-right font-medium">Omzet Bersih</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="c in channelBreakdown" :key="c.channel" class="border-b last:border-0">
                            <td class="py-2.5 pr-4 font-medium text-slate-800">{{ c.label }}</td>
                            <td class="py-2.5 pr-4">
                                <span :class="c.group === 'online' ? 'inline-flex rounded-full bg-blue-50 px-2 py-0.5 text-xs font-medium text-blue-700' : 'inline-flex rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600'">
                                    {{ c.group === 'online' ? 'Online' : 'Offline' }}
                                </span>
                            </td>
                            <td class="py-2.5 pr-4 text-right">{{ c.transaction_count }}</td>
                            <td class="py-2.5 pr-4 text-right">Rp {{ formatCurrency(c.gross_revenue) }}</td>
                            <td class="py-2.5 pr-4 text-right" :class="c.commission_amount > 0 ? 'text-rose-600' : 'text-slate-400'">
                                {{ c.commission_amount > 0 ? 'Rp ' + formatCurrency(c.commission_amount) : '—' }}
                            </td>
                            <td class="py-2.5 text-right font-semibold text-emerald-700">Rp {{ formatCurrency(c.net_revenue) }}</td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-slate-200">
                            <td colspan="2" class="pt-3 font-semibold text-slate-700">Total Offline</td>
                            <td colspan="4" class="pt-3 text-right font-semibold text-slate-700">Rp {{ formatCurrency(channelBreakdown.filter(c => c.group === 'offline').reduce((s, c) => s + c.gross_revenue, 0)) }}</td>
                        </tr>
                        <tr>
                            <td colspan="2" class="pb-3 font-semibold text-slate-700">Total Online</td>
                            <td colspan="4" class="pb-3 text-right font-semibold text-slate-700">Rp {{ formatCurrency(channelBreakdown.filter(c => c.group === 'online').reduce((s, c) => s + c.gross_revenue, 0)) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </CardContent>
        </Card>

        <Card class="border-0 shadow-lg">
            <CardHeader>
                <CardTitle class="flex items-center gap-2">
                    <Receipt class="h-5 w-5 text-slate-600" />
                    Transaksi selesai
                </CardTitle>
                <CardDescription>
                    {{ store.name }} — {{ transactions.data?.length ?? 0 }} entri di halaman ini
                </CardDescription>
            </CardHeader>
            <CardContent class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b text-left text-slate-500">
                            <th class="pb-3 pr-4 font-medium">No.</th>
                            <th class="pb-3 pr-4 font-medium">Waktu</th>
                            <th class="pb-3 pr-4 font-medium">Pelanggan</th>
                            <th class="pb-3 pr-4 font-medium">Bayar</th>
                            <th class="pb-3 pr-4 font-medium text-right">Total</th>
                            <th v-if="!readonly" class="pb-3 pr-4 font-medium text-right">Laba Kotor</th>
                            <th v-if="!readonly" class="pb-3 font-medium text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="t in transactions.data" :key="t.id" class="border-b border-slate-100">
                            <td class="py-3 pr-4 font-mono text-xs">
                                <button @click="openInvoiceModal(t.id)" class="hover:underline text-teal-700 text-left cursor-pointer" :class="{ 'line-through text-slate-400': t.is_void }">
                                    {{ t.transaction_number }}
                                </button>
                                <span v-if="t.is_void" class="ml-1 inline-flex items-center rounded-full bg-red-50 px-1.5 py-0.5 text-[10px] font-medium text-red-700">VOID</span>
                            </td>
                            <td class="py-3 pr-4 whitespace-nowrap">{{ formatDate(t.created_at) }}</td>
                            <td class="py-3 pr-4">{{ t.customer?.name ?? '—' }}</td>
                            <td class="py-3 pr-4">{{ paymentLabel(t.payment_method) }}</td>
                            <td class="py-3 pr-4 text-right font-semibold">Rp {{ formatCurrency(t.total_amount) }}</td>
                            <td v-if="!readonly" class="py-3 pr-4 text-right">
                                <span v-if="transactionGrossProfit(t) !== null" class="text-emerald-700 font-semibold">Rp {{ formatCurrency(transactionGrossProfit(t)!) }}</span>
                                <span v-else class="text-slate-400">—</span>
                            </td>
                            <td v-if="!readonly" class="py-3 text-center">
                                <div class="flex items-center justify-center gap-1">
                                    <template v-if="!t.is_void">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            class="h-7 px-2 rounded-lg text-blue-600 border-blue-200 hover:bg-blue-50 hover:border-blue-300"
                                            @click="openEditModal(t)"
                                            title="Edit transaksi"
                                        >
                                            <Pencil class="h-3 w-3" />
                                        </Button>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            class="h-7 px-2 rounded-lg text-amber-600 border-amber-200 hover:bg-amber-50 hover:border-amber-300"
                                            @click="openVoidModal(t)"
                                            title="Batalkan (void) transaksi"
                                        >
                                            <Ban class="h-3 w-3" />
                                        </Button>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            class="h-7 px-2 rounded-lg text-red-600 border-red-200 hover:bg-red-50 hover:border-red-300"
                                            @click="deleteTransaction(t)"
                                            title="Hapus permanen (khusus owner)"
                                        >
                                            <Trash2 class="h-3 w-3" />
                                        </Button>
                                    </template>
                                    <span v-else class="text-xs text-slate-400" :title="t.void_reason || ''">Dibatalkan</span>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!transactions.data?.length">
                            <td :colspan="readonly ? 5 : 7" class="py-10 text-center text-slate-500">Tidak ada transaksi di periode ini.</td>
                        </tr>
                    </tbody>
                </table>

                <div v-if="transactions.links && transactions.links.length > 3" class="mt-6 flex justify-center">
                    <nav class="flex flex-wrap items-center justify-center gap-1">
                        <Link
                            v-for="link in transactions.links"
                            :key="`${link.label}-${link.active}`"
                            :href="link.url || '#'"
                            :class="[
                                'px-3 py-2 text-sm rounded-xl font-semibold transition-colors',
                                link.active ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100',
                            ]"
                            v-html="link.label"
                        />
                    </nav>
                </div>
            </CardContent>
        </Card>

        <InvoiceModal
            :open="invoiceModalOpen"
            :transaction-id="invoiceTransactionId"
            @update:open="invoiceModalOpen = $event"
        />

        <!-- Edit Transaksi Modal -->
        <Dialog :open="editModalOpen" @update:open="editModalOpen = $event">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Edit Transaksi</DialogTitle>
                    <DialogDescription>
                        {{ editForm.transaction_number }}
                    </DialogDescription>
                </DialogHeader>

                <div class="space-y-4 py-2">
                    <div class="space-y-2">
                        <Label for="edit-customer">Pelanggan</Label>
                        <select
                            id="edit-customer"
                            v-model="editForm.customer_id"
                            class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                        >
                            <option :value="null">— Tanpa Pelanggan —</option>
                            <option v-for="c in (props.customers ?? [])" :key="c.id" :value="c.id">
                                {{ c.name }}
                            </option>
                        </select>
                    </div>

                    <div class="space-y-2">
                        <Label for="edit-payment">Metode Pembayaran</Label>
                        <select
                            id="edit-payment"
                            v-model="editForm.payment_method"
                            class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                        >
                            <option value="cash">Tunai</option>
                            <option value="card">Kartu</option>
                            <option value="ewallet">E-Wallet</option>
                            <option value="transfer">Transfer</option>
                            <option value="other">Lainnya</option>
                        </select>
                    </div>

                    <div class="space-y-2">
                        <Label for="edit-notes">Catatan</Label>
                        <Textarea
                            id="edit-notes"
                            v-model="editForm.notes"
                            placeholder="Catatan opsional..."
                            rows="3"
                        />
                    </div>
                </div>

                <DialogFooter>
                    <Button
                        variant="outline"
                        class="rounded-xl"
                        @click="editModalOpen = false"
                        :disabled="editSaving"
                    >
                        Batal
                    </Button>
                    <Button
                        class="rounded-xl"
                        @click="saveEditTransaction"
                        :disabled="editSaving"
                    >
                        {{ editSaving ? 'Menyimpan...' : 'Simpan' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <!-- Void Modal -->
        <Dialog :open="voidModalOpen" @update:open="voidModalOpen = $event">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Batalkan (Void) Transaksi</DialogTitle>
                    <DialogDescription>
                        Anda akan membatalkan transaksi <span class="font-bold text-slate-700">{{ voidForm.transaction_number }}</span> senilai Rp {{ formatCurrency(voidForm.total_amount) }}.<br/>
                        Stok, diskon, dan jurnal akan dikembalikan, tetapi data ini tetap tercatat di sistem dengan status VOID.
                    </DialogDescription>
                </DialogHeader>

                <div class="space-y-4 py-2">
                    <div class="space-y-2">
                        <Label>Alasan Pembatalan <span class="text-red-500">*</span></Label>
                        <Textarea 
                            v-model="voidForm.reason" 
                            placeholder="Tuliskan alasan yang jelas (wajib)..." 
                            class="resize-none border-red-200 focus-visible:ring-red-500" 
                            rows="3" 
                        />
                    </div>
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        class="rounded-xl"
                        @click="voidModalOpen = false"
                        :disabled="voidSaving"
                    >
                        Tutup
                    </Button>
                    <Button
                        type="button"
                        variant="destructive"
                        class="rounded-xl bg-amber-600 hover:bg-amber-700 text-white"
                        @click="submitVoid"
                        :disabled="voidSaving || !voidForm.reason.trim()"
                    >
                        {{ voidSaving ? 'Memproses...' : 'Proses Void' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AdminLayout>
</template>
