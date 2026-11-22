<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { History, MessageCircle, Search, Settings2, SlidersHorizontal, Stamp, Trash2, X } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { stampCardShareLink, type StampCardCustomer } from '@/composables/useStampCard';
import { confirmDelete, toastError } from '@/lib/swal';

interface Rules {
    title: string;
    target: number;
    mode: 'transaction' | 'item';
    min_amount: number;
    product_ids: number[];
    category_ids: number[];
    reward: string;
    expiry_months: number;
}

type Row = StampCardCustomer & { last_at: string | null; expired: boolean };

interface Paginated<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    total: number;
}

interface HistoryRow {
    id: number;
    type: string;
    stamps: number;
    rewards_delta: number;
    count_after: number;
    rewards_after: number;
    note: string | null;
    by: string | null;
    at: string | null;
}

const props = defineProps<{
    enabled: boolean;
    rules: Rules;
    stats: { members: number; ready: number; near: number; redeemed: number };
    customers: Paginated<Row>;
    filter: 'all' | 'ready' | 'near';
    search: string;
    store_name: string;
    products: { id: number; name: string }[];
    categories: { id: number; name: string }[];
}>();

// ── Aturan ──
const form = useForm<Rules>({ ...props.rules });
const showRules = ref(!props.stats.members);
const productQuery = ref('');
const filteredProducts = computed(() => {
    const q = productQuery.value.trim().toLowerCase();
    const list = q ? props.products.filter((p) => p.name.toLowerCase().includes(q)) : props.products;
    return list.slice(0, 60);
});
function toggleId(list: number[], id: number) {
    const i = list.indexOf(id);
    if (i >= 0) list.splice(i, 1);
    else list.push(id);
}
function saveRules() {
    form.put('/stamp-card/settings', { preserveScroll: true, preserveState: true, onSuccess: () => (showRules.value = false) });
}
const ruleSummary = computed(() => {
    const r = props.rules;
    const how =
        r.mode === 'item'
            ? '1 stempel per porsi menu pilihan'
            : r.min_amount > 0
              ? `1 stempel tiap belanja min. Rp${fmt(r.min_amount)}`
              : '1 stempel tiap transaksi';
    return `${r.target} stempel = ${r.reward} · ${how}${r.expiry_months ? ` · hangus ${r.expiry_months} bln` : ''}`;
});

// ── Daftar ──
const search = ref(props.search);
let t: ReturnType<typeof setTimeout> | null = null;
watch(search, (v) => {
    if (t) clearTimeout(t);
    t = setTimeout(() => go({ q: v || undefined }), 350);
});
function go(params: Record<string, string | undefined>) {
    router.get('/stamp-card', { filter: props.filter, q: props.search || undefined, ...params }, { preserveState: true, preserveScroll: true, replace: true });
}
const filters = computed(() => [
    { key: 'all', label: 'Semua', count: props.stats.members },
    { key: 'ready', label: 'Siap tukar', count: props.stats.ready },
    { key: 'near', label: 'Hampir dapat hadiah', count: props.stats.near },
]);

// ── Riwayat ──
const historyFor = ref<Row | null>(null);
const historyRows = ref<HistoryRow[]>([]);
const historyLoading = ref(false);
async function openHistory(row: Row) {
    historyFor.value = row;
    historyRows.value = [];
    historyLoading.value = true;
    try {
        const res = await fetch(`/stamp-card/customers/${row.id}/history`, { headers: { Accept: 'application/json' } });
        const data = await res.json();
        historyRows.value = data.history ?? [];
    } catch {
        toastError('Gagal memuat riwayat.');
    } finally {
        historyLoading.value = false;
    }
}
const typeLabel: Record<string, string> = {
    earn: 'Belanja',
    reverse: 'Transaksi batal',
    redeem: 'Tukar hadiah',
    adjust: 'Koreksi',
    expire: 'Hangus',
    remove: 'Kartu dihapus',
};

