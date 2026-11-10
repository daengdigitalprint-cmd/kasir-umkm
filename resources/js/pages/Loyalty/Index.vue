<script setup lang="ts">
import { Head, useForm, router, Link } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { confirmDelete } from '@/lib/swal';
import { update as updateSettingsRoute } from '@/routes/loyalty/settings';
import { store as storeRewardRoute, destroy as destroyRewardRoute, toggle as toggleRewardRoute } from '@/routes/loyalty/rewards';
import { promo as promoRoute, notifications as notificationsRoute } from '@/routes/loyalty';
import { Gift, Trash2, Settings2, Award, Bell, Megaphone } from 'lucide-vue-next';
import { ref } from 'vue';

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

const num = (n: number | string) => new Intl.NumberFormat('id-ID').format(Number(n));
const formatDate = (d: string | null) =>
    d ? new Date(d).toLocaleString('id-ID', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '-';

const rewardTypeLabel = (t: string) =>
    ({ voucher: 'Voucher', free_product: 'Produk gratis', service: 'Layanan' })[t] ?? t;

// --- Form aturan ---
const settingsForm = useForm({
    enabled: props.settings.enabled,
    rupiah_per_point: props.settings.rupiah_per_point,
    birthday_bonus_points: props.settings.birthday_bonus_points,
    referral_bonus_points: props.settings.referral_bonus_points,
    expiry_months: props.settings.expiry_months,
    expiry_warn_days: props.settings.expiry_warn_days,
});
const saveSettings = () => settingsForm.put(updateSettingsRoute.url(), { preserveScroll: true });

// --- Form hadiah baru ---
const rewardForm = useForm({
    name: '',
    description: '',
    type: 'voucher' as Reward['type'],
    points_cost: 100,
    voucher_value: null as number | null,
    stock: null as number | null,
    is_active: true,
});
const submitReward = () => {
    rewardForm.post(storeRewardRoute.url(), {
        preserveScroll: true,
        onSuccess: () => rewardForm.reset('name', 'description', 'points_cost', 'voucher_value', 'stock'),
    });
};
const destroyReward = async (r: Reward) => {
    if (!(await confirmDelete('Hapus hadiah?', `Hadiah "${r.name}" akan dihapus.`))) return;
    router.delete(destroyRewardRoute.url(r.id), { preserveScroll: true });
};
const toggleReward = (r: Reward) => {
    router.patch(toggleRewardRoute.url(r.id), {}, { preserveScroll: true });
};

// --- Form promo ---
const showPromo = ref(false);
const promoForm = useForm({ title: '', body: '' });
const sendPromo = () => {
    promoForm.post(promoRoute.url(), {
        preserveScroll: true,
        onSuccess: () => {
            promoForm.reset();
            showPromo.value = false;
        },
    });
};
</script>

<template>
    <Head title="Program Loyalitas" />

    <AdminLayout title="Program Loyalitas & Poin">
        <div class="mx-auto space-y-6 pb-8" style="max-width: 90rem">
            <!-- Statistik -->
            <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                <Card class="rounded-2xl border-slate-200 shadow-sm dark:border-slate-700">
                    <CardContent class="p-4">
                        <p class="text-xs text-slate-500 dark:text-slate-400">Poin beredar</p>
                        <p class="text-2xl font-black text-slate-900 dark:text-slate-100">{{ num(stats.total_circulating) }}</p>
                    </CardContent>
                </Card>
                <Card class="rounded-2xl border-slate-200 shadow-sm dark:border-slate-700">
                    <CardContent class="p-4">
                        <p class="text-xs text-slate-500 dark:text-slate-400">Total diperoleh</p>
                        <p class="text-2xl font-black text-emerald-600 dark:text-emerald-400">{{ num(stats.total_earned) }}</p>
                    </CardContent>
                </Card>
                <Card class="rounded-2xl border-slate-200 shadow-sm dark:border-slate-700">
                    <CardContent class="p-4">
                        <p class="text-xs text-slate-500 dark:text-slate-400">Total ditukar</p>
                        <p class="text-2xl font-black text-orange-600 dark:text-orange-400">{{ num(stats.total_redeemed) }}</p>
                    </CardContent>
                </Card>
                <Card class="rounded-2xl border-slate-200 shadow-sm dark:border-slate-700">
                    <CardContent class="p-4">
                        <p class="text-xs text-slate-500 dark:text-slate-400">Anggota berpoin</p>
                        <p class="text-2xl font-black text-slate-900 dark:text-slate-100">{{ num(stats.members_with_points) }}</p>
                    </CardContent>
                </Card>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <Link :href="notificationsRoute.url()">
                    <Button variant="outline" size="sm" class="gap-1.5 rounded-xl">
                        <Bell class="h-4 w-4" /> Notifikasi
                    </Button>
                </Link>
                <Button variant="outline" size="sm" class="gap-1.5 rounded-xl" @click="showPromo = !showPromo">
                    <Megaphone class="h-4 w-4" /> Kirim promo poin
                </Button>
            </div>

            <!-- Form promo -->
            <Card v-if="showPromo" class="rounded-2xl border-slate-200 shadow-sm dark:border-slate-700">
                <CardHeader>
                    <CardTitle class="text-base">Kirim notifikasi promo</CardTitle>
                    <CardDescription>Notifikasi in-app dikirim ke seluruh pelanggan toko dan dicatat di log.</CardDescription>
                </CardHeader>
                <CardContent class="space-y-3">
                    <div class="space-y-2">
                        <Label for="p_title">Judul *</Label>
                        <Input id="p_title" v-model="promoForm.title" placeholder="Double poin akhir pekan!" class="h-10" />
                        <p v-if="promoForm.errors.title" class="text-sm text-red-600 dark:text-red-400">{{ promoForm.errors.title }}</p>
                    </div>
                    <div class="space-y-2">
                        <Label for="p_body">Isi pesan *</Label>
                        <textarea
                            id="p_body"
                            v-model="promoForm.body"
                            rows="3"
                            class="flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                            placeholder="Belanja Sabtu–Minggu ini dan dapatkan poin dua kali lipat."
                        />
                        <p v-if="promoForm.errors.body" class="text-sm text-red-600 dark:text-red-400">{{ promoForm.errors.body }}</p>
                    </div>
                    <Button size="sm" class="rounded-xl" :disabled="promoForm.processing" @click="sendPromo">Kirim</Button>
                </CardContent>
            </Card>

            <!-- Aturan perolehan -->
            <Card class="rounded-2xl border-slate-200 shadow-sm dark:border-slate-700">
                <CardHeader>
                    <div class="flex items-center gap-2">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-primary/10">
                            <Settings2 class="h-5 w-5 text-primary" />
                        </div>
                        <div>
                            <CardTitle>Aturan perolehan & kadaluarsa poin</CardTitle>
                            <CardDescription>Atur bagaimana pelanggan memperoleh dan kehilangan poin.</CardDescription>
                        </div>
                    </div>
                </CardHeader>
                <CardContent>
                    <form class="grid gap-4 md:grid-cols-2" @submit.prevent="saveSettings">
                        <label class="flex items-center gap-2 md:col-span-2">
                            <input type="checkbox" v-model="settingsForm.enabled" class="h-4 w-4 rounded border-slate-300 dark:border-slate-600" />
                            <span class="text-sm font-medium">Aktifkan program loyalitas</span>
                        </label>
                        <div class="space-y-2">
                            <Label for="s_rpp">Rupiah per 1 poin *</Label>
                            <Input id="s_rpp" v-model.number="settingsForm.rupiah_per_point" type="number" min="1" class="h-10" />
                            <p class="text-xs text-slate-500 dark:text-slate-400">Contoh: 10.000 = 1 poin tiap belanja Rp 10.000.</p>
                            <p v-if="settingsForm.errors.rupiah_per_point" class="text-sm text-red-600 dark:text-red-400">{{ settingsForm.errors.rupiah_per_point }}</p>
                        </div>
                        <div class="space-y-2">
                            <Label for="s_exp">Masa berlaku poin (bulan) *</Label>
                            <Input id="s_exp" v-model.number="settingsForm.expiry_months" type="number" min="0" max="120" class="h-10" />
                            <p class="text-xs text-slate-500 dark:text-slate-400">0 = poin tidak pernah kadaluarsa.</p>
                        </div>
                        <div class="space-y-2">
                            <Label for="s_bday">Bonus ulang tahun (poin)</Label>
                            <Input id="s_bday" v-model.number="settingsForm.birthday_bonus_points" type="number" min="0" class="h-10" />
                        </div>
                        <div class="space-y-2">
                            <Label for="s_ref">Bonus undang teman (poin)</Label>
                            <Input id="s_ref" v-model.number="settingsForm.referral_bonus_points" type="number" min="0" class="h-10" />
                        </div>
                        <div class="space-y-2">
                            <Label for="s_warn">Ambang peringatan kadaluarsa (hari) *</Label>
                            <Input id="s_warn" v-model.number="settingsForm.expiry_warn_days" type="number" min="1" max="365" class="h-10" />
                        </div>
                        <div class="md:col-span-2">
                            <Button type="submit" class="rounded-xl" :disabled="settingsForm.processing">Simpan aturan</Button>
                        </div>
                    </form>
                </CardContent>
            </Card>

            <!-- Katalog hadiah -->
            <Card class="rounded-2xl border-slate-200 shadow-sm dark:border-slate-700">
                <CardHeader>
                    <div class="flex items-center gap-2">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-primary/10">
                            <Gift class="h-5 w-5 text-primary" />
                        </div>
                        <div>
                            <CardTitle>Katalog hadiah</CardTitle>
                            <CardDescription>Hadiah yang dapat ditukar pelanggan dengan poin.</CardDescription>
                        </div>
                    </div>
                </CardHeader>
                <CardContent class="space-y-6">
                    <form class="grid gap-4 md:grid-cols-2" @submit.prevent="submitReward">
                        <div class="space-y-2 md:col-span-2">
                            <Label for="r_name">Nama hadiah *</Label>
                            <Input id="r_name" v-model="rewardForm.name" required placeholder="Voucher Rp 20.000" class="h-10" />
                            <p v-if="rewardForm.errors.name" class="text-sm text-red-600 dark:text-red-400">{{ rewardForm.errors.name }}</p>
                        </div>
                        <div class="space-y-2">
                            <Label for="r_type">Tipe *</Label>
                            <select id="r_type" v-model="rewardForm.type" class="flex h-10 w-full rounded-md border border-input bg-background px-3 text-sm">
                                <option value="voucher">Voucher diskon</option>
                                <option value="free_product">Produk gratis</option>
                                <option value="service">Layanan eksklusif</option>
                            </select>
                        </div>
                        <div class="space-y-2">
                            <Label for="r_cost">Biaya poin *</Label>
                            <Input id="r_cost" v-model.number="rewardForm.points_cost" type="number" min="1" required class="h-10" />
                            <p v-if="rewardForm.errors.points_cost" class="text-sm text-red-600 dark:text-red-400">{{ rewardForm.errors.points_cost }}</p>
                        </div>
                        <div v-if="rewardForm.type === 'voucher'" class="space-y-2">
                            <Label for="r_val">Nilai voucher (Rp) *</Label>
                            <Input id="r_val" v-model.number="rewardForm.voucher_value" type="number" min="0" class="h-10" />
                            <p v-if="rewardForm.errors.voucher_value" class="text-sm text-red-600 dark:text-red-400">{{ rewardForm.errors.voucher_value }}</p>
                        </div>
                        <div class="space-y-2">
                            <Label for="r_stock">Stok (kosong = tak terbatas)</Label>
                            <Input id="r_stock" v-model.number="rewardForm.stock" type="number" min="0" class="h-10" />
                        </div>
                        <div class="space-y-2 md:col-span-2">
                            <Label for="r_desc">Deskripsi</Label>
                            <Input id="r_desc" v-model="rewardForm.description" class="h-10" placeholder="Opsional" />
                        </div>
                        <div class="md:col-span-2">
                            <Button type="submit" class="rounded-xl" :disabled="rewardForm.processing">Tambah hadiah</Button>
                        </div>
                    </form>

                    <div v-if="rewards.length" class="divide-y divide-slate-100 rounded-xl border border-slate-200 dark:divide-slate-800 dark:border-slate-700">
                        <div v-for="r in rewards" :key="r.id" class="flex items-center gap-3 p-3">
                            <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-slate-100 dark:bg-slate-800">
                                <Award class="h-5 w-5 text-slate-500 dark:text-slate-400" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="truncate font-semibold text-slate-900 dark:text-slate-100">{{ r.name }}</p>
                                <p class="text-xs text-slate-500 dark:text-slate-400">
                                    {{ rewardTypeLabel(r.type) }} · {{ num(r.points_cost) }} poin
                                    <span v-if="r.type === 'voucher' && r.voucher_value"> · Rp {{ num(r.voucher_value) }}</span>
                                    · Stok: {{ r.stock === null ? '∞' : r.stock }} · Ditukar: {{ r.redeemed_count }}×
                                </p>
                            </div>
                            <Badge :variant="r.is_active ? 'default' : 'secondary'" class="cursor-pointer" @click="toggleReward(r)">
                                {{ r.is_active ? 'Aktif' : 'Nonaktif' }}
                            </Badge>
                            <Button variant="ghost" size="sm" class="text-red-600 dark:text-red-400" @click="destroyReward(r)">
                                <Trash2 class="h-4 w-4" />
                            </Button>
                        </div>
                    </div>
                    <p v-else class="text-sm text-slate-500 dark:text-slate-400">Belum ada hadiah. Tambahkan hadiah pertama Anda.</p>
                </CardContent>
            </Card>

            <div class="grid gap-6 lg:grid-cols-2">
                <!-- Top member -->
                <Card class="rounded-2xl border-slate-200 shadow-sm dark:border-slate-700">
                    <CardHeader><CardTitle class="text-base">Anggota poin teratas</CardTitle></CardHeader>
                    <CardContent>
                        <div v-if="topMembers.length" class="divide-y divide-slate-100 dark:divide-slate-800">
                            <Link
                                v-for="m in topMembers"
                                :key="m.id"
                                :href="`/customers/${m.id}`"
                                class="flex items-center justify-between py-2.5 hover:bg-slate-50 dark:hover:bg-slate-800/60"
                            >
                                <span class="truncate text-sm font-medium text-slate-800 dark:text-slate-200">{{ m.name }}</span>
                                <span class="text-sm font-bold text-primary">{{ num(m.points_balance) }} poin</span>
                            </Link>
                        </div>
                        <p v-else class="text-sm text-slate-500 dark:text-slate-400">Belum ada data.</p>
                    </CardContent>
                </Card>

                <!-- Riwayat penukaran -->
                <Card class="rounded-2xl border-slate-200 shadow-sm dark:border-slate-700">
                    <CardHeader><CardTitle class="text-base">Penukaran hadiah terbaru</CardTitle></CardHeader>
                    <CardContent>
                        <div v-if="recentRedemptions.length" class="divide-y divide-slate-100 dark:divide-slate-800">
                            <div v-for="r in recentRedemptions" :key="r.id" class="py-2.5">
                                <div class="flex items-center justify-between">
                                    <span class="truncate text-sm font-medium text-slate-800 dark:text-slate-200">{{ r.customer_name }}</span>
                                    <span class="text-sm font-bold text-orange-600 dark:text-orange-400">-{{ num(r.points_spent) }}</span>
                                </div>
                                <p class="text-xs text-slate-500 dark:text-slate-400">
                                    {{ r.reward_name }}
                                    <span v-if="r.voucher_code"> · Kode: <span class="font-mono">{{ r.voucher_code }}</span></span>
                                    · {{ formatDate(r.created_at) }}
                                </p>
                            </div>
                        </div>
                        <p v-else class="text-sm text-slate-500 dark:text-slate-400">Belum ada penukaran.</p>
                    </CardContent>
                </Card>
            </div>
        </div>
    </AdminLayout>
</template>
