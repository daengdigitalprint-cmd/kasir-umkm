<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import AttendanceNav from '@/components/AttendanceNav.vue';
import { computed, ref } from 'vue';
import { MapPin, Save, BadgeCheck, AlertCircle, CalendarOff, Plus, Trash2 } from 'lucide-vue-next';

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
        overtime_mode: 'auto_approval' | 'auto' | 'request';
        overtime_min_minutes: number;
        overtime_rounding_minutes: number;
        late_deduction_method: string;
        late_deduction_rate: number;
    };
    package: {
        is_purchased: boolean;
        expires_at: string | null;
    };
    store: { id: number; name: string };
    holidays: { id: number; date: string; name: string }[];
}>();

const holidayForm = useForm({ date: '', name: '' });

const addHoliday = () => {
    holidayForm.post('/attendance/holidays', {
        preserveScroll: true,
        onSuccess: () => holidayForm.reset(),
    });
};

const removeHoliday = (id: number) => {
    if (confirm('Hapus hari libur ini?')) {
        router.delete(`/attendance/holidays/${id}`, { preserveScroll: true });
    }
};

const formatDate = (date: string) =>
    new Date(`${date}T00:00:00`).toLocaleDateString('id-ID', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });

const form = useForm({
    latitude: props.settings.latitude ?? '',
    longitude: props.settings.longitude ?? '',
    radius_meters: props.settings.radius_meters,
    require_selfie: props.settings.require_selfie,
    require_geolocation: props.settings.require_geolocation,
    grace_minutes: props.settings.grace_minutes,
    overtime_mode: props.settings.overtime_mode,
    overtime_min_minutes: props.settings.overtime_min_minutes,
    overtime_rounding_minutes: props.settings.overtime_rounding_minutes,
    late_deduction_method: props.settings.late_deduction_method,
    late_deduction_rate: props.settings.late_deduction_rate,
});

const overtimeModes = [
    {
        value: 'auto_approval',
        label: 'Otomatis + persetujuan admin',
        help: 'Lembur terdeteksi dari jam absen pulang, lalu Anda cukup menyetujui. Karyawan tidak perlu mengajukan.',
    },
    { value: 'auto', label: 'Otomatis langsung dihitung', help: 'Lembur dari jam absen pulang langsung masuk gaji tanpa persetujuan.' },
    { value: 'request', label: 'Hanya lewat pengajuan', help: 'Lembur dihitung hanya bila diajukan karyawan dan disetujui.' },
] as const;

const roundingExample = computed(() => {
    const step = Number(form.overtime_rounding_minutes) || 0;
    const minutes = step ? Math.floor(110 / step) * step : 110;
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;
    return m ? `${h} jam ${m} menit` : `${h} jam`;
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
    <Head title="Pengaturan SDM" />

    <AdminLayout title="Pengaturan SDM · Absensi">
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
                    <div class="space-y-2 border-t border-slate-100 pt-4">
                        <Label>Cara menghitung lembur</Label>
                        <div class="grid gap-2">
                            <label
                                v-for="option in overtimeModes"
                                :key="option.value"
                                class="flex cursor-pointer gap-3 rounded-xl border p-3 transition"
                                :class="form.overtime_mode === option.value ? 'border-indigo-500 bg-indigo-50' : 'border-slate-200 hover:bg-slate-50'"
                            >
                                <input v-model="form.overtime_mode" type="radio" :value="option.value" class="mt-1 accent-indigo-600" />
                                <span>
                                    <span class="block text-sm font-semibold text-slate-800">
                                        {{ option.label }}
                                        <span v-if="option.value === 'auto_approval'" class="ml-1 rounded-full bg-indigo-100 px-2 py-0.5 text-[10px] font-bold text-indigo-700">Disarankan</span>
                                    </span>
                                    <span class="block text-xs text-slate-500">{{ option.help }}</span>
                                </span>
                            </label>
                        </div>
                    </div>
                    <div v-if="form.overtime_mode !== 'request'" class="grid grid-cols-2 gap-3">
                        <div class="space-y-1">
                            <Label>Minimal lembur (menit)</Label>
                            <Input v-model.number="form.overtime_min_minutes" type="number" min="0" max="240" step="5" />
                            <p class="text-xs text-slate-500">Pulang telat di bawah ini tidak dihitung lembur.</p>
                            <p v-if="form.errors.overtime_min_minutes" class="text-sm text-red-600">{{ form.errors.overtime_min_minutes }}</p>
                        </div>
                        <div class="space-y-1">
                            <Label>Pembulatan</Label>
                            <select v-model.number="form.overtime_rounding_minutes" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm">
                                <option :value="0">Tanpa pembulatan</option>
                                <option :value="15">Ke bawah per 15 menit</option>
                                <option :value="30">Ke bawah per 30 menit</option>
                                <option :value="60">Ke bawah per 1 jam</option>
                            </select>
                            <p class="text-xs text-slate-500">Contoh: 1 jam 50 menit → {{ roundingExample }}.</p>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <p v-if="form.errors.radius_meters" class="text-sm text-red-600">{{ form.errors.radius_meters }}</p>
            <p v-if="form.errors.latitude" class="text-sm text-red-600">{{ form.errors.latitude }}</p>
            <p v-if="form.errors.longitude" class="text-sm text-red-600">{{ form.errors.longitude }}</p>

            <Button type="button" class="w-full rounded-xl" :disabled="form.processing" @click="submit">
                <Save class="h-4 w-4 mr-1" /> Simpan Pengaturan
            </Button>

            <Card v-if="package.is_purchased && settings.enabled" class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <CardTitle class="flex items-center gap-2"><CalendarOff class="h-5 w-5" /> Hari Libur</CardTitle>
                    <CardDescription>
                        Libur nasional, cuti bersama, atau libur toko. Pada tanggal ini karyawan tidak ditandai alpa,
                        dan lembur dibayar dengan tarif hari libur.
                    </CardDescription>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-[180px_1fr_auto] sm:items-start">
                        <div class="space-y-1">
                            <Input v-model="holidayForm.date" type="date" />
                            <p v-if="holidayForm.errors.date" class="text-sm text-red-600">{{ holidayForm.errors.date }}</p>
                        </div>
                        <div class="space-y-1">
                            <Input v-model="holidayForm.name" placeholder="Mis. Hari Raya Idul Fitri" />
                            <p v-if="holidayForm.errors.name" class="text-sm text-red-600">{{ holidayForm.errors.name }}</p>
                        </div>
                        <Button type="button" class="rounded-xl" :disabled="holidayForm.processing" @click="addHoliday">
                            <Plus class="h-4 w-4 mr-1" /> Tambah
                        </Button>
                    </div>
                    <ul class="divide-y divide-slate-100 rounded-xl border border-slate-200">
                        <li v-for="holiday in holidays" :key="holiday.id" class="flex items-center justify-between px-3 py-2 text-sm">
                            <span>
                                <span class="font-medium text-slate-700">{{ formatDate(holiday.date) }}</span>
                                <span class="text-slate-500"> — {{ holiday.name }}</span>
                            </span>
                            <button type="button" class="text-slate-400 hover:text-red-600" @click="removeHoliday(holiday.id)">
                                <Trash2 class="h-4 w-4" />
                            </button>
                        </li>
                        <li v-if="!holidays.length" class="px-3 py-4 text-center text-sm text-slate-400">Belum ada hari libur.</li>
                    </ul>
                </CardContent>
            </Card>
        </div>
    </AdminLayout>
</template>
