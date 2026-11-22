import Swal from 'sweetalert2';

const toastBase = {
    toast: true,
    position: 'top-end' as const,
    showConfirmButton: false,
    timerProgressBar: true,
    padding: '0.6rem 0.9rem',
    customClass: {
        popup: 'rounded-xl shadow-lg font-sans',
        title: 'text-sm font-semibold',
    },
};

export type InertiaFlash = {
    success?: string | null;
    error?: string | null;
    warning?: string | null;
    info?: string | null;
    status?: string | null;
};

/** Toast hijau (sukses). `timer` default 2500 ms; pakai lebih pendek sebelum reload. */
export function toastSuccess(title: string, timer = 2500) {
    return Swal.fire({
        ...toastBase,
        icon: 'success',
        title,
        timer,
    });
}

export function toastError(title: string, timer = 6000) {
    return Swal.fire({
        ...toastBase,
        icon: 'error',
        title,
        timer,
    });
}

export function toastWarning(title: string, timer = 4500) {
    return Swal.fire({
        ...toastBase,
        icon: 'warning',
        title,
        timer,
    });
}

export function toastInfo(title: string, timer = 4000) {
    return Swal.fire({
        ...toastBase,
        icon: 'info',
        title,
        timer,
    });
}

const modalBase = {
    confirmButtonColor: '#0891b2',
    cancelButtonColor: '#64748b',
    reverseButtons: true,
    customClass: {
        popup: 'rounded-2xl shadow-2xl font-sans',
        title: 'text-xl font-bold text-slate-900',
        confirmButton: 'px-6 py-2 rounded-xl font-semibold',
        cancelButton: 'px-6 py-2 rounded-xl font-semibold',
    },
};

export function alertInfo(title: string, text?: string) {
    return Swal.fire({
        icon: 'info',
        title,
        text,
        confirmButtonText: 'OK',
        ...modalBase,
    });
}

/**
 * Popup sukses terpusat (di tengah layar) tanpa tombol, otomatis tertutup.
 * Dipakai untuk konfirmasi cepat seperti hasil scan barcode di POS.
 * Ukuran dibuat ringkas agar tidak terlalu besar di layar mobile.
 */
export function popupSuccessCenter(title: string, timer = 1200) {
    return Swal.fire({
        icon: 'success',
        title,
        position: 'center',
        showConfirmButton: false,
        timer,
        timerProgressBar: true,
        width: '16rem',
        customClass: {
            popup: 'rounded-xl shadow-lg font-sans !p-3',
            title: '!text-[20px] font-semibold text-slate-800',
            icon: '!scale-75',
        },
    });
}

/** Dialog sukses di tengah layar (bukan toast). */
export function alertSuccess(title: string, text?: string) {
    return Swal.fire({
        icon: 'success',
        title,
        text,
        confirmButtonText: 'OK',
        ...modalBase,
    });
}

/** Dialog konfirmasi — mengembalikan true jika pengguna menekan Ya. */
export async function confirmDialog(
    title: string,
    text: string,
    options?: {
        confirmText?: string;
        cancelText?: string;
        icon?: 'warning' | 'question' | 'error' | 'info';
        confirmColor?: string;
    },
): Promise<boolean> {
    const result = await Swal.fire({
        icon: options?.icon ?? 'warning',
        title,
        text,
        showCancelButton: true,
        confirmButtonText: options?.confirmText ?? 'Ya',
        cancelButtonText: options?.cancelText ?? 'Batal',
        confirmButtonColor: options?.confirmColor ?? '#0891b2',
        cancelButtonColor: '#64748b',
        reverseButtons: true,
        customClass: {
            popup: 'rounded-2xl shadow-2xl font-sans',
            title: 'text-lg font-bold text-slate-900',
            htmlContainer: 'text-sm text-slate-600 text-left',
            confirmButton: 'px-6 py-2 rounded-xl font-semibold',
            cancelButton: 'px-6 py-2 rounded-xl font-semibold',
        },
    });
    return result.isConfirmed;
}

/**
 * Konfirmasi meninggalkan halaman dengan perubahan belum disimpan.
 * Tombol aman ("Tetap di sini") jadi tombol utama dan terfokus; true = pengguna memilih keluar.
 */
