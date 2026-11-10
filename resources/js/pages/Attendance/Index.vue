<script setup lang="ts">
import { Head, useForm, router } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import AttendanceNav from '@/components/AttendanceNav.vue';
import PayrollSetupGuide, { type SetupStep } from '@/components/PayrollSetupGuide.vue';
import PinLoginModal from '@/components/CashierPin/PinLoginModal.vue';
import SwipeButton from '@/components/attendance/SwipeButton.vue';
import { ref, computed, onBeforeUnmount, onMounted, nextTick } from 'vue';
import {
    CalendarCheck2,
    Camera,
    CheckCircle2,
    Clock,
    Fingerprint,
    LocateFixed,
    LogIn,
    LogOut,
    MapPinOff,
    MapPinned,
    RefreshCw,
    RotateCcw,
    Umbrella,
    UserX,
    Users,
    X,
    XCircle,
} from 'lucide-vue-next';
import { toastError } from '@/lib/swal';

/**
 * Halaman absen karyawan versi web, tampilannya mengikuti aplikasi Android Absensi:
 * header ungu dengan jam, kartu kehadiran hari ini, tombol geser, status lokasi,
 * rekap bulan ini, dan grafik jam kerja 7 hari.
 */

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

interface Profile {
    name: string;
    position: string | null;
    photo_url: string | null;
    schedule: { shift_start: string; shift_end: string } | null;
    is_holiday: boolean;
    office: { latitude: number; longitude: number } | null;
    month: { on_time: number; late: number; absent: number; leave: number };
    week: { date: string; status: string | null; hours: number }[];
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
    setup: { steps: SetupStep[]; done: number; total: number; complete: boolean } | null;
    /** null = pengelola (owner/admin) yang belum didaftarkan sebagai karyawan. */
    profile: Profile | null;
}>();

const BRAND = {
    violet: '#533AFD',
    violetDark: '#3520C4',
    orange: '#FFA940',
    orangeDark: '#D9730B',
    present: '#16A34A',
    late: '#D97706',
    absent: '#DC2626',
    leave: '#0284C7',
};

const STATUS: Record<string, { label: string; color: string }> = {
    present: { label: 'Hadir', color: BRAND.present },
    late: { label: 'Terlambat', color: BRAND.late },
    absent: { label: 'Alpa', color: BRAND.absent },
    leave: { label: 'Cuti', color: BRAND.leave },
    sick: { label: 'Sakit', color: BRAND.leave },
    holiday: { label: 'Libur', color: '#64748B' },
    day_off: { label: 'Hari libur', color: '#64748B' },
};

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

// ── Jam berjalan (zona waktu toko) ──
const now = ref(new Date());
let clockTimer: ReturnType<typeof setInterval> | null = null;
const storeTz = computed(() => props.store.timezone || 'UTC');
const fmt = (opts: Intl.DateTimeFormatOptions) => new Intl.DateTimeFormat('id-ID', { ...opts, timeZone: storeTz.value }).format(now.value);
const clockText = computed(() => fmt({ hour: '2-digit', minute: '2-digit', hour12: false }).replace('.', ':'));
const dayText = computed(() => fmt({ weekday: 'long' }));
const dateText = computed(() => fmt({ day: 'numeric', month: 'long', year: 'numeric' }));
const storeHour = computed(() => Number(fmt({ hour: '2-digit', hour12: false })));
const greeting = computed(() => {
    const h = storeHour.value;
    if (h < 11) return 'Selamat pagi,';
    if (h < 15) return 'Selamat siang,';
    if (h < 18) return 'Selamat sore,';
    return 'Selamat malam,';
});

const displayName = computed(() => props.activeCashier?.employee_name ?? props.profile!.name);
const initials = computed(() =>
    displayName.value
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((p) => p.charAt(0).toUpperCase())
        .join(''),
);
const photoBroken = ref(false);

// ── Status hari ini ──
const nextAction = computed<'in' | 'out' | 'done'>(() => {
    if (!props.attendanceToday?.clock_in) return 'in';
    if (!props.attendanceToday.clock_out) return 'out';
    return 'done';
});
const statusInfo = computed(() => (props.attendanceToday ? STATUS[props.attendanceToday.status] : null));

