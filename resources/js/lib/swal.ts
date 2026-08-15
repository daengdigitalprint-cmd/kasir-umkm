import Swal from 'sweetalert2';

const toastBase = {
    toast: true,
    position: 'top-end' as const,
    showConfirmButton: false,
    timerProgressBar: true,
    customClass: {
        popup: 'rounded-2xl shadow-lg font-sans',
        title: 'text-sm font-bold',
    },
};

export type InertiaFlash = {
    success?: string | null;
    error?: string | null;
    warning?: string | null;
    info?: string | null;
    status?: string | null;
};

/** Toast hijau (sukses). `timer` default 4000 ms; pakai lebih pendek sebelum reload. */
export function toastSuccess(title: string, timer = 4000) {
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
        width: '20rem',
        customClass: {
            popup: 'rounded-2xl shadow-2xl font-sans !p-4',
            title: 'text-base font-bold text-slate-900',
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

export async function confirmDelete(title: string, text: string): Promise<boolean> {
    return confirmDialog(title, text, {
        confirmText: 'Hapus',
        icon: 'warning',
        confirmColor: '#dc2626',
    });
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

/** Proses flash Inertia satu kali per navigasi (prioritas: error → warning → success). */
export function processInertiaFlash(flash: InertiaFlash | undefined | null) {
    if (!flash || typeof flash !== 'object') {
        return;
    }
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
