<script setup lang="ts">
/**
 * BarcodeScanner.vue
 *
 * Komponen pemindaian barcode dua mode:
 * 1. Kamera (ZXing-js) — mendukung EAN-13, UPC-A, Code 128, QR Code
 * 2. USB Scanner / keyboard wedge — menangkap input keyboard cepat
 *
 * Props:
 *   mode: 'camera' | 'keyboard' | 'both'
 *   active: boolean — aktifkan/nonaktifkan pemindaian
 *
 * Emits:
 *   scanned(barcode: string) — barcode berhasil terbaca
 *   error(msg: string)       — error kamera atau baca
 */
import { BrowserMultiFormatReader } from '@zxing/browser';
import { BarcodeFormat, DecodeHintType } from '@zxing/library';
import { Camera, Keyboard, X, ZoomIn } from 'lucide-vue-next';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = withDefaults(
    defineProps<{
        mode?: 'camera' | 'keyboard' | 'both';
        active?: boolean;
    }>(),
    {
        mode: 'both',
        active: true,
    },
);

const emit = defineEmits<{
    scanned: [barcode: string];
    error: [msg: string];
}>();

// ─── State ────────────────────────────────────────────────────────────────────
const showCamera = ref(false);
const cameraLoading = ref(false);
const cameraError = ref('');
const videoRef = ref<HTMLVideoElement | null>(null);
const lastScanned = ref('');
const lastScannedAt = ref(0);

// Keyboard-wedge state
const kwBuffer = ref('');
const kwLastKeyAt = ref(0);
const KW_TIMEOUT_MS = 150; // karakter masuk < 150ms = scanner fisik (diperbesar untuk scanner USB yang lebih lambat)
const KW_MIN_LEN = 4; // panjang minimum barcode valid

// Log audit pemindaian (disimpan di memory, bisa dikirim ke server)
export interface ScanLog {
    timestamp: string;
    barcode: string;
    mode: 'camera' | 'keyboard';
    success: boolean;
    note?: string;
}
const scanLogs = ref<ScanLog[]>([]);

function addLog(barcode: string, mode: 'camera' | 'keyboard', success: boolean, note?: string) {
    scanLogs.value.push({
        timestamp: new Date().toISOString(),
        barcode,
        mode,
        success,
        note,
    });
    // Batasi log di memory: simpan 500 terakhir
    if (scanLogs.value.length > 500) {
        scanLogs.value.splice(0, scanLogs.value.length - 500);
    }
}

/** Ekspor log untuk komponen induk (opsional). */
function getLogs(): ScanLog[] {
    return scanLogs.value;
}
defineExpose({ getLogs });

// ─── Anti-duplikat — abaikan barcode sama dalam 1,5 detik ────────────────────
function isDuplicate(barcode: string): boolean {
    const now = Date.now();
    if (barcode === lastScanned.value && now - lastScannedAt.value < 1500) {
        return true;
    }
    lastScanned.value = barcode;
    lastScannedAt.value = now;
    return false;
}

// ─── Validasi format barcode ──────────────────────────────────────────────────
const BARCODE_PATTERN = /^[\x21-\x7E]{4,100}$/; // ASCII printable, 4–100 karakter

function isValidBarcode(raw: string): boolean {
    return BARCODE_PATTERN.test(raw.trim());
}

// ─── Mode Kamera (ZXing) ─────────────────────────────────────────────────────
let zxingReader: BrowserMultiFormatReader | null = null;
let zxingControls: Awaited<ReturnType<BrowserMultiFormatReader['decodeFromVideoDevice']>> | null =
    null;
const cameraReady = ref(false); // true setelah video stream aktif menampilkan frame

/** Tunggu hingga videoRef tersedia di DOM (nextTick loop). */
function waitForVideoRef(timeoutMs = 2000): Promise<HTMLVideoElement> {
    return new Promise((resolve, reject) => {
        const start = Date.now();
        const check = () => {
            if (videoRef.value) return resolve(videoRef.value);
            if (Date.now() - start > timeoutMs) return reject(new Error('Video element tidak tersedia'));
            requestAnimationFrame(check);
        };
        check();
    });
}

