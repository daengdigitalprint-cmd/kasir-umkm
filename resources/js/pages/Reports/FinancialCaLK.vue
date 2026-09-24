<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { BookOpen, Info, FileText } from 'lucide-vue-next';
import { ref, watch } from 'vue';
import ReportTabs from '@/components/ReportTabs.vue';
import ReportExportButton from '@/components/ReportExportButton.vue';
import FinancialReportStatusBadge from '@/components/FinancialReportStatusBadge.vue';
import ReportDocumentHeader from '@/components/ReportDocumentHeader.vue';

const props = defineProps<{
    store: { id: number; name: string; currency: string };
    asOf: string;
    summary: {
        total_products: number;
        total_stock_qty: number;
        total_stock_value: number;
        journal_count: number;
        currency: string;
    };
    balances: {
        kas: number;
        bank: number;
        piutang_usaha: number;
        persediaan: number;
        utang_usaha: number;
        modal: number;
    };
    reportStatus: {
        status: 'draft' | 'final' | 'closed';
        label: string;
        finalized_at: string | null;
        finalized_by: string | null;
        report_number: string | null;
        issued_at: string | null;
        prepared_by: string | null;
        reviewed_by: string | null;
    };
}>();

const filterAsOf = ref(props.asOf);

watch(
    () => props.asOf,
    (v) => {
        filterAsOf.value = v;
    },
);

const applyFilter = () => {
    router.get(
        '/financial/calk',
        { as_of: filterAsOf.value },
        { preserveState: true, preserveScroll: true },
    );
};

const formatCurrency = (n: number) =>
    new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(n);

const formatQty = (n: number) => {
    return Number.isInteger(n) ? String(n) : n.toLocaleString('id-ID', { maximumFractionDigits: 4 });
};
</script>