// ── Hapus kartu ──
async function removeCard(row: Row) {
    const rewards = row.rewards_ready > 0 ? ` dan ${row.rewards_ready} hadiah yang belum ditukar` : '';
    const ok = await confirmDelete(
        `Kartu stempel ${row.name}`,
        `${row.count} stempel${rewards} akan dihapus dan tautan kartunya tidak berlaku lagi. Data pelanggan tetap ada.`,
    );
    if (!ok) return;
    removingId.value = row.id;
    router.delete(`/stamp-card/customers/${row.id}`, {
        preserveScroll: true,
        preserveState: true,
        onFinish: () => (removingId.value = null),
    });
}
const removingId = ref<number | null>(null);

// ── Koreksi ──
const adjustFor = ref<Row | null>(null);
const adjustForm = useForm({ delta: 1, note: '' });
function openAdjust(row: Row) {
    adjustFor.value = row;
    adjustForm.reset();
    adjustForm.clearErrors();
}
function submitAdjust() {
    if (!adjustFor.value) return;
    adjustForm.post(`/stamp-card/customers/${adjustFor.value.id}/adjust`, {
        preserveScroll: true,
        onSuccess: () => (adjustFor.value = null),
    });
}

const fmt = (n: number) => new Intl.NumberFormat('id-ID').format(n);
const fmtDate = (iso: string | null) =>
    iso ? new Date(iso).toLocaleString('id-ID', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '-';
const shareLink = (row: Row) => stampCardShareLink(row, props.store_name);
</script>

<template>
    <Head title="Kartu Stempel" />

    <AdminLayout title="Kartu Stempel">
        <div class="mx-auto max-w-5xl space-y-5 pb-8">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-black text-slate-900">Kartu Stempel</h1>
                    <p class="mt-1 text-sm text-slate-600">Pengganti kartu stempel kertas. Pelanggan menyimpan kartunya di HP, stempel bertambah otomatis saat bayar.</p>
                </div>
                <Button variant="outline" class="rounded-xl" @click="showRules = !showRules">
                    <Settings2 class="mr-1.5 h-4 w-4" /> Atur kartu
                </Button>
            </div>

            <div v-if="!enabled" class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                Kartu stempel belum aktif, jadi kasir belum memberi stempel. Nyalakan di
                <Link href="/settings/profile" class="font-semibold underline">Pengaturan → Fitur → Lainnya</Link>.
            </div>

            <!-- Aturan -->
            <Card v-if="showRules" class="rounded-2xl border-slate-200 shadow-sm">
                <CardContent class="space-y-4 pt-6">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="block">
                            <span class="mb-1 block text-sm font-semibold text-slate-700">Nama kartu</span>
                            <input v-model="form.title" type="text" maxlength="60" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm" />
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-sm font-semibold text-slate-700">Jumlah stempel untuk 1 hadiah</span>
                            <input v-model.number="form.target" type="number" min="2" max="30" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm" />
                            <span v-if="form.errors.target" class="text-xs text-red-600">{{ form.errors.target }}</span>
                        </label>
                        <label class="block sm:col-span-2">
                            <span class="mb-1 block text-sm font-semibold text-slate-700">Hadiah saat kartu penuh</span>
                            <input v-model="form.reward" type="text" maxlength="120" placeholder="1 Es Kopi Susu gratis" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm" />
                            <span v-if="form.errors.reward" class="text-xs text-red-600">{{ form.errors.reward }}</span>
                        </label>
                    </div>

                    <div>
                        <span class="mb-1 block text-sm font-semibold text-slate-700">Cara dapat stempel</span>
                        <div class="grid gap-2 sm:grid-cols-2">
                            <label class="flex cursor-pointer items-start gap-2 rounded-xl border px-3 py-2.5 text-sm" :class="form.mode === 'transaction' ? 'border-primary bg-primary/5' : 'border-slate-200'">
                                <input v-model="form.mode" type="radio" value="transaction" class="mt-0.5" />
                                <span><b class="font-semibold">Per transaksi</b><br /><span class="text-slate-500">1 stempel setiap kali belanja.</span></span>
                            </label>
                            <label class="flex cursor-pointer items-start gap-2 rounded-xl border px-3 py-2.5 text-sm" :class="form.mode === 'item' ? 'border-primary bg-primary/5' : 'border-slate-200'">
                                <input v-model="form.mode" type="radio" value="item" class="mt-0.5" />
                                <span><b class="font-semibold">Per menu pilihan</b><br /><span class="text-slate-500">1 stempel tiap porsi menu tertentu (mis. beli 2 kopi = 2 stempel).</span></span>
                            </label>
                        </div>
                    </div>

                    <label v-if="form.mode === 'transaction'" class="block max-w-xs">
                        <span class="mb-1 block text-sm font-semibold text-slate-700">Belanja minimal (Rp)</span>
                        <input v-model.number="form.min_amount" type="number" min="0" step="1000" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm" />
                        <span class="text-xs text-slate-500">Isi 0 bila semua belanja dapat stempel.</span>
                    </label>

                    <div v-else class="space-y-3">
                        <div v-if="categories.length">
                            <span class="mb-1 block text-sm font-semibold text-slate-700">Kategori</span>
                            <div class="flex flex-wrap gap-2">
                                <button
                                    v-for="c in categories"
                                    :key="c.id"
                                    type="button"
                                    class="rounded-full border px-3 py-1 text-xs"
                                    :class="form.category_ids.includes(c.id) ? 'border-primary bg-primary text-white' : 'border-slate-200 text-slate-600'"
                                    @click="toggleId(form.category_ids, c.id)"
                                >
                                    {{ c.name }}
                                </button>
                            </div>
                        </div>
                        <div>
                            <span class="mb-1 block text-sm font-semibold text-slate-700">Produk ({{ form.product_ids.length }} dipilih)</span>
                            <input v-model="productQuery" type="search" placeholder="Cari produk" class="mb-2 w-full max-w-xs rounded-xl border border-slate-200 px-3 py-1.5 text-sm" />
                            <div class="flex max-h-40 flex-wrap gap-2 overflow-y-auto">
                                <button
                                    v-for="p in filteredProducts"
                                    :key="p.id"
                                    type="button"
                                    class="rounded-full border px-3 py-1 text-xs"
                                    :class="form.product_ids.includes(p.id) ? 'border-primary bg-primary text-white' : 'border-slate-200 text-slate-600'"
                                    @click="toggleId(form.product_ids, p.id)"
                                >
                                    {{ p.name }}
                                </button>
                            </div>
                            <span v-if="form.errors.product_ids" class="text-xs text-red-600">{{ form.errors.product_ids }}</span>
                        </div>
                    </div>

                    <label class="block max-w-xs">
                        <span class="mb-1 block text-sm font-semibold text-slate-700">Stempel hangus bila tidak datang selama</span>
                        <select v-model.number="form.expiry_months" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm">
                            <option :value="0">Tidak pernah hangus</option>
                            <option v-for="m in [1, 2, 3, 6, 12]" :key="m" :value="m">{{ m }} bulan</option>
                        </select>
                    </label>

                    <div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
                        <Button variant="ghost" @click="showRules = false">Batal</Button>
                        <Button :disabled="form.processing" @click="saveRules">Simpan aturan</Button>
                    </div>
                </CardContent>
            </Card>
            <p v-else class="flex items-center gap-2 text-sm text-slate-600">
                <Stamp class="h-4 w-4 text-primary" /> {{ ruleSummary }}
            </p>

            <!-- Ringkasan -->
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div class="rounded-2xl bg-white p-4 ring-1 ring-slate-200">
                    <div class="text-xs text-slate-500">Pemegang kartu</div>
                    <div class="text-2xl font-black text-slate-900">{{ fmt(stats.members) }}</div>
                </div>
                <div class="rounded-2xl bg-white p-4 ring-1 ring-slate-200">
                    <div class="text-xs text-slate-500">Siap tukar</div>
                    <div class="text-2xl font-black text-emerald-600">{{ fmt(stats.ready) }}</div>
                </div>
                <div class="rounded-2xl bg-white p-4 ring-1 ring-slate-200">
                    <div class="text-xs text-slate-500">Hampir dapat hadiah</div>
                    <div class="text-2xl font-black text-primary">{{ fmt(stats.near) }}</div>
                </div>
                <div class="rounded-2xl bg-white p-4 ring-1 ring-slate-200">
                    <div class="text-xs text-slate-500">Hadiah sudah ditukar</div>
                    <div class="text-2xl font-black text-slate-900">{{ fmt(stats.redeemed) }}</div>
                </div>
            </div>

            <!-- Daftar -->
            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardContent class="space-y-3 pt-5">
                    <div class="flex flex-wrap items-center gap-2">
                        <button
                            v-for="f in filters"
                            :key="f.key"
                            type="button"
                            class="rounded-full border px-3 py-1.5 text-sm"
                            :class="filter === f.key ? 'border-primary bg-primary text-white' : 'border-slate-200 text-slate-600'"
                            @click="go({ filter: f.key })"
                        >
                            {{ f.label }} ({{ f.count }})
                        </button>
                        <div class="relative ml-auto w-full sm:w-60">
                            <Search class="absolute top-2.5 left-3 h-4 w-4 text-slate-400" />
                            <input v-model="search" type="search" placeholder="Cari nama / no. WA" class="w-full rounded-xl border border-slate-200 py-2 pr-3 pl-9 text-sm" />
                        </div>
                    </div>

                    <div v-if="!customers.data.length" class="py-10 text-center text-sm text-slate-500">
                        Belum ada pelanggan di daftar ini. Stempel pertama muncul setelah kasir memilih pelanggan saat bayar.
                    </div>

                    <div
                        v-for="row in customers.data"
                        :key="row.id"
                        class="flex flex-wrap items-center gap-3 border-t border-slate-100 py-3 transition-opacity first:border-0"
                        :class="removingId === row.id && 'pointer-events-none opacity-40'"
                    >
                        <div class="min-w-0 flex-1">
                            <div class="truncate font-semibold text-slate-900">{{ row.name }}</div>
                            <div class="text-xs text-slate-500">{{ row.phone || 'Tanpa no. WA' }} · terakhir {{ fmtDate(row.last_at) }}</div>
                        </div>
                        <div class="flex items-center gap-2">
                            <span v-if="row.rewards_ready > 0" class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700">{{ row.rewards_ready }} hadiah siap</span>
                            <span class="rounded-full bg-primary/10 px-2.5 py-1 text-xs font-semibold text-primary">{{ row.count }}/{{ row.target }}</span>
                            <span v-if="row.expired" class="text-xs text-amber-600">hangus</span>
                        </div>
                        <div class="flex gap-1">
                            <a
                                v-if="shareLink(row)"
                                :href="shareLink(row)!"
                                target="_blank"
                                rel="noopener"
                                class="rounded-lg p-2 text-emerald-600 hover:bg-emerald-50"
                                title="Kirim kartu lewat WhatsApp"
                                aria-label="Kirim kartu lewat WhatsApp"
                            >
                                <MessageCircle class="h-4 w-4" />
                            </a>
                            <button type="button" class="rounded-lg p-2 text-slate-500 hover:bg-slate-100" title="Riwayat" aria-label="Riwayat" @click="openHistory(row)">
                                <History class="h-4 w-4" />
                            </button>
                            <button type="button" class="rounded-lg p-2 text-slate-500 hover:bg-slate-100" title="Koreksi stempel" aria-label="Koreksi stempel" @click="openAdjust(row)">
                                <SlidersHorizontal class="h-4 w-4" />
                            </button>
                            <button type="button" class="rounded-lg p-2 text-red-500 hover:bg-red-50" title="Hapus kartu" aria-label="Hapus kartu" @click="removeCard(row)">
                                <Trash2 v-if="removingId !== row.id" class="h-4 w-4" />
                                <span v-else class="text-xs">Menghapus…</span>
                            </button>
                        </div>
                    </div>

                    <div v-if="customers.links.length > 3" class="flex flex-wrap justify-center gap-1 pt-2">
                        <Link
                            v-for="(l, i) in customers.links"
                            :key="i"
                            :href="l.url || '#'"
                            preserve-scroll
                            class="rounded-lg px-3 py-1 text-sm"
                            :class="[l.active ? 'bg-primary text-white' : 'text-slate-600 hover:bg-slate-100', !l.url && 'pointer-events-none opacity-40']"
                        >
                            <span v-html="l.label" />
                        </Link>
                    </div>
                </CardContent>
            </Card>
        </div>

        <!-- Modal riwayat -->
        <div v-if="historyFor" class="fixed inset-0 z-50 flex items-end justify-center bg-black/40 sm:items-center" @click.self="historyFor = null">
            <div class="max-h-[85vh] w-full max-w-md overflow-y-auto rounded-t-3xl bg-white p-5 sm:rounded-3xl">
                <div class="mb-3 flex items-center justify-between">
                    <div>
                        <div class="font-bold text-slate-900">Riwayat {{ historyFor.name }}</div>
                        <div class="text-xs text-slate-500">Sekarang {{ historyFor.count }}/{{ historyFor.target }} · {{ historyFor.rewards_ready }} hadiah siap</div>
                    </div>
                    <button type="button" class="rounded-lg p-1.5 hover:bg-slate-100" aria-label="Tutup" @click="historyFor = null"><X class="h-5 w-5" /></button>
                </div>
                <div v-if="historyLoading" class="py-6 text-center text-sm text-slate-500">Memuat…</div>
                <div v-else-if="!historyRows.length" class="py-6 text-center text-sm text-slate-500">Belum ada riwayat.</div>
                <div v-for="h in historyRows" :key="h.id" class="border-b border-slate-100 py-2 text-sm last:border-0">
                    <div class="flex justify-between">
                        <span class="font-semibold text-slate-800">{{ typeLabel[h.type] ?? h.type }}</span>
                        <span :class="h.stamps < 0 ? 'text-red-600' : 'text-slate-700'">
                            <template v-if="h.type === 'redeem'">−1 hadiah</template>
                            <template v-else>{{ h.stamps > 0 ? '+' : '' }}{{ h.stamps }} stempel</template>
                        </span>
                    </div>
                    <div class="text-xs text-slate-500">{{ h.note }} · {{ fmtDate(h.at) }}{{ h.by ? ` · ${h.by}` : '' }}</div>
                </div>
            </div>
        </div>

        <!-- Modal koreksi -->
        <div v-if="adjustFor" class="fixed inset-0 z-50 flex items-end justify-center bg-black/40 sm:items-center" @click.self="adjustFor = null">
            <div class="w-full max-w-sm rounded-t-3xl bg-white p-5 sm:rounded-3xl">
                <div class="mb-1 font-bold text-slate-900">Koreksi stempel {{ adjustFor.name }}</div>
                <p class="mb-4 text-xs text-slate-500">Pakai angka minus untuk mengurangi. Koreksi tercatat di riwayat dan log aktivitas.</p>
                <label class="mb-3 block">
                    <span class="mb-1 block text-sm font-semibold text-slate-700">Jumlah stempel</span>
                    <input v-model.number="adjustForm.delta" type="number" min="-100" max="100" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm" />
                    <span v-if="adjustForm.errors.delta" class="text-xs text-red-600">{{ adjustForm.errors.delta }}</span>
                </label>
                <label class="mb-4 block">
                    <span class="mb-1 block text-sm font-semibold text-slate-700">Alasan</span>
                    <input v-model="adjustForm.note" type="text" maxlength="200" placeholder="Pindahan dari kartu kertas" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm" />
                    <span v-if="adjustForm.errors.note" class="text-xs text-red-600">{{ adjustForm.errors.note }}</span>
                </label>
                <div class="flex justify-end gap-2">
                    <Button variant="ghost" @click="adjustFor = null">Batal</Button>
                    <Button :disabled="adjustForm.processing" @click="submitAdjust">Simpan</Button>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
