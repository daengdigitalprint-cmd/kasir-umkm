<script setup lang="ts">
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { reminderLink, rupiah, waNumber } from '@/composables/useReceivables';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { alertInfo, confirmDelete } from '@/lib/swal';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowDownUp,
    ChevronLeft,
    ChevronRight,
    Clock,
    MessageCircle,
    MoreVertical,
    Pencil,
    Plus,
    Search,
    Sparkles,
    Star,
    Trash2,
    UserPlus,
    Users,
} from 'lucide-vue-next';
import { ref, watch } from 'vue';

interface CustomerRow {
    id: number;
    name: string;
    phone: string | null;
    email: string | null;
    customer_type: string | null;
    total_spent: number;
    visits: number;
    last_purchase_at: string | null;
    points_balance: number;
    outstanding_debt: number;
}

const props = defineProps<{
    customers: {
        data: CustomerRow[];
        total: number;
        current_page: number;
        last_page: number;
        from: number | null;
        to: number | null;
    };
    filters: { search: string; filter: string; sort: string };
    counts: { all: number; debt: number; inactive: number; new: number };
    store: { id: number; name: string };
}>();

// ── Cari, filter, urutan (di server) ────────────────────────────────────
const search = ref(props.filters.search ?? '');
const sortKey = ref(props.filters.sort ?? 'recent');

