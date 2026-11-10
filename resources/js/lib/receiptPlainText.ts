/**
 * Struk teks polos untuk thermal (Bluetooth / ESC/POS), selaras dengan data struk HTML di POS.
 */

import {
    escPosDefaultLineSpacing,
    escPosLineSpacing,
    type EscPosLine,
} from './escPosEncode';

export function formatCurrencyId(amount: number): string {
    if (Number.isNaN(amount) || amount === null || amount === undefined) {
        return '0';
    }
    return new Intl.NumberFormat('id-ID', {
        style: 'decimal',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(amount);
}

function paymentMethodLabel(method: string): string {
    const m: Record<string, string> = {
        cash: 'Tunai',
        card: 'Kartu',
        ewallet: 'E-Wallet',
        transfer: 'Transfer',
        bank_transfer: 'Transfer',
        qris: 'QRIS',
        other: 'Lainnya',
    };
    return m[method] ?? method;
}

function orderTypeLabel(t: string | null | undefined): string {
    const o: Record<string, string> = {
        dine_in: 'Makan di tempat',
        takeaway: 'Bungkus',
        delivery: 'Antar',
    };
    return t && o[t] ? o[t] : '';
}

function rowLR(left: string, right: string, width: number): string {
    const L = left.trimEnd();
    const R = right.trimStart();
    const space = width - L.length - R.length;
    if (space >= 1) {
        return `${L}${' '.repeat(space)}${R}`;
    }
    const maxL = Math.max(1, width - R.length - 1);
    return `${L.slice(0, maxL)} ${R}`;
}

function centerLine(text: string, width: number): string {
    const t = text.trim();
    if (t.length >= width) {
        return t.slice(0, width);
    }
    const pad = width - t.length;
    const left = Math.floor(pad / 2);
    return `${' '.repeat(left)}${t}${' '.repeat(pad - left)}`;
}

function rule(width: number): string {
    return '-'.repeat(width);
}

/** Payload dari respons transaksi POS (setelah pembayaran) + meta toko. */
export function buildReceiptPlainLinesFromPosResponse(
    tx: Record<string, unknown>,
    store: {
        name: string;
        tax_name?: string;
        pharmacy_enabled?: boolean;
        receipt?: {
            header_name?: string;
            subtitle?: string;
            footer?: string;
            footer_line2?: string;
            show_address?: boolean;
            show_phone?: boolean;
            address?: string;
            phone?: string;
            paper_width_mm?: number;
        } | null;
    },
    /**
     * Perintah raster ESC/POS untuk logo (hasil `imageUrlToEscPosRaster`).
     * Bila null, struk dicetak tanpa logo.
     */
    logoRaster?: Uint8Array | null,
): EscPosLine[] {
    const r = (tx.receipt as Record<string, unknown> | null | undefined) ?? store.receipt ?? {};
    const mm = Number(r.paper_width_mm) === 58 ? 58 : 80;
    const w = mm === 58 ? 32 : 48;

    const headerName = String(r.header_name ?? store.name ?? 'Toko');
    const subtitle = String(r.subtitle ?? '').trim();
    const footer = String(r.footer ?? 'Terima kasih.').trim();
    const footerLine2 = String(r.footer_line2 ?? '').trim();

    const showAddr = Boolean(r.show_address);
    const showPh = Boolean(r.show_phone);
    const addr = String(r.address ?? '').trim();
    const phone = String(r.phone ?? '').trim();

    const taxLabel = String(
        (tx.store && typeof tx.store === 'object' && (tx.store as { tax_name?: string }).tax_name) ||
            store.tax_name ||
            'PPN',
    );

    const completed = new Date(String(tx.completed_at ?? ''));
    const tglStr = completed.toLocaleString('id-ID', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });

    const discName =
        tx.discount && typeof tx.discount === 'object' && (tx.discount as { name?: string }).name
            ? String((tx.discount as { name?: string }).name)
            : 'Diskon';

    const lines: EscPosLine[] = [];
    // Logo di kepala struk, rata tengah (perintah raster sudah memuat ESC a 1)
    if (logoRaster) {
        lines.push(logoRaster);
    }
    lines.push(centerLine(headerName.toUpperCase(), w));
    if (subtitle) {
        lines.push(centerLine(subtitle, w));
    }
    if (showAddr && addr) {
        for (const chunk of wrapText(addr, w)) {
            lines.push(centerLine(chunk, w));
        }
    }
    if (showPh && phone) {
        lines.push(centerLine(`Telp. ${phone}`, w));
    }
    lines.push(rule(w));
    lines.push(rowLR('No. Struk', String(tx.transaction_number ?? ''), w));
    lines.push(rowLR('Tgl/Jam', tglStr, w));
    lines.push(rowLR('Kasir', String((tx.user as { name?: string } | undefined)?.name ?? ''), w));
    const barberName = String(((tx.barber as { name?: string } | undefined)?.name) ?? '').trim();
    if (barberName) {
        lines.push(rowLR('Teknisi', barberName, w));
    }
    if (tx.order_type) {
        lines.push(rowLR('Pesanan', orderTypeLabel(String(tx.order_type)), w));
    }
    if (tx.table_reference && String(tx.table_reference).trim()) {
        lines.push(rowLR('Meja/No', String(tx.table_reference).trim(), w));
    }
    lines.push(rule(w));

    const items = Array.isArray(tx.items) ? (tx.items as Array<Record<string, unknown>>) : [];
    for (const item of items) {
        const name = String(item.product_name ?? '');
        for (const chunk of wrapText(name, w)) {
            lines.push(chunk);
        }
        const mods = Array.isArray(item.modifiers)
            ? (item.modifiers as Array<{ label?: string; price?: number }>)
            : [];
        for (const m of mods) {
            const lab = String(m.label ?? '');
            lines.push(`  + ${lab} (+${formatCurrencyId(Number(m.price ?? 0))})`);
        }
        const qty = Number(item.quantity ?? 0);
        const unit = formatCurrencyId(Number(item.unit_price ?? 0));
        const lt = formatCurrencyId(Number(item.total_price ?? 0));
        lines.push(rowLR(`  ${qty} x ${unit}`, lt, w));
    }

    lines.push(rule(w));
    lines.push(rowLR('Subtotal', formatCurrencyId(Number(tx.subtotal ?? 0)), w));
    if (Number(tx.discount_amount) > 0) {
        lines.push(rowLR(discName, `-${formatCurrencyId(Number(tx.discount_amount))}`, w));
    }
    if (Number(tx.service_charge_amount) > 0) {
        const p =
            tx.service_charge_percent != null && Number(tx.service_charge_percent) > 0
                ? ` (${Number(tx.service_charge_percent)}%)`
                : '';
        lines.push(rowLR(`Biaya layanan${p}`, formatCurrencyId(Number(tx.service_charge_amount)), w));
    }
    if (Number(tx.tax_amount) > 0) {
        lines.push(rowLR(taxLabel, formatCurrencyId(Number(tx.tax_amount)), w));
    }
    if (Number(tx.tip_amount) > 0) {
        lines.push(rowLR('Tip', formatCurrencyId(Number(tx.tip_amount)), w));
    }
    lines.push(rowLR('TOTAL', formatCurrencyId(Number(tx.total_amount ?? 0)), w));

    const pdRaw = tx.payment_details as
        | {
              split_bill?: { shares?: number[] };
              pre_order?: { order_number?: string; deposit_applied?: number; balance_due?: number };
              pharmacy?: {
                  sale_reference?: string | null;
                  prescription?: {
                      reference?: string;
                      number?: string;
                      patient_name?: string;
                      doctor_name?: string;
                      issued_at?: string;
                  } | null;
                  has_prescription_lines?: boolean;
                  has_limited_lines?: boolean;
              };
          }
        | null
        | undefined;

    const sbill = pdRaw?.split_bill;
    if (sbill && Array.isArray(sbill.shares) && sbill.shares.length >= 2) {
        lines.push(centerLine(`Bagi tagihan (${sbill.shares.length})`, w));
        sbill.shares.forEach((amt, i) => {
            lines.push(rowLR(`Bagian ${i + 1}`, formatCurrencyId(Number(amt)), w));
        });
    }

    const po = pdRaw?.pre_order;
    if (po && Number(po.deposit_applied) > 0) {
        const bon = po.order_number ? ` (${String(po.order_number)})` : '';
        lines.push(centerLine(`Pre-order${bon}`, w));
        lines.push(rowLR('DP dipakai', `-${formatCurrencyId(Number(po.deposit_applied))}`, w));
        lines.push(rowLR('Sisa dibayar', formatCurrencyId(Number(po.balance_due ?? 0)), w));
    }

    const ph = pdRaw?.pharmacy;
    if (ph && (ph.sale_reference || ph.has_prescription_lines || ph.has_limited_lines)) {
        const tags: string[] = [];
        if (ph.has_prescription_lines) {
            tags.push('Resep');
        }
        if (ph.has_limited_lines) {
            tags.push('Terbatas');
        }
        const tagStr = tags.length ? tags.join(', ') : '';
        lines.push(centerLine(`Apotek${tagStr ? ` (${tagStr})` : ''}`, w));
        const prescriptionNo = ph.prescription?.number || ph.prescription?.reference || ph.sale_reference;
        if (prescriptionNo) {
            for (const chunk of wrapText(`No. resep: ${String(prescriptionNo)}`, w)) {
                lines.push(chunk);
            }
        }
        if (ph.prescription?.patient_name) {
            for (const chunk of wrapText(`Pasien: ${ph.prescription.patient_name}`, w)) {
                lines.push(chunk);
            }
        }
        if (ph.prescription?.doctor_name) {
            for (const chunk of wrapText(`Dokter: ${ph.prescription.doctor_name}`, w)) {
                lines.push(chunk);
            }
        }
    }

    lines.push(rule(w));
    const payLabel = paymentMethodLabel(String(tx.payment_method ?? ''));
    const paymentLines = Array.isArray(tx.payments) ? (tx.payments as { method?: string; amount?: number | string }[]) : [];
    if (paymentLines.length > 1) {
        // Split tender: uang tunai diterima = total dibayar − porsi non-tunai.
        const nonCashTotal = paymentLines.filter((line) => line.method !== 'cash').reduce((sum, line) => sum + Number(line.amount ?? 0), 0);
        for (const line of paymentLines) {
            const method = String(line.method ?? '');
            const amount = method === 'cash' ? Number(tx.amount_paid ?? 0) - nonCashTotal : Number(line.amount ?? 0);
            lines.push(rowLR(`Bayar (${paymentMethodLabel(method)})`, formatCurrencyId(amount), w));
        }
    } else {
        lines.push(rowLR(`Bayar (${payLabel})`, formatCurrencyId(Number(tx.amount_paid ?? 0)), w));
    }
    if (Number(tx.change_amount) > 0) {
        lines.push(rowLR('Kembali', formatCurrencyId(Number(tx.change_amount)), w));
    }
    lines.push(rule(w));

    if (store.pharmacy_enabled === true) {
        for (const chunk of wrapText(
            'Obat dipakai sesuai resep dan aturan. Software ini tidak menggantikan kewajiban hukum atau kewenangan apoteker.',
            w,
        )) {
            lines.push(centerLine(chunk, w));
        }
    }

    // Footer diberi jarak antar baris lebih lega agar tidak terlihat rapat
    lines.push(escPosLineSpacing(44));
    lines.push(centerLine(footer, w));
    if (footerLine2) {
        for (const chunk of wrapText(footerLine2, w)) {
            lines.push(centerLine(chunk, w));
        }
    }
    lines.push(escPosDefaultLineSpacing());

    lines.push(centerLine(`Dicetak: ${new Date().toLocaleString('id-ID')}`, w));
    lines.push('');
    lines.push('');

    return lines;
}

function wrapText(text: string, width: number): string[] {
    const words = text.split(/\s+/).filter(Boolean);
    if (words.length === 0) {
        return [''];
    }
    const out: string[] = [];
    let line = '';
    for (const word of words) {
        if (word.length > width) {
            if (line) {
                out.push(line);
                line = '';
            }
            for (let i = 0; i < word.length; i += width) {
                out.push(word.slice(i, i + width));
            }
            continue;
        }
        const tryLine = line ? `${line} ${word}` : word;
        if (tryLine.length <= width) {
            line = tryLine;
        } else {
            if (line) {
                out.push(line);
            }
            line = word;
        }
    }
    if (line) {
        out.push(line);
    }
    return out;
}

/** Invoice halaman publik (baca saja) — tanpa konfigurasi struk kustom penuh. */
export function buildReceiptPlainLinesFromInvoicePage(
    tx: {
    transaction_number: string;
    completed_at: string;
    payment_method: string;
    subtotal: number;
    tax_amount: number;
    discount_amount: number;
    service_charge_amount?: number;
    service_charge_percent?: number | null;
    tip_amount?: number;
    total_amount: number;
    amount_paid: number;
    change_amount: number;
    payments?: { method: string; amount: number | string; reference?: string | null }[] | null;
    order_type?: string | null;
    table_reference?: string | null;
    payment_details?: {
        split_bill?: { shares?: number[] };
        pre_order?: { order_number?: string; deposit_applied?: number; balance_due?: number };
        pharmacy?: {
            sale_reference?: string | null;
            prescription?: {
                reference?: string;
                number?: string;
                patient_name?: string;
                doctor_name?: string;
                issued_at?: string;
            } | null;
            has_prescription_lines?: boolean;
            has_limited_lines?: boolean;
        };
    } | null;
    items: Array<{
        product_name: string;
        quantity: number;
        unit_price: number;
        total_price: number;
        modifiers?: Array<{ label?: string; price?: number }> | null;
    }>;
    store: { name: string; address?: string; phone?: string };
    user: { name: string };
    customer?: { name?: string };
    barber?: { name?: string } | null;
    },
    pharmacyEnabled = false,
): string[] {
    const w = 48;
    const lines: string[] = [];
    lines.push(centerLine(String(tx.store.name).toUpperCase(), w));
    if (tx.store.address?.trim()) {
        for (const c of wrapText(tx.store.address.trim(), w)) {
            lines.push(centerLine(c, w));
        }
    }
    if (tx.store.phone?.trim()) {
        lines.push(centerLine(`Telp. ${tx.store.phone.trim()}`, w));
    }
    lines.push(rule(w));
    lines.push(rowLR('No.', String(tx.transaction_number), w));
    lines.push(
        rowLR(
            'Tgl',
            new Date(tx.completed_at).toLocaleString('id-ID', {
                day: '2-digit',
                month: '2-digit',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
            }),
            w,
        ),
    );
    lines.push(rowLR('Kasir', tx.user.name, w));
    if (tx.barber?.name) {
        lines.push(rowLR('Teknisi', tx.barber.name, w));
    }
    if (tx.customer?.name) {
        lines.push(rowLR('Pelanggan', tx.customer.name, w));
    }
    if (tx.order_type) {
        lines.push(rowLR('Pesanan', orderTypeLabel(tx.order_type), w));
    }
    if (tx.table_reference?.trim()) {
        lines.push(rowLR('Meja/No', tx.table_reference.trim(), w));
    }
    lines.push(rule(w));
    for (const item of tx.items) {
        for (const chunk of wrapText(item.product_name, w)) {
            lines.push(chunk);
        }
        const mods = item.modifiers ?? [];
        for (const m of mods) {
            lines.push(`  + ${String(m.label ?? '')} (+${formatCurrencyId(Number(m.price ?? 0))})`);
        }
        lines.push(
            rowLR(
                `  ${item.quantity} x ${formatCurrencyId(item.unit_price)}`,
                formatCurrencyId(item.total_price),
                w,
            ),
        );
    }
    lines.push(rule(w));
    lines.push(rowLR('Subtotal', formatCurrencyId(tx.subtotal), w));
    if (tx.discount_amount > 0) {
        lines.push(rowLR('Diskon', `-${formatCurrencyId(tx.discount_amount)}`, w));
    }
    if (Number(tx.service_charge_amount) > 0) {
        const p =
            tx.service_charge_percent != null && Number(tx.service_charge_percent) > 0
                ? ` (${Number(tx.service_charge_percent)}%)`
                : '';
        lines.push(rowLR(`Biaya layanan${p}`, formatCurrencyId(Number(tx.service_charge_amount)), w));
    }
    if (tx.tax_amount > 0) {
        lines.push(rowLR('Pajak', formatCurrencyId(tx.tax_amount), w));
    }
    if (Number(tx.tip_amount) > 0) {
        lines.push(rowLR('Tip', formatCurrencyId(Number(tx.tip_amount)), w));
    }
    lines.push(rowLR('TOTAL', formatCurrencyId(tx.total_amount), w));

    const pd = tx.payment_details;
    const shares = pd?.split_bill?.shares;
    if (Array.isArray(shares) && shares.length >= 2) {
        lines.push(centerLine(`Bagi tagihan (${shares.length})`, w));
        shares.forEach((amt, i) => {
            lines.push(rowLR(`Bagian ${i + 1}`, formatCurrencyId(Number(amt)), w));
        });
    }
    const po = pd?.pre_order;
    if (po && Number(po.deposit_applied) > 0) {
        const bon = po.order_number ? ` (${po.order_number})` : '';
        lines.push(centerLine(`Pre-order${bon}`, w));
        lines.push(rowLR('DP dipakai', `-${formatCurrencyId(Number(po.deposit_applied))}`, w));
        lines.push(rowLR('Sisa dibayar', formatCurrencyId(Number(po.balance_due ?? 0)), w));
    }
    const ph = pd?.pharmacy;
    if (ph && (ph.sale_reference || ph.has_prescription_lines || ph.has_limited_lines)) {
        const prescriptionNo = ph.prescription?.number || ph.prescription?.reference || ph.sale_reference;
        if (prescriptionNo) {
            lines.push(rowLR('No. resep', String(prescriptionNo).slice(0, 20), w));
        }
        if (ph.prescription?.patient_name) {
            lines.push(rowLR('Pasien', ph.prescription.patient_name.slice(0, 20), w));
        }
        if (ph.prescription?.doctor_name) {
            lines.push(rowLR('Dokter', ph.prescription.doctor_name.slice(0, 20), w));
        }
    }

    lines.push(rule(w));
    lines.push(rowLR(`Bayar (${paymentMethodLabel(tx.payment_method)})`, formatCurrencyId(tx.amount_paid), w));
    if (tx.change_amount > 0) {
        lines.push(rowLR('Kembali', formatCurrencyId(tx.change_amount), w));
    }
    lines.push(rule(w));
    if (pharmacyEnabled) {
        for (const chunk of wrapText(
            'Obat dipakai sesuai resep dan aturan. Software ini tidak menggantikan kewajiban hukum atau kewenangan apoteker.',
            w,
        )) {
            lines.push(centerLine(chunk, w));
        }
    }
    lines.push(centerLine('Terima kasih', w));
    lines.push(centerLine(`Dicetak: ${new Date().toLocaleString('id-ID')}`, w));
    lines.push('');
    lines.push('');
    return lines;
}

const ORDER_TYPE_LABEL_KOT: Record<string, string> = {
    dine_in: 'Makan di tempat',
    takeaway: 'Bungkus',
    delivery: 'Antar',
};

/** Buat baris teks plain ESC/POS untuk KOT (tanpa harga). */
export function buildKotPlainLines(opts: {
    storeName: string;
    transactionNumber: string;
    completedAt?: string | null;
    orderType?: string | null;
    tableReference?: string | null;
    customerName?: string | null;
    cashierName?: string | null;
    items: Array<{ product_name: string; quantity: number | string; modifiers?: Array<{ label?: string }> | null }>;
    paperWidthMm?: 58 | 80;
}): string[] {
    const w = opts.paperWidthMm === 58 ? 32 : 42;
    const lines: string[] = [];

    const tgl = opts.completedAt ? new Date(opts.completedAt) : new Date();
    const tglStr = Number.isFinite(tgl.getTime())
        ? tgl.toLocaleString('id-ID', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })
        : '—';

    lines.push(centerLine('*** KOT — DAPUR ***', w));
    lines.push(centerLine(opts.storeName, w));
    lines.push(rule(w));
    lines.push(rowLR('No.', opts.transactionNumber, w));
    lines.push(rowLR('Waktu', tglStr, w));

    const ot = opts.orderType && ORDER_TYPE_LABEL_KOT[opts.orderType]
        ? ORDER_TYPE_LABEL_KOT[opts.orderType]
        : opts.orderType ?? '—';
    lines.push(rowLR('Pesanan', ot, w));

    if (opts.tableReference && String(opts.tableReference).trim()) {
        lines.push(rowLR('Meja/No', String(opts.tableReference).trim(), w));
    }
    if (opts.customerName && String(opts.customerName).trim()) {
        lines.push(rowLR('Pelanggan', String(opts.customerName).trim(), w));
    }
    lines.push(rowLR('Kasir', String(opts.cashierName ?? '—'), w));
    lines.push(rule(w));
    lines.push('ITEM');
    lines.push(rule(w));

    for (const item of opts.items) {
        const qty = typeof item.quantity === 'number'
            ? item.quantity
            : parseFloat(String(item.quantity));
        const qtyStr = Number.isFinite(qty) ? String(qty) : String(item.quantity ?? '');
        lines.push(`${qtyStr}x ${String(item.product_name ?? '')}`);
        if (Array.isArray(item.modifiers)) {
            for (const mod of item.modifiers) {
                const label = String(mod?.label ?? '').trim();
                if (label) lines.push(`   > ${label}`);
            }
        }
    }

    lines.push(rule(w));
    lines.push(centerLine('Tidak untuk pembayaran', w));
    lines.push(centerLine(`Dicetak: ${new Date().toLocaleString('id-ID')}`, w));
    lines.push('');
    lines.push('');
    return lines;
}