export async function confirmLeave(): Promise<boolean> {
    const result = await Swal.fire({
        icon: 'warning',
        iconColor: '#f59e0b',
        title: 'Tinggalkan halaman ini?',
        html: 'Perubahan yang Anda isi <b>belum disimpan</b> dan akan hilang jika keluar sekarang.',
        showCancelButton: true,
        confirmButtonText: 'Tetap keluar',
        cancelButtonText: 'Tetap di sini',
        buttonsStyling: false,
        reverseButtons: true,
        focusCancel: true,
        width: '24rem',
        customClass: {
            popup: '!rounded-3xl shadow-2xl !font-sans !p-6',
            icon: '!mt-1 !mb-4 scale-75',
            title: '!p-0 !text-xl !leading-snug !font-bold !text-slate-900',
            htmlContainer: '!mx-0 !mt-2 !p-0 !text-base !leading-relaxed !text-slate-600',
            actions: '!mt-6 !w-full flex-col-reverse gap-2 sm:flex-row',
            cancelButton:
                'w-full sm:w-auto sm:flex-1 rounded-xl bg-primary px-5 py-3 text-base font-semibold text-primary-foreground shadow-sm hover:bg-primary/90 focus:outline-none focus:ring-4 focus:ring-primary/30',
            confirmButton:
                'w-full sm:w-auto sm:flex-1 rounded-xl border border-red-200 bg-red-50 px-5 py-3 text-base font-semibold text-red-600 hover:bg-red-100 focus:outline-none focus:ring-4 focus:ring-red-200',
        },
    });
    return result.isConfirmed;
}

export async function confirmDelete(title: string, text: string): Promise<boolean> {
    return confirmDialog(title, text, {
        confirmText: 'Hapus',
        icon: 'warning',
        confirmColor: '#dc2626',
    });
}

/**
 * Dialog dua pilihan + batal. Mengembalikan 'primary', 'secondary', atau null bila dibatalkan.
 * Pilihan utama memakai warna utama; pilihan kedua (mis. tindakan berisiko) berwarna merah.
 */
export async function choiceDialog(
    title: string,
    text: string,
    options: { primaryText: string; secondaryText: string; cancelText?: string },
): Promise<'primary' | 'secondary' | null> {
    const result = await Swal.fire({
        icon: 'question',
        title,
        text,
        showCancelButton: true,
        showDenyButton: true,
        confirmButtonText: options.primaryText,
        denyButtonText: options.secondaryText,
        cancelButtonText: options.cancelText ?? 'Batal',
        confirmButtonColor: '#7943e5',
        denyButtonColor: '#dc2626',
        cancelButtonColor: '#64748b',
        customClass: {
            popup: 'rounded-2xl font-sans',
            title: 'text-lg font-bold text-slate-900',
            htmlContainer: 'text-sm text-slate-600 text-left',
            confirmButton: 'px-5 py-2 rounded-xl font-semibold',
            denyButton: 'px-5 py-2 rounded-xl font-semibold',
            cancelButton: 'px-5 py-2 rounded-xl font-semibold',
        },
    });
    if (result.isConfirmed) return 'primary';
    if (result.isDenied) return 'secondary';
    return null;
}

/** Minta PIN persetujuan (angka, disamarkan). Mengembalikan PIN, atau null bila dibatalkan. */
export async function promptApprovalPin(title: string, text?: string): Promise<string | null> {
    const result = await Swal.fire({
        icon: 'warning',
        title,
        text,
        input: 'password',
        inputPlaceholder: 'PIN 4–6 angka',
        inputAttributes: { inputmode: 'numeric', maxlength: '6', autocomplete: 'off', 'aria-label': 'PIN persetujuan' },
        showCancelButton: true,
        confirmButtonText: 'Setujui',
        cancelButtonText: 'Batal',
        confirmButtonColor: '#7c3aed',
        cancelButtonColor: '#64748b',
        reverseButtons: true,
        customClass: {
            popup: 'rounded-2xl shadow-2xl font-sans',
            title: 'text-lg font-bold text-slate-900',
            htmlContainer: 'text-sm text-slate-600',
            input: 'mt-3 w-full px-4 py-2 border border-slate-300 rounded-xl text-center tracking-widest',
            confirmButton: 'px-6 py-2 rounded-xl font-semibold',
            cancelButton: 'px-6 py-2 rounded-xl font-semibold',
        },
        preConfirm: (value) => {
            if (!/^\d{4,6}$/.test(String(value ?? ''))) {
                Swal.showValidationMessage('PIN harus 4–6 angka');
                return false;
            }
            return value;
        },
    });

    return result.isConfirmed && result.value ? String(result.value) : null;
}