async function startCamera() {
    cameraLoading.value = true;
    cameraError.value = '';
    cameraReady.value = false;

    try {
        if (!navigator.mediaDevices?.getUserMedia) {
            throw new Error('Browser tidak mendukung akses kamera (getUserMedia).');
        }

        // Tunggu video element benar-benar ada di DOM
        const videoEl = await waitForVideoRef();

        // Pastikan video element masih tersedia setelah async
        if (!videoRef.value) {
            return;
        }

        const hints = new Map<DecodeHintType, unknown>();
        hints.set(DecodeHintType.TRY_HARDER, true);
        // Batasi ke format yang benar-benar dipakai retail. Format numerik pendek
        // (EAN-8, UPC-E) dan ITF/Codabar sengaja TIDAK diaktifkan karena sangat
        // rawan salah-baca (false positive): decoder bisa mengunci sebagian garis
        // dari barcode Code 128/39 lalu menghasilkan kode pendek yang kebetulan
        // lolos checksum (mis. "X5825100299" terbaca jadi EAN-8 "17221030").
        hints.set(DecodeHintType.POSSIBLE_FORMATS, [
            BarcodeFormat.EAN_13,
            BarcodeFormat.UPC_A,
            BarcodeFormat.CODE_128,
            BarcodeFormat.CODE_39,
            BarcodeFormat.QR_CODE,
            BarcodeFormat.DATA_MATRIX,
        ]);

        zxingReader = new BrowserMultiFormatReader(hints, {
            delayBetweenScanAttempts: 75,
            delayBetweenScanSuccess: 400,
            tryPlayVideoTimeout: 5000,
        });

        // ── Konfirmasi multi-baca kamera ────────────────────────────────────
        // Barcode harus terbaca identik beberapa kali berturut-turut sebelum
        // diterima. False positive bersifat tidak stabil antar-frame, sehingga
        // syarat konfirmasi ini menyaringnya tanpa memengaruhi barcode asli.
        //
        // Pengecualian: QR Code dan Data Matrix memakai koreksi galat Reed-Solomon
        // yang membuat salah-baca praktis mustahil, jadi cukup satu kali baca.
        // Ini memangkas latensi scan di HP yang terasa seperti "tidak merespon".
        let confirmCandidate = '';
        let confirmCount = 0;
        const STRONG_ECC_FORMATS = new Set<unknown>([BarcodeFormat.QR_CODE, BarcodeFormat.DATA_MATRIX]);

        function requiredConfirmations(format: unknown): number {
            return STRONG_ECC_FORMATS.has(format) ? 1 : 2;
        }

        // Pastikan video element masih tersedia setelah async
        if (!videoRef.value) {
            return;
        }

        // Listener: tandai kamera siap saat metadata dimuat (stream benar-benar jalan)
        const onLoadedMetadata = () => {
            cameraReady.value = true;
            cameraLoading.value = false;
        };
        videoEl.addEventListener('loadedmetadata', onLoadedMetadata, { once: true });

        const onDecode = (result: unknown, err: unknown) => {
            if (result && typeof result === 'object' && 'getText' in result) {
                const code = String((result as { getText: () => string }).getText()).trim();
                const format = (result as { getBarcodeFormat?: () => unknown }).getBarcodeFormat?.();
                if (!isValidBarcode(code)) {
                    addLog(code, 'camera', false, 'Format tidak valid');
                    return;
                }

                // Konfirmasi multi-baca: butuh N baca identik berturut-turut.
                const needed = requiredConfirmations(format);
                if (code === confirmCandidate) {
                    confirmCount += 1;
                } else {
                    confirmCandidate = code;
                    confirmCount = 1;
                }
                if (confirmCount < needed) {
                    return;
                }
                // Reset counter setelah diterima
                confirmCandidate = '';
                confirmCount = 0;

                if (isDuplicate(code)) return;
                addLog(code, 'camera', true);
                emit('scanned', code);
            }
            if (err) {
                const msg = err instanceof Error ? err.message : String(err);
                if (
                    !msg.toLowerCase().includes('notfound') &&
                    !msg.toLowerCase().includes('no barcode') &&
                    !msg.toLowerCase().includes('no qr')
                ) {
                    addLog('', 'camera', false, msg);
                }
            }
        };

        const constraints: MediaStreamConstraints = {
            video: {
                facingMode: { ideal: 'environment' },
                width: { ideal: 1280 },
                height: { ideal: 720 },
            },
        };

        try {
            zxingControls = await zxingReader.decodeFromConstraints(constraints, videoEl, onDecode);
        } catch {
            const devices = await BrowserMultiFormatReader.listVideoInputDevices();

            if (!devices.length) {
                throw new Error('Tidak ada kamera yang terdeteksi di perangkat ini.');
            }

            const preferred =
                devices.find(
                    (d) =>
                        d.label?.toLowerCase().includes('back') ||
                        d.label?.toLowerCase().includes('rear') ||
                        d.label?.toLowerCase().includes('environment'),
                ) ?? (devices.length > 1 ? devices[devices.length - 1] : devices[0]);

            zxingControls = await zxingReader.decodeFromVideoDevice(preferred.deviceId, videoEl, onDecode);
        }

        // Fallback: jika loadedmetadata tidak fire dalam 3 detik, paksa sembunyikan loading
        setTimeout(() => {
            if (cameraLoading.value) {
                cameraLoading.value = false;
                cameraReady.value = true;
            }
        }, 3000);

        // Paksa play video (beberapa browser perlu ini)
        try {
            await videoEl.play();
        } catch {
            // autoplay biasanya sudah cukup, abaikan error
        }

    } catch (e) {
        const rawMsg = e instanceof Error ? e.message : String(e);
        const errName = e instanceof Error ? (e as unknown as { name?: string }).name : undefined;
        const isInsecureContext = typeof window !== 'undefined' && !window.isSecureContext;

        const msg = (() => {
            if (errName === 'NotAllowedError') {
                return isInsecureContext
                    ? 'Izin kamera ditolak atau diblokir. Di HP, kamera biasanya wajib HTTPS. Buka aplikasi via HTTPS lalu izinkan kamera.'
                    : 'Izin kamera ditolak atau diblokir. Silakan izinkan akses kamera di pengaturan browser.';
            }
            if (errName === 'NotFoundError') {
                return 'Kamera tidak ditemukan di perangkat ini.';
            }
            if (errName === 'NotReadableError') {
                return 'Kamera sedang dipakai aplikasi lain atau tidak dapat dibuka. Tutup aplikasi kamera lain lalu coba lagi.';
            }
            return rawMsg || 'Gagal mengakses kamera. Pastikan izin kamera sudah diberikan.';
        })();
        cameraError.value = msg;
        emit('error', msg);
        addLog('', 'camera', false, msg);
        cameraLoading.value = false;
    }
}