const shiftProgress = computed(() => {
    const att = props.attendanceToday;
    const schedule = props.profile!.schedule;
    if (!schedule || !att?.clock_in) return null;
    if (att.clock_out) return 1;
    const toMin = (hhmm: string) => {
        const [h, m] = hhmm.split(':').map(Number);
        return h * 60 + m;
    };
    const start = toMin(schedule.shift_start);
    let end = toMin(schedule.shift_end);
    if (end <= start) end += 24 * 60;
    let current = storeHour.value * 60 + Number(fmt({ minute: '2-digit' }));
    if (current < start && end > 24 * 60) current += 24 * 60;
    return Math.min(1, Math.max(0, (current - start) / (end - start)));
});

const formatHours = (hours: number | null | undefined) => {
    if (!hours) return '0 j';
    const h = Math.floor(hours);
    const m = Math.round((hours - h) * 60);
    return m ? `${h} j ${m} m` : `${h} j`;
};

// ── Lokasi ──
type LocationState = { latitude: number; longitude: number; accuracy: number; distance: number | null };
const location = ref<LocationState | null>(null);
const locError = ref<string | null>(null);
const locating = ref(false);

const distanceMeters = (lat1: number, lon1: number, lat2: number, lon2: number) => {
    const rad = (d: number) => (d * Math.PI) / 180;
    const a = Math.sin(rad(lat2 - lat1) / 2) ** 2 + Math.cos(rad(lat1)) * Math.cos(rad(lat2)) * Math.sin(rad(lon2 - lon1) / 2) ** 2;
    return 6371000 * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
};

const distanceLabel = (m: number) => (m >= 1000 ? `${(m / 1000).toFixed(1)} km` : `${Math.round(m)} m`);

const locationErrorMessage = (err: GeolocationPositionError): string => {
    switch (err.code) {
        case err.PERMISSION_DENIED:
            return 'Izin lokasi ditolak. Izinkan akses lokasi di browser lalu coba lagi.';
        case err.POSITION_UNAVAILABLE:
            return 'Posisi tidak tersedia. Pastikan GPS aktif lalu coba lagi.';
        case err.TIMEOUT:
            return 'Lokasi belum didapat. Coba lagi di tempat terbuka.';
        default:
            return 'Lokasi tidak dapat ditentukan. Coba lagi.';
    }
};

const refreshLocation = (): Promise<LocationState | null> => {
    if (!navigator.geolocation) {
        locError.value = 'Browser tidak mendukung akses lokasi.';
        return Promise.resolve(null);
    }
    locating.value = true;
    return new Promise((resolve) => {
        navigator.geolocation.getCurrentPosition(
            (pos) => {
                const office = props.profile!.office;
                location.value = {
                    latitude: pos.coords.latitude,
                    longitude: pos.coords.longitude,
                    accuracy: pos.coords.accuracy,
                    distance: office ? distanceMeters(pos.coords.latitude, pos.coords.longitude, office.latitude, office.longitude) : null,
                };
                locError.value = null;
                locating.value = false;
                resolve(location.value);
            },
            (err) => {
                locError.value = locationErrorMessage(err);
                locating.value = false;
                resolve(null);
            },
            { enableHighAccuracy: true, timeout: 15000, maximumAge: 30000 },
        );
    });
};

const inRange = computed(() => location.value?.distance == null || location.value.distance <= props.settings.radius_meters);

// ── Selfie ──
const cameraOpen = ref(false);
const videoEl = ref<HTMLVideoElement | null>(null);
const photoPreview = ref<string | null>(null);
const photoFile = ref<File | null>(null);
let stream: MediaStream | null = null;

const stopCamera = () => {
    stream?.getTracks().forEach((t) => t.stop());
    stream = null;
};

const clearPhoto = () => {
    if (photoPreview.value) URL.revokeObjectURL(photoPreview.value);
    photoFile.value = null;
    photoPreview.value = null;
};

const openCamera = async () => {
    clearPhoto();
    cameraOpen.value = true;
    try {
        stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false });
        await nextTick();
        if (videoEl.value) {
            videoEl.value.srcObject = stream;
            await videoEl.value.play();
        }
    } catch {
        cameraOpen.value = false;
        toastError('Tidak dapat mengakses kamera. Izinkan akses kamera lalu coba lagi.');
    }
};

const closeCamera = () => {
    stopCamera();
    clearPhoto();
    cameraOpen.value = false;
};

