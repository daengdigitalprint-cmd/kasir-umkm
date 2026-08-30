<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Wallet, Plus, Trash2, Receipt } from 'lucide-vue-next';
import { ref } from 'vue';
import { confirmDelete, toastSuccess } from '@/lib/swal';
import ReportExportButton from '@/components/ReportExportButton.vue';

interface ExpenseRow {
    id: number;
    category: string;
    description: string;
    amount: number;
    expense_date: string;
    payment_method: string;
    notes: string | null;
    created_at: string;
    user: { id: number; name: string } | null;
}

interface CategoryItem {
    [key: string]: string;
}

const props = defineProps<{
    expenses: { data: ExpenseRow[]; current_page: number; last_page: number; total: number; per_page: number; links: Array<{ url: string | null; label: string; active: boolean }> };
    categories: CategoryItem;
    summary: { total: number; count: number };
    byCategory: Array<{ category: string; total: number }>;
    filters: { category?: string; start_date?: string; end_date?: string };
}>();

const showDialog = ref(false);
const isDeleting = ref(false);

const form = useForm({
    category: '',
    description: '',
    amount: '',
    expense_date: new Date().toISOString().slice(0, 10),
    payment_method: 'cash',
    notes: '',
});

function submit() {
    form.post('/expenses', {
        preserveScroll: true,
        onSuccess: () => {
            showDialog.value = false;
            toastSuccess('Biaya operasional berhasil dicatat.');
        },
    });
}

async function destroyExpense(expense: ExpenseRow) {
    const confirmed = await confirmDelete(expense.description);
    if (!confirmed) return;
    isDeleting.value = true;
    router.delete(`/expenses/${expense.id}`, {
        preserveScroll: true,
        onFinish: () => { isDeleting.value = false; },
        onSuccess: () => toastSuccess('Biaya operasional berhasil dihapus.'),
    });
}

const formatRp = (n: number | string | null) => {
    const num = typeof n === 'string' ? parseFloat(n) : (n ?? 0);
    return 'Rp ' + num.toLocaleString('id-ID', { maximumFractionDigits: 0 });
};

const filterCategory = ref(props.filters.category ?? '');
const filterStart = ref(props.filters.start_date ?? '');
const filterEnd = ref(props.filters.end_date ?? '');

const applyFilter = () => {
    const params: Record<string, string> = {};
    if (filterCategory.value) params.category = filterCategory.value;
    if (filterStart.value) params.start_date = filterStart.value;
    if (filterEnd.value) params.end_date = filterEnd.value;
    router.get('/expenses', params, { preserveState: true, preserveScroll: true });
};

const goToPage = (page: number) => {
    router.get('/expenses', { ...props.filters, page }, { preserveState: true, preserveScroll: true });
};

const categoryColor = (cat: string) => {
    const colors: Record<string, string> = {
        sewa: 'bg-blue-100 text-blue-800',
        gaji: 'bg-green-100 text-green-800',
        listrik: 'bg-yellow-100 text-yellow-800',
        air: 'bg-cyan-100 text-cyan-800',
        internet: 'bg-purple-100 text-purple-800',
        transport: 'bg-orange-100 text-orange-800',
        lainnya: 'bg-slate-100 text-slate-800',
    };
    return colors[cat] ?? 'bg-slate-100 text-slate-800';
};

const paymentLabel = (m: string) => ({ cash: 'Tunai', transfer: 'Transfer', ewallet: 'E-Wallet' }[m] ?? m);

// Format rupiah untuk input field
const amountDisplay = ref('');

function formatAmountInput(e: Event) {
    const input = e.target as HTMLInputElement;
    const raw = input.value.replace(/[^0-9]/g, '');
    amountDisplay.value = raw ? Number(raw).toLocaleString('id-ID') : '';
    form.amount = raw || '';
}

function openCreateDialog() {
    form.reset();
    form.expense_date = new Date().toISOString().slice(0, 10);
    form.payment_method = 'cash';
    amountDisplay.value = '';
    showDialog.value = true;
}

</script>

