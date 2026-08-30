<script setup lang="ts">
import { Head, useForm, router } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import AttendanceNav from '@/components/AttendanceNav.vue';
import PinLoginModal from '@/components/CashierPin/PinLoginModal.vue';
import { ref, computed, onBeforeUnmount, onMounted, nextTick } from 'vue';
import { Camera, LogIn, LogOut } from 'lucide-vue-next';
import { toastError } from '@/lib/swal';

interface AttendanceToday {
    id: number;
    work_date: string;
    clock_in: string | null;
    clock_out: string | null;
    status: string;
    late_minutes: number;
    hours_worked: number | null;
}

interface Cashier {
    id: number;
    name: string;
    position: string | null;
    has_pin: boolean;
}

const props = defineProps<{
    attendanceToday: AttendanceToday | null;
    settings: {
        enabled: boolean;
        require_selfie: boolean;
        require_geolocation: boolean;
        radius_meters: number;
        grace_minutes: number;
        has_office_location: boolean;
    };
    store: { id: number; name: string; timezone: string };
    cashiers: Cashier[];
    cashier_login_mode: string;
    activeCashier: { employee_id: number; employee_name: string } | null;
}>();

const form = useForm<{
    photo: File | null;
    latitude: number | null;
    longitude: number | null;
    accuracy: number | null;
}>({
    photo: null,
    latitude: null,
    longitude: null,
    accuracy: null,
});

const videoEl = ref<HTMLVideoElement | null>(null);
const photoPreview = ref<string | null>(null);
const photoFile = ref<File | null>(null);
const cameraOn = ref(false);
const locError = ref<string | null>(null);
const busy = ref(false);
const mode = ref<'in' | 'out' | null>(null);

const now = ref(new Date());
let clockTimer: ReturnType<typeof setInterval> | null = null;

const storeTz = computed(() => props.store.timezone || 'UTC');

const clockText = computed(() =>
    new Intl.DateTimeFormat('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit', timeZone: storeTz.value }).format(now.value),
);
const dateText = computed(() =>
    new Intl.DateTimeFormat('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', timeZone: storeTz.value }).format(now.value),
);

const canCheckIn = computed(() => !props.attendanceToday?.clock_in);
const canCheckOut = computed(() => !!props.attendanceToday?.clock_in && !props.attendanceToday?.clock_out);

const isPinMode = computed(() => props.cashier_login_mode === 'pin');
const showPinLogin = computed(() => isPinMode.value && !props.activeCashier);

function onPinLoginSuccess() {
    router.reload({ only: ['activeCashier', 'cashiers', 'attendanceToday'] });
}

const statusText = computed(() =>
    props.attendanceToday
        ? statusLabel[props.attendanceToday.status] ?? props.attendanceToday.status
        : 'Belum absen',
);

onMounted(() => {
    clockTimer = setInterval(() => {
        now.value = new Date();
    }, 1000);
});

let stream: MediaStream | null = null;

const startCamera = async () => {
    try {
        stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false });
        cameraOn.value = true;
        await nextTick();
        if (videoEl.value) {
            videoEl.value.srcObject = stream;
            await videoEl.value.play();
        }
    } catch {
        toastError('Tidak dapat mengakses kamera. Izinkan akses kamera lalu coba lagi.');
    }
};

const stopCamera = () => {
    stream?.getTracks().forEach((t) => t.stop());
    stream = null;
    cameraOn.value = false;
};

// Revoke object URL selfie agar tidak bocor memori saat reset/ulangi/batal.
const clearPhoto = () => {
    if (photoPreview.value) {
        URL.revokeObjectURL(photoPreview.value);
    }
    photoFile.value = null;
    photoPreview.value = null;
};

const capture = () => {
    const video = videoEl.value;
    if (!video) return;

    const canvas = document.createElement('canvas');
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    canvas.getContext('2d')?.drawImage(video, 0, 0, canvas.width, canvas.height);

    canvas.toBlob((blob) => {
        if (!blob) return;
        clearPhoto();
        photoFile.value = new File([blob], 'selfie.jpg', { type: 'image/jpeg' });
        photoPreview.value = URL.createObjectURL(blob);
        stopCamera();
    }, 'image/jpeg', 0.85);
};

const locationErrorMessage = (err: GeolocationPositionError): string => {
    switch (err.code) {
        case err.PERMISSION_DENIED:
            return 'Izin lokasi ditolak. Izinkan akses lokasi di browser lalu coba lagi.';
        case err.POSITION_UNAVAILABLE:
            return 'Posisi tidak tersedia. Pastikan GPS aktif dan sinyal memadai, lalu coba lagi.';
        case err.TIMEOUT:
            return 'Waktu pengambilan lokasi habis. Coba lagi di area dengan sinyal GPS lebih baik.';
        default:
            return 'Lokasi tidak dapat ditentukan. Coba lagi.';
    }
};

