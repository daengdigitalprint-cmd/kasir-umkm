<script setup lang="ts">
import { Head, useForm, Link } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ArrowLeft, Plus, Trash2, ArrowRightLeft } from 'lucide-vue-next';
import { ref, watch } from 'vue';
import { modalError } from '@/lib/swal';

interface ProductOpt {
    id: number;
    name: string;
    sku: string | null;
    barcode: string | null;
    unit: string | null;
    stock_quantity: string | number;
    allows_decimal: boolean;
}

interface DestStore {
    id: number;
    name: string;
}

const props = defineProps<{
    sourceStore: { id: number; name: string; organization_name?: string | null };
    destStores: DestStore[];
    products: ProductOpt[];
}>();

/** Hilangkan trailing .0000 dari angka desimal database. */
const fmtQty = (val: string | number) => {
    const num = Number(val);
    return Number.isInteger(num) ? num.toString() : num.toFixed(4).replace(/\.?0+$/, '');
};

interface LineDraft {
    from_product_id: number | '';
    quantity: number | string;
}

const matchHint = ref<Record<number, { ok: boolean; text: string }>>({});

const form = useForm({
    to_store_id: (props.destStores[0]?.id ?? '') as number | '',
    notes: '',
    lines: [
        {
            from_product_id: '' as number | '',
            quantity: 1,
        },
    ] as LineDraft[],
});

function productById(id: number | ''): ProductOpt | undefined {
    if (id === '' || id === null) return undefined;
    return props.products.find((p) => p.id === id);
}

function addLine() {
    form.lines.push({ from_product_id: '', quantity: 1 });
}

function removeLine(idx: number) {
    if (form.lines.length <= 1) return;
    form.lines.splice(idx, 1);
}