function stopCamera() {
    // Hentikan semua track dari video element langsung
    if (videoRef.value?.srcObject) {
        const stream = videoRef.value.srcObject as MediaStream;
        stream.getTracks().forEach((t) => t.stop());
        videoRef.value.srcObject = null;
    }
    try {
        zxingControls?.stop();
    } catch {
        // abaikan
    }
    zxingControls = null;
    zxingReader = null;
    showCamera.value = false;
    cameraError.value = '';
    cameraReady.value = false;
}

async function toggleCamera() {
    if (showCamera.value) {
        stopCamera();
    } else {
        showCamera.value = true;
        // Gunakan nextTick + rAF untuk memastikan DOM sudah ter-render
        await new Promise<void>((r) => {
            requestAnimationFrame(() => {
                requestAnimationFrame(() => r());
            });
        });
        await startCamera();
    }
}

// ─── Mode Keyboard Wedge ─────────────────────────────────────────────────────
// Threshold kecepatan ketuk: karakter dari scanner USB masuk < 100ms,
// manusia mengetik biasanya > 150ms.
const KW_SCANNER_SPEED_MS = 100;

// Log debug (nonaktif)
// biome-ignore lint/correctness/noUnusedVariables: kept for re-enabling during debug sessions
function dbg(..._args: unknown[]) {}

