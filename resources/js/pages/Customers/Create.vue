<script setup lang="ts">
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { ArrowLeft, Save } from 'lucide-vue-next';

interface Props {
    store: {
        id: number;
        name: string;
        currency: string;
    };
}

defineProps<Props>();

const form = useForm({
    name: '',
    email: '',
    phone: '',
    address: '',
    customer_type: 'ecer',
    date_of_birth: '',
    referral_code: '',
});

const submit = () => {
    form.post('/customers');
};
</script>

<template>
    <AdminLayout title="Tambah Pelanggan">
        <Head title="Tambah Pelanggan" />

        <div class="max-w-xl mx-auto space-y-5 p-4">
            <!-- Header -->
            <div class="flex items-center gap-3">
                <Link href="/customers">
                    <Button variant="ghost" size="sm" class="gap-1.5 rounded-xl">
                        <ArrowLeft class="h-4 w-4" />
                        Kembali
                    </Button>
                </Link>
                <h1 class="text-xl font-black text-slate-900">Tambah Pelanggan</h1>
            </div>

            <!-- Form -->
            <Card class="rounded-2xl border border-slate-200 shadow-sm">
                <CardContent class="p-5">
                    <form @submit.prevent="submit" class="space-y-4">
                        <!-- Nama -->
                        <div class="space-y-1.5">
                            <Label for="name">Nama <span class="text-red-500">*</span></Label>
                            <Input
                                id="name"
                                v-model="form.name"
                                type="text"
                                placeholder="Nama pelanggan"
                                :class="{ 'border-red-400': form.errors.name }"
                            />
                            <p v-if="form.errors.name" class="text-xs text-red-600">{{ form.errors.name }}</p>
                        </div>

                        <!-- Email -->
                        <div class="space-y-1.5">
                            <Label for="email">Email</Label>
                            <Input
                                id="email"
                                v-model="form.email"
                                type="email"
                                placeholder="email@contoh.com"
                                :class="{ 'border-red-400': form.errors.email }"
                            />
                            <p v-if="form.errors.email" class="text-xs text-red-600">{{ form.errors.email }}</p>
                        </div>

                        <!-- Telepon -->
                        <div class="space-y-1.5">
                            <Label for="phone">Telepon</Label>
                            <Input
                                id="phone"
                                v-model="form.phone"
                                type="text"
                                placeholder="08xxxxxxxxxx"
                                :class="{ 'border-red-400': form.errors.phone }"
                            />
                            <p v-if="form.errors.phone" class="text-xs text-red-600">{{ form.errors.phone }}</p>
                        </div>

                        <!-- Tipe Pelanggan -->
                        <div v-if="props.wholesaleEnabled" class="space-y-1.5">
                            <Label for="customer_type">Tipe Pelanggan</Label>
                            <select
                                id="customer_type"
                                v-model="form.customer_type"
                                class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                            >
                                <option value="ecer">Ecer</option>
                                <option value="grosir">Grosir</option>
                            </select>
                            <p class="text-xs text-muted-foreground">Pelanggan grosir memakai harga grosir produk (bila diisi).</p>
                            <p v-if="form.errors.customer_type" class="text-xs text-red-600">{{ form.errors.customer_type }}</p>
                        </div>

                        <!-- Tanggal Lahir -->
                        <div class="space-y-1.5">
                            <Label for="date_of_birth">Tanggal Lahir</Label>
                            <Input
                                id="date_of_birth"
                                v-model="form.date_of_birth"
                                type="date"
                                :class="{ 'border-red-400': form.errors.date_of_birth }"
                            />
                            <p class="text-xs text-muted-foreground">Dipakai untuk bonus poin ulang tahun.</p>
                            <p v-if="form.errors.date_of_birth" class="text-xs text-red-600">{{ form.errors.date_of_birth }}</p>
                        </div>

                        <!-- Kode Referral -->
                        <div class="space-y-1.5">
                            <Label for="referral_code">Kode Referral</Label>
                            <Input
                                id="referral_code"
                                v-model="form.referral_code"
                                type="text"
                                placeholder="RF-XXXXX-XXXXX (opsional)"
                                class="font-mono uppercase"
                                :class="{ 'border-red-400': form.errors.referral_code }"
                            />
                            <p class="text-xs text-muted-foreground">Kode milik pelanggan yang mengundang (bila ada).</p>
                            <p v-if="form.errors.referral_code" class="text-xs text-red-600">{{ form.errors.referral_code }}</p>
                        </div>

                        <!-- Alamat -->
                        <div class="space-y-1.5">
                            <Label for="address">Alamat</Label>
                            <Textarea
                                id="address"
                                v-model="form.address"
                                placeholder="Alamat lengkap"
                                rows="3"
                                :class="{ 'border-red-400': form.errors.address }"
                            />
                            <p v-if="form.errors.address" class="text-xs text-red-600">{{ form.errors.address }}</p>
                        </div>

                        <!-- Tombol -->
                        <div class="flex gap-2 pt-2">
                            <Button
                                type="submit"
                                class="flex-1 gap-2 rounded-xl"
                                :disabled="form.processing"
                            >
                                <Save class="h-4 w-4" />
                                {{ form.processing ? 'Menyimpan…' : 'Simpan Pelanggan' }}
                            </Button>
                            <Link href="/customers">
                                <Button type="button" variant="outline" class="rounded-xl">
                                    Batal
                                </Button>
                            </Link>
                        </div>
                    </form>
                </CardContent>
            </Card>
        </div>
    </AdminLayout>
</template>
