<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { confirmDelete } from '@/lib/swal';
import { notifications as notificationsRoute, promo as promoRoute } from '@/routes/loyalty';
import {
    destroy as destroyRewardRoute,
    store as storeRewardRoute,
    toggle as toggleRewardRoute,
    update as updateRewardRoute,
} from '@/routes/loyalty/rewards';
import { update as updateSettingsRoute } from '@/routes/loyalty/settings';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    Bell,
    Cake,
    CircleAlert,
    CircleCheck,
    Coins,
    Gift,
    History,
    Hourglass,
    Megaphone,
    Package,
    Pencil,
    Plus,
    Repeat,
    Settings2,
    Sparkles,
    Ticket,
    Trash2,
    TrendingUp,
    Trophy,
    UserPlus,
    Users,
} from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

interface Reward {
    id: number;
    name: string;
    description: string | null;
    type: 'voucher' | 'free_product' | 'service';
    points_cost: number;
    voucher_value: string | null;
    stock: number | null;
    redeemed_count: number;
    is_active: boolean;
}

interface Redemption {
    id: number;
    customer_name: string;
    reward_name: string;
    reward_type: string;
    points_spent: number;
    voucher_value: string | null;
    voucher_code: string | null;
    status: string;
    created_at: string | null;
}

interface Member {
    id: number;
    name: string;
    points_balance: number;
    points_earned_total: number;
    points_redeemed_total: number;
}

interface Settings {
    enabled: boolean;
    rupiah_per_point: number;
    birthday_bonus_points: number;
    referral_bonus_points: number;
    expiry_months: number;
    expiry_warn_days: number;
}

const props = defineProps<{
    settings: Settings;
    stats: {
        total_circulating: number;
        total_earned: number;
        total_redeemed: number;
        members_with_points: number;
        rewards_count: number;
    };
    rewards: Reward[];
    recentRedemptions: Redemption[];
    topMembers: Member[];
    store: { id: number; name: string; currency: string };
}>();

