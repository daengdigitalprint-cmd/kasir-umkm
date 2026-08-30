<script setup lang="ts">
import { Head, useForm, Link } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ArrowLeft, Plus, Trash2, Shirt } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { modalError } from '@/lib/swal';

interface CustomerOpt {
    id: number;
    name: string;
    phone: string | null;
}

interface ServiceOpt {
    id: number;
    name: string;
    unit: string | null;
    price: number | string | null;
    allows_decimal?: boolean;
}

const props = defineProps<{
    customers: CustomerOpt[];
    services: ServiceOpt[];
    store: { id: number; name: string; currency: string };
}>();

interface LineDraft {
    product_id: number | '';
    service_name: string;
    unit: string;
    quantity: number;
    unit_price: number;
    notes: string;
}

const lineDrafts = ref<LineDraft[]>([
    { product_id: '', service_name: '', unit: '', quantity: 1, unit_price: 0, notes: '' },
]);

const form = useForm({
    customer_id: null as number | null,
    customer_name: '',
    customer_phone: '',
    estimated_ready_at: '' as string,
    notes: '',
    amount_paid: 0 as number,
    payment_method: 'cash' as string,
    lines: [] as LineDraft[],
});

function serviceById(id: number | ''): ServiceOpt | undefined {
    if (id === '') return undefined;
    return props.services.find((s) => s.id === Number(id));
}

// Saat memilih layanan dari katalog produk, isi otomatis nama, satuan, dan harga.
function onServicePicked(idx: number) {
    const line = lineDrafts.value[idx];
    const svc = serviceById(line.product_id);
    if (svc) {
        line.service_name = svc.name;
        line.unit = svc.unit ?? '';
        line.unit_price = Number(svc.price ?? 0);
    }
}

function addLine() {
    lineDrafts.value.push({ product_id: '', service_name: '', unit: '', quantity: 1, unit_price: 0, notes: '' });
}

function removeLine(idx: number) {
    if (lineDrafts.value.length === 1) return;
    lineDrafts.value.splice(idx, 1);
}

const total = computed(() =>
    lineDrafts.value.reduce((sum, l) => sum + Math.max(0, Number(l.quantity) || 0) * Math.max(0, Number(l.unit_price) || 0), 0),
);

function formatMoney(n: number) {
    return new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(n);
}

function onCustomerPicked() {
    const c = props.customers.find((x) => x.id === Number(form.customer_id));
    if (c) {
        form.customer_name = c.name;
        form.customer_phone = c.phone ?? '';
    }
}

function submit() {
    // Validasi ringan sebelum kirim.
    for (const l of lineDrafts.value) {
        if (!String(l.service_name).trim()) {
            void modalError('Layanan wajib diisi', 'Setiap baris harus memiliki nama layanan.');
            return;
        }
        if (Number(l.quantity) <= 0) {
            void modalError('Jumlah tidak valid', 'Jumlah harus lebih dari nol.');
            return;
        }
    }

    form.lines = lineDrafts.value.map((l) => ({
        product_id: l.product_id === '' ? ('' as unknown as number | '') : Number(l.product_id),
        service_name: l.service_name.trim(),
        unit: l.unit,
        quantity: Number(l.quantity),
        unit_price: Number(l.unit_price),
        notes: l.notes,
    }));

    form.transform((data) => ({
        ...data,
        customer_id: data.customer_id || null,
        lines: data.lines.map((l) => ({ ...l, product_id: l.product_id === '' ? null : l.product_id })),
    })).post('/laundry-orders', {
        onError: () => {
            void modalError('Gagal menyimpan', 'Periksa kembali data order laundry.');
        },
    });
}
</script>