function handleKeydown(e: KeyboardEvent) {
    if (!props.active) {
        dbg('handleKeydown: SKIP — scanner tidak aktif');
        return;
    }
    if (!['both', 'keyboard'].includes(props.mode)) {
        dbg('handleKeydown: SKIP — mode tidak mendukung keyboard:', props.mode);
        return;
    }

    const now = Date.now();
    const tag = (e.target as HTMLElement)?.tagName?.toLowerCase();
    const isInputEl = tag === 'input' || tag === 'textarea' || tag === 'select';
    const isBarcodeinput = (e.target as HTMLElement)?.dataset?.barcodeInput === 'true';

    const timeSinceLast = now - kwLastKeyAt.value;
    const bufferHasChars = kwBuffer.value.length > 0;
    const isScanner = bufferHasChars && timeSinceLast < KW_SCANNER_SPEED_MS;

    dbg(
        `key="${e.key}" target=${tag} isInput=${isInputEl} isBarcodeInput=${isBarcodeinput}`,
        `timeSinceLast=${timeSinceLast}ms isScanner=${isScanner} buffer="${kwBuffer.value}"`
    );

    // Jika fokus di elemen form biasa dan ini bukan scanner → abaikan
    if (isInputEl && !isBarcodeinput && !isScanner) {
        dbg('SKIP — fokus di input biasa dan bukan scanner');
        return;
    }

    // Karakter Enter/Tab = terminasi barcode dari scanner
    if (e.key === 'Enter' || e.key === 'Tab') {
        dbg(`ENTER/TAB — buffer length=${kwBuffer.value.length}`);
        if (kwBuffer.value.length >= KW_MIN_LEN) {
            const code = kwBuffer.value.trim();
            kwBuffer.value = '';
            kwLastKeyAt.value = 0;
            dbg('EMIT scanned:', code);
            if (!isValidBarcode(code)) {
                addLog(code, 'keyboard', false, 'Format tidak valid');
                dbg('INVALID barcode format:', code);
                emit('error', `Barcode tidak valid: ${code}`);
                return;
            }
            if (isDuplicate(code)) {
                dbg('DUPLICATE barcode, diabaikan');
                return;
            }
            addLog(code, 'keyboard', true);
            emit('scanned', code);
            if (!isInputEl || isBarcodeinput) e.preventDefault();
        } else {
            dbg('Buffer terlalu pendek untuk dianggap barcode, diabaikan');
        }
        kwBuffer.value = '';
        kwLastKeyAt.value = 0;
        return;
    }

    // Hanya karakter tunggal yang bisa jadi barcode
    if (e.key.length !== 1) {
        dbg('SKIP — bukan karakter tunggal:', e.key);
        return;
    }

    // Jika jeda terlalu lama → reset buffer
    if (timeSinceLast > KW_TIMEOUT_MS && bufferHasChars) {
        dbg(`RESET buffer — jeda ${timeSinceLast}ms > ${KW_TIMEOUT_MS}ms`);
        kwBuffer.value = '';
    }

    kwLastKeyAt.value = now;
    kwBuffer.value += e.key;
    dbg('Buffer sekarang:', kwBuffer.value);

    // Batasi buffer
    if (kwBuffer.value.length > 100) kwBuffer.value = '';
}