const num = (n: number | string | null | undefined) => new Intl.NumberFormat('id-ID').format(Number(n ?? 0));
const formatDate = (d: string | null) =>
    d ? new Date(d).toLocaleString('id-ID', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '-';

const rewardTypes = {
    voucher: { label: 'Voucher diskon', icon: Ticket, tone: 'bg-sky-50 text-sky-700' },
    free_product: { label: 'Produk gratis', icon: Package, tone: 'bg-emerald-50 text-emerald-700' },
    service: { label: 'Layanan eksklusif', icon: Sparkles, tone: 'bg-violet-50 text-violet-700' },
} as const;
const typeInfo = (t: string) => rewardTypes[t as Reward['type']] ?? rewardTypes.voucher;

// --- Tab (diingat per perangkat) ---
type Tab = 'rewards' | 'rules' | 'members';
const TAB_KEY = 'loyalty.tab';
const readTab = (): Tab => {
    try {
        const t = localStorage.getItem(TAB_KEY);
        if (t === 'rewards' || t === 'rules' || t === 'members') return t;
    } catch {
        /* abaikan */
    }
    return 'rewards';
};
const tab = ref<Tab>(readTab());
watch(tab, (t) => {
    try {
        localStorage.setItem(TAB_KEY, t);
    } catch {
        /* abaikan */
    }
});
const tabs = computed(() => [
    { key: 'rewards' as Tab, label: 'Katalog hadiah', icon: Gift, count: props.rewards.length },
    { key: 'rules' as Tab, label: 'Aturan poin', icon: Settings2, count: null },
    { key: 'members' as Tab, label: 'Anggota & riwayat', icon: Users, count: null },
]);

// --- Form aturan ---
const settingsForm = useForm({
    enabled: props.settings.enabled,
    rupiah_per_point: props.settings.rupiah_per_point,
    birthday_bonus_points: props.settings.birthday_bonus_points,
    referral_bonus_points: props.settings.referral_bonus_points,
    expiry_months: props.settings.expiry_months,
    expiry_warn_days: props.settings.expiry_warn_days,
});
const saveSettings = () =>
    settingsForm
        // Status aktif diatur lewat tombol di banner; jangan sampai tertimpa nilai lama form.
        .transform((d) => ({ ...d, enabled: props.settings.enabled }))
        .put(updateSettingsRoute.url(), {
            preserveScroll: true,
            onSuccess: () => settingsForm.defaults(),
        });
// Aktif/nonaktifkan program memakai aturan yang sudah tersimpan, bukan isian form yang belum disimpan.
const togglingProgram = ref(false);
const toggleProgram = () => {
    router.put(
        updateSettingsRoute.url(),
        { ...props.settings, enabled: !props.settings.enabled },
        {
            preserveScroll: true,
            preserveState: true,
            onStart: () => (togglingProgram.value = true),
            onFinish: () => (togglingProgram.value = false),
        },
    );
};

// Contoh simulasi agar aturan mudah dipahami
const exampleSpend = 100000;
const examplePoints = computed(() => {
    const rpp = Number(settingsForm.rupiah_per_point) || 0;
    return rpp > 0 ? Math.floor(exampleSpend / rpp) : 0;
});
const cheapestReward = computed(() => props.rewards.filter((r) => r.is_active).sort((a, b) => a.points_cost - b.points_cost)[0] ?? null);
const spendForCheapest = computed(() => (cheapestReward.value ? cheapestReward.value.points_cost * (Number(settingsForm.rupiah_per_point) || 0) : 0));

// --- Dialog hadiah (tambah & ubah) ---
const rewardDialog = ref(false);
const editingReward = ref<Reward | null>(null);
const rewardForm = useForm({
    name: '',
    description: '',
    type: 'voucher' as Reward['type'],
    points_cost: 100,
    voucher_value: undefined as number | undefined,
    stock: undefined as number | undefined,
    is_active: true,
});

const rewardTemplates = [
    { name: 'Voucher Rp 10.000', type: 'voucher' as const, points_cost: 100, voucher_value: 10000 },
    { name: 'Voucher Rp 25.000', type: 'voucher' as const, points_cost: 250, voucher_value: 25000 },
    { name: 'Minuman gratis', type: 'free_product' as const, points_cost: 150, voucher_value: null },
];

const openCreateReward = (tpl?: (typeof rewardTemplates)[number]) => {
    editingReward.value = null;
    rewardForm.clearErrors();
    rewardForm.name = tpl?.name ?? '';
    rewardForm.description = '';
    rewardForm.type = tpl?.type ?? 'voucher';
    rewardForm.points_cost = tpl?.points_cost ?? 100;
    rewardForm.voucher_value = tpl?.voucher_value ?? undefined;
    rewardForm.stock = undefined;
    rewardForm.is_active = true;
    rewardDialog.value = true;
};
const openEditReward = (r: Reward) => {
    editingReward.value = r;
    rewardForm.clearErrors();
    rewardForm.name = r.name;
    rewardForm.description = r.description ?? '';
    rewardForm.type = r.type;
    rewardForm.points_cost = r.points_cost;
    rewardForm.voucher_value = r.voucher_value !== null ? Number(r.voucher_value) : undefined;
    rewardForm.stock = r.stock ?? undefined;
    rewardForm.is_active = r.is_active;
    rewardDialog.value = true;
};
const submitReward = () => {
    const opts = { preserveScroll: true, onSuccess: () => (rewardDialog.value = false) };
    // Input number yang dikosongkan bernilai '' → kirim null agar lolos validasi nullable
    rewardForm.transform((d) => ({
        ...d,
        stock: d.stock === undefined || (d.stock as unknown) === '' ? null : d.stock,
        voucher_value: d.type === 'voucher' && (d.voucher_value as unknown) !== '' ? (d.voucher_value ?? null) : null,
    }));
    if (editingReward.value) rewardForm.put(updateRewardRoute.url(editingReward.value.id), opts);
    else rewardForm.post(storeRewardRoute.url(), opts);
};
const destroyReward = async (r: Reward) => {
    if (!(await confirmDelete('Hapus hadiah?', `Hadiah "${r.name}" akan dihapus dari katalog.`))) return;
    router.delete(destroyRewardRoute.url(r.id), { preserveScroll: true });
};
const toggleReward = (r: Reward) => {
    router.patch(toggleRewardRoute.url(r.id), {}, { preserveScroll: true });
};

const rewardValueText = (r: Reward) => {
    if (r.type === 'voucher' && r.voucher_value) return `Potongan Rp ${num(r.voucher_value)}`;
    return typeInfo(r.type).label;
};

// --- Dialog promo ---
const promoDialog = ref(false);
const promoForm = useForm({ title: '', body: '' });
const sendPromo = () => {
    promoForm.post(promoRoute.url(), {
        preserveScroll: true,
        onSuccess: () => {
            promoForm.reset();
            promoDialog.value = false;
        },
    });
};

const statCards = computed(() => [
    {
        label: 'Poin beredar',
        hint: 'Saldo poin milik pelanggan saat ini',
        value: props.stats.total_circulating,
        icon: Coins,
        tone: 'text-primary bg-primary/10',
    },
    {
        label: 'Total diperoleh',
        hint: 'Akumulasi poin dari belanja & bonus',
        value: props.stats.total_earned,
        icon: TrendingUp,
        tone: 'text-emerald-600 bg-emerald-50',
    },
    {
        label: 'Total ditukar',
        hint: 'Poin yang sudah jadi hadiah',
        value: props.stats.total_redeemed,
        icon: Repeat,
        tone: 'text-orange-600 bg-orange-50',
    },
    {
        label: 'Pelanggan berpoin',
        hint: 'Pelanggan dengan saldo > 0',
        value: props.stats.members_with_points,
        icon: Users,
        tone: 'text-sky-600 bg-sky-50',
    },
]);

const fieldClass = 'flex w-full rounded-md border border-input bg-background px-3 text-sm';
</script>

<template>
    <Head title="Program Loyalitas" />

    <AdminLayout title="Program Loyalitas & Poin">
        <div class="mx-auto space-y-5 pb-8" style="max-width: 90rem">
            <!-- Status program + aksi cepat -->
            <div
                class="flex flex-col gap-4 rounded-2xl border p-4 sm:flex-row sm:items-center sm:justify-between"
                :class="settings.enabled ? 'border-emerald-200 bg-emerald-50/70' : 'border-amber-200 bg-amber-50/70'"
            >
                <div class="flex items-start gap-3">
                    <component
                        :is="settings.enabled ? CircleCheck : CircleAlert"
                        class="mt-0.5 h-6 w-6 shrink-0"
                        :class="settings.enabled ? 'text-emerald-600' : 'text-amber-600'"
                    />
                    <div>
                        <p class="font-bold text-slate-900">
                            {{ settings.enabled ? 'Program loyalitas sedang berjalan' : 'Program loyalitas belum aktif' }}
                        </p>
                        <p class="text-sm text-slate-600">
                            <template v-if="settings.enabled">
                                Pelanggan mendapat 1 poin setiap belanja Rp {{ num(settings.rupiah_per_point) }}.
                                <span v-if="settings.expiry_months > 0">Poin berlaku {{ settings.expiry_months }} bulan.</span>
                                <span v-else>Poin tidak kadaluarsa.</span>
                            </template>
                            <template v-else>Aktifkan agar transaksi pelanggan di kasir mulai menghasilkan poin.</template>
                        </p>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <Button
                        size="sm"
                        class="rounded-xl"
                        :variant="settings.enabled ? 'outline' : 'default'"
                        :disabled="togglingProgram"
                        @click="toggleProgram"
                    >
                        {{ settings.enabled ? 'Nonaktifkan' : 'Aktifkan sekarang' }}
                    </Button>
                    <Button variant="outline" size="sm" class="gap-1.5 rounded-xl" @click="promoDialog = true">
                        <Megaphone class="h-4 w-4" /> Kirim promo
                    </Button>
                    <Link :href="notificationsRoute.url()">
                        <Button variant="outline" size="sm" class="gap-1.5 rounded-xl"> <Bell class="h-4 w-4" /> Notifikasi </Button>
                    </Link>
                </div>
            </div>

            <!-- Statistik -->
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <Card v-for="s in statCards" :key="s.label" class="rounded-2xl border-slate-200 shadow-sm">
                    <CardContent class="flex items-start gap-3 p-4">
                        <div class="hidden h-10 w-10 shrink-0 items-center justify-center rounded-xl sm:flex" :class="s.tone">
                            <component :is="s.icon" class="h-5 w-5" />
                        </div>
                        <div class="min-w-0">
                            <p class="text-xs font-medium text-slate-500">{{ s.label }}</p>
                            <p class="text-2xl font-black text-slate-900 tabular-nums">{{ num(s.value) }}</p>
                            <p class="hidden text-[11px] text-slate-400 md:block">{{ s.hint }}</p>
                        </div>
                    </CardContent>
                </Card>
            </div>

            <!-- Tab -->
            <div class="flex gap-1 overflow-x-auto rounded-xl border border-slate-200 bg-slate-100/70 p-1">
                <button
                    v-for="t in tabs"
                    :key="t.key"
                    type="button"
                    class="flex flex-1 items-center justify-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold whitespace-nowrap transition"
                    :class="tab === t.key ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-800'"
                    @click="tab = t.key"
                >
                    <component :is="t.icon" class="h-4 w-4" />
                    {{ t.label }}
                    <span v-if="t.count !== null" class="rounded-full bg-slate-200 px-1.5 text-xs text-slate-600">{{ t.count }}</span>
                </button>
            </div>

            <!-- ===== Tab: Katalog hadiah ===== -->
            <section v-if="tab === 'rewards'" class="space-y-4">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-bold text-slate-900">Katalog hadiah</h2>
                        <p class="text-sm text-slate-500">
                            Hadiah yang bisa ditukar pelanggan dengan poin. Kasir menukarkannya dari halaman pelanggan.
                        </p>
                    </div>
                    <Button class="gap-1.5 rounded-xl" @click="openCreateReward()"><Plus class="h-4 w-4" /> Tambah hadiah</Button>
                </div>

                <div v-if="rewards.length" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    <div
                        v-for="r in rewards"
                        :key="r.id"
                        class="flex flex-col rounded-2xl border bg-white p-4 shadow-sm transition"
                        :class="r.is_active ? 'border-slate-200' : 'border-dashed border-slate-300 opacity-70'"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <span
                                class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold"
                                :class="typeInfo(r.type).tone"
                            >
                                <component :is="typeInfo(r.type).icon" class="h-3.5 w-3.5" />
                                {{ typeInfo(r.type).label }}
                            </span>
                            <!-- Saklar aktif/nonaktif -->
                            <button
                                type="button"
                                role="switch"
                                :aria-checked="r.is_active"
                                :title="r.is_active ? 'Aktif — klik untuk menonaktifkan' : 'Nonaktif — klik untuk mengaktifkan'"
                                class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition"
                                :class="r.is_active ? 'bg-emerald-500' : 'bg-slate-300'"
                                @click="toggleReward(r)"
                            >
                                <span
                                    class="inline-block h-5 w-5 rounded-full bg-white shadow transition"
                                    :class="r.is_active ? 'translate-x-5' : 'translate-x-0.5'"
                                />
                            </button>
                        </div>

                        <p class="mt-3 line-clamp-2 font-bold text-slate-900">{{ r.name }}</p>
                        <p v-if="r.description" class="mt-0.5 line-clamp-2 text-xs text-slate-500">{{ r.description }}</p>

                        <div class="mt-3 flex items-baseline gap-1.5">
                            <span class="text-2xl font-black text-primary tabular-nums">{{ num(r.points_cost) }}</span>
                            <span class="text-sm font-medium text-slate-500">poin</span>
                            <span class="ml-auto text-xs font-medium text-slate-500">{{ rewardValueText(r) }}</span>
                        </div>

                        <div class="mt-3 flex items-center gap-3 border-t border-slate-100 pt-3 text-xs text-slate-500">
                            <span>
                                Stok:
                                <b class="text-slate-700" :class="{ 'text-red-600': r.stock === 0 }">
                                    {{ r.stock === null ? 'Tak terbatas' : r.stock === 0 ? 'Habis' : num(r.stock) }}
                                </b>
                            </span>
                            <span
                                >Ditukar: <b class="text-slate-700">{{ num(r.redeemed_count) }}×</b></span
                            >
                            <div class="ml-auto flex gap-1">
                                <Button variant="ghost" size="sm" class="h-8 w-8 p-0" title="Ubah hadiah" @click="openEditReward(r)">
                                    <Pencil class="h-4 w-4" />
                                </Button>
                                <Button variant="ghost" size="sm" class="h-8 w-8 p-0 text-red-600" title="Hapus hadiah" @click="destroyReward(r)">
                                    <Trash2 class="h-4 w-4" />
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Kosong: beri contoh siap pakai -->
                <div v-else class="rounded-2xl border border-dashed border-slate-300 p-8 text-center">
                    <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-primary/10">
                        <Gift class="h-6 w-6 text-primary" />
                    </div>
                    <p class="mt-3 font-bold text-slate-900">Belum ada hadiah</p>
                    <p class="mx-auto mt-1 max-w-md text-sm text-slate-500">
                        Pelanggan lebih semangat mengumpulkan poin bila hadiahnya jelas. Mulai dari contoh di bawah, lalu sesuaikan.
                    </p>
                    <div class="mt-4 flex flex-wrap justify-center gap-2">
                        <Button
                            v-for="tpl in rewardTemplates"
                            :key="tpl.name"
                            variant="outline"
                            size="sm"
                            class="rounded-xl"
                            @click="openCreateReward(tpl)"
                        >
                            {{ tpl.name }} · {{ num(tpl.points_cost) }} poin
                        </Button>
                    </div>
                </div>
            </section>

            <!-- ===== Tab: Aturan poin ===== -->
            <section v-else-if="tab === 'rules'">
                <form class="grid gap-5 lg:grid-cols-3" @submit.prevent="saveSettings">
                    <div class="space-y-5 lg:col-span-2">
                        <Card class="rounded-2xl border-slate-200 shadow-sm">
                            <CardHeader class="pb-3">
                                <CardTitle class="flex items-center gap-2 text-base"
                                    ><Coins class="h-4 w-4 text-primary" /> Cara mendapat poin</CardTitle
                                >
                                <CardDescription>Poin dihitung otomatis dari total belanja pelanggan di kasir.</CardDescription>
                            </CardHeader>
                            <CardContent class="space-y-2">
                                <Label for="s_rpp">Setiap belanja kelipatan *</Label>
                                <div class="flex items-center gap-2">
                                    <div class="relative max-w-xs flex-1">
                                        <span class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm text-slate-500">Rp</span>
                                        <Input id="s_rpp" v-model.number="settingsForm.rupiah_per_point" type="number" min="1" class="h-10 pl-9" />
                                    </div>
                                    <span class="text-sm font-medium text-slate-600">= 1 poin</span>
                                </div>
                                <p v-if="settingsForm.errors.rupiah_per_point" class="text-sm text-red-600">
                                    {{ settingsForm.errors.rupiah_per_point }}
                                </p>
                            </CardContent>
                        </Card>

                        <Card class="rounded-2xl border-slate-200 shadow-sm">
                            <CardHeader class="pb-3">
                                <CardTitle class="flex items-center gap-2 text-base"><Gift class="h-4 w-4 text-primary" /> Poin bonus</CardTitle>
                                <CardDescription>Isi 0 bila tidak ingin memberi bonus.</CardDescription>
                            </CardHeader>
                            <CardContent class="grid gap-4 sm:grid-cols-2">
                                <div class="space-y-2">
                                    <Label for="s_bday" class="flex items-center gap-1.5"
                                        ><Cake class="h-4 w-4 text-slate-400" /> Bonus ulang tahun</Label
                                    >
                                    <div class="relative">
                                        <Input
                                            id="s_bday"
                                            v-model.number="settingsForm.birthday_bonus_points"
                                            type="number"
                                            min="0"
                                            class="h-10 pr-14"
                                        />
                                        <span class="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm text-slate-500">poin</span>
                                    </div>
                                    <p class="text-xs text-slate-500">Diberikan saat pelanggan berulang tahun (tanggal lahir harus terisi).</p>
                                </div>
                                <div class="space-y-2">
                                    <Label for="s_ref" class="flex items-center gap-1.5"
                                        ><UserPlus class="h-4 w-4 text-slate-400" /> Bonus undang teman</Label
                                    >
                                    <div class="relative">
                                        <Input
                                            id="s_ref"
                                            v-model.number="settingsForm.referral_bonus_points"
                                            type="number"
                                            min="0"
                                            class="h-10 pr-14"
                                        />
                                        <span class="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm text-slate-500">poin</span>
                                    </div>
                                    <p class="text-xs text-slate-500">Diberikan ke pelanggan yang mengajak teman menjadi pelanggan baru.</p>
                                </div>
                            </CardContent>
                        </Card>

                        <Card class="rounded-2xl border-slate-200 shadow-sm">
                            <CardHeader class="pb-3">
                                <CardTitle class="flex items-center gap-2 text-base"
                                    ><Hourglass class="h-4 w-4 text-primary" /> Masa berlaku poin</CardTitle
                                >
                                <CardDescription>Poin yang lewat masa berlaku akan hangus otomatis.</CardDescription>
                            </CardHeader>
                            <CardContent class="grid gap-4 sm:grid-cols-2">
                                <div class="space-y-2">
                                    <Label for="s_exp">Poin berlaku selama *</Label>
                                    <div class="relative">
                                        <Input
                                            id="s_exp"
                                            v-model.number="settingsForm.expiry_months"
                                            type="number"
                                            min="0"
                                            max="120"
                                            class="h-10 pr-16"
                                        />
                                        <span class="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm text-slate-500"
                                            >bulan</span
                                        >
                                    </div>
                                    <p class="text-xs text-slate-500">Isi 0 agar poin tidak pernah kadaluarsa.</p>
                                    <p v-if="settingsForm.errors.expiry_months" class="text-sm text-red-600">
                                        {{ settingsForm.errors.expiry_months }}
                                    </p>
                                </div>
                                <div class="space-y-2" :class="{ 'opacity-50': !settingsForm.expiry_months }">
                                    <Label for="s_warn">Ingatkan pelanggan sebelum hangus *</Label>
                                    <div class="relative">
                                        <Input
                                            id="s_warn"
                                            v-model.number="settingsForm.expiry_warn_days"
                                            type="number"
                                            min="1"
                                            max="365"
                                            class="h-10 pr-14"
                                            :disabled="!settingsForm.expiry_months"
                                        />
                                        <span class="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm text-slate-500">hari</span>
                                    </div>
                                    <p class="text-xs text-slate-500">Notifikasi dikirim sekian hari sebelum poin hangus.</p>
                                    <p v-if="settingsForm.errors.expiry_warn_days" class="text-sm text-red-600">
                                        {{ settingsForm.errors.expiry_warn_days }}
                                    </p>
                                </div>
                            </CardContent>
                        </Card>
                    </div>

                    <!-- Simulasi + simpan -->
                    <div class="space-y-4 lg:sticky lg:top-4 lg:self-start">
                        <Card class="rounded-2xl border-primary/30 bg-primary/5 shadow-sm">
                            <CardHeader class="pb-2">
                                <CardTitle class="text-base">Simulasi untuk pelanggan</CardTitle>
                                <CardDescription>Berubah langsung saat Anda mengisi aturan.</CardDescription>
                            </CardHeader>
                            <CardContent class="space-y-3 text-sm text-slate-700">
                                <p>
                                    Belanja <b>Rp {{ num(exampleSpend) }}</b> → dapat <b class="text-primary">{{ num(examplePoints) }} poin</b>.
                                </p>
                                <p v-if="cheapestReward">
                                    Untuk menukar <b>{{ cheapestReward.name }}</b> ({{ num(cheapestReward.points_cost) }} poin), pelanggan perlu
                                    belanja total sekitar <b>Rp {{ num(spendForCheapest) }}</b
                                    >.
                                </p>
                                <p v-else class="text-slate-500">Tambahkan hadiah aktif untuk melihat berapa belanja yang dibutuhkan.</p>
                                <p v-if="settingsForm.expiry_months > 0">
                                    Poin hangus setelah {{ settingsForm.expiry_months }} bulan, diingatkan {{ settingsForm.expiry_warn_days }} hari
                                    sebelumnya.
                                </p>
                                <p v-else>Poin tidak pernah hangus.</p>
                            </CardContent>
                        </Card>

                        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                            <p v-if="settingsForm.isDirty" class="mb-3 text-sm font-medium text-amber-600">Ada perubahan yang belum disimpan.</p>
                            <p v-else class="mb-3 text-sm text-slate-500">Semua aturan sudah tersimpan.</p>
                            <Button type="submit" class="w-full rounded-xl" :disabled="settingsForm.processing || !settingsForm.isDirty">
                                {{ settingsForm.processing ? 'Menyimpan…' : 'Simpan aturan' }}
                            </Button>
                        </div>
                    </div>
                </form>
            </section>

            <!-- ===== Tab: Anggota & riwayat ===== -->
            <section v-else class="grid gap-5 lg:grid-cols-2">
                <Card class="rounded-2xl border-slate-200 shadow-sm">
                    <CardHeader class="pb-3">
                        <CardTitle class="flex items-center gap-2 text-base"
                            ><Trophy class="h-4 w-4 text-amber-500" /> Pelanggan poin terbanyak</CardTitle
                        >
                        <CardDescription>Klik nama untuk membuka profil dan menukar poin.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div v-if="topMembers.length" class="divide-y divide-slate-100">
                            <Link
                                v-for="(m, i) in topMembers"
                                :key="m.id"
                                :href="`/customers/${m.id}`"
                                class="-mx-2 flex items-center gap-3 rounded-lg px-2 py-2.5 hover:bg-slate-50"
                            >
                                <span
                                    class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-bold"
                                    :class="i < 3 ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-500'"
                                    >{{ i + 1 }}</span
                                >
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-semibold text-slate-800">{{ m.name }}</p>
                                    <p class="text-xs text-slate-500">
                                        Diperoleh {{ num(m.points_earned_total) }} · Ditukar {{ num(m.points_redeemed_total) }}
                                    </p>
                                </div>
                                <span class="text-sm font-bold whitespace-nowrap text-primary tabular-nums">{{ num(m.points_balance) }} poin</span>
                            </Link>
                        </div>
                        <p v-else class="py-6 text-center text-sm text-slate-500">Belum ada pelanggan yang memiliki poin.</p>
                    </CardContent>
                </Card>

                <Card class="rounded-2xl border-slate-200 shadow-sm">
                    <CardHeader class="pb-3">
                        <CardTitle class="flex items-center gap-2 text-base"
                            ><History class="h-4 w-4 text-primary" /> Penukaran hadiah terbaru</CardTitle
                        >
                        <CardDescription>20 penukaran terakhir.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div v-if="recentRedemptions.length" class="divide-y divide-slate-100">
                            <div v-for="r in recentRedemptions" :key="r.id" class="py-2.5">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="truncate text-sm font-semibold text-slate-800">{{ r.customer_name }}</span>
                                    <span class="text-sm font-bold whitespace-nowrap text-orange-600 tabular-nums"
                                        >−{{ num(r.points_spent) }} poin</span
                                    >
                                </div>
                                <div class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-500">
                                    <span>{{ r.reward_name }}</span>
                                    <span v-if="r.voucher_code" class="rounded bg-slate-100 px-1.5 font-mono text-slate-700">{{
                                        r.voucher_code
                                    }}</span>
                                    <span v-if="r.status === 'cancelled'" class="rounded-full bg-red-50 px-1.5 text-red-700">Dibatalkan</span>
                                    <span class="ml-auto">{{ formatDate(r.created_at) }}</span>
                                </div>
                            </div>
                        </div>
                        <p v-else class="py-6 text-center text-sm text-slate-500">Belum ada penukaran hadiah.</p>
                    </CardContent>
                </Card>
            </section>
        </div>

        <!-- Dialog tambah/ubah hadiah -->
        <Dialog v-model:open="rewardDialog">
            <DialogContent class="sm:max-w-[520px]">
                <DialogHeader>
                    <DialogTitle>{{ editingReward ? 'Ubah hadiah' : 'Tambah hadiah' }}</DialogTitle>
                    <DialogDescription>Tentukan hadiah dan berapa poin yang dibutuhkan untuk menukarnya.</DialogDescription>
                </DialogHeader>
                <form class="space-y-4" @submit.prevent="submitReward">
                    <div class="space-y-2">
                        <Label>Jenis hadiah *</Label>
                        <div class="grid grid-cols-3 gap-2">
                            <button
                                v-for="(info, key) in rewardTypes"
                                :key="key"
                                type="button"
                                class="flex flex-col items-center gap-1 rounded-xl border p-2.5 text-xs font-semibold transition"
                                :class="
                                    rewardForm.type === key
                                        ? 'border-primary bg-primary/5 text-primary'
                                        : 'border-slate-200 text-slate-600 hover:border-slate-300'
                                "
                                @click="rewardForm.type = key"
                            >
                                <component :is="info.icon" class="h-5 w-5" />
                                {{ info.label }}
                            </button>
                        </div>
                    </div>
                    <div class="space-y-2">
                        <Label for="r_name">Nama hadiah *</Label>
                        <Input id="r_name" v-model="rewardForm.name" required placeholder="Contoh: Voucher Rp 20.000" class="h-10" />
                        <p v-if="rewardForm.errors.name" class="text-sm text-red-600">{{ rewardForm.errors.name }}</p>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="space-y-2">
                            <Label for="r_cost">Ditukar dengan *</Label>
                            <div class="relative">
                                <Input id="r_cost" v-model.number="rewardForm.points_cost" type="number" min="1" required class="h-10 pr-14" />
                                <span class="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm text-slate-500">poin</span>
                            </div>
                            <p v-if="rewardForm.points_cost && settings.rupiah_per_point" class="text-xs text-slate-500">
                                ≈ belanja Rp {{ num(Number(rewardForm.points_cost) * settings.rupiah_per_point) }}
                            </p>
                            <p v-if="rewardForm.errors.points_cost" class="text-sm text-red-600">
                                {{ rewardForm.errors.points_cost }}
                            </p>
                        </div>
                        <div v-if="rewardForm.type === 'voucher'" class="space-y-2">
                            <Label for="r_val">Nilai potongan *</Label>
                            <div class="relative">
                                <span class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm text-slate-500">Rp</span>
                                <Input id="r_val" v-model.number="rewardForm.voucher_value" type="number" min="0" class="h-10 pl-9" />
                            </div>
                            <p v-if="rewardForm.errors.voucher_value" class="text-sm text-red-600">
                                {{ rewardForm.errors.voucher_value }}
                            </p>
                        </div>
                        <div class="space-y-2">
                            <Label for="r_stock">Stok hadiah</Label>
                            <Input
                                id="r_stock"
                                v-model.number="rewardForm.stock"
                                type="number"
                                min="0"
                                placeholder="Kosongkan = tak terbatas"
                                class="h-10"
                            />
                            <p v-if="rewardForm.errors.stock" class="text-sm text-red-600">{{ rewardForm.errors.stock }}</p>
                        </div>
                    </div>
                    <div class="space-y-2">
                        <Label for="r_desc">Keterangan</Label>
                        <textarea
                            id="r_desc"
                            v-model="rewardForm.description"
                            rows="2"
                            :class="[fieldClass, 'py-2']"
                            placeholder="Opsional, misal: berlaku untuk semua menu minuman"
                        />
                    </div>
                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="rewardForm.is_active" type="checkbox" class="h-4 w-4 rounded border-slate-300" />
                        Tampilkan hadiah ini (bisa ditukar pelanggan)
                    </label>
                    <DialogFooter>
                        <Button type="button" variant="outline" @click="rewardDialog = false">Batal</Button>
                        <Button type="submit" :disabled="rewardForm.processing">
                            {{ rewardForm.processing ? 'Menyimpan…' : editingReward ? 'Simpan perubahan' : 'Tambah hadiah' }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <!-- Dialog promo -->
        <Dialog v-model:open="promoDialog">
            <DialogContent class="sm:max-w-[500px]">
                <DialogHeader>
                    <DialogTitle>Kirim promo poin</DialogTitle>
                    <DialogDescription>Notifikasi in-app dikirim ke seluruh pelanggan toko dan dicatat di log notifikasi.</DialogDescription>
                </DialogHeader>
                <form class="space-y-4" @submit.prevent="sendPromo">
                    <div class="space-y-2">
                        <Label for="p_title">Judul *</Label>
                        <Input id="p_title" v-model="promoForm.title" placeholder="Double poin akhir pekan!" class="h-10" />
                        <p v-if="promoForm.errors.title" class="text-sm text-red-600">{{ promoForm.errors.title }}</p>
                    </div>
                    <div class="space-y-2">
                        <Label for="p_body">Isi pesan *</Label>
                        <textarea
                            id="p_body"
                            v-model="promoForm.body"
                            rows="4"
                            :class="[fieldClass, 'py-2']"
                            placeholder="Belanja Sabtu–Minggu ini dan dapatkan poin dua kali lipat."
                        />
                        <p v-if="promoForm.errors.body" class="text-sm text-red-600">{{ promoForm.errors.body }}</p>
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" @click="promoDialog = false">Batal</Button>
                        <Button type="submit" class="gap-1.5" :disabled="promoForm.processing || !promoForm.title || !promoForm.body">
                            <Megaphone class="h-4 w-4" /> Kirim ke pelanggan
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </AdminLayout>
</template>