const capture = () => {
    const video = videoEl.value;
    if (!video) return;
    const canvas = document.createElement('canvas');
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    const ctx = canvas.getContext('2d');
    if (!ctx) return;
    // Cermin seperti kamera depan, lalu cap waktu & nama di pojok bawah.
    ctx.translate(canvas.width, 0);
    ctx.scale(-1, 1);
    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
    ctx.setTransform(1, 0, 0, 1, 0, 0);
    const lines = [
        `${nextAction.value === 'in' ? 'MASUK' : 'PULANG'}  ${new Intl.DateTimeFormat('id-ID', { dateStyle: 'short', timeStyle: 'medium', timeZone: storeTz.value }).format(new Date())}`,
        `${displayName.value} - ${props.store.name}`,
    ];
    const size = Math.max(14, Math.round(canvas.width / 28));
    ctx.font = `600 ${size}px sans-serif`;
    ctx.fillStyle = 'rgba(0,0,0,0.45)';
    ctx.fillRect(0, canvas.height - size * 1.6 * lines.length - size * 0.6, canvas.width, size * 1.6 * lines.length + size * 0.6);
    ctx.fillStyle = '#fff';
    lines.forEach((line, i) => ctx.fillText(line, size * 0.6, canvas.height - size * 1.6 * (lines.length - 1 - i) - size * 0.7));
    stopCamera();
    canvas.toBlob(
        (blob) => {
            if (!blob) return;
            photoFile.value = new File([blob], 'selfie.jpg', { type: 'image/jpeg' });
            photoPreview.value = URL.createObjectURL(blob);
        },
        'image/jpeg',
        0.85,
    );
};

const retake = async () => {
    clearPhoto();
    await openCamera();
};

// ── Kirim absen ──
const busy = ref(false);
const serverError = computed(
    () =>
        (form.errors as Record<string, string | undefined>).location ??
        form.errors.photo ??
        (form.errors as Record<string, string | undefined>).clock_in ??
        (form.errors as Record<string, string | undefined>).clock_out ??
        null,
);

const submit = async () => {
    const action = nextAction.value;
    if (action === 'done') return;
    busy.value = true;
    const geo = props.settings.require_geolocation ? await refreshLocation() : null;
    if (props.settings.require_geolocation) {
        if (!geo) {
            busy.value = false;
            toastError(locError.value ?? 'Lokasi belum didapat. Nyalakan GPS lalu coba lagi.');
            return;
        }
        if (geo.distance != null && geo.distance > props.settings.radius_meters) {
            busy.value = false;
            toastError(`Anda berjarak ${distanceLabel(geo.distance)} dari toko (batas ${props.settings.radius_meters} m).`);
            return;
        }
    }
    form.latitude = geo?.latitude ?? null;
    form.longitude = geo?.longitude ?? null;
    form.accuracy = geo?.accuracy ?? null;
    form.photo = photoFile.value;
    form.post(action === 'in' ? '/attendance/clock-in' : '/attendance/clock-out', {
        preserveScroll: true,
        onSuccess: () => closeCamera(),
        onFinish: () => {
            busy.value = false;
        },
    });
};

const onSwiped = async () => {
    form.clearErrors();
    if (props.settings.require_selfie) {
        await openCamera();
        return;
    }
    await submit();
};

// ── Mode PIN kasir ──
const isPinMode = computed(() => props.cashier_login_mode === 'pin');
const showPinLogin = computed(() => isPinMode.value && !props.activeCashier);
function onPinLoginSuccess() {
    router.reload({ only: ['activeCashier', 'cashiers', 'attendanceToday', 'profile'] });
}
/** Perangkat bersama: karyawan berikutnya masuk dengan PIN-nya sendiri. */
function switchEmployee() {
    closeCamera();
    router.post('/cashier-pin/logout', { redirect_to: '/attendance' }, { preserveScroll: true });
}

// ── Rekap & grafik ──
const monthCards = computed(() => [
    { label: 'Tepat waktu', value: props.profile!.month.on_time, color: BRAND.present, icon: CheckCircle2 },
    { label: 'Telat', value: props.profile!.month.late, color: BRAND.late, icon: Clock },
    { label: 'Alpa', value: props.profile!.month.absent, color: BRAND.absent, icon: XCircle },
    { label: 'Cuti', value: props.profile!.month.leave, color: BRAND.leave, icon: Umbrella },
]);

