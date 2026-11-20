<script setup lang="ts">
import { useAllowsAdvancedRetail } from '@/composables/usePlanLimits';
import { Link, usePage } from '@inertiajs/vue3';
import { ChevronDown, LayoutGrid, X } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Navigasi laporan: tab berisi laporan dalam kelompok yang sedang dibuka, ditambah
 * tombol "Semua laporan" yang menampilkan seluruh laporan per kelompok beserta penjelasannya.
 * (Dulu satu baris berisi hingga 23 tab yang harus digeser.)
 */
const props = defineProps<{
    activeTab?: string;
    /** Nama lama yang masih dipakai satu halaman. */
    active?: string;
}>();

const allowsAdvancedRetail = useAllowsAdvancedRetail();
const page = usePage();
const flag = (key: string) => computed(() => Boolean((page.props as Record<string, unknown>)[key]));
const pharmacyEnabled = flag('pharmacy_enabled');
const accountingEnabled = flag('accounting_enabled');
const barbershopEnabled = flag('barbershop_enabled');

type Report = {
    key: string;
    label: string;
    href: string;
    desc: string;
    financial?: boolean;
    advanced?: boolean;
    pharmacy?: boolean;
    barbershop?: boolean;
};
type Group = { key: string; label: string; reports: Report[] };

const groups: Group[] = [
    {
        key: 'penjualan',
        label: 'Penjualan',
        reports: [
            { key: 'ringkasan', label: 'Ringkasan', href: '/reports', desc: 'Penjualan bersih, laba kotor, produk terlaris, dan tren.' },
            { key: 'penjualan', label: 'Penjualan', href: '/reports/sales', desc: 'Daftar transaksi dan rincian penjualan per periode.' },
            {
                key: 'komisi',
                label: 'Komisi barber',
                href: '/reports/commissions',
                desc: 'Komisi layanan per barber.',
                advanced: true,
                barbershop: true,
            },
        ],
    },
    {
        key: 'stok',
        label: 'Stok & Produksi',
        reports: [
            { key: 'stok', label: 'Stok', href: '/reports/inventory', desc: 'Posisi stok dan nilai persediaan saat ini.' },
            { key: 'kartu-stok', label: 'Kartu stok', href: '/reports/stock-card', desc: 'Riwayat keluar-masuk satu produk.', advanced: true },
            {
                key: 'selisih-stok',
                label: 'Selisih stok',
                href: '/reports/stock-variance',
                desc: 'Selisih hasil stok opname dengan catatan sistem.',
                advanced: true,
            },
            {
                key: 'pemusnahan',
                label: 'Pemusnahan',
                href: '/reports/destructions',
                desc: 'Barang rusak/kedaluwarsa yang dimusnahkan.',
                pharmacy: true,
            },
            { key: 'produksi', label: 'Produksi', href: '/reports/production', desc: 'Hasil produksi dan bahan yang terpakai.' },
        ],
    },
    {
        key: 'kas',
        label: 'Kas',
        reports: [
            { key: 'shift', label: 'Shift kasir', href: '/reports/cash-shifts', desc: 'Buka-tutup shift dan selisih uang laci.', advanced: true },
            {
                key: 'cash-transactions',
                label: 'Kas masuk/keluar',
                href: '/reports/cash-transactions',
                desc: 'Uang masuk dan keluar dari laci kasir.',
                advanced: true,
            },
            {
                key: 'cash-flow',
                label: 'Arus kas harian',
                href: '/reports/cash-flow',
                desc: 'Uang masuk dan keluar per hari dari semua sumber.',
                financial: true,
            },
        ],
    },
    {
        key: 'pembelian',
        label: 'Pembelian & Utang',
        reports: [
            { key: 'pembelian', label: 'Pembelian', href: '/reports/purchases', desc: 'Pembelian barang per supplier dan periode.' },
            {
                key: 'aging-payables',
                label: 'Utang usaha',
                href: '/reports/aging-payables',
                desc: 'Tagihan supplier yang belum dibayar dan umurnya.',
                // Tidak perlu modul Akuntansi: datanya dari pembelian bayar tempo.
                advanced: true,
            },
        ],
    },
    {
        key: 'akuntansi',
        label: 'Akuntansi',
        reports: [
            {
                key: 'laba-rugi',
                label: 'Laba rugi',
                href: '/financial/income-statement',
                desc: 'Pendapatan dikurangi beban dalam satu periode.',
                financial: true,
            },
            { key: 'neraca', label: 'Neraca', href: '/financial/balance-sheet', desc: 'Harta, utang, dan modal pada satu tanggal.', financial: true },
            {
                key: 'arus-kas-akuntansi',
                label: 'Laporan arus kas',
                href: '/financial/cash-flow',
                desc: 'Arus kas operasi, investasi, dan pendanaan (format akuntansi).',
                financial: true,
            },
            {
                key: 'perubahan-ekuitas',
                label: 'Perubahan modal',
                href: '/financial/statement-of-equity-changes',
                desc: 'Perubahan modal pemilik dalam satu periode.',
                financial: true,
            },
            {
                key: 'neraca-saldo',
                label: 'Neraca saldo',
                href: '/financial/trial-balance',
                desc: 'Saldo debit-kredit setiap akun (untuk pemeriksaan).',
                financial: true,
            },
            { key: 'jurnal', label: 'Jurnal', href: '/financial/journal', desc: 'Catatan jurnal otomatis dari setiap transaksi.', financial: true },
            {
                key: 'rekonsiliasi',
                label: 'Rekonsiliasi',
                href: '/financial/reconciliation',
                desc: 'Mencocokkan saldo kas/bank dengan catatan.',
                financial: true,
            },
            {
                key: 'ppn',
                label: 'Rekap PPN',
                href: '/reports/tax/ppn-summary',
                desc: 'PPN keluaran dan masukan untuk lapor pajak.',
                financial: true,
            },
            { key: 'calk', label: 'CaLK', href: '/financial/calk', desc: 'Catatan atas laporan keuangan.', financial: true },
            {
                key: 'periode-akuntansi',
                label: 'Periode akuntansi',
                href: '/financial/accounting-periods',
                desc: 'Tutup buku bulanan.',
                financial: true,
            },
        ],
    },
];