<template>
    <AdminLayout title="Biaya Operasional">
        <Head title="Biaya Operasional" />

        <div class="flex items-center justify-between gap-3 mb-6">
            <div>
                <p class="text-sm text-slate-600">Catat semua biaya operasional usaha Anda (sewa, gaji, listrik, dll).</p>
            </div>
            <div class="flex items-center gap-2">
                <ReportExportButton report="beban" :params="{ category: filterCategory, start_date: filterStart, end_date: filterEnd }" />
                <Button @click="openCreateDialog" class="inline-flex items-center gap-2">
                    <Plus class="h-4 w-4" />
                    Tambah Biaya
                </Button>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardContent class="p-4">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-primary/10">
                            <Wallet class="h-5 w-5 text-primary" />
                        </div>
                        <div>
                            <p class="text-xs text-slate-500">Total Biaya</p>
                            <p class="text-lg font-bold text-slate-900">{{ formatRp(summary.total) }}</p>
                        </div>
                    </div>
                </CardContent>
            </Card>
            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardContent class="p-4">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-amber-500/10">
                            <Receipt class="h-5 w-5 text-amber-700" />
                        </div>
                        <div>
                            <p class="text-xs text-slate-500">Jumlah Transaksi</p>
                            <p class="text-lg font-bold text-slate-900">{{ summary.count }}</p>
                        </div>
                    </div>
                </CardContent>
            </Card>
            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardContent class="p-4">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-500/10">
                            <span class="text-sm font-bold text-emerald-700">{{ byCategory.length }}</span>
                        </div>
                        <div>
                            <p class="text-xs text-slate-500">Kategori Aktif</p>
                            <p class="text-lg font-bold text-slate-900">Kategori</p>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>

        <!-- Filter -->
        <Card class="rounded-2xl border-slate-200 shadow-sm mb-6">
            <CardContent class="p-4">
                <form class="flex flex-wrap items-end gap-3" @submit.prevent="applyFilter">
                    <div class="space-y-1.5">
                        <Label class="text-xs">Kategori</Label>
                        <select v-model="filterCategory" class="flex h-9 w-44 items-center rounded-md border border-slate-200 bg-white px-3 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20">
                            <option value="">Semua kategori</option>
                            <option v-for="(label, key) in props.categories" :key="key" :value="key">{{ label }}</option>
                        </select>
                    </div>
                    <div class="space-y-1.5">
                        <Label class="text-xs">Dari</Label>
                        <Input v-model="filterStart" type="date" class="w-40" />
                    </div>
                    <div class="space-y-1.5">
                        <Label class="text-xs">Sampai</Label>
                        <Input v-model="filterEnd" type="date" class="w-40" />
                    </div>
                    <Button type="submit" variant="outline">Terapkan</Button>
                </form>
            </CardContent>
        </Card>

        <!-- By Category Breakdown -->
        <Card v-if="byCategory.length > 0" class="rounded-2xl border-slate-200 shadow-sm mb-6">
            <CardHeader class="pb-2">
                <CardTitle class="text-sm">Rincian per Kategori</CardTitle>
            </CardHeader>
            <CardContent>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    <div v-for="item in byCategory" :key="item.category" class="rounded-xl bg-slate-50 p-3">
                        <p class="text-xs text-slate-500">{{ categories[item.category] ?? item.category }}</p>
                        <p class="text-sm font-bold text-slate-900">{{ formatRp(item.total) }}</p>
                    </div>
                </div>
            </CardContent>
        </Card>

        <!-- Table -->
        <Card class="rounded-2xl border-slate-200 shadow-sm">
            <CardHeader class="pb-2">
                <CardTitle class="text-sm">Daftar Biaya Operasional</CardTitle>
                <CardDescription>Menampilkan {{ expenses.data.length }} dari {{ expenses.total }} biaya</CardDescription>
            </CardHeader>
            <CardContent class="p-0">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-100 bg-slate-50/80">
                                <th class="px-4 py-3 text-left font-semibold text-slate-700">Tanggal</th>
                                <th class="px-4 py-3 text-left font-semibold text-slate-700">Kategori</th>
                                <th class="px-4 py-3 text-left font-semibold text-slate-700">Deskripsi</th>
                                <th class="px-4 py-3 text-left font-semibold text-slate-700">Metode</th>
                                <th class="px-4 py-3 text-left font-semibold text-slate-700">Catat oleh</th>
                                <th class="px-4 py-3 text-right font-semibold text-slate-700">Jumlah</th>
                                <th class="px-4 py-3 text-right font-semibold text-slate-700">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="expense in expenses.data" :key="expense.id" class="border-b border-slate-50 hover:bg-slate-50/50">
                                <td class="px-4 py-3 text-slate-700">{{ new Date(expense.expense_date).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) }}</td>
                                <td class="px-4 py-3">
                                    <span :class="['inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium', categoryColor(expense.category)]">
                                        {{ categories[expense.category] ?? expense.category }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-slate-700 max-w-[200px] truncate">{{ expense.description }}</td>
                                <td class="px-4 py-3 text-slate-500 text-xs">{{ paymentLabel(expense.payment_method) }}</td>
                                <td class="px-4 py-3 text-slate-500 text-xs">{{ expense.user?.name ?? '-' }}</td>
                                <td class="px-4 py-3 text-right font-semibold text-red-600">-{{ formatRp(expense.amount) }}</td>
                                <td class="px-4 py-3 text-right">
                                    <button @click="destroyExpense(expense)" :disabled="isDeleting" class="p-1.5 rounded-lg text-slate-400 hover:text-red-600 hover:bg-red-50 transition-colors disabled:opacity-50">
                                        <Trash2 class="h-4 w-4" />
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="expenses.data.length === 0">
                                <td colspan="7" class="px-4 py-12 text-center text-slate-400">Belum ada biaya operasional.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div v-if="expenses.last_page > 1" class="flex items-center justify-between px-4 py-3 border-t border-slate-100">
                    <p class="text-xs text-slate-500">Halaman {{ expenses.current_page }} dari {{ expenses.last_page }}</p>
                    <div class="flex gap-1">
                        <Button v-if="expenses.current_page > 1" @click="goToPage(expenses.current_page - 1)" variant="outline" size="sm">Sebelumnya</Button>
                        <Button v-if="expenses.current_page < expenses.last_page" @click="goToPage(expenses.current_page + 1)" variant="outline" size="sm">Selanjutnya</Button>
                    </div>
                </div>
            </CardContent>
        </Card>

        <!-- Dialog Create -->
        <Dialog v-model:open="showDialog">
            <DialogContent class="sm:max-w-[500px]">
                <DialogHeader>
                    <DialogTitle>Tambah Biaya Operasional</DialogTitle>
                    <DialogDescription>Catat biaya operasional baru. Jurnal akuntansi akan diposting otomatis.</DialogDescription>
                </DialogHeader>
                <form @submit.prevent="submit" class="space-y-4">
                    <div class="space-y-1.5">
                        <Label>Kategori <span class="text-red-500">*</span></Label>
                        <select v-model="form.category" required class="flex h-9 w-full items-center rounded-md border border-slate-200 bg-white px-3 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20">
                            <option value="" disabled>Pilih kategori</option>
                            <option v-for="(label, key) in props.categories" :key="key" :value="key">{{ label }}</option>
                        </select>
                        <p v-if="form.errors.category" class="text-xs text-red-500">{{ form.errors.category }}</p>
                    </div>
                    <div class="space-y-1.5">
                        <Label>Deskripsi <span class="text-red-500">*</span></Label>
                        <Input v-model="form.description" placeholder="Contoh: Sewa bulanan Agustus" required />
                        <p v-if="form.errors.description" class="text-xs text-red-500">{{ form.errors.description }}</p>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="space-y-1.5">
                            <Label>Jumlah (Rp) <span class="text-red-500">*</span></Label>
                            <div class="relative">
                                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-sm text-slate-500">Rp</span>
                                <input
                                    :value="amountDisplay"
                                    @input="formatAmountInput"
                                    type="text"
                                    inputmode="numeric"
                                    placeholder="0"
                                    class="flex h-9 w-full items-center rounded-md border border-slate-200 bg-white pl-10 pr-3 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20"
                                    required
                                />
                            </div>
                            <p v-if="form.errors.amount" class="text-xs text-red-500">{{ form.errors.amount }}</p>
                        </div>
                        <div class="space-y-1.5">
                            <Label>Tanggal <span class="text-red-500">*</span></Label>
                            <Input v-model="form.expense_date" type="date" required />
                            <p v-if="form.errors.expense_date" class="text-xs text-red-500">{{ form.errors.expense_date }}</p>
                        </div>
                    </div>
                    <div class="space-y-1.5">
                        <Label>Metode Pembayaran</Label>
                        <select v-model="form.payment_method" class="flex h-9 w-full items-center rounded-md border border-slate-200 bg-white px-3 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20">
                            <option value="cash">Tunai</option>
                            <option value="transfer">Transfer</option>
                            <option value="ewallet">E-Wallet</option>
                        </select>
                    </div>
                    <div class="space-y-1.5">
                        <Label>Catatan</Label>
                        <Textarea v-model="form.notes" placeholder="Catatan tambahan (opsional)" rows="2" />
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" @click="showDialog = false">Batal</Button>
                        <Button type="submit" :disabled="form.processing">
                            {{ form.processing ? 'Menyimpan...' : 'Simpan' }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </AdminLayout>
</template>