const getLocation = async (): Promise<{ latitude: number; longitude: number; accuracy: number } | null> => {
    if (!navigator.geolocation) {
        locError.value = 'Browser tidak mendukung akses lokasi. Gunakan browser modern atau akses lewat HTTPS.';
        return null;
    }
    return new Promise((resolve) => {
        navigator.geolocation.getCurrentPosition(
            (pos) => resolve({
                latitude: pos.coords.latitude,
                longitude: pos.coords.longitude,
                accuracy: pos.coords.accuracy,
            }),
            (err) => {
                locError.value = locationErrorMessage(err);
                resolve(null);
            },
            { enableHighAccuracy: true, timeout: 15000, maximumAge: 30000 },
        );
    });
};

const clockIn = async () => {
    busy.value = true;
    locError.value = null;
    try {
        const geo = await getLocation();
        form.latitude = geo?.latitude ?? null;
        form.longitude = geo?.longitude ?? null;
        form.accuracy = geo?.accuracy ?? null;
        form.photo = photoFile.value;

        if (props.settings.require_selfie && !photoFile.value) {
            locError.value = 'Foto selfie wajib diunggah sebelum check-in.';
            return;
        }

        form.post('/attendance/clock-in', {
            preserveScroll: true,
            onSuccess: () => {
                clearPhoto();
                mode.value = null;
            },
        });
    } finally {
        busy.value = false;
    }
};

const clockOut = async () => {
    busy.value = true;
    locError.value = null;
    try {
        const geo = await getLocation();
        form.latitude = geo?.latitude ?? null;
        form.longitude = geo?.longitude ?? null;
        form.accuracy = geo?.accuracy ?? null;
        form.photo = photoFile.value;

        if (props.settings.require_selfie && !photoFile.value) {
            locError.value = 'Foto selfie wajib diunggah sebelum check-out.';
            return;
        }

        form.post('/attendance/clock-out', {
            preserveScroll: true,
            onSuccess: () => {
                clearPhoto();
                mode.value = null;
            },
        });
    } finally {
        busy.value = false;
    }
};

const beginAction = (m: 'in' | 'out') => {
    mode.value = m;
    clearPhoto();
    locError.value = null;
    if (props.settings.require_selfie) {
        startCamera();
    }
};

const submitAction = async () => {
    if (!mode.value) return;
    if (props.settings.require_selfie && !photoFile.value) {
        locError.value = `Foto selfie wajib diunggah sebelum ${mode.value === 'in' ? 'check-in' : 'check-out'}.`;
        return;
    }
    if (mode.value === 'in') {
        await clockIn();
    } else {
        await clockOut();
    }
};

const cancelAction = () => {
    mode.value = null;
    stopCamera();
    clearPhoto();
    locError.value = null;
};

const fmtTime = (t: string | null) => t ?? '—';

const statusLabel: Record<string, string> = {
    present: 'Hadir',
    late: 'Terlambat',
    absent: 'Tidak hadir',
    leave: 'Cuti',
    sick: 'Sakit',
    holiday: 'Libur',
    day_off: 'Hari libur',
};

onBeforeUnmount(() => {
    if (clockTimer) {
        clearInterval(clockTimer);
    }
    stopCamera();
    clearPhoto();
});
</script>

