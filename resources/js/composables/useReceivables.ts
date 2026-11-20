/**
 * Bantuan bersama halaman piutang pelanggan: format rupiah, status jatuh tempo,
 * metode bayar (sama dengan layar bayar kasir), dan tautan pengingat WhatsApp.
 */

export const rupiah = (n: number | string | null | undefined) =>
    'Rp ' + new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(Number(n ?? 0));

export const formatDate = (value: string | null) =>
    value ? new Date(value).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' }) : '—';

export const formatDateTime = (value: string) =>
    new Date(value).toLocaleString('id-ID', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });

/** Pilihan metode bayar piutang, sama dengan layar bayar kasir. */
export const PAYMENT_METHODS = [
    { value: 'cash', label: 'Tunai' },
    { value: 'ewallet', label: 'E-Wallet' },
    { value: 'other', label: 'Lainnya' },
] as const;

const METHOD_LABELS: Record<string, string> = {
    cash: 'Tunai',
    ewallet: 'E-Wallet',
    other: 'Lainnya',
    qris: 'QRIS',
    transfer: 'Transfer',
    card: 'Kartu',
    credit: 'Piutang',
};
export const paymentMethodLabel = (method: string) => METHOD_LABELS[method] ?? method;

export type DueTone = 'overdue' | 'today' | 'soon' | 'later' | 'none' | 'paid';

/** Status jatuh tempo dalam kata-kata: "Lewat 8 hari", "Jatuh tempo hari ini", "3 hari lagi". */
export function dueStatus(dueAt: string | null, paid = false): { label: string; tone: DueTone } {
    if (paid) return { label: 'Lunas', tone: 'paid' };
    if (!dueAt) return { label: 'Tanpa jatuh tempo', tone: 'none' };
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const due = new Date(dueAt);
    due.setHours(0, 0, 0, 0);
    const days = Math.round((due.getTime() - today.getTime()) / 86_400_000);
    if (days < 0) return { label: `Lewat ${-days} hari`, tone: 'overdue' };
    if (days === 0) return { label: 'Jatuh tempo hari ini', tone: 'today' };
    if (days <= 7) return { label: `${days} hari lagi`, tone: 'soon' };
    return { label: `Tempo ${formatDate(dueAt)}`, tone: 'later' };
}

export const DUE_TONE_CLASS: Record<DueTone, string> = {
    overdue: 'bg-red-50 text-red-700',
    today: 'bg-amber-50 text-amber-700',
    soon: 'bg-amber-50 text-amber-700',
    later: 'bg-slate-100 text-slate-600',
    none: 'bg-slate-100 text-slate-500',
    paid: 'bg-emerald-50 text-emerald-700',
};

/** Nomor HP lokal (08…) → format internasional untuk wa.me (628…). */
export function waNumber(phone: string | null | undefined): string | null {
    const digits = (phone ?? '').replace(/\D/g, '');
    if (digits.length < 8) return null;
    if (digits.startsWith('62')) return digits;
    if (digits.startsWith('0')) return '62' + digits.slice(1);
    if (digits.startsWith('8')) return '62' + digits;
    return digits;
}

/** Tautan pengingat tagihan via WhatsApp dengan pesan yang sudah terisi. */
export function reminderLink(opts: {
    phone: string | null | undefined;
    customerName: string;
    storeName: string;
    amount: number;
    dueAt: string | null;
}): string | null {
    const number = waNumber(opts.phone);
    if (!number) return null;
    const due = opts.dueAt ? ` (jatuh tempo ${formatDate(opts.dueAt)})` : '';
    const message =
        `Halo ${opts.customerName}, kami dari ${opts.storeName} ingin mengingatkan sisa tagihan sebesar ${rupiah(opts.amount)}${due}. ` +
        'Terima kasih atas kepercayaannya.';
    return `https://wa.me/${number}?text=${encodeURIComponent(message)}`;
}