const isVisible = (r: Report) =>
    (!r.advanced || allowsAdvancedRetail.value) &&
    (!r.pharmacy || pharmacyEnabled.value) &&
    (!r.financial || accountingEnabled.value) &&
    (!r.barbershop || barbershopEnabled.value);

const visibleGroups = computed(() => groups.map((g) => ({ ...g, reports: g.reports.filter(isVisible) })).filter((g) => g.reports.length > 0));

const activeKey = computed(() => props.activeTab ?? props.active ?? 'ringkasan');
const activeGroup = computed(() => visibleGroups.value.find((g) => g.reports.some((r) => r.key === activeKey.value)) ?? visibleGroups.value[0]);
const activeReport = computed(() => activeGroup.value?.reports.find((r) => r.key === activeKey.value) ?? null);

// ── Panel "Semua laporan" ──
const open = ref(false);
const rootRef = ref<HTMLElement | null>(null);
const onDocClick = (e: MouseEvent) => {
    if (open.value && rootRef.value && !rootRef.value.contains(e.target as Node)) open.value = false;
};
const onKey = (e: KeyboardEvent) => {
    if (e.key === 'Escape') open.value = false;
};
onMounted(() => {
    document.addEventListener('click', onDocClick);
    document.addEventListener('keydown', onKey);
});
onBeforeUnmount(() => {
    document.removeEventListener('click', onDocClick);
    document.removeEventListener('keydown', onKey);
});
</script>

<template>
    <div ref="rootRef" class="relative mb-4 min-w-0 flex-1">
        <div class="flex items-center gap-2">
            <button
                type="button"
                class="inline-flex shrink-0 items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:border-slate-300"
                :aria-expanded="open"
                @click.stop="open = !open"
            >
                <LayoutGrid class="h-4 w-4 text-primary" />
                <span class="hidden sm:inline">{{ activeGroup?.label ?? 'Laporan' }}</span>
                <span class="sm:hidden">Laporan</span>
                <ChevronDown class="h-4 w-4 text-slate-400 transition-transform" :class="{ 'rotate-180': open }" />
            </button>

            <!-- Laporan dalam kelompok yang sedang dibuka -->
            <nav
                class="flex min-w-0 gap-1 overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
                aria-label="Laporan dalam kelompok ini"
            >
                <Link
                    v-for="r in activeGroup?.reports ?? []"
                    :key="r.key"
                    :href="r.href"
                    class="shrink-0 rounded-xl px-3 py-2 text-sm font-semibold whitespace-nowrap transition"
                    :class="r.key === activeKey ? 'bg-primary text-white' : 'text-slate-500 hover:bg-slate-100 hover:text-slate-800'"
                    :aria-current="r.key === activeKey ? 'page' : undefined"
                >
                    {{ r.label }}
                </Link>
            </nav>
        </div>
        <p v-if="activeReport" class="mt-1.5 px-1 text-xs text-slate-500">{{ activeReport.desc }}</p>

        <!-- Panel semua laporan -->
        <div
            v-if="open"
            class="absolute top-full left-0 z-50 mt-2 max-h-[70vh] w-[min(46rem,calc(100vw-2rem))] overflow-y-auto rounded-2xl border border-slate-200 bg-white p-4"
            role="dialog"
            aria-label="Semua laporan"
        >
            <div class="mb-3 flex items-center justify-between">
                <p class="font-bold text-slate-900">Semua laporan</p>
                <button type="button" class="rounded-lg p-1 text-slate-400 hover:bg-slate-100" aria-label="Tutup" @click="open = false">
                    <X class="h-4 w-4" />
                </button>
            </div>
            <div class="grid gap-5 sm:grid-cols-2">
                <section v-for="g in visibleGroups" :key="g.key">
                    <p class="mb-1.5 text-[11px] font-black tracking-widest text-slate-400 uppercase">{{ g.label }}</p>
                    <ul class="space-y-0.5">
                        <li v-for="r in g.reports" :key="r.key">
                            <Link
                                :href="r.href"
                                class="block rounded-xl px-2.5 py-2 transition"
                                :class="r.key === activeKey ? 'bg-primary/10' : 'hover:bg-slate-50'"
                                @click="open = false"
                            >
                                <span class="block text-sm font-semibold" :class="r.key === activeKey ? 'text-primary' : 'text-slate-800'">{{
                                    r.label
                                }}</span>
                                <span class="block text-xs text-slate-500">{{ r.desc }}</span>
                            </Link>
                        </li>
                    </ul>
                </section>
            </div>
        </div>
    </div>
</template>
