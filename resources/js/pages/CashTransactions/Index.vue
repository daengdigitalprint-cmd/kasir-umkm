<script setup lang="ts">
import { Head, useForm, Link, usePage } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { ArrowLeft, ArrowDownCircle, ArrowUpCircle, BarChart3 } from 'lucide-vue-next';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { toastSuccess, toastError } from '@/lib/swal';

interface TransactionRow {
    id: number;
    type: 'in' | 'out';
    type_label: string;
    category: string;
    category_label: string;
    amount: string;
    party_name: string | null;
    notes: string;
    recorded_by: string | null;
    created_at: string;
}

const props = defineProps<{
    shift: {
        id: number;
        shift_number: string;
        opening_cash: number;
        expected_cash: number;
    };
    summary: {
        opening_cash: number;
        sales_cash: number;
        cash_in: number;
        cash_out: number;
        expected_cash: number;
    };
    transactions: TransactionRow[];
    canViewReport?: boolean;
}>();

const activeTab = ref<'in' | 'out'>('in');
const showCategoryModal = ref(false);
const page = usePage();

const flashSuccess = computed(() => (page.props as { flash?: { success?: string } }).flash?.success);
const flashError = computed(() => (page.props as { flash?: { error?: string } }).flash?.error);

watch(flashSuccess, (m) => { if (m) void toastSuccess(m); });
watch(flashError, (m) => { if (m) void toastError(m); });

const form = useForm({
    type: 'in',
    category: '',
    amount: '',
    party_name: '',
    notes: '',
});

function switchTab(tab: 'in' | 'out') {
    activeTab.value = tab;
    form.reset();
    form.type = tab;
}

const categories = {
    in: [
        { id: 'revenue', name: 'Pendapatan Lain', desc: 'Uang yang masuk ke laci kasir yang menambahkan profit atau laba bisnis. Contoh: Menjual kardus bekas.' },
        { id: 'non_revenue', name: 'Non Pendapatan', desc: 'Uang yang masuk ke laci kasir yang TIDAK menambahkan profit. Contoh: Tambahan modal kembalian.' },
    ],
    out: [
        { id: 'expense', name: 'Biaya', desc: 'Uang yang keluar dari laci kasir yang mengurangi profit atau laba bisnis. Contoh: Bayar listrik warung, beli galon.' },
        { id: 'non_expense', name: 'Non Biaya', desc: 'Uang yang keluar dari laci kasir yang TIDAK mengurangi profit. Contoh: Ambil uang disetorkan ke owner.' },
    ],
};

const currentCategories = computed(() => categories[activeTab.value]);

const selectedCategoryName = computed(() => {
    if (!form.category) return 'Pilih jenis transaksi...';
    const found = currentCategories.value.find(c => c.id === form.category);
    return found ? found.name : '';
});

const filteredTransactions = computed(() =>
    props.transactions.filter((t) => t.type === activeTab.value),
);

const tabTotal = computed(() =>
    filteredTransactions.value.reduce((sum, t) => sum + Number(t.amount), 0),
);

function selectCategory(id: string) {
    form.category = id;
    showCategoryModal.value = false;
}

function formatMoney(n: number) {
    return new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(n);
}