<template>
    <AdminLayout title="Catatan atas Laporan Keuangan">
        <Head title="Catatan atas Laporan Keuangan" />

        <div class="flex items-start justify-between gap-3">
            <ReportTabs active-tab="calk" />
            <ReportExportButton report="calk" :params="{ as_of: filterAsOf }" />
        </div>

        <div class="flex justify-end mb-4">
            <FinancialReportStatusBadge
                report-type="calk"
                :period-end="filterAsOf"
                :report-status="reportStatus"
            />
        </div>

        <ReportDocumentHeader report-type="calk" :period-end="filterAsOf" :report-status="reportStatus" />

        <p class="text-sm text-slate-600 mb-4">
            CaLK merupakan bagian integral dari laporan keuangan sesuai SAK EMKM.
            Berisi kebijakan akuntansi, rincian akun penting, dan catatan yang diperlukan.
        </p>

        <!-- Filter tanggal -->
        <Card class="rounded-2xl border-slate-200 shadow-sm mb-6">
            <CardHeader class="pb-2">
                <div class="flex items-center gap-2">
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-primary/10">
                        <BookOpen class="h-5 w-5 text-primary" />
                    </div>
                    <div>
                        <CardTitle>Per tanggal</CardTitle>
                        <CardDescription>{{ store.name }}</CardDescription>
                    </div>
                </div>
            </CardHeader>
            <CardContent>
                <form class="flex flex-wrap items-end gap-3" @submit.prevent="applyFilter">
                    <div class="space-y-1.5">
                        <Label for="calk-as-of" class="text-xs">Per tanggal</Label>
                        <Input id="calk-as-of" v-model="filterAsOf" type="date" class="w-40" required />
                    </div>
                    <Button type="submit">Terapkan</Button>
                </form>
            </CardContent>
        </Card>

        <!-- Kebijakan Akuntansi -->
        <Card class="rounded-2xl border-slate-200 shadow-sm mb-6">
            <CardHeader class="pb-2">
                <div class="flex items-center gap-2">
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-violet-500/10">
                        <FileText class="h-5 w-5 text-violet-700" />
                    </div>
                    <div>
                        <CardTitle>Kebijakan Akuntansi</CardTitle>
                        <CardDescription>Standar yang digunakan dalam penyusunan laporan keuangan</CardDescription>
                    </div>
                </div>
            </CardHeader>
            <CardContent>
                <ul class="space-y-2 text-sm text-slate-700">
                    <li class="flex gap-2"><span class="text-primary font-semibold shrink-0">1.</span>Laporan keuangan ini disusun berdasarkan Standar Akuntansi Keuangan untuk Entitas Mikro, Kecil, dan Menengah (SAK EMKM).</li>
                    <li class="flex gap-2"><span class="text-primary font-semibold shrink-0">2.</span>Metode pencatatan: Double-entry bookkeeping (jurnal debit-kredit).</li>
                    <li class="flex gap-2"><span class="text-primary font-semibold shrink-0">3.</span>Pendapatan diakui pada saat penjualan terjadi (basis akrual), bukan saat kas diterima.</li>
                    <li class="flex gap-2"><span class="text-primary font-semibold shrink-0">4.</span>Metode costing persediaan: Rata-rata tertimbang (weighted average).</li>
                    <li class="flex gap-2"><span class="text-primary font-semibold shrink-0">5.</span>Aset dilaporkan berdasarkan biaya perolehan (historical cost).</li>
                    <li class="flex gap-2"><span class="text-primary font-semibold shrink-0">6.</span>Aset tetap disusutkan menggunakan metode garis lurus (straight line) — diterapkan saat klasifikasi aset tetap tersedia.</li>
                    <li class="flex gap-2"><span class="text-primary font-semibold shrink-0">7.</span>Mata uang pelaporan: {{ summary.currency }}.</li>
                </ul>
            </CardContent>
        </Card>

        <!-- Rincian Akun Neraca -->
        <Card class="rounded-2xl border-slate-200 shadow-sm mb-6">
            <CardHeader class="pb-2">
                <div class="flex items-center gap-2">
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-sky-500/10">
                        <BookOpen class="h-5 w-5 text-sky-700" />
                    </div>
                    <div>
                        <CardTitle>Rincian Akun Penting</CardTitle>
                        <CardDescription>Saldo buku per {{ filterAsOf }}</CardDescription>
                    </div>
                </div>
            </CardHeader>
            <CardContent>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="rounded-xl bg-slate-50 p-4">
                        <p class="text-xs text-slate-500 mb-1">Kas</p>
                        <p class="font-semibold text-slate-900">Rp {{ formatCurrency(balances.kas) }}</p>
                    </div>
                    <div class="rounded-xl bg-slate-50 p-4">
                        <p class="text-xs text-slate-500 mb-1">Bank (non-tunai)</p>
                        <p class="font-semibold text-slate-900">Rp {{ formatCurrency(balances.bank) }}</p>
                    </div>
                    <div class="rounded-xl bg-slate-50 p-4">
                        <p class="text-xs text-slate-500 mb-1">Piutang Usaha</p>
                        <p class="font-semibold text-slate-900">Rp {{ formatCurrency(balances.piutang_usaha) }}</p>
                    </div>
                    <div class="rounded-xl bg-slate-50 p-4">
                        <p class="text-xs text-slate-500 mb-1">Persediaan</p>
                        <p class="font-semibold text-slate-900">Rp {{ formatCurrency(balances.persediaan) }}</p>
                    </div>
                    <div class="rounded-xl bg-slate-50 p-4">
                        <p class="text-xs text-slate-500 mb-1">Utang Usaha</p>
                        <p class="font-semibold text-slate-900">Rp {{ formatCurrency(balances.utang_usaha) }}</p>
                    </div>
                    <div class="rounded-xl bg-slate-50 p-4">
                        <p class="text-xs text-slate-500 mb-1">Modal Pemilik</p>
                        <p class="font-semibold text-slate-900">Rp {{ formatCurrency(balances.modal) }}</p>
                    </div>
                </div>
            </CardContent>
        </Card>

        <!-- Rincian Entitas -->
        <Card class="rounded-2xl border-slate-200 shadow-sm mb-6">
            <CardHeader class="pb-2">
                <div class="flex items-center gap-2">
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-500/10">
                        <Info class="h-5 w-5 text-emerald-700" />
                    </div>
                    <div>
                        <CardTitle>Rincian Entitas</CardTitle>
                        <CardDescription>Informasi dasar usaha per {{ filterAsOf }}</CardDescription>
                    </div>
                </div>
            </CardHeader>
            <CardContent>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="rounded-xl bg-slate-50 p-4">
                        <p class="text-xs text-slate-500 mb-1">Nama Usaha</p>
                        <p class="font-semibold text-slate-900">{{ store.name }}</p>
                    </div>
                    <div class="rounded-xl bg-slate-50 p-4">
                        <p class="text-xs text-slate-500 mb-1">Jumlah Produk</p>
                        <p class="font-semibold text-slate-900">{{ summary.total_products }}</p>
                    </div>
                    <div class="rounded-xl bg-slate-50 p-4">
                        <p class="text-xs text-slate-500 mb-1">Total Unit Persediaan</p>
                        <p class="font-semibold text-slate-900">{{ formatQty(summary.total_stock_qty) }}</p>
                    </div>
                    <div class="rounded-xl bg-slate-50 p-4">
                        <p class="text-xs text-slate-500 mb-1">Nilai Persediaan (HPP)</p>
                        <p class="font-semibold text-slate-900">Rp {{ formatCurrency(summary.total_stock_value) }}</p>
                    </div>
                    <div class="rounded-xl bg-slate-50 p-4">
                        <p class="text-xs text-slate-500 mb-1">Jumlah Jurnal (Tahun Berjalan)</p>
                        <p class="font-semibold text-slate-900">{{ summary.journal_count }}</p>
                    </div>
                    <div class="rounded-xl bg-slate-50 p-4">
                        <p class="text-xs text-slate-500 mb-1">Tanggal Penyusunan</p>
                        <p class="font-semibold text-slate-900">{{ new Date().toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric' }) }}</p>
                    </div>
                </div>
            </CardContent>
        </Card>

        <!-- Catatan Penting -->
        <Card class="rounded-2xl border-slate-200 shadow-sm">
            <CardContent class="p-6">
                <div class="rounded-xl bg-amber-50 border border-amber-200 p-4">
                    <p class="text-sm font-semibold text-amber-900 mb-2">Catatan Penting</p>
                    <ul class="space-y-1 text-sm text-amber-800">
                        <li>CaLK ini merupakan bagian integral dari laporan keuangan sesuai SAK EMKM.</li>
                        <li>Seluruh transaksi penjualan, pembelian, dan penyesuaian stok telah dicatat secara otomatis melalui sistem jurnal double-entry.</li>
                        <li>Transaksi pihak berelasi: belum ada transaksi material dengan pihak berelasi yang perlu diungkapkan.</li>
                        <li>Komitmen dan kontinjensi: tidak terdapat komitmen atau kewajiban kontinjensi yang material.</li>
                        <li>Peristiwa setelah periode pelaporan: tidak terdapat peristiwa signifikan setelah tanggal pelaporan yang memengaruhi laporan keuangan.</li>
                        <li>Laporan ini bukan pengganti audit independen. Disarankan untuk melakukan evaluasi berkala terhadap catatan akuntansi ini.</li>
                    </ul>
                </div>
            </CardContent>
        </Card>
    </AdminLayout>
</template>