const week = computed(() => {
    const maxHours = Math.max(8, ...props.profile!.week.map((d) => d.hours));
    const total = props.profile!.week.reduce((sum, d) => sum + d.hours, 0);
    const worked = props.profile!.week.filter((d) => d.hours > 0).length;
    const today = props.profile!.week[props.profile!.week.length - 1]?.date;
    return {
        total,
        average: worked ? total / worked : 0,
        bars: props.profile!.week.map((d) => ({
            ...d,
            height: d.hours > 0 ? d.hours / maxHours : d.status === 'absent' ? 0.06 : 0.03,
            color: d.status ? STATUS[d.status]?.color ?? '#94A3B8' : null,
            day: new Intl.DateTimeFormat('id-ID', { weekday: 'short', timeZone: 'UTC' }).format(new Date(`${d.date}T00:00:00Z`)),
            isToday: d.date === today,
        })),
    };
});

const barsReady = ref(false);

onMounted(() => {
    clockTimer = setInterval(() => (now.value = new Date()), 1000);
    if (props.profile && props.settings.require_geolocation) refreshLocation();
    requestAnimationFrame(() => (barsReady.value = true));
});

onBeforeUnmount(() => {
    if (clockTimer) clearInterval(clockTimer);
    stopCamera();
    clearPhoto();
});
</script>

