<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { confirmDelete } from '@/lib/swal';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Banknote, Gift, Loader2, MoreVertical, Pencil, Percent, Plus, Search, Tag, Trash2, X } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

type DiscountType = 'percentage' | 'fixed_amount' | 'buy_x_get_y';
type Status = 'running' | 'scheduled' | 'ended' | 'quota' | 'off';

interface Discount {
    id: number;
    name: string;
    code: string | null;
    type: DiscountType;
    value: string;
    buy_x?: number | null;
    get_y?: number | null;
    minimum_amount: string | null;
    maximum_uses: number | null;
    used_count: number;
    starts_at_local?: string | null;
    expires_at_local?: string | null;
    is_active: boolean;
    description: string | null;
    applicable_categories: string[] | null;
    applicable_products: number[] | null;
    status: Status;
}

const props = defineProps<{
    discounts: Discount[];
    categories: string[];
    products: Array<{ id: number; name: string; category: string | null }>;
}>();

const page = usePage();
const allowsAdvancedRetail = computed(() => {
    const pl = page.props.plan_limits as { allows_advanced_retail?: boolean } | null | undefined;
    return pl == null ? true : pl.allows_advanced_retail === true;
});

const rupiah = (n: number | string | null | undefined) => 'Rp ' + new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(Number(n ?? 0));
const productName = (id: number) => props.products.find((p) => p.id === id)?.name ?? `Produk #${id}`;

// ── Status & filter ─────────────────────────────────────────────────────
const STATUS: Record<Status, { label: string; class: string }> = {
    running: { label: 'Berjalan', class: 'bg-emerald-50 text-emerald-700' },
    scheduled: { label: 'Terjadwal', class: 'bg-sky-50 text-sky-700' },
    ended: { label: 'Berakhir', class: 'bg-slate-100 text-slate-500' },
    quota: { label: 'Kuota habis', class: 'bg-amber-50 text-amber-700' },
    off: { label: 'Dimatikan', class: 'bg-slate-100 text-slate-500' },
};
const filter = ref<'all' | 'running' | 'scheduled' | 'past'>('all');
const FILTERS = computed(() => [
    { key: 'all' as const, label: 'Semua', count: props.discounts.length },
    { key: 'running' as const, label: 'Berjalan', count: props.discounts.filter((d) => d.status === 'running').length },
    { key: 'scheduled' as const, label: 'Terjadwal', count: props.discounts.filter((d) => d.status === 'scheduled').length },
    { key: 'past' as const, label: 'Selesai / mati', count: props.discounts.filter((d) => ['ended', 'quota', 'off'].includes(d.status)).length },
]);
const visible = computed(() =>
    props.discounts.filter((d) =>
        filter.value === 'all' ? true : filter.value === 'past' ? ['ended', 'quota', 'off'].includes(d.status) : d.status === filter.value,
    ),
);