// ─── Lifecycle ────────────────────────────────────────────────────────────────
onMounted(() => {
    if (['both', 'keyboard'].includes(props.mode)) {
        window.addEventListener('keydown', handleKeydown, true);
    }
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', handleKeydown, true);
    stopCamera();
});

watch(
    () => props.active,
    (val) => {
        if (!val) {
            stopCamera();
            kwBuffer.value = '';
        }
    },
);
</script>

<template>
    <div class="barcode-scanner">
        <!-- Tombol aktifkan kamera (hanya jika mode mendukung) -->
        <div v-if="['both', 'camera'].includes(mode)" class="flex items-center gap-2">
            <button
                type="button"
                :class="[
                    'inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm font-medium transition-colors',
                    showCamera
                        ? 'border-red-200 bg-red-50 text-red-700 hover:bg-red-100'
                        : 'border-teal-200 bg-teal-50 text-teal-700 hover:bg-teal-100',
                ]"
                :disabled="!active"
                :aria-label="showCamera ? 'Matikan kamera pemindai' : 'Aktifkan kamera pemindai'"
                @click="toggleCamera"
            >
                <Camera class="h-4 w-4" />
                <span>{{ showCamera ? 'Matikan kamera' : 'Scan kamera' }}</span>
            </button>

            <span
                v-if="['both', 'keyboard'].includes(mode)"
                class="inline-flex items-center gap-1 text-xs text-slate-400"
                title="Scanner USB/keyboard wedge aktif secara otomatis"
            >
                <Keyboard class="h-3.5 w-3.5" />
                USB aktif
            </span>
        </div>

        <!-- Tampilan kamera -->
        <transition name="scanner-slide">
            <div
                v-if="showCamera"
                class="mt-3 overflow-hidden rounded-xl border border-teal-200 bg-black shadow-lg"
                style="max-width: 100%; width: 360px"
                role="region"
                aria-label="Tampilan kamera pemindai barcode"
            >
                <!-- Video feed — SELALU di DOM agar ZXing bisa attach stream -->
                <div class="relative">
                    <video
                        ref="videoRef"
                        class="h-48 w-full object-cover"
                        autoplay
                        playsinline
                        muted
                        disablePictureInPicture
                        controlsList="nodownload nofullscreen noremoteplayback"
                        :controls="false"
                        aria-label="Umpan kamera untuk pemindaian barcode"
                    />

                    <!-- Loading overlay (di atas video, bukan menggantikan) -->
                    <div
                        v-if="cameraLoading"
                        class="absolute inset-0 flex items-center justify-center bg-slate-900/90 text-sm text-slate-400"
                    >
                        <span class="animate-pulse">Menghubungkan kamera…</span>
                    </div>

                    <!-- Error overlay (di atas video) -->
                    <div
                        v-else-if="cameraError"
                        class="absolute inset-0 flex flex-col items-center justify-center gap-2 bg-slate-900/95 px-4 text-center"
                    >
                        <ZoomIn class="h-8 w-8 text-red-400" />
                        <p class="text-sm text-red-300">{{ cameraError }}</p>
                        <button
                            type="button"
                            class="mt-1 rounded-lg bg-teal-600 px-3 py-1 text-xs text-white hover:bg-teal-700"
                            @click="startCamera"
                        >
                            Coba lagi
                        </button>
                    </div>

                    <!-- Tombol tutup -->
                    <button
                        type="button"
                        class="absolute right-2 top-2 rounded-full bg-black/50 p-1 text-white hover:bg-black/80"
                        aria-label="Tutup kamera"
                        @click="stopCamera"
                    >
                        <X class="h-4 w-4" />
                    </button>
                </div>
            </div>
        </transition>
    </div>
</template>

<style scoped>
.scanner-slide-enter-active,
.scanner-slide-leave-active {
    transition:
        opacity 0.2s ease,
        transform 0.2s ease;
}
.scanner-slide-enter-from,
.scanner-slide-leave-to {
    opacity: 0;
    transform: translateY(-6px);
}

</style>
