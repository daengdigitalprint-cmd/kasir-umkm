<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import AttendanceNav from '@/components/AttendanceNav.vue';
import { ref } from 'vue';
import { MapPin, Save, BadgeCheck, AlertCircle } from 'lucide-vue-next';

const props = defineProps<{
    settings: {
        enabled: boolean;
        latitude: number | null;
        longitude: number | null;
        radius_meters: number;
        require_selfie: boolean;
        require_geolocation: boolean;
        grace_minutes: number;
        overtime_requires_approval: boolean;
        late_deduction_method: string;
        late_deduction_rate: number;
    };
    package: {
        is_purchased: boolean;
        expires_at: string | null;
    };
    store: { id: number; name: string };
}>();

const form = useForm({
    latitude: props.settings.latitude ?? '',
    longitude: props.settings.longitude ?? '',
    radius_meters: props.settings.radius_meters,
    require_selfie: props.settings.require_selfie,
    require_geolocation: props.settings.require_geolocation,
    grace_minutes: props.settings.grace_minutes,
    overtime_requires_approval: props.settings.overtime_requires_approval,
    late_deduction_method: props.settings.late_deduction_method,
    late_deduction_rate: props.settings.late_deduction_rate,
});

const locating = ref(false);

const useMyLocation = () => {
    if (!navigator.geolocation) {
        alert('Browser tidak mendukung geolokasi.');
        return;
    }
    locating.value = true;
    navigator.geolocation.getCurrentPosition(
        (pos) => {
            form.latitude = pos.coords.latitude;
            form.longitude = pos.coords.longitude;
            locating.value = false;
        },
        () => {
            alert('Gagal mendapatkan lokasi. Pastikan GPS aktif.');
            locating.value = false;
        },
        { enableHighAccuracy: true, timeout: 10000 },
    );
};

const submit = () => form.post('/attendance/settings', { preserveScroll: true });
</script>

<template>
    <Head title="Pengaturan Absensi" />

    <AdminLayout title="Pengaturan Absensi">
        <div class="space-y-6 pb-8">
            <AttendanceNav />

            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <CardTitle>Status Paket Absensi + Payroll</CardTitle>
                    <CardDescription>
                        Modul ini aktif selama masa paket berbayar masih berlaku.
                    </CardDescription>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div v-if="props.package.is_purchased && props.settings.enabled" class="flex items-center gap-3 rounded-xl bg-emerald-50 border border-emerald-200 p-4">
                        <BadgeCheck class="h-6 w-6 text-emerald-600 shrink-0" />
                        <div class="text-sm text-emerald-800">
                            <p class="font-bold">Paket aktif & modul aktif</p>
                            <p v-if="props.package.expires_at">
                                Berlaku sampai {{ new Date(props.package.expires_at).toLocaleDateString('id-ID') }}
                            </p>
                        </div>
                    </div>
                    <div v-else-if="props.package.is_purchased" class="rounded-xl bg-sky-50 border border-sky-200 p-4">
                        <div class="flex items-start gap-3">
                            <BadgeCheck class="h-6 w-6 text-sky-600 shrink-0" />
                            <div class="text-sm text-sky-800">
                                <p class="font-bold">Paket aktif, modul belum diaktifkan</p>
                                <p class="mt-1">Aktifkan modul absensi di pengaturan toko.</p>
                                <Link href="/settings/profile">
                                    <Button type="button" class="mt-3 rounded-xl bg-sky-600 text-white hover:bg-sky-700">
                                        Aktifkan modul
                                    </Button>
                                </Link>
                            </div>
                        </div>
                    </div>
                    <div v-else class="rounded-xl bg-amber-50 border border-amber-200 p-4">
                        <div class="flex items-start gap-3">
                            <AlertCircle class="h-6 w-6 text-amber-600 shrink-0" />
                            <div class="text-sm text-amber-800">
                                <p class="font-bold">Paket belum aktif</p>
                                <p class="mt-1">Beli paket Absensi + Payroll untuk mengaktifkan modul ini.</p>
                                <Link href="/subscription">
                                    <Button type="button" class="mt-3 rounded-xl bg-amber-600 text-white hover:bg-amber-700">
                                        Beli paket
                                    </Button>
                                </Link>
                            </div>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <CardTitle>Lokasi & Radius Kantor</CardTitle>
                    <CardDescription>Lokasi ini dipakai untuk memblokir absensi di luar jangkauan.</CardDescription>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div class="space-y-1">
                            <Label>Latitude</Label>
                            <Input v-model="form.latitude" type="number" step="any" placeholder="-6.200000" />
                        </div>
                        <div class="space-y-1">
                            <Label>Longitude</Label>
                            <Input v-model="form.longitude" type="number" step="any" placeholder="106.800000" />
                        </div>
                    </div>

                    <Button type="button" variant="outline" size="sm" class="rounded-xl" :disabled="locating" @click="useMyLocation">
                        <MapPin class="h-4 w-4 mr-1" /> Gunakan lokasi saya
                    </Button>

                    <div class="space-y-1">
                        <Label>Radius (meter)</Label>
                        <Input v-model="form.radius_meters" type="number" min="1" max="10000" />
                    </div>
                </CardContent>
            </Card>

            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <CardTitle>Kebijakan Absensi</CardTitle>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div class="flex items-center justify-between">
                        <Label>Wajib foto selfie</Label>
                        <Checkbox v-model="form.require_selfie" />
                    </div>
                    <div class="flex items-center justify-between">
                        <Label>Wajib lokasi (geolokasi)</Label>
                        <Checkbox v-model="form.require_geolocation" />
                    </div>
                    <div class="space-y-1">
                        <Label>Toleransi terlambat (menit)</Label>
                        <Input v-model="form.grace_minutes" type="number" min="0" max="240" />
                    </div>
                    <div class="flex items-center justify-between">
                        <Label>Lembur wajib approval</Label>
                        <Checkbox v-model="form.overtime_requires_approval" />
                    </div>
                </CardContent>
            </Card>

            <p v-if="form.errors.radius_meters" class="text-sm text-red-600">{{ form.errors.radius_meters }}</p>
            <p v-if="form.errors.latitude" class="text-sm text-red-600">{{ form.errors.latitude }}</p>
            <p v-if="form.errors.longitude" class="text-sm text-red-600">{{ form.errors.longitude }}</p>

            <Button type="button" class="w-full rounded-xl" :disabled="form.processing" @click="submit">
                <Save class="h-4 w-4 mr-1" /> Simpan Pengaturan
            </Button>
        </div>
    </AdminLayout>
</template>