function valueText(d: { type: string; value: string | number; buy_x?: number | null; get_y?: number | null }) {
    if (d.type === 'percentage') return `${Number(d.value)}%`;
    if (d.type === 'buy_x_get_y') return `Beli ${d.buy_x ?? '—'} gratis ${d.get_y ?? '—'}`;
    return rupiah(d.value);
}
function scopeText(d: { applicable_categories: string[] | null; applicable_products: number[] | null }) {
    if (d.applicable_products?.length) {
        const names = d.applicable_products.slice(0, 2).map(productName).join(', ');
        return d.applicable_products.length > 2 ? `${names} +${d.applicable_products.length - 2}` : names;
    }
    if (d.applicable_categories?.length) return `Kategori ${d.applicable_categories.join(', ')}`;
    return 'Semua produk';
}
const fmtDate = (v?: string | null) => (v ? new Date(v).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' }) : null);
function periodText(d: Discount) {
    const a = fmtDate(d.starts_at_local);
    const b = fmtDate(d.expires_at_local);
    if (a && b) return `${a} – ${b}`;
    if (a) return `Mulai ${a}`;
    if (b) return `Sampai ${b}`;
    return 'Tanpa batas waktu';
}

// ── Form tambah / ubah (panel) ──────────────────────────────────────────
const editing = ref<Discount | null>(null);
const formOpen = ref(false);
const form = useForm({
    name: '',
    code: '',
    type: 'percentage' as DiscountType,
    value: 0 as number,
    buy_x: null as number | null,
    get_y: null as number | null,
    minimum_amount: null as number | null,
    maximum_uses: null as number | null,
    starts_at: '',
    expires_at: '',
    description: '',
    is_active: true,
    applies_to: 'all' as 'all' | 'categories' | 'products',
    applicable_categories: [] as string[],
    applicable_products: [] as number[],
});

function openCreate() {
    editing.value = null;
    form.reset();
    form.clearErrors();
    formOpen.value = true;
}
function openEdit(d: Discount) {
    editing.value = d;
    form.clearErrors();
    form.name = d.name;
    form.code = d.code ?? '';
    form.type = d.type;
    form.value = Number(d.value);
    form.buy_x = d.buy_x ?? null;
    form.get_y = d.get_y ?? null;
    form.minimum_amount = d.minimum_amount != null ? Number(d.minimum_amount) : null;
    form.maximum_uses = d.maximum_uses;
    form.starts_at = d.starts_at_local ?? '';
    form.expires_at = d.expires_at_local ?? '';
    form.description = d.description ?? '';
    form.is_active = d.is_active;
    form.applicable_products = d.applicable_products ?? [];
    form.applicable_categories = d.applicable_categories ?? [];
    form.applies_to = form.applicable_products.length ? 'products' : form.applicable_categories.length ? 'categories' : 'all';
    formOpen.value = true;
}
watch(
    () => form.type,
    (t) => {
        if (t === 'buy_x_get_y') form.value = 0;
        else {
            form.buy_x = null;
            form.get_y = null;
        }
    },
);

function submit() {
    const opts = { preserveScroll: true, onSuccess: () => (formOpen.value = false) };
    if (editing.value) form.patch(`/discounts/${editing.value.id}`, opts);
    else form.post('/discounts', opts);
}

// Pilih produk untuk "Produk tertentu"
const productSearch = ref('');
const productResults = computed(() => {
    const q = productSearch.value.trim().toLowerCase();
    if (!q) return [];
    return props.products.filter((p) => p.name.toLowerCase().includes(q) && !form.applicable_products.includes(p.id)).slice(0, 6);
});
function addProduct(id: number) {
    form.applicable_products = [...form.applicable_products, id];
    productSearch.value = '';
}
function toggleCategory(name: string) {
    form.applicable_categories = form.applicable_categories.includes(name)
        ? form.applicable_categories.filter((c) => c !== name)
        : [...form.applicable_categories, name];
}

/** Pratinjau kalimat promo. */
const preview = computed(() => {
    const what =
        form.type === 'buy_x_get_y'
            ? `Beli ${form.buy_x || 'X'} gratis ${form.get_y || 'Y'}`
            : form.type === 'percentage'
              ? `Diskon ${form.value || 0}%`
              : `Potongan ${rupiah(form.value)}`;
    const scope =
        form.applies_to === 'products'
            ? form.applicable_products.length
                ? `untuk ${form.applicable_products.map(productName).join(', ')}`
                : 'untuk produk yang dipilih'
            : form.applies_to === 'categories'
              ? form.applicable_categories.length
                  ? `untuk kategori ${form.applicable_categories.join(', ')}`
                  : 'untuk kategori yang dipilih'
              : 'untuk semua produk';
    const min = form.minimum_amount ? `, minimal belanja ${rupiah(form.minimum_amount)}` : '';
    const a = fmtDate(form.starts_at);
    const b = fmtDate(form.expires_at);
    const when = a && b ? `, ${a} – ${b}` : a ? `, mulai ${a}` : b ? `, sampai ${b}` : '';
    const code = form.code.trim() ? ` Pakai kode ${form.code.trim().toUpperCase()}.` : ' Kasir cukup memilih promo ini.';
    return `${what} ${scope}${min}${when}.${code}`;
});

// ── Aksi baris ──────────────────────────────────────────────────────────
function toggleActive(d: Discount) {
    router.patch(
        `/discounts/${d.id}`,
        {
            name: d.name,
            code: d.code ?? '',
            type: d.type,
            value: Number(d.value),
            buy_x: d.buy_x ?? null,
            get_y: d.get_y ?? null,
            minimum_amount: d.minimum_amount != null ? Number(d.minimum_amount) : null,
            maximum_uses: d.maximum_uses,
            starts_at: d.starts_at_local ?? null,
            expires_at: d.expires_at_local ?? null,
            description: d.description ?? '',
            is_active: !d.is_active,
            applies_to: d.applicable_products?.length ? 'products' : d.applicable_categories?.length ? 'categories' : 'all',
            applicable_products: d.applicable_products ?? [],
            applicable_categories: d.applicable_categories ?? [],
        },
        { preserveScroll: true },
    );
}
async function destroy(d: Discount) {
    if (!(await confirmDelete('Hapus promo?', `Promo «${d.name}» akan dihapus.`))) return;
    router.delete(`/discounts/${d.id}`, { preserveScroll: true });
}

const inputClass =
    'h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none';
</script>

<template>
    <Head title="Diskon & Promo" />

    <AdminLayout title="Diskon & Promo">
        <template #header-actions>
            <Button v-if="allowsAdvancedRetail" class="gap-1.5" @click="openCreate"><Plus class="h-4 w-4" /> Buat promo</Button>
        </template>
        <template #mobile-header-actions>
            <Button v-if="allowsAdvancedRetail" size="sm" class="gap-1 text-xs" @click="openCreate"><Plus class="h-3.5 w-3.5" /> Promo</Button>
        </template>

        <div class="mx-auto max-w-5xl space-y-4">
            <p v-if="!allowsAdvancedRetail" class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                Diskon & promo tersedia di paket GrowUp. <Link href="/subscription" class="font-semibold underline">Lihat paket</Link>
            </p>

            <section class="rounded-2xl border border-slate-200 bg-white">
                <div class="flex gap-1 overflow-x-auto border-b border-slate-100 px-3 py-2 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                    <button
                        v-for="f in FILTERS"
                        :key="f.key"
                        type="button"
                        class="inline-flex shrink-0 items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-semibold whitespace-nowrap transition"
                        :class="filter === f.key ? 'bg-primary text-white' : 'text-slate-500 hover:bg-slate-100 hover:text-slate-800'"
                        @click="filter = f.key"
                    >
                        {{ f.label }}
                        <span class="rounded-full px-1.5 text-xs" :class="filter === f.key ? 'bg-white/20' : 'bg-slate-100 text-slate-600'">{{
                            f.count
                        }}</span>
                    </button>
                </div>

                <div v-if="!visible.length" class="px-6 py-14 text-center">
                    <Tag class="mx-auto mb-3 h-8 w-8 text-slate-300" />
                    <p class="font-semibold text-slate-700">{{ discounts.length ? 'Tidak ada promo di daftar ini' : 'Belum ada promo' }}</p>
                    <p v-if="!discounts.length" class="mt-1 text-sm text-slate-500">Buat diskon persen, potongan rupiah, atau beli X gratis Y.</p>
                    <Button v-if="!discounts.length && allowsAdvancedRetail" class="mt-4 gap-1.5" @click="openCreate"
                        ><Plus class="h-4 w-4" /> Buat promo pertama</Button
                    >
                </div>

                <ul v-else class="divide-y divide-slate-100">
                    <li v-for="d in visible" :key="d.id" class="flex items-center gap-3 px-4 py-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary">
                            <Percent v-if="d.type === 'percentage'" class="h-4 w-4" />
                            <Gift v-else-if="d.type === 'buy_x_get_y'" class="h-4 w-4" />
                            <Banknote v-else class="h-4 w-4" />
                        </span>
                        <button type="button" class="min-w-0 flex-1 text-left" :disabled="!allowsAdvancedRetail" @click="openEdit(d)">
                            <span class="flex flex-wrap items-center gap-2">
                                <span class="truncate text-sm font-semibold text-slate-900">{{ d.name }}</span>
                                <span v-if="d.code" class="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[11px] text-slate-600">{{
                                    d.code
                                }}</span>
                                <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="STATUS[d.status].class">{{
                                    STATUS[d.status].label
                                }}</span>
                            </span>
                            <span class="mt-0.5 block truncate text-xs text-slate-500">
                                <strong class="font-semibold text-slate-700">{{ valueText(d) }}</strong> · {{ scopeText(d)
                                }}<template v-if="d.minimum_amount && Number(d.minimum_amount) > 0"> · min. {{ rupiah(d.minimum_amount) }}</template>
                            </span>
                            <span class="mt-0.5 block text-xs text-slate-400">{{ periodText(d) }}</span>
                        </button>
                        <span class="hidden shrink-0 text-right sm:block">
                            <span class="block text-sm font-semibold text-slate-800"
                                >{{ d.used_count }}<template v-if="d.maximum_uses">/{{ d.maximum_uses }}</template></span
                            >
                            <span class="block text-xs text-slate-400">dipakai</span>
                            <span v-if="d.maximum_uses" class="mt-1 block h-1 w-16 overflow-hidden rounded-full bg-slate-100">
                                <span
                                    class="block h-full bg-primary"
                                    :style="{ width: Math.min(100, (d.used_count / d.maximum_uses) * 100) + '%' }"
                                />
                            </span>
                        </span>
                        <button
                            type="button"
                            role="switch"
                            :aria-checked="d.is_active"
                            :aria-label="`${d.is_active ? 'Matikan' : 'Aktifkan'} ${d.name}`"
                            :disabled="!allowsAdvancedRetail"
                            class="relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition-colors disabled:opacity-50"
                            :class="d.is_active ? 'bg-primary' : 'bg-slate-300'"
                            @click="toggleActive(d)"
                        >
                            <span
                                class="inline-block h-4 w-4 rounded-full bg-white transition-transform"
                                :class="d.is_active ? 'translate-x-[18px]' : 'translate-x-0.5'"
                            />
                        </button>
                        <DropdownMenu>
                            <DropdownMenuTrigger as-child>
                                <button
                                    type="button"
                                    class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700"
                                    :aria-label="`Aksi ${d.name}`"
                                >
                                    <MoreVertical class="h-4 w-4" />
                                </button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" class="w-40">
                                <DropdownMenuItem
                                    class="flex cursor-pointer items-center gap-2"
                                    :disabled="!allowsAdvancedRetail"
                                    @click="openEdit(d)"
                                >
                                    <Pencil class="h-4 w-4" /> Ubah
                                </DropdownMenuItem>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem class="flex cursor-pointer items-center gap-2 text-red-600" @click="destroy(d)"
                                    ><Trash2 class="h-4 w-4" /> Hapus</DropdownMenuItem
                                >
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </li>
                </ul>
            </section>
        </div>

        <!-- Panel tambah / ubah promo -->
        <Dialog :open="formOpen" @update:open="(v) => !v && !form.processing && (formOpen = false)">
            <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{{ editing ? 'Ubah promo' : 'Buat promo' }}</DialogTitle>
                    <DialogDescription>{{ preview }}</DialogDescription>
                </DialogHeader>

                <form id="discount-form" class="space-y-4" @submit.prevent="submit">
                    <div class="space-y-1.5">
                        <label for="d_name" class="text-sm font-semibold text-slate-700">Nama promo</label>
                        <input id="d_name" v-model="form.name" required :class="inputClass" placeholder="Mis. Promo akhir pekan" />
                        <p v-if="form.errors.name" class="text-sm text-red-600">{{ form.errors.name }}</p>
                    </div>

                    <div class="space-y-1.5">
                        <span class="text-sm font-semibold text-slate-700">Jenis promo</span>
                        <div class="grid grid-cols-3 gap-2" role="radiogroup">
                            <button
                                v-for="t in [
                                    { key: 'percentage', label: 'Persen', icon: Percent },
                                    { key: 'fixed_amount', label: 'Potongan Rp', icon: Banknote },
                                    { key: 'buy_x_get_y', label: 'Beli X gratis Y', icon: Gift },
                                ] as const"
                                :key="t.key"
                                type="button"
                                role="radio"
                                :aria-checked="form.type === t.key"
                                class="flex flex-col items-center gap-1 rounded-xl border px-2 py-2.5 text-xs font-semibold transition"
                                :class="
                                    form.type === t.key
                                        ? 'border-primary bg-primary/5 text-primary'
                                        : 'border-slate-200 text-slate-600 hover:border-slate-300'
                                "
                                @click="form.type = t.key"
                            >
                                <component :is="t.icon" class="h-4 w-4" />
                                {{ t.label }}
                            </button>
                        </div>
                    </div>

                    <div v-if="form.type !== 'buy_x_get_y'" class="space-y-1.5">
                        <label for="d_value" class="text-sm font-semibold text-slate-700">{{
                            form.type === 'percentage' ? 'Besar diskon (%)' : 'Potongan (Rp)'
                        }}</label>
                        <input
                            id="d_value"
                            v-model.number="form.value"
                            type="number"
                            min="0"
                            step="any"
                            :max="form.type === 'percentage' ? 100 : undefined"
                            :class="inputClass"
                        />
                        <p v-if="form.errors.value" class="text-sm text-red-600">{{ form.errors.value }}</p>
                    </div>
                    <div v-else class="grid grid-cols-2 gap-3">
                        <div class="space-y-1.5">
                            <label for="d_bx" class="text-sm font-semibold text-slate-700">Beli (jumlah dibayar)</label>
                            <input id="d_bx" v-model.number="form.buy_x" type="number" min="1" max="999" :class="inputClass" />
                        </div>
                        <div class="space-y-1.5">
                            <label for="d_gy" class="text-sm font-semibold text-slate-700">Gratis (jumlah)</label>
                            <input id="d_gy" v-model.number="form.get_y" type="number" min="1" max="999" :class="inputClass" />
                        </div>
                        <p v-if="form.errors.buy_x || form.errors.get_y" class="col-span-2 text-sm text-red-600">
                            {{ form.errors.buy_x || form.errors.get_y }}
                        </p>
                    </div>

                    <!-- Berlaku untuk -->
                    <div class="space-y-1.5">
                        <span class="text-sm font-semibold text-slate-700">Berlaku untuk</span>
                        <div class="grid grid-cols-3 gap-2" role="radiogroup">
                            <button
                                v-for="a in [
                                    { key: 'all', label: 'Semua produk' },
                                    { key: 'categories', label: 'Kategori' },
                                    { key: 'products', label: 'Produk tertentu' },
                                ] as const"
                                :key="a.key"
                                type="button"
                                role="radio"
                                :aria-checked="form.applies_to === a.key"
                                class="rounded-xl border px-2 py-2 text-xs font-semibold transition"
                                :class="
                                    form.applies_to === a.key
                                        ? 'border-primary bg-primary/5 text-primary'
                                        : 'border-slate-200 text-slate-600 hover:border-slate-300'
                                "
                                @click="form.applies_to = a.key"
                            >
                                {{ a.label }}
                            </button>
                        </div>
                        <div v-if="form.applies_to === 'categories'" class="flex flex-wrap gap-1.5 pt-1">
                            <button
                                v-for="c in categories"
                                :key="c"
                                type="button"
                                class="rounded-full border px-3 py-1 text-xs font-semibold transition"
                                :class="
                                    form.applicable_categories.includes(c)
                                        ? 'border-primary bg-primary text-white'
                                        : 'border-slate-200 text-slate-600 hover:border-slate-300'
                                "
                                @click="toggleCategory(c)"
                            >
                                {{ c }}
                            </button>
                            <p v-if="!categories.length" class="text-xs text-slate-500">
                                Belum ada kategori. <Link href="/product-categories" class="font-semibold text-primary">Buat kategori</Link>
                            </p>
                        </div>
                        <div v-if="form.applies_to === 'products'" class="space-y-2 pt-1">
                            <div class="relative">
                                <Search class="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-slate-400" />
                                <input v-model="productSearch" type="search" placeholder="Cari produk lalu pilih" :class="[inputClass, 'pl-9']" />
                                <ul
                                    v-if="productResults.length"
                                    class="absolute top-full right-0 left-0 z-20 mt-1 overflow-hidden rounded-xl border border-slate-200 bg-white"
                                >
                                    <li v-for="p in productResults" :key="p.id">
                                        <button
                                            type="button"
                                            class="block w-full px-3 py-2 text-left text-sm hover:bg-slate-50"
                                            @click="addProduct(p.id)"
                                        >
                                            {{ p.name }} <span v-if="p.category" class="text-xs text-slate-400">· {{ p.category }}</span>
                                        </button>
                                    </li>
                                </ul>
                            </div>
                            <div class="flex flex-wrap gap-1.5">
                                <span
                                    v-for="id in form.applicable_products"
                                    :key="id"
                                    class="inline-flex items-center gap-1 rounded-full bg-primary/10 px-2.5 py-1 text-xs font-semibold text-primary"
                                >
                                    {{ productName(id) }}
                                    <button
                                        type="button"
                                        :aria-label="`Hapus ${productName(id)}`"
                                        @click="form.applicable_products = form.applicable_products.filter((x) => x !== id)"
                                    >
                                        <X class="h-3 w-3" />
                                    </button>
                                </span>
                            </div>
                        </div>
                        <p v-if="form.errors.applicable_categories || form.errors.applicable_products" class="text-sm text-red-600">
                            {{ form.errors.applicable_categories || form.errors.applicable_products }}
                        </p>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div class="space-y-1.5">
                            <label for="d_min" class="text-sm font-semibold text-slate-700"
                                >Minimal belanja <span class="font-normal text-slate-400">(Rp)</span></label
                            >
                            <input
                                id="d_min"
                                v-model.number="form.minimum_amount"
                                type="number"
                                min="0"
                                step="any"
                                :class="inputClass"
                                placeholder="Tanpa minimal"
                            />
                        </div>
                        <div class="space-y-1.5">
                            <label for="d_max" class="text-sm font-semibold text-slate-700">Kuota pemakaian</label>
                            <input
                                id="d_max"
                                v-model.number="form.maximum_uses"
                                type="number"
                                min="1"
                                :class="inputClass"
                                placeholder="Tanpa batas"
                            />
                        </div>
                        <div class="space-y-1.5">
                            <label for="d_start" class="text-sm font-semibold text-slate-700">Mulai</label>
                            <input id="d_start" v-model="form.starts_at" type="datetime-local" :class="inputClass" />
                        </div>
                        <div class="space-y-1.5">
                            <label for="d_end" class="text-sm font-semibold text-slate-700">Berakhir</label>
                            <input id="d_end" v-model="form.expires_at" type="datetime-local" :class="inputClass" />
                        </div>
                        <p v-if="form.errors.expires_at" class="col-span-2 text-sm text-red-600">{{ form.errors.expires_at }}</p>
                    </div>

                    <div class="space-y-1.5">
                        <label for="d_code" class="text-sm font-semibold text-slate-700"
                            >Kode promo <span class="font-normal text-slate-400">(opsional)</span></label
                        >
                        <input id="d_code" v-model="form.code" :class="[inputClass, 'font-mono uppercase']" placeholder="Mis. HEMAT10" />
                        <p class="text-xs text-slate-500">
                            Kosongkan agar kasir cukup memilih promo dari daftar. Isi bila pelanggan harus menyebut kode.
                        </p>
                    </div>

                    <div class="space-y-1.5">
                        <label for="d_desc" class="text-sm font-semibold text-slate-700"
                            >Catatan <span class="font-normal text-slate-400">(opsional)</span></label
                        >
                        <input id="d_desc" v-model="form.description" :class="inputClass" />
                    </div>

                    <label class="flex items-center gap-2 text-sm text-slate-700">
                        <input v-model="form.is_active" type="checkbox" class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary" />
                        Aktifkan promo
                    </label>
                </form>

                <DialogFooter class="gap-2">
                    <Button type="button" variant="ghost" :disabled="form.processing" @click="formOpen = false">Batal</Button>
                    <Button type="submit" form="discount-form" :disabled="form.processing">
                        <Loader2 v-if="form.processing" class="mr-1 h-4 w-4 animate-spin" />
                        {{ editing ? 'Simpan perubahan' : 'Simpan promo' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AdminLayout>
</template>