async function refreshMatch(idx: number) {
    const line = form.lines[idx];
    const toId = form.to_store_id;
    const pid = line.from_product_id;
    if (!toId || pid === '' || pid === null) {
        matchHint.value[idx] = { ok: false, text: '' };
        return;
    }
    try {
        const q = new URLSearchParams({
            to_store_id: String(toId),
            from_product_id: String(pid),
        });
        const res = await fetch(`/stock-transfers/match?${q.toString()}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!res.ok) {
            matchHint.value[idx] = { ok: false, text: 'Tidak dapat memeriksa pasangan produk.' };
            return;
        }
        const data = (await res.json()) as {
            matched: boolean;
            to_product?: { name: string; sku: string | null; stock_quantity: string };
        };
        if (data.matched && data.to_product) {
            matchHint.value[idx] = {
                ok: true,
                text: `Tujuan: ${data.to_product.name} · stok ${fmtQty(data.to_product.stock_quantity)}`,
            };
        } else {
            matchHint.value[idx] = {
                ok: false,
                text: 'Tidak ada produk di outlet tujuan dengan SKU/barcode yang sama.',
            };
        }
    } catch {
        matchHint.value[idx] = { ok: false, text: 'Gagal memuat pratinjau.' };
    }
}

watch(
    () => [form.to_store_id, ...form.lines.map((l) => l.from_product_id)],
    () => {
        form.lines.forEach((_, idx) => {
            void refreshMatch(idx);
        });
    },
    { deep: true, immediate: true },
);

function submitTransfer() {
    if (form.to_store_id === '' || form.to_store_id === null) {
        void modalError('Data belum lengkap', 'Pilih outlet tujuan.');
        return;
    }
    for (let i = 0; i < form.lines.length; i++) {
        const l = form.lines[i];
        if (l.from_product_id === '' || l.from_product_id === null) {
            void modalError('Data belum lengkap', `Pilih produk pada baris ${i + 1}.`);
            return;
        }
        if (Number(l.quantity) <= 0) {
            void modalError('Data belum lengkap', `Jumlah baris ${i + 1} harus lebih dari 0.`);
            return;
        }
    }

    form
        .transform((data) => ({
            to_store_id: Number(data.to_store_id),
            notes: data.notes || null,
            lines: data.lines.map((l) => ({
                from_product_id: Number(l.from_product_id),
                quantity: l.quantity,
            })),
        }))
        .post('/stock-transfers', {
            preserveScroll: true,
            onError: async (errors) => {
                const first = Object.values(errors)[0];
                const msg = Array.isArray(first) ? first[0] : typeof first === 'string' ? first : 'Periksa kembali formulir.';
                await modalError('Transfer gagal', msg);
            },
        });
}
</script>

<template>
    <Head title="Transfer stok baru" />

    <AdminLayout title="Transfer stok">
        <div class="mx-auto space-y-6 pb-8" style="max-width: 90rem">
            <Button as-child variant="outline" size="sm" class="gap-2 rounded-xl mb-2">
                <Link href="/stock-transfers">
                    <ArrowLeft class="h-4 w-4" />
                    Riwayat transfer
                </Link>
            </Button>

            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <div class="flex items-center gap-2">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-violet-500/10">
                            <ArrowRightLeft class="h-5 w-5 text-violet-700" />
                        </div>
                        <div>
                            <CardTitle>Transfer baru (Draft)</CardTitle>
                            <CardDescription>
                                <span v-if="sourceStore.organization_name" class="block text-slate-700 mb-1">
                                    Grup usaha: <strong>{{ sourceStore.organization_name }}</strong> — hanya outlet dalam grup ini yang
                                    dapat dipilih.
                                </span>
                                Dari <strong>{{ sourceStore.name }}</strong> — pastikan SKU atau barcode sama di outlet tujuan (bukan varian /
                                paket). Transfer disimpan sebagai draft; stok belum berubah sampai dikirim.
                            </CardDescription>
                        </div>
                    </div>
                </CardHeader>
                <CardContent class="space-y-6">
                    <div class="space-y-2">
                        <Label for="to_store">Outlet tujuan</Label>
                        <select
                            id="to_store"
                            v-model="form.to_store_id"
                            class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm"
                            required
                        >
                            <option value="" disabled>Pilih toko</option>
                            <option v-for="s in destStores" :key="s.id" :value="s.id">
                                {{ s.name }}
                            </option>
                        </select>
                        <p v-if="form.errors.to_store_id" class="text-sm text-red-600">{{ form.errors.to_store_id }}</p>
                    </div>

                    <div class="space-y-2">
                        <Label for="notes">Catatan (opsional)</Label>
                        <Input id="notes" v-model="form.notes" class="rounded-xl" placeholder="Contoh: Restock cabang B" />
                    </div>

                    <div class="space-y-4">
                        <div class="flex items-center justify-between">
                            <Label>Baris transfer</Label>
                            <Button type="button" variant="outline" size="sm" class="rounded-xl gap-1" @click="addLine">
                                <Plus class="h-4 w-4" />
                                Baris
                            </Button>
                        </div>

                        <div
                            v-for="(line, idx) in form.lines"
                            :key="idx"
                            class="rounded-xl border border-slate-200 p-4 space-y-3 bg-slate-50/50"
                        >
                            <div class="flex justify-between items-start gap-2">
                                <span class="text-xs font-bold text-slate-500">#{{ idx + 1 }}</span>
                                <Button
                                    v-if="form.lines.length > 1"
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    class="text-red-600 h-8"
                                    @click="removeLine(idx)"
                                >
                                    <Trash2 class="h-4 w-4" />
                                </Button>
                            </div>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <div class="space-y-1 sm:col-span-2">
                                    <Label :for="'prod-' + idx">Produk (toko asal)</Label>
                                    <select
                                        :id="'prod-' + idx"
                                        v-model="line.from_product_id"
                                        class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm"
                                        required
                                    >
                                        <option value="" disabled>Pilih produk</option>
                                        <option v-for="p in products" :key="p.id" :value="p.id">
                                            {{ p.name }}
                                            <template v-if="p.sku"> — SKU {{ p.sku }}</template>
                                            (stok {{ fmtQty(p.stock_quantity) }}{{ p.unit ? ` ${p.unit}` : '' }})
                                        </option>
                                    </select>
                                    <p v-if="form.errors[`lines.${idx}.from_product_id`]" class="text-sm text-red-600">
                                        {{ form.errors[`lines.${idx}.from_product_id`] }}
                                    </p>
                                    <p
                                        v-else-if="matchHint[idx]?.text"
                                        class="text-xs"
                                        :class="matchHint[idx]?.ok ? 'text-emerald-700' : 'text-amber-800'"
                                    >
                                        {{ matchHint[idx]?.text }}
                                    </p>
                                </div>
                                <div class="space-y-1">
                                    <Label :for="'qty-' + idx">Jumlah</Label>
                                    <Input
                                        :id="'qty-' + idx"
                                        v-model.number="line.quantity"
                                        type="number"
                                        :step="productById(line.from_product_id)?.allows_decimal ? 0.25 : 1"
                                        min="0.0001"
                                        class="rounded-xl"
                                        required
                                    />
                                    <p v-if="form.errors[`lines.${idx}.quantity`]" class="text-sm text-red-600">
                                        {{ form.errors[`lines.${idx}.quantity`] }}
                                    </p>
                                </div>
                            </div>
                        </div>
                        <p v-if="form.errors.lines" class="text-sm text-red-600">{{ form.errors.lines }}</p>
                    </div>

                    <div class="flex flex-wrap gap-3 pt-2">
                        <Button type="button" class="rounded-xl" :disabled="form.processing" @click="submitTransfer">
                            Simpan draft
                        </Button>
                        <Button as-child variant="outline" class="rounded-xl">
                            <Link href="/stock-transfers">Batal</Link>
                        </Button>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AdminLayout>
</template>
