<script setup lang="ts">
import { Head, useForm, router, usePage } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Tag, Trash2, Percent, Banknote, Gift } from 'lucide-vue-next';
import { confirmDelete } from '@/lib/swal';
import { computed, watch } from 'vue';

interface Discount {
    id: number;
    name: string;
    code: string | null;
    type: string;
    value: string;
    buy_x?: number | null;
    get_y?: number | null;
    minimum_amount: string | null;
    maximum_uses: number | null;
    used_count: number;
    starts_at: string | null;
    expires_at: string | null;
    starts_at_local?: string | null;
    expires_at_local?: string | null;
    is_active: boolean;
    description: string | null;
}

defineProps<{
    discounts: Discount[];
}>();

const page = usePage();
const allowsAdvancedRetail = computed(() => {
    const pl = page.props.plan_limits as { allows_advanced_retail?: boolean } | null | undefined;
    if (pl == null) return true;
    return pl.allows_advanced_retail === true;
});

const form = useForm({
    name: '',
    code: '',
    type: 'percentage' as 'percentage' | 'fixed_amount' | 'buy_x_get_y',
    value: 0,
    buy_x: null as number | null,
    get_y: null as number | null,
    minimum_amount: null as number | null,
    maximum_uses: null as number | null,
    starts_at: '',
    expires_at: '',
    description: '',
    is_active: true,
});

watch(
    () => form.type,
    (t) => {
        if (t === 'buy_x_get_y') {
            form.value = 0;
        } else {
            form.buy_x = null;
            form.get_y = null;
        }
    },
);

const submit = () => {
    form.post('/discounts', {
        preserveScroll: true,
        onSuccess: () =>
            form.reset(
                'name',
                'code',
                'value',
                'buy_x',
                'get_y',
                'minimum_amount',
                'maximum_uses',
                'starts_at',
                'expires_at',
                'description',
            ),
    });
};

const formatCurrency = (n: number | string) =>
    new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(Number(n));

const destroyDiscount = async (d: Discount) => {
    if (!(await confirmDelete('Hapus diskon?', `Diskon "${d.name}" akan dihapus.`))) return;
    router.delete(`/discounts/${d.id}`, { preserveScroll: true });
};

const patchDiscount = (d: Discount, overrides: Record<string, unknown>) => {
    const base = {
        name: d.name,
        code: d.code ?? '',
        type: d.type,
        value: Number(d.value),
        buy_x: d.buy_x ?? null,
        get_y: d.get_y ?? null,
        minimum_amount: d.minimum_amount != null ? Number(d.minimum_amount) : null,
        maximum_uses: d.maximum_uses,
        starts_at: d.starts_at_local ?? (d.starts_at ? d.starts_at.slice(0, 19) : null),
        expires_at: d.expires_at_local ?? (d.expires_at ? d.expires_at.slice(0, 19) : null),
        description: d.description ?? '',
        is_active: d.is_active,
    };
    router.patch(`/discounts/${d.id}`, { ...base, ...overrides }, { preserveScroll: true });
};

const toggleActive = (d: Discount) => {
    patchDiscount(d, { is_active: !d.is_active });
};
</script>

