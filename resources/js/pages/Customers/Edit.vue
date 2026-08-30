<script setup lang="ts">
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { ArrowLeft, Save } from 'lucide-vue-next';

interface Customer {
    id: number;
    name: string;
    email: string | null;
    phone: string | null;
    address: string | null;
    customer_type?: string;
}

interface Props {
    wholesaleEnabled?: boolean;
    customer: Customer;
    store: {
        id: number;
        name: string;
        currency: string;
    };
}

const props = withDefaults(defineProps<Props>(), {
    wholesaleEnabled: false,
});

const form = useForm({
    name: props.customer.name,
    email: props.customer.email ?? '',
    phone: props.customer.phone ?? '',
    address: props.customer.address ?? '',
    customer_type: props.customer.customer_type ?? 'ecer',
});

const submit = () => {
    form.put(`/customers/${props.customer.id}`);
};
</script>

<template>
    <AdminLayout title="Edit Pelanggan">
        <Head title="Edit Pelanggan" />

        <div class="max-w-xl mx-auto space-y-5 p-4">
            <!-- Header -->
            <div class="flex items-center gap-3">
                <Link :href="`/customers/${customer.id}`">
                    <Button variant="ghost" size="sm" class="gap-1.5 rounded-xl">
                        <ArrowLeft class="h-4 w-4" />
                        Kembali
                    </Button>
                </Link>
                <h1 class="text-xl font-black text-slate-900">Edit Pelanggan</h1>
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
                        <div class="space-y-1.5">
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
                                {{ form.processing ? 'Menyimpan…' : 'Simpan Perubahan' }}
                            </Button>
                            <Link :href="`/customers/${customer.id}`">
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