<template>
    <Head title="Absensi" />

    <AdminLayout title="Absensi Karyawan">
        <div class="space-y-6 pb-8">
            <AttendanceNav />

            <!-- Kartu absensi utama -->
            <Card class="rounded-3xl border-slate-200 shadow-sm overflow-hidden">
                <CardContent class="p-6 sm:p-8">
                    <!-- Jam realtime + status -->
                    <div class="text-center">
                        <p class="text-sm font-medium text-slate-500">{{ dateText }}</p>
                        <p class="mt-1 text-5xl sm:text-6xl font-black tracking-tight text-slate-900 tabular-nums">{{ clockText }}</p>
                        <p class="mt-2 text-sm text-slate-600">
                            {{ store.name }}
                            <template v-if="activeCashier">
                                <span class="mx-1">·</span>
                                <strong class="text-primary">{{ activeCashier.employee_name }}</strong>
                            </template>
                            <span class="mx-1">·</span>
                            Status:
                            <strong class="capitalize">{{ statusText }}</strong>
                            <span v-if="attendanceToday?.late_minutes" class="text-red-600"> · Terlambat {{ attendanceToday.late_minutes }} menit</span>
                        </p>
                    </div>

                    <!-- Info masuk / keluar ringkas -->
                    <div class="mt-6 grid grid-cols-2 gap-3 text-sm">
                        <div class="rounded-2xl bg-slate-50 p-4 text-center">
                            <LogIn class="h-5 w-5 mx-auto text-emerald-600" />
                            <p class="mt-1 text-xs font-medium text-slate-500">Masuk</p>
                            <p class="text-lg font-bold tabular-nums">{{ fmtTime(attendanceToday?.clock_in ?? null) }}</p>
                        </div>
                        <div class="rounded-2xl bg-slate-50 p-4 text-center">
                            <LogOut class="h-5 w-5 mx-auto text-orange-600" />
                            <p class="mt-1 text-xs font-medium text-slate-500">Keluar</p>
                            <p class="text-lg font-bold tabular-nums">{{ fmtTime(attendanceToday?.clock_out ?? null) }}</p>
                        </div>
                    </div>

                    <!-- Aksi utama -->
                    <div class="mt-6">
                        <!-- Mode awal: tombol CTA besar -->
                        <template v-if="!mode">
                            <Button
                                v-if="canCheckIn"
                                type="button"
                                class="h-16 w-full rounded-2xl bg-primary text-lg font-bold text-white hover:bg-primary/90"
                                :disabled="busy || form.processing"
                                @click="beginAction('in')"
                            >
                                <LogIn class="h-6 w-6 mr-2" /> Check In
                            </Button>
                            <Button
                                v-else-if="canCheckOut"
                                type="button"
                                class="h-16 w-full rounded-2xl bg-rose-600 text-lg font-bold hover:bg-rose-700"
                                :disabled="busy || form.processing"
                                @click="beginAction('out')"
                            >
                                <LogOut class="h-6 w-6 mr-2" /> Check Out
                            </Button>
                            <Button
                                v-else
                                type="button"
                                disabled
                                class="h-16 w-full rounded-2xl bg-slate-200 text-lg font-bold text-slate-400 cursor-not-allowed"
                            >
                                Selesai
                            </Button>
                        </template>

                        <!-- Mode aksi: kamera + submit -->
                        <template v-else>
                            <!-- Selfie -->
                            <div v-if="settings.require_selfie" class="space-y-3">
                                <p class="text-sm font-medium">
                                    Foto selfie <span class="text-red-500">*</span>
                                </p>
                                <div v-if="cameraOn" class="space-y-2">
                                    <video ref="videoEl" class="w-full max-w-xs mx-auto aspect-[3/4] rounded-xl bg-black object-cover" autoplay playsinline />
                                    <div class="flex justify-center gap-2">
                                        <Button type="button" size="sm" class="rounded-xl" @click="capture">
                                            <Camera class="h-4 w-4 mr-1" /> Ambil foto
                                        </Button>
                                        <Button type="button" size="sm" variant="outline" class="rounded-xl" @click="cancelAction">Batal</Button>
                                    </div>
                                </div>
                                <div v-else-if="photoPreview" class="flex items-center justify-center gap-3">
                                    <img :src="photoPreview" alt="Selfie" class="h-20 w-20 rounded-xl object-cover" />
                                    <Button type="button" size="sm" variant="outline" class="rounded-xl" @click="startCamera">Ulangi</Button>
                                </div>
                                <div v-else class="flex justify-center">
                                    <Button type="button" size="sm" variant="outline" class="rounded-xl" @click="startCamera">
                                        <Camera class="h-4 w-4 mr-1" /> Buka kamera
                                    </Button>
                                </div>
                            </div>

                            <!-- Tombol submit -->
                            <Button
                                type="button"
                                class="mt-4 h-14 w-full rounded-2xl text-lg font-bold"
                                :class="mode === 'in' ? 'bg-primary text-white hover:bg-primary/90' : 'bg-rose-600 hover:bg-rose-700'"
                                :disabled="busy || form.processing || (settings.require_selfie && !photoFile)"
                                @click="submitAction"
                            >
                                <LogIn v-if="mode === 'in'" class="h-6 w-6 mr-2" />
                                <LogOut v-else class="h-6 w-6 mr-2" />
                                {{ form.processing ? 'Memproses...' : (mode === 'in' ? 'Submit Check In' : 'Submit Check Out') }}
                            </Button>

                            <Button
                                type="button"
                                variant="ghost"
                                class="mt-2 w-full rounded-xl text-slate-500"
                                @click="cancelAction"
                            >
                                Batal
                            </Button>
                        </template>
                    </div>

                    <!-- Error -->
                    <div class="mt-4 space-y-1 text-sm text-red-600">
                        <p v-if="locError">{{ locError }}</p>
                        <p v-if="form.errors.location">{{ form.errors.location }}</p>
                        <p v-if="form.errors.photo">{{ form.errors.photo }}</p>
                        <p v-if="form.errors.clock_in">{{ form.errors.clock_in }}</p>
                        <p v-if="form.errors.clock_out">{{ form.errors.clock_out }}</p>
                    </div>

                    <p v-if="settings.require_geolocation && !settings.has_office_location" class="mt-4 rounded-xl bg-amber-50 p-3 text-sm text-amber-700">
                        Lokasi kantor belum diatur admin. Check-in mungkin ditolak hingga lokasi ditentukan.
                    </p>

                    <p class="mt-4 text-center text-xs text-slate-400">
                        Radius kantor {{ settings.radius_meters }} m · Toleransi terlambat {{ settings.grace_minutes }} menit
                    </p>
                </CardContent>
            </Card>
        </div>

        <PinLoginModal
            v-if="showPinLogin"
            :cashiers="cashiers"
            :store-name="store.name"
            :dismissible="false"
            @success="onPinLoginSuccess"
        />
    </AdminLayout>
</template>