/** Dialog konfirmasi dengan input teks — mengembalikan teks yang diinput jika Ya, null jika Batal. */
export async function confirmWithInput(
    title: string,
    inputPlaceholder: string,
    options?: {
        confirmText?: string;
        cancelText?: string;
        icon?: 'warning' | 'question' | 'error' | 'info';
        confirmColor?: string;
    },
): Promise<string | null> {
    const result = await Swal.fire({
        icon: options?.icon ?? 'warning',
        title,
        input: 'text',
        inputPlaceholder,
        inputAttributes: {
            'aria-label': inputPlaceholder,
        },
        showCancelButton: true,
        confirmButtonText: options?.confirmText ?? 'Ya',
        cancelButtonText: options?.cancelText ?? 'Batal',
        confirmButtonColor: options?.confirmColor ?? '#dc2626',
        cancelButtonColor: '#64748b',
        reverseButtons: true,
        customClass: {
            popup: 'rounded-2xl shadow-2xl font-sans',
            title: 'text-lg font-bold text-slate-900',
            htmlContainer: 'text-sm text-slate-600 text-left',
            input: 'mt-3 w-full px-4 py-2 border border-slate-300 rounded-xl focus:ring-2 focus:ring-red-500 focus:border-red-500',
            confirmButton: 'px-6 py-2 rounded-xl font-semibold',
            cancelButton: 'px-6 py-2 rounded-xl font-semibold',
        },
        preConfirm: (value) => {
            if (!value || !value.trim()) {
                Swal.showValidationMessage('Alasan harus diisi');
                return false;
            }
            return value;
        },
    });

    if (result.isConfirmed && result.value) {
        return result.value;
    }
    return null;
}

/** Modal error layar penuh (kasir, validasi). */
export async function modalError(title: string, text?: string) {
    return Swal.fire({
        icon: 'error',
        title,
        text,
        confirmButtonColor: '#dc2626',
        confirmButtonText: 'OK',
        background: '#ffffff',
        customClass: {
            popup: 'rounded-2xl shadow-2xl font-sans',
            title: 'text-xl font-bold text-gray-900',
            confirmButton: 'px-6 py-2 rounded-2xl font-medium',
        },
    });
}

/** Pesan singkat lalu reload (setelah sukses fetch). */
export async function toastSuccessThenReload(title: string, waitMs = 1600) {
    await toastSuccess(title, waitMs);
    window.location.reload();
}

/**
 * Proses flash Inertia satu kali per navigasi (prioritas: error → warning → success).
 *
 * Flash di-dedup agar pesan yang sama tidak di-toast berulang kali. Ini penting
 * karena shared prop `flash` ikut terkirim pada partial reload (mis. polling
 * `router.reload({ only: [...] })` di halaman POS) — tanpa dedup, toast sukses
 * (mis. "Shift kasir dibuka") akan muncul berulang setiap kali polling berjalan.
 */
let lastFlashProcessed = '';
export function processInertiaFlash(flash: InertiaFlash | undefined | null) {
    if (!flash || typeof flash !== 'object') {
        return;
    }
    const flashKey = [flash.error, flash.warning, flash.success, flash.info, flash.status].join('|');
    if (!flashKey) {
        return;
    }
    if (flashKey === lastFlashProcessed) {
        return;
    }
    lastFlashProcessed = flashKey;
    if (flash.error) {
        toastError(flash.error);
        return;
    }
    if (flash.warning) {
        toastWarning(flash.warning);
        return;
    }
    if (flash.success) {
        toastSuccess(flash.success);
        return;
    }
    if (flash.info) {
        toastInfo(flash.info);
        return;
    }
    if (flash.status && typeof flash.status === 'string') {
        const s = flash.status;
        if (s === 'store-updated' || s === 'profile-updated') {
            toastSuccess(
                s === 'store-updated'
                    ? 'Informasi toko diperbarui.'
                    : 'Profil diperbarui.',
            );
            return;
        }
        toastInfo(s);
    }
}