<template>
    <Head title="Absensi" />

    <AdminLayout title="Absensi">
        <div class="mx-auto max-w-xl space-y-4 pb-8">
            <AttendanceNav />

            <PayrollSetupGuide v-if="setup" :setup="setup" />

            <!-- Pengelola yang belum terdaftar sebagai karyawan -->
            <section v-if="!profile" class="rounded-3xl bg-white p-6 text-center shadow-sm ring-1 ring-slate-100">
                <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-indigo-50">
                    <UserX class="h-7 w-7 text-indigo-600" />
                </div>
                <h2 class="mt-4 text-lg font-extrabold text-slate-900">Anda belum terdaftar sebagai karyawan</h2>
                <p class="mx-auto mt-1 max-w-sm text-sm text-slate-500">
                    Pemilik dan admin tidak otomatis ikut absen. Bila Anda juga bekerja shift dan perlu absen, daftarkan akun Anda di menu Karyawan.
                </p>
                <div class="mt-5 flex flex-wrap justify-center gap-2">
                    <a href="/attendance/report" class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-bold text-white hover:bg-indigo-700">Lihat kehadiran karyawan</a>
                    <a href="/attendance/employees" class="rounded-xl bg-slate-100 px-4 py-2 text-sm font-bold text-slate-700 hover:bg-slate-200">Buka menu Karyawan</a>
                </div>
            </section>

            <template v-else>
            <!-- Header ungu: profil, jam, jadwal -->
            <section
                class="relative overflow-hidden rounded-[28px] px-5 pb-20 pt-6 text-white"
                style="background: linear-gradient(135deg, #6a4dff 0%, #533afd 45%, #26158f 100%)"
            >
                <div class="flex items-center gap-3.5">
                    <div class="h-14 w-14 shrink-0 overflow-hidden rounded-2xl bg-white shadow-lg shadow-black/20">
                        <img
                            v-if="profile.photo_url && !photoBroken"
                            :src="profile.photo_url"
                            alt=""
                            class="h-full w-full object-cover"
                            @error="photoBroken = true"
                        />
                        <div v-else class="flex h-full w-full items-center justify-center text-lg font-extrabold text-[#533AFD]">
                            {{ initials }}
                        </div>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm text-white/75">{{ greeting }}</p>
                        <p class="truncate text-lg font-extrabold leading-tight">{{ displayName }}</p>
                        <p class="truncate text-sm text-white/75">{{ store.name }}</p>
                    </div>
                    <button
                        v-if="isPinMode && activeCashier"
                        type="button"
                        class="flex shrink-0 items-center gap-1.5 self-start rounded-full bg-white/15 px-3 py-1.5 text-xs font-bold ring-1 ring-white/25 hover:bg-white/25"
                        @click="switchEmployee"
                    >
                        <Users class="h-4 w-4" /> Ganti karyawan
                    </button>
                </div>

                <!-- Jam + tanggal, lalu jadwal shift di baris sendiri (rapi di semua lebar layar) -->
                <div class="mt-5 flex items-center gap-3">
                    <p class="text-[44px] font-extrabold leading-none tracking-tight tabular-nums sm:text-5xl">{{ clockText }}</p>
                    <div class="min-w-0 border-l border-white/25 pl-3 leading-snug">
                        <p class="text-sm font-bold capitalize">{{ dayText }}</p>
                        <p class="truncate text-xs text-white/75">{{ dateText }}</p>
                    </div>
                </div>
                <div
                    v-if="profile.schedule || profile.is_holiday"
                    class="mt-3 inline-flex items-center gap-1.5 whitespace-nowrap rounded-full bg-white/15 px-3 py-1.5 text-xs ring-1 ring-white/25"
                >
                    <Clock class="h-4 w-4 text-lime-300" />
                    <span class="text-white/75">{{ profile.is_holiday ? 'Hari ini' : 'Shift' }}</span>
                    <span class="font-bold tabular-nums">{{ profile.is_holiday ? 'Libur' : `${profile.schedule!.shift_start}–${profile.schedule!.shift_end}` }}</span>
                </div>
            </section>

            <!-- Kartu kehadiran hari ini (menimpa header) -->
            <section class="relative -mt-16 mx-3 rounded-3xl bg-white p-5 shadow-[0_12px_30px_-12px_rgba(38,21,143,0.35)]">
                <div class="flex items-center">
                    <h2 class="font-extrabold text-slate-900">Kehadiran hari ini</h2>
                    <span
                        v-if="statusInfo"
                        class="ml-auto rounded-full px-3 py-1 text-xs font-bold"
                        :style="{ color: statusInfo.color, backgroundColor: `${statusInfo.color}1A` }"
                    >
                        {{ statusInfo.label }}
                    </span>
                </div>
                <div class="mt-4 grid grid-cols-2 divide-x divide-slate-100 text-center">
                    <div>
                        <p class="flex items-center justify-center gap-1.5 text-sm text-slate-500">
                            <LogIn class="h-4 w-4" :style="{ color: BRAND.present }" /> Masuk
                        </p>
                        <p class="mt-1 text-3xl font-extrabold tabular-nums" :class="attendanceToday?.clock_in ? 'text-slate-900' : 'text-slate-300'">
                            {{ attendanceToday?.clock_in ?? '--:--' }}
                        </p>
                    </div>
                    <div>
                        <p class="flex items-center justify-center gap-1.5 text-sm text-slate-500">
                            <LogOut class="h-4 w-4" :style="{ color: BRAND.orangeDark }" /> Pulang
                        </p>
                        <p class="mt-1 text-3xl font-extrabold tabular-nums" :class="attendanceToday?.clock_out ? 'text-slate-900' : 'text-slate-300'">
                            {{ attendanceToday?.clock_out ?? '--:--' }}
                        </p>
                    </div>
                </div>
                <template v-if="shiftProgress !== null">
                    <div class="mt-4 h-[7px] overflow-hidden rounded-full bg-indigo-50">
                        <div
                            class="h-full rounded-full transition-all duration-700"
                            :style="{ width: `${shiftProgress * 100}%`, backgroundColor: attendanceToday?.clock_out ? BRAND.present : BRAND.violet }"
                        />
                    </div>
                    <div class="mt-1.5 flex text-xs font-semibold text-slate-500">
                        <span>
                            {{ attendanceToday?.clock_out ? `Kerja ${formatHours(attendanceToday.hours_worked)}` : `Shift berjalan ${Math.round(shiftProgress * 100)}%` }}
                        </span>
                        <span v-if="attendanceToday?.late_minutes" class="ml-auto font-bold" :style="{ color: BRAND.late }">
                            Telat {{ attendanceToday.late_minutes }} menit
                        </span>
                    </div>
                </template>
            </section>

            <!-- Tombol geser + aturan + lokasi -->
            <section class="space-y-2.5 px-1 pt-1">
                <SwipeButton
                    v-if="nextAction !== 'done'"
                    :label="nextAction === 'in' ? 'Swipe to Check In' : 'Swipe to Check Out'"
                    :icon="nextAction === 'in' ? Fingerprint : LogOut"
                    :from="nextAction === 'in' ? '#6A4DFF' : BRAND.orange"
                    :to="nextAction === 'in' ? BRAND.violetDark : BRAND.orangeDark"
                    :busy="busy || form.processing"
                    @swiped="onSwiped"
                />
                <div v-else class="flex items-center justify-center gap-2 rounded-full bg-emerald-50 py-4 font-bold text-emerald-700 ring-1 ring-emerald-100">
                    <CalendarCheck2 class="h-5 w-5" /> Absen hari ini sudah lengkap
                </div>

                <p class="text-center text-xs text-slate-500">
                    {{
                        [
                            settings.require_selfie ? 'Wajib selfie' : null,
                            settings.require_geolocation ? `Radius ${settings.radius_meters} m` : null,
                            `Toleransi telat ${settings.grace_minutes} menit`,
                        ]
                            .filter(Boolean)
                            .join('  ·  ')
                    }}
                </p>

                <p v-if="serverError" class="rounded-2xl bg-red-50 px-4 py-3 text-sm font-medium text-red-700">{{ serverError }}</p>

                <button
                    v-if="settings.require_geolocation"
                    type="button"
                    class="flex w-full items-center gap-3 rounded-2xl px-4 py-3.5 text-left text-sm font-bold"
                    :class="
                        locError || !settings.has_office_location
                            ? 'bg-amber-50 text-amber-800'
                            : !location
                              ? 'bg-slate-100 text-slate-700'
                              : inRange
                                ? 'bg-emerald-50 text-emerald-700'
                                : 'bg-red-50 text-red-700'
                    "
                    @click="refreshLocation"
                >
                    <LocateFixed v-if="!location && !locError" class="h-5 w-5 shrink-0" :class="locating ? 'animate-pulse' : ''" />
                    <MapPinned v-else-if="location && inRange" class="h-5 w-5 shrink-0" />
                    <MapPinOff v-else class="h-5 w-5 shrink-0" />
                    <span class="min-w-0 flex-1">
                        <template v-if="!settings.has_office_location">Lokasi toko belum diatur admin</template>
                        <template v-else-if="locError">{{ locError }}</template>
                        <template v-else-if="!location">Mencari lokasi Anda...</template>
                        <template v-else-if="inRange">
                            Di area toko{{ location.distance != null ? ` · ${distanceLabel(location.distance)}` : '' }}
                        </template>
                        <template v-else>Di luar area toko · {{ distanceLabel(location.distance!) }}</template>
                    </span>
                    <RefreshCw class="h-4 w-4 shrink-0 opacity-70" :class="locating ? 'animate-spin' : ''" />
                </button>
            </section>

            <!-- Rekap bulan ini -->
            <section class="space-y-3 pt-3">
                <div class="flex items-center px-1">
                    <h2 class="text-lg font-extrabold text-slate-900">Bulan {{ new Intl.DateTimeFormat('id-ID', { month: 'long', timeZone: storeTz }).format(now) }}</h2>
                    <a href="/attendance/history" class="ml-auto text-sm font-semibold text-[#533AFD] hover:underline">Lihat riwayat</a>
                </div>
                <div class="grid grid-cols-4 gap-2.5">
                    <div v-for="card in monthCards" :key="card.label" class="rounded-2xl bg-white px-1 py-4 text-center shadow-sm ring-1 ring-slate-100">
                        <component :is="card.icon" class="mx-auto h-6 w-6" :style="{ color: card.color }" />
                        <p class="mt-1.5 text-2xl font-extrabold" :style="{ color: card.color }">{{ card.value }}</p>
                        <p class="text-[11px] font-medium text-slate-500 sm:text-xs">{{ card.label }}</p>
                    </div>
                </div>
            </section>

            <!-- Grafik jam kerja 7 hari -->
            <section class="rounded-3xl bg-white p-5 shadow-sm ring-1 ring-slate-100">
                <div class="flex items-start">
                    <div>
                        <p class="text-xs font-semibold text-slate-500">Jam kerja 7 hari</p>
                        <p class="text-2xl font-extrabold text-slate-900">{{ week.total ? formatHours(week.total) : '—' }}</p>
                    </div>
                    <span class="ml-auto rounded-full bg-indigo-50 px-3 py-1.5 text-xs font-bold text-[#533AFD]">
                        {{ week.total ? `Rata-rata ${formatHours(week.average)}/hari` : 'Belum ada' }}
                    </span>
                </div>
                <div class="mt-4 flex h-36 items-end">
                    <div v-for="bar in week.bars" :key="bar.date" class="flex flex-1 flex-col items-center justify-end">
                        <span v-if="bar.hours > 0" class="mb-1 text-[10.5px] font-bold text-slate-500">
                            {{ Number.isInteger(bar.hours) ? bar.hours : bar.hours.toFixed(1) }}
                        </span>
                        <div
                            class="w-[22px] rounded-lg transition-[height] duration-700 ease-out"
                            :style="{
                                height: `${(barsReady ? bar.height : 0) * 92}px`,
                                background: bar.color ? `linear-gradient(to top, ${bar.color}, ${bar.color}99)` : '#E9EBF2',
                            }"
                        />
                        <span class="mt-2 text-[11.5px]" :class="bar.isToday ? 'font-extrabold text-[#533AFD]' : 'font-medium text-slate-500'">
                            {{ bar.day }}
                        </span>
                    </div>
                </div>
                <div class="mt-3 flex gap-3 text-[11px] text-slate-500">
                    <span v-for="key in ['present', 'late', 'absent']" :key="key" class="flex items-center gap-1">
                        <span class="h-2 w-2 rounded-full" :style="{ backgroundColor: STATUS[key].color }" /> {{ STATUS[key].label }}
                    </span>
                </div>
            </section>
            </template>
        </div>

        <!-- Kamera selfie layar penuh -->
        <Teleport to="body">
            <div v-if="cameraOpen" class="fixed inset-0 z-[100] flex flex-col bg-black text-white">
                <div class="flex items-center px-4 py-3">
                    <button type="button" class="rounded-full p-2 hover:bg-white/10" aria-label="Tutup" @click="closeCamera">
                        <X class="h-6 w-6" />
                    </button>
                    <p class="ml-2 font-bold">{{ nextAction === 'in' ? 'Selfie absen masuk' : 'Selfie absen pulang' }}</p>
                </div>
                <div class="relative flex flex-1 items-center justify-center overflow-hidden px-4">
                    <img v-if="photoPreview" :src="photoPreview" alt="Selfie" class="max-h-full max-w-full rounded-3xl object-contain" />
                    <video v-else ref="videoEl" class="max-h-full max-w-full -scale-x-100 rounded-3xl object-cover" autoplay playsinline muted />
                </div>
                <p v-if="serverError" class="mx-4 mt-3 rounded-2xl bg-red-500/20 px-4 py-3 text-sm text-red-100">{{ serverError }}</p>
                <div class="flex items-center justify-center gap-4 px-4 pb-8 pt-5">
                    <template v-if="!photoPreview">
                        <button
                            type="button"
                            class="flex h-20 w-20 items-center justify-center rounded-full border-4 border-white/80 bg-white/10 hover:bg-white/20"
                            aria-label="Ambil foto"
                            @click="capture"
                        >
                            <Camera class="h-8 w-8" />
                        </button>
                    </template>
                    <template v-else>
                        <button type="button" class="flex items-center gap-2 rounded-full bg-white/10 px-5 py-3 font-bold hover:bg-white/20" :disabled="busy || form.processing" @click="retake">
                            <RotateCcw class="h-5 w-5" /> Ulangi
                        </button>
                        <button
                            type="button"
                            class="flex items-center gap-2 rounded-full px-6 py-3 font-bold text-white disabled:opacity-60"
                            :style="{ background: nextAction === 'in' ? BRAND.violet : BRAND.orangeDark }"
                            :disabled="busy || form.processing"
                            @click="submit"
                        >
                            <CheckCircle2 class="h-5 w-5" />
                            {{ busy || form.processing ? 'Mengirim...' : nextAction === 'in' ? 'Kirim absen masuk' : 'Kirim absen pulang' }}
                        </button>
                    </template>
                </div>
            </div>
        </Teleport>

        <PinLoginModal
            v-if="showPinLogin"
            :cashiers="cashiers"
            :store-name="store.name"
            :dismissible="false"
            redirect-to="/attendance"
            @success="onPinLoginSuccess"
        />
    </AdminLayout>
</template>