<template>
    <AdminLayout title="Buat Order Laundry">
        <Head title="Buat Order Laundry" />

        <div class="mb-6 flex items-center gap-3">
            <Link href="/laundry-orders" class="text-slate-500 hover:text-slate-800">
                <ArrowLeft class="h-5 w-5" />
            </Link>
            <h1 class="text-2xl font-bold text-slate-900 flex items-center gap-2">
                <Shirt class="h-7 w-7 text-cyan-600" />
                Buat Order Laundry
            </h1>
        </div>

        <form class="grid gap-6 lg:grid-cols-3" @submit.prevent="submit">
            <div class="lg:col-span-2 space-y-6">
                <Card class="border-0 shadow-lg">
                    <CardHeader>
                        <CardTitle>Rincian layanan</CardTitle>
                        <CardDescription>Tambahkan layanan cuci/setrika. Pilih dari katalog atau ketik manual.</CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <div
                            v-for="(line, idx) in lineDrafts"
                            :key="idx"
                            class="rounded-xl border border-slate-200 p-4 space-y-3"
                        >
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-semibold text-slate-500">Layanan #{{ idx + 1 }}</span>
                                <button
                                    type="button"
                                    class="text-red-500 hover:text-red-700 disabled:opacity-30"
                                    :disabled="lineDrafts.length === 1"
                                    @click="removeLine(idx)"
                                >
                                    <Trash2 class="h-4 w-4" />
                                </button>
                            </div>

                            <div class="grid gap-3 sm:grid-cols-2">
                                <div>
                                    <Label class="text-xs">Pilih dari katalog (opsional)</Label>
                                    <select
                                        v-model="line.product_id"
                                        class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                                        @change="onServicePicked(idx)"
                                    >
                                        <option value="">— Ketik manual —</option>
                                        <option v-for="s in services" :key="s.id" :value="s.id">{{ s.name }}</option>
                                    </select>
                                </div>
                                <div>
                                    <Label class="text-xs">Nama layanan *</Label>
                                    <Input v-model="line.service_name" type="text" placeholder="mis. Cuci Kiloan" class="mt-1" />
                                </div>
                            </div>

                            <div class="grid gap-3 sm:grid-cols-4">
                                <div>
                                    <Label class="text-xs">Satuan</Label>
                                    <Input v-model="line.unit" type="text" placeholder="kg / pcs" class="mt-1" />
                                </div>
                                <div>
                                    <Label class="text-xs">Jumlah *</Label>
                                    <Input v-model.number="line.quantity" type="number" step="0.01" min="0" class="mt-1" />
                                </div>
                                <div>
                                    <Label class="text-xs">Harga satuan *</Label>
                                    <Input v-model.number="line.unit_price" type="number" step="1" min="0" class="mt-1" />
                                </div>
                                <div>
                                    <Label class="text-xs">Subtotal</Label>
                                    <div class="mt-1 py-2 text-sm font-semibold tabular-nums text-slate-700">
                                        Rp {{ formatMoney(Math.max(0, line.quantity || 0) * Math.max(0, line.unit_price || 0)) }}
                                    </div>
                                </div>
                            </div>

                            <div>
                                <Label class="text-xs">Catatan baris</Label>
                                <Input v-model="line.notes" type="text" placeholder="mis. noda membandel, pisahkan warna" class="mt-1" />
                            </div>
                        </div>

                        <Button type="button" variant="outline" class="rounded-xl inline-flex items-center gap-2" @click="addLine">
                            <Plus class="h-4 w-4" />
                            Tambah layanan
                        </Button>
                    </CardContent>
                </Card>
            </div>

            <div class="space-y-6">
                <Card class="border-0 shadow-lg">
                    <CardHeader>
                        <CardTitle>Pelanggan</CardTitle>
                    </CardHeader>
                    <CardContent class="space-y-3">
                        <div>
                            <Label class="text-xs">Pelanggan terdaftar (opsional)</Label>
                            <select
                                v-model="form.customer_id"
                                class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                                @change="onCustomerPicked"
                            >
                                <option :value="null">— Tanpa pelanggan terdaftar —</option>
                                <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.name }}</option>
                            </select>
                        </div>
                        <div>
                            <Label class="text-xs">Nama pelanggan</Label>
                            <Input v-model="form.customer_name" type="text" class="mt-1" />
                        </div>
                        <div>
                            <Label class="text-xs">No. HP</Label>
                            <Input v-model="form.customer_phone" type="text" class="mt-1" />
                        </div>
                        <div>
                            <Label class="text-xs">Estimasi selesai</Label>
                            <Input v-model="form.estimated_ready_at" type="datetime-local" class="mt-1" />
                        </div>
                        <div>
                            <Label class="text-xs">Catatan order</Label>
                            <Input v-model="form.notes" type="text" class="mt-1" />
                        </div>
                    </CardContent>
                </Card>

                <Card class="border-0 shadow-lg">
                    <CardHeader>
                        <CardTitle>Pembayaran</CardTitle>
                        <CardDescription>Boleh dibayar sebagian (DP) atau lunas di muka.</CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-3">
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-slate-600">Total</span>
                            <span class="font-bold tabular-nums text-slate-900">Rp {{ formatMoney(total) }}</span>
                        </div>
                        <div>
                            <Label class="text-xs">Bayar sekarang</Label>
                            <Input v-model.number="form.amount_paid" type="number" step="1" min="0" class="mt-1" />
                        </div>
                        <div>
                            <Label class="text-xs">Metode bayar</Label>
                            <select v-model="form.payment_method" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                <option value="cash">Tunai</option>
                                <option value="card">Kartu</option>
                                <option value="ewallet">E-Wallet</option>
                                <option value="transfer">Transfer</option>
                                <option value="other">Lainnya</option>
                            </select>
                        </div>
                        <Button type="submit" class="w-full rounded-xl" :disabled="form.processing">
                            Simpan order
                        </Button>
                    </CardContent>
                </Card>
            </div>
        </form>
    </AdminLayout>
</template>