function visit(params: { filter?: string; sort?: string; search?: string; page?: number }) {
    const filter = params.filter ?? props.filters.filter;
    const sort = params.sort ?? sortKey.value;
    const q = params.search ?? search.value;
    router.get(
        '/customers',
        {
            search: q || undefined,
            filter: filter || undefined,
            sort: sort === 'recent' ? undefined : sort,
            page: params.page && params.page > 1 ? params.page : undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

let timer: ReturnType<typeof setTimeout> | null = null;
watch(search, (v) => {
    if (timer) clearTimeout(timer);
    timer = setTimeout(() => visit({ search: v, page: 1 }), 350);
});
watch(sortKey, (v) => visit({ sort: v, page: 1 }));

const QUICK = [
    { key: '', label: 'Semua', icon: Users, countKey: 'all' as const },
    { key: 'debt', label: 'Punya utang', icon: AlertTriangle, countKey: 'debt' as const },
    { key: 'inactive', label: 'Lama tidak datang', icon: Clock, countKey: 'inactive' as const },
    { key: 'new', label: 'Baru bulan ini', icon: Sparkles, countKey: 'new' as const },
];

// ── Tampilan baris ──────────────────────────────────────────────────────
function lastVisit(iso: string | null): string {
    if (!iso) return 'Belum pernah belanja';
    const days = Math.floor((Date.now() - new Date(iso).getTime()) / 86_400_000);
    if (days <= 0) return 'Datang hari ini';
    if (days === 1) return 'Datang kemarin';
    if (days < 30) return `Datang ${days} hari lalu`;
    const months = Math.floor(days / 30);
    return `Datang ${months} bulan lalu`;
}
const isInactive = (iso: string | null) => !!iso && Date.now() - new Date(iso).getTime() > 30 * 86_400_000;

function waLink(c: CustomerRow): string | null {
    if (c.outstanding_debt > 0) {
        return reminderLink({ phone: c.phone, customerName: c.name, storeName: props.store.name, amount: c.outstanding_debt, dueAt: null });
    }
    const n = waNumber(c.phone);
    return n ? `https://wa.me/${n}` : null;
}

async function destroy(c: CustomerRow) {
    const note =
        c.outstanding_debt > 0
            ? `Pelanggan ini masih punya utang ${rupiah(c.outstanding_debt)}. Pelanggan yang sudah pernah bertransaksi tidak bisa dihapus.`
            : c.visits > 0
              ? 'Pelanggan yang sudah pernah bertransaksi tidak bisa dihapus agar riwayat penjualan tetap utuh.'
              : `«${c.name}» akan dihapus permanen.`;
    if (c.visits > 0 || c.outstanding_debt > 0) {
        await alertInfo('Tidak bisa dihapus', note);
        return;
    }
    if (await confirmDelete('Hapus pelanggan?', note)) {
        router.delete(`/customers/${c.id}`, { preserveScroll: true });
    }
}
</script>

<template>
    <Head title="Pelanggan" />

    <AdminLayout title="Pelanggan">
        <template #header-actions>
            <Link
                href="/customers/create"
                class="inline-flex items-center gap-1.5 rounded-xl bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90"
            >
                <Plus class="h-4 w-4" /> Tambah pelanggan
            </Link>
        </template>
        <template #mobile-header-actions>
            <Link
                href="/customers/create"
                class="inline-flex items-center gap-1 rounded-lg bg-primary px-2.5 py-1.5 text-xs font-semibold text-white"
                aria-label="Tambah pelanggan"
            >
                <Plus class="h-3.5 w-3.5" /> Tambah
            </Link>
        </template>

        <div class="mx-auto max-w-5xl space-y-4">
            <!-- Ringkasan yang bisa diklik -->
            <div class="flex gap-2 overflow-x-auto pb-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                <button
                    v-for="q in QUICK"
                    :key="q.key"
                    type="button"
                    :aria-pressed="filters.filter === q.key"
                    class="flex shrink-0 items-center gap-2 rounded-2xl border px-4 py-2.5 transition"
                    :class="filters.filter === q.key ? 'border-primary bg-primary/5' : 'border-slate-200 bg-white hover:border-slate-300'"
                    @click="visit({ filter: q.key, page: 1 })"
                >
                    <component
                        :is="q.icon"
                        class="h-4 w-4"
                        :class="filters.filter === q.key ? 'text-primary' : q.key === 'debt' && counts.debt > 0 ? 'text-red-500' : 'text-slate-400'"
                    />
                    <span class="text-sm font-semibold text-slate-700">{{ q.label }}</span>
                    <span
                        class="rounded-full px-2 py-0.5 text-xs font-bold"
                        :class="
                            filters.filter === q.key
                                ? 'bg-primary text-white'
                                : q.key === 'debt' && counts.debt > 0
                                  ? 'bg-red-50 text-red-700'
                                  : 'bg-slate-100 text-slate-600'
                        "
                        >{{ counts[q.countKey] }}</span
                    >
                </button>
            </div>

            <section class="rounded-2xl border border-slate-200 bg-white">
                <div class="flex flex-col gap-2 border-b border-slate-100 p-3 sm:flex-row">
                    <div class="relative flex-1">
                        <Search class="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-slate-400" />
                        <input
                            v-model="search"
                            type="search"
                            placeholder="Cari nama, nomor HP, atau email"
                            class="h-10 w-full rounded-xl border border-slate-200 bg-white pr-3 pl-9 text-sm focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none"
                        />
                    </div>
                    <div class="relative sm:w-56">
                        <ArrowDownUp class="pointer-events-none absolute top-1/2 left-3 h-3.5 w-3.5 -translate-y-1/2 text-slate-400" />
                        <select
                            v-model="sortKey"
                            aria-label="Urutkan"
                            class="h-10 w-full rounded-xl border border-slate-200 bg-white pr-3 pl-8 text-sm text-slate-700 focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none"
                        >
                            <option value="recent">Terakhir datang</option>
                            <option value="spent">Belanja terbanyak</option>
                            <option value="name">Nama A–Z</option>
                        </select>
                    </div>
                </div>

                <div class="flex items-center justify-between px-4 pt-3 pb-1 text-xs text-slate-500">
                    <span>{{ customers.total }} pelanggan</span>
                    <Link href="/customers/segments" class="font-semibold text-primary hover:underline">Lihat segmen pelanggan →</Link>
                </div>

                <!-- Kosong -->
                <div v-if="!customers.data.length" class="px-6 py-14 text-center">
                    <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-slate-100">
                        <Users class="h-5 w-5 text-slate-400" />
                    </div>
                    <template v-if="filters.search || filters.filter">
                        <p class="font-semibold text-slate-700">Tidak ada pelanggan yang cocok</p>
                        <button
                            type="button"
                            class="mt-2 text-sm font-semibold text-primary hover:underline"
                            @click="
                                search = '';
                                visit({ filter: '', search: '', page: 1 });
                            "
                        >
                            Hapus pencarian & filter
                        </button>
                    </template>
                    <template v-else>
                        <p class="font-semibold text-slate-700">Belum ada pelanggan</p>
                        <p class="mt-1 text-sm text-slate-500">Pelanggan juga bisa ditambahkan langsung dari kasir saat transaksi.</p>
                        <Link
                            href="/customers/create"
                            class="mt-4 inline-flex items-center gap-1.5 rounded-xl bg-primary px-4 py-2 text-sm font-semibold text-white"
                        >
                            <UserPlus class="h-4 w-4" /> Tambah pelanggan pertama
                        </Link>
                    </template>
                </div>

                <!-- Daftar -->
                <ul v-else class="divide-y divide-slate-100">
                    <li v-for="c in customers.data" :key="c.id" class="flex items-center gap-3 px-4 py-3">
                        <Link :href="`/customers/${c.id}`" class="flex min-w-0 flex-1 items-center gap-3">
                            <span
                                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary/10 text-sm font-bold text-primary"
                            >
                                {{ c.name.trim().charAt(0).toUpperCase() || '?' }}
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="flex items-center gap-1.5">
                                    <span class="truncate text-sm font-semibold text-slate-900">{{ c.name }}</span>
                                    <span
                                        v-if="c.customer_type === 'grosir'"
                                        class="shrink-0 rounded-full bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold text-slate-600"
                                        >Grosir</span
                                    >
                                </span>
                                <span class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-500">
                                    <span v-if="c.phone">{{ c.phone }}</span>
                                    <span :class="isInactive(c.last_purchase_at) ? 'text-amber-600' : ''">· {{ lastVisit(c.last_purchase_at) }}</span>
                                    <span v-if="c.points_balance > 0" class="inline-flex items-center gap-0.5"
                                        >· <Star class="h-3 w-3 text-amber-500" /> {{ c.points_balance }} poin</span
                                    >
                                </span>
                            </span>
                            <span class="shrink-0 text-right">
                                <span class="block text-sm font-semibold text-slate-900">{{ rupiah(c.total_spent) }}</span>
                                <span class="block text-xs text-slate-400">{{ c.visits }}× belanja</span>
                                <span
                                    v-if="c.outstanding_debt > 0"
                                    class="mt-0.5 inline-block rounded-full bg-red-50 px-2 py-0.5 text-[11px] font-semibold text-red-700"
                                >
                                    Utang {{ rupiah(c.outstanding_debt) }}
                                </span>
                            </span>
                        </Link>
                        <a
                            v-if="waLink(c)"
                            :href="waLink(c)!"
                            target="_blank"
                            rel="noopener"
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-slate-200 text-emerald-600 hover:bg-emerald-50"
                            :title="c.outstanding_debt > 0 ? 'Ingatkan utang via WhatsApp' : 'Chat WhatsApp'"
                            :aria-label="`WhatsApp ${c.name}`"
                        >
                            <MessageCircle class="h-4 w-4" />
                        </a>
                        <span v-else class="h-9 w-9 shrink-0" aria-hidden="true" />
                        <DropdownMenu>
                            <DropdownMenuTrigger as-child>
                                <button
                                    type="button"
                                    class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700"
                                    :aria-label="`Aksi ${c.name}`"
                                >
                                    <MoreVertical class="h-4 w-4" />
                                </button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" class="w-40">
                                <DropdownMenuItem as-child>
                                    <Link :href="`/customers/${c.id}/edit`" class="flex cursor-pointer items-center gap-2"
                                        ><Pencil class="h-4 w-4" /> Ubah</Link
                                    >
                                </DropdownMenuItem>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem class="flex cursor-pointer items-center gap-2 text-red-600" @click="destroy(c)">
                                    <Trash2 class="h-4 w-4" /> Hapus
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </li>
                </ul>

                <div v-if="customers.last_page > 1" class="flex items-center justify-between border-t border-slate-100 px-4 py-3">
                    <p class="text-xs text-slate-500">{{ customers.from }}–{{ customers.to }} dari {{ customers.total }}</p>
                    <div class="flex gap-1">
                        <button
                            type="button"
                            class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 disabled:opacity-40"
                            :disabled="customers.current_page <= 1"
                            aria-label="Sebelumnya"
                            @click="visit({ page: customers.current_page - 1 })"
                        >
                            <ChevronLeft class="h-4 w-4" />
                        </button>
                        <button
                            type="button"
                            class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 disabled:opacity-40"
                            :disabled="customers.current_page >= customers.last_page"
                            aria-label="Berikutnya"
                            @click="visit({ page: customers.current_page + 1 })"
                        >
                            <ChevronRight class="h-4 w-4" />
                        </button>
                    </div>
                </div>
            </section>
        </div>
    </AdminLayout>
</template>