<template>
    <Head title="Diskon & Promo" />

    <AdminLayout title="Diskon & Promo">
        <div class="mx-auto space-y-8 pb-6" style="max-width: 90rem">
            <Card v-if="allowsAdvancedRetail" class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <div class="flex items-center gap-2">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-primary/10">
                            <Tag class="h-5 w-5 text-primary" />
                        </div>
                        <div>
                            <CardTitle>Tambah diskon / promo</CardTitle>
                            <CardDescription>
                                Kode opsional untuk kasir. Tersedia: persen, nominal, dan Beli X Gratis Y (per produk/varian
                                yang sama di keranjang).
                            </CardDescription>
                        </div>
                    </div>
                </CardHeader>
                <CardContent>
                    <form class="grid gap-4 md:grid-cols-2" @submit.prevent="submit">
                        <div class="space-y-2 md:col-span-2">
                            <Label for="d_name">Nama promo *</Label>
                            <Input id="d_name" v-model="form.name" required placeholder="Promo weekend" class="h-10" />
                            <p v-if="form.errors.name" class="text-sm text-red-600">{{ form.errors.name }}</p>
                        </div>
                        <div class="space-y-2">
                            <Label for="d_code">Kode (kosongkan untuk auto tanpa kode)</Label>
                            <Input id="d_code" v-model="form.code" class="h-10 font-mono uppercase" placeholder="HEMAT10" />
                            <p v-if="form.errors.code" class="text-sm text-red-600">{{ form.errors.code }}</p>
                        </div>
                        <div class="space-y-2">
                            <Label for="d_type">Tipe *</Label>
                            <select
                                id="d_type"
                                v-model="form.type"
                                class="flex h-10 w-full rounded-md border border-input bg-background px-3 text-sm"
                            >
                                <option value="percentage">Persentase</option>
                                <option value="fixed_amount">Nominal (Rp)</option>
                                <option value="buy_x_get_y">Beli X gratis Y</option>
                            </select>
                        </div>
                        <div v-if="form.type !== 'buy_x_get_y'" class="space-y-2">
                            <Label for="d_value">Nilai *</Label>
                            <Input id="d_value" v-model.number="form.value" type="number" min="0" step="0.01" required class="h-10" />
                            <p v-if="form.errors.value" class="text-sm text-red-600">{{ form.errors.value }}</p>
                        </div>
                        <div v-else class="space-y-2 md:col-span-2">
                            <p class="text-xs text-slate-600">
                                Untuk setiap kombinasi produk + varian yang memenuhi filter (semua produk jika tanpa filter):
                                tiap kelipatan (X+Y) qty, Y unit dihitung gratis (diskon = harga rata-rata × qty gratis).
                            </p>
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div class="space-y-2">
                                    <Label for="d_bx">Beli X (qty dibayar) *</Label>
                                    <Input id="d_bx" v-model.number="form.buy_x" type="number" min="1" max="999" class="h-10" />
                                    <p v-if="form.errors.buy_x" class="text-sm text-red-600">{{ form.errors.buy_x }}</p>
                                </div>
                                <div class="space-y-2">
                                    <Label for="d_gy">Gratis Y (qty gratis) *</Label>
                                    <Input id="d_gy" v-model.number="form.get_y" type="number" min="1" max="999" class="h-10" />
                                    <p v-if="form.errors.get_y" class="text-sm text-red-600">{{ form.errors.get_y }}</p>
                                </div>
                            </div>
                        </div>
                        <div class="space-y-2">
                            <Label for="d_min">Minimum belanja (Rp)</Label>
                            <Input
                                id="d_min"
                                v-model.number="form.minimum_amount"
                                type="number"
                                min="0"
                                step="1"
                                class="h-10"
                            />
                        </div>
                        <div class="space-y-2">
                            <Label for="d_max">Maks. pemakaian (kosong = tak terbatas)</Label>
                            <Input
                                id="d_max"
                                v-model.number="form.maximum_uses"
                                type="number"
                                min="1"
                                class="h-10"
                            />
                        </div>
                        <div class="space-y-2">
                            <Label for="d_start">Mulai</Label>
                            <Input id="d_start" v-model="form.starts_at" type="datetime-local" class="h-10" />
                        </div>
                        <div class="space-y-2">
                            <Label for="d_end">Berakhir</Label>
                            <Input id="d_end" v-model="form.expires_at" type="datetime-local" class="h-10" />
                        </div>
                        <div class="space-y-2 md:col-span-2">
                            <Label for="d_desc">Keterangan</Label>
                            <Input id="d_desc" v-model="form.description" class="h-10" />
                        </div>
                        <div class="flex items-center gap-2 md:col-span-2">
                            <input id="d_active" v-model="form.is_active" type="checkbox" class="rounded border-gray-300" />
                            <Label for="d_active" class="font-normal">Aktif</Label>
                        </div>
                        <div class="md:col-span-2">
                            <Button type="submit" :disabled="form.processing" class="rounded-xl">
                                Simpan diskon
                            </Button>
                        </div>
                    </form>
                </CardContent>
            </Card>

            <Card v-else class="rounded-2xl border-amber-200 bg-amber-50/80 shadow-sm">
                <CardHeader>
                    <CardTitle class="text-amber-950">Diskon tidak tersedia di paket Starter</CardTitle>
                    <CardDescription class="text-amber-900/90">
                        Naikkan ke paket <strong>GrowUp</strong> di halaman
                        <a href="/subscription" class="font-bold text-amber-950 underline">Langganan</a>
                        untuk mengaktifkan promo & diskon.
                    </CardDescription>
                </CardHeader>
            </Card>

            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <CardTitle>Daftar diskon</CardTitle>
                    <CardDescription>{{ discounts.length }} entri</CardDescription>
                </CardHeader>
                <CardContent class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b text-left text-slate-500">
                                <th class="pb-2 pr-2">Nama</th>
                                <th class="pb-2 pr-2">Kode</th>
                                <th class="pb-2 pr-2">Nilai</th>
                                <th class="pb-2 pr-2">Pakai</th>
                                <th class="pb-2 pr-2">Status</th>
                                <th class="pb-2" />
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="d in discounts" :key="d.id" class="border-b border-slate-100">
                                <td class="py-3 pr-2 font-medium text-slate-900">{{ d.name }}</td>
                                <td class="py-3 pr-2 font-mono text-xs">{{ d.code || '—' }}</td>
                                <td class="py-3 pr-2">
                                    <span class="inline-flex items-center gap-1">
                                        <Percent v-if="d.type === 'percentage'" class="h-3.5 w-3.5 text-slate-400" />
                                        <Gift v-else-if="d.type === 'buy_x_get_y'" class="h-3.5 w-3.5 text-slate-400" />
                                        <Banknote v-else class="h-3.5 w-3.5 text-slate-400" />
                                        {{
                                            d.type === 'percentage'
                                                ? `${d.value}%`
                                                : d.type === 'buy_x_get_y'
                                                  ? `Beli ${d.buy_x ?? '—'} gratis ${d.get_y ?? '—'}`
                                                  : `Rp ${formatCurrency(d.value)}`
                                        }}
                                    </span>
                                </td>
                                <td class="py-3 pr-2 text-slate-600">
                                    {{ d.used_count }}
                                    <span v-if="d.maximum_uses"> / {{ d.maximum_uses }}</span>
                                </td>
                                <td class="py-3 pr-2">
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        class="h-8 rounded-lg"
                                        :disabled="!allowsAdvancedRetail"
                                        @click="toggleActive(d)"
                                    >
                                        {{ d.is_active ? 'Nonaktifkan' : 'Aktifkan' }}
                                    </Button>
                                    <Badge v-if="d.is_active" class="ml-2 bg-emerald-600">Aktif</Badge>
                                    <Badge v-else variant="secondary" class="ml-2">Mati</Badge>
                                </td>
                                <td class="py-3 text-right">
                                    <Button size="sm" variant="ghost" class="h-8 text-red-600" @click="destroyDiscount(d)">
                                        <Trash2 class="h-4 w-4" />
                                    </Button>
                                </td>
                            </tr>
                            <tr v-if="discounts.length === 0">
                                <td colspan="6" class="py-10 text-center text-slate-500">
                                    Belum ada diskon. Buat dari formulir di atas.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </CardContent>
            </Card>
        </div>
    </AdminLayout>
</template>