function formatRupiah(value: string | number): string {
    const n = Number(value);
    if (!Number.isFinite(n)) return 'Rp 0';
    return 'Rp ' + n.toLocaleString('id-ID', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
}

const amountDisplay = computed({
    get() {
        const raw = form.amount;
        if (!raw) return '';
        return Number(raw).toLocaleString('id-ID', { maximumFractionDigits: 0 });
    },
    set(val: string) {
        form.amount = val.replace(/[^0-9]/g, '');
    },
});

function submit() {
    form.post('/cash-shifts/transactions', {
        onSuccess: () => {
            form.reset();
            form.type = activeTab.value;
        },
    });
}
</script>

<template>
    <Head title="Manajemen Kas" />
    <AdminLayout title="Manajemen Laci Kasir">
        <template #header-actions>
            <div class="flex items-center gap-2">
                <Button v-if="canViewReport" as-child variant="outline" size="sm" class="gap-2">
                    <Link href="/reports/cash-transactions">
                        <BarChart3 class="w-4 h-4" /> Laporan Lengkap
                    </Link>
                </Button>
                <Button as-child variant="outline" size="sm" class="gap-2">
                    <Link href="/cash-shifts">
                        <ArrowLeft class="w-4 h-4" /> Kembali ke Shift
                    </Link>
                </Button>
            </div>
        </template>

        <div class="max-w-6xl mx-auto flex flex-col lg:flex-row gap-6 p-4">
            <!-- Sidebar -->
            <div class="w-full lg:w-72 flex-shrink-0 space-y-4">
                <div class="space-y-2">
                    <button
                        type="button"
                        class="w-full text-left px-4 py-3 rounded-xl text-sm font-medium transition-colors flex items-center gap-3"
                        :class="activeTab === 'in' ? 'bg-green-100 text-green-800' : 'text-gray-600 hover:bg-gray-100'"
                        @click="switchTab('in')"
                    >
                        <ArrowDownCircle class="w-5 h-5" :class="activeTab === 'in' ? 'text-green-600' : 'text-gray-400'" />
                        Uang Masuk
                    </button>
                    <button
                        type="button"
                        class="w-full text-left px-4 py-3 rounded-xl text-sm font-medium transition-colors flex items-center gap-3"
                        :class="activeTab === 'out' ? 'bg-red-100 text-red-800' : 'text-gray-600 hover:bg-gray-100'"
                        @click="switchTab('out')"
                    >
                        <ArrowUpCircle class="w-5 h-5" :class="activeTab === 'out' ? 'text-red-600' : 'text-gray-400'" />
                        Uang Keluar
                    </button>
                </div>

                <!-- Laporan Shift -->
                <Card class="border-0 shadow-md">
                    <CardContent class="p-4 space-y-3">
                        <h3 class="font-bold text-gray-900 text-sm uppercase">Laporan Shift Ini</h3>
                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between">
                                <span class="text-gray-500">Kas awal</span>
                                <span class="font-medium tabular-nums">Rp {{ formatMoney(summary.opening_cash) }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-500">Penjualan tunai</span>
                                <span class="font-medium tabular-nums text-blue-700">+ Rp {{ formatMoney(summary.sales_cash) }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-500">Kas masuk</span>
                                <span class="font-medium tabular-nums text-emerald-700">+ Rp {{ formatMoney(summary.cash_in) }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-500">Kas keluar</span>
                                <span class="font-medium tabular-nums text-red-700">- Rp {{ formatMoney(summary.cash_out) }}</span>
                            </div>
                            <div class="border-t pt-2 flex justify-between font-bold">
                                <span class="text-gray-700">Saldo kas</span>
                                <span class="tabular-nums" :class="summary.expected_cash >= 0 ? 'text-emerald-700' : 'text-red-700'">
                                    Rp {{ formatMoney(summary.expected_cash) }}
                                </span>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <!-- Riwayat per tab -->
                <div>
                    <div class="flex items-center justify-between px-1 mb-2">
                        <h3 class="font-bold text-gray-900 text-sm uppercase">
                            Riwayat {{ activeTab === 'in' ? 'Masuk' : 'Keluar' }}
                        </h3>
                        <span class="text-xs text-gray-500">{{ formatRupiah(tabTotal) }}</span>
                    </div>
                    <div class="space-y-2 max-h-[360px] overflow-y-auto">
                        <div v-for="t in filteredTransactions" :key="t.id" class="p-3 bg-white rounded-lg border text-sm">
                            <div class="flex justify-between items-start mb-1 gap-2">
                                <span class="font-medium tabular-nums" :class="t.type === 'in' ? 'text-green-600' : 'text-red-600'">
                                    {{ t.type === 'in' ? '+' : '-' }} {{ formatRupiah(t.amount) }}
                                </span>
                                <span class="text-xs text-gray-500 shrink-0">
                                    {{ new Date(t.created_at).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' }) }}
                                </span>
                            </div>
                            <p class="text-gray-800 font-medium truncate">{{ t.notes }}</p>
                            <div class="flex flex-wrap gap-1 mt-1.5">
                                <Badge variant="outline" class="text-[10px]">{{ t.category_label }}</Badge>
                                <span v-if="t.party_name" class="text-[11px] text-gray-500 truncate">{{ t.party_name }}</span>
                            </div>
                        </div>
                        <p v-if="!filteredTransactions.length" class="text-xs text-gray-500 px-1 italic">
                            Belum ada transaksi {{ activeTab === 'in' ? 'masuk' : 'keluar' }} pada shift ini.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Form -->
            <Card class="flex-1 border-0 shadow-lg">
                <CardContent class="p-6 md:p-8">
                    <div class="mb-6 p-4 rounded-xl border-2 border-dashed" :class="shift.expected_cash >= 0 ? 'border-emerald-300 bg-emerald-50' : 'border-red-300 bg-red-50'">
                        <p class="text-xs text-gray-500 uppercase mb-1">Saldo Kas Saat Ini</p>
                        <p class="text-2xl font-bold tabular-nums" :class="shift.expected_cash >= 0 ? 'text-emerald-700' : 'text-red-700'">
                            Rp {{ formatMoney(shift.expected_cash) }}
                        </p>
                        <p class="text-xs text-gray-400 mt-1">Shift {{ shift.shift_number }}</p>
                    </div>

                    <h2 class="text-xl font-bold text-gray-900 mb-6">
                        Buat Transaksi {{ activeTab === 'in' ? 'Uang Masuk' : 'Uang Keluar' }}
                    </h2>

                    <form class="space-y-5" @submit.prevent="submit">
                        <div class="space-y-1.5">
                            <Label class="text-xs text-gray-500 uppercase">{{ activeTab === 'in' ? 'Masuk ke' : 'Ambil dari' }}</Label>
                            <div class="px-4 py-3 bg-gray-50 border rounded-lg text-sm text-gray-700 font-medium">
                                Kasir: Shift {{ shift.shift_number }}
                            </div>
                        </div>

                        <div class="space-y-1.5">
                            <Label class="text-xs text-gray-500 uppercase">{{ activeTab === 'in' ? 'Terima Dari' : 'Dibayar Ke' }}</Label>
                            <Input v-model="form.party_name" placeholder="Nama pelanggan, supplier, atau staf (opsional)" />
                        </div>

                        <div class="space-y-1.5">
                            <Label class="text-xs text-gray-500 uppercase">Keterangan <span class="text-red-500">*</span></Label>
                            <Input v-model="form.notes" :placeholder="activeTab === 'in' ? 'Contoh: Modal kembalian awal' : 'Contoh: Bayar galon air'" required />
                            <p v-if="form.errors.notes" class="text-xs text-red-500">{{ form.errors.notes }}</p>
                        </div>

                        <div class="space-y-1.5">
                            <Label class="text-xs text-gray-500 uppercase">Jumlah (Rp) <span class="text-red-500">*</span></Label>
                            <Input v-model="amountDisplay" type="text" inputmode="numeric" placeholder="0" required class="text-lg font-bold" />
                            <p v-if="form.errors.amount" class="text-xs text-red-500">{{ form.errors.amount }}</p>
                        </div>

                        <div class="space-y-1.5">
                            <Label class="text-xs text-gray-500 uppercase">Jenis {{ activeTab === 'in' ? 'Pendapatan' : 'Pengeluaran' }} <span class="text-red-500">*</span></Label>
                            <button
                                type="button"
                                class="w-full flex items-center justify-between px-4 py-3 border rounded-lg hover:bg-gray-50 transition-colors focus:outline-none focus:ring-2 focus:ring-primary"
                                :class="form.errors.category ? 'border-red-300' : ''"
                                @click="showCategoryModal = true"
                            >
                                <span :class="form.category ? 'text-gray-900 font-medium' : 'text-gray-500'">{{ selectedCategoryName }}</span>
                                <span class="text-gray-400">Pilih &gt;</span>
                            </button>
                            <p v-if="form.errors.category" class="text-xs text-red-500">{{ form.errors.category }}</p>
                        </div>

                        <div class="pt-4 flex justify-end">
                            <Button type="submit" size="lg" class="w-full md:w-auto px-8" :disabled="form.processing || !form.category">
                                Simpan Transaksi
                            </Button>
                        </div>
                    </form>
                </CardContent>
            </Card>
        </div>

        <Dialog :open="showCategoryModal" @update:open="showCategoryModal = $event">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Pilih Jenis {{ activeTab === 'in' ? 'Pendapatan' : 'Pengeluaran' }}</DialogTitle>
                </DialogHeader>
                <div class="space-y-3 mt-4">
                    <button
                        v-for="cat in currentCategories"
                        :key="cat.id"
                        type="button"
                        class="w-full text-left p-4 border rounded-xl hover:border-primary hover:bg-primary/5 transition-all"
                        :class="form.category === cat.id ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'border-gray-200'"
                        @click="selectCategory(cat.id)"
                    >
                        <div class="flex items-center gap-3">
                            <div class="w-5 h-5 rounded-full border flex items-center justify-center" :class="form.category === cat.id ? 'border-primary bg-primary' : 'border-gray-300'">
                                <div v-if="form.category === cat.id" class="w-2 h-2 bg-white rounded-full" />
                            </div>
                            <div>
                                <h4 class="font-bold text-gray-900 mb-1">{{ cat.name }}</h4>
                                <p class="text-xs text-gray-500 leading-relaxed">{{ cat.desc }}</p>
                            </div>
                        </div>
                    </button>
                </div>
            </DialogContent>
        </Dialog>
    </AdminLayout>
</template>
