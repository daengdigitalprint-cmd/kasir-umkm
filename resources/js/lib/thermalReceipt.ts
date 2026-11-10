/** Konfigurasi struk thermal — sama dengan ReceiptSettings di server */

export interface ReceiptConfig {
    header_name: string;
    subtitle: string;
    footer: string;
    footer_line2: string;
    show_address: boolean;
    show_phone: boolean;
    show_logo: boolean;
    logo_url: string | null;
    paper_width_mm: number;
    address: string;
    phone: string;
}

export interface ThermalReceiptContext {
    taxName: string;
    pharmacyEnabled: boolean;
}

export function defaultReceiptConfig(storeName: string): ReceiptConfig {
    return {
        header_name: storeName,
        subtitle: '',
        footer: 'Terima kasih atas kunjungan Anda.',
        footer_line2: '',
        show_address: true,
        show_phone: true,
        show_logo: true,
        logo_url: null,
        paper_width_mm: 80,
        address: '',
        phone: '',
    };
}

export function mergeReceiptConfig(
    fromTx: Partial<ReceiptConfig> | null | undefined,
    fallback: Partial<ReceiptConfig> | null | undefined,
): ReceiptConfig {
    const a = fromTx ?? {};
    const b = fallback ?? {};
    return {
        header_name: String(a.header_name ?? b.header_name ?? 'Toko'),
        subtitle: String(a.subtitle ?? b.subtitle ?? ''),
        footer: String(a.footer ?? b.footer ?? 'Terima kasih atas kunjungan Anda.'),
        footer_line2: String(a.footer_line2 ?? b.footer_line2 ?? ''),
        show_address: Boolean(a.show_address ?? b.show_address ?? true),
        show_phone: Boolean(a.show_phone ?? b.show_phone ?? true),
        show_logo: Boolean(a.show_logo ?? b.show_logo ?? true),
        logo_url: (a.logo_url ?? b.logo_url ?? null) as string | null,
        paper_width_mm: (() => {
            const v = Number(a.paper_width_mm ?? b.paper_width_mm ?? 80);
            return v === 58 ? 58 : 80;
        })(),
        address: String(a.address ?? b.address ?? ''),
        phone: String(a.phone ?? b.phone ?? ''),
    };
}

function escapeHtml(text: string): string {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatCurrency(amount: number): string {
    if (isNaN(amount) || amount === null || amount === undefined) {
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

export function buildThermalReceiptInnerHtml(
    tx: Record<string, unknown>,
    r: ReceiptConfig,
    ctx: ThermalReceiptContext,
): string {
    const store = tx.store as { tax_name?: string } | undefined;
    const taxLabel = escapeHtml(store?.tax_name || ctx.taxName || 'PPN');

    const completedRaw = tx.completed_at;
    const completed = completedRaw ? new Date(String(completedRaw)) : new Date();
    const tglStr = Number.isNaN(completed.getTime())
        ? new Date().toLocaleString('id-ID', {
              day: '2-digit',
              month: '2-digit',
              year: 'numeric',
              hour: '2-digit',
              minute: '2-digit',
          })
        : completed.toLocaleString('id-ID', {
              day: '2-digit',
              month: '2-digit',
              year: 'numeric',
              hour: '2-digit',
              minute: '2-digit',
          });

    const disc = tx.discount as { name?: string } | undefined;
    const discName =
        disc && typeof disc === 'object' && disc.name ? String(disc.name) : 'Diskon';

    let logoBlock = '';
    if (r.show_logo && r.logo_url) {
        // Pastikan URL absolut agar bisa diakses dari iframe print (about:blank)
        const logoSrc = r.logo_url.startsWith('http')
            ? r.logo_url
            : `${window.location.origin}${r.logo_url.startsWith('/') ? '' : '/'}${r.logo_url}`;
        logoBlock = `<div class="struk-logo-wrap"><img class="struk-logo" src="${escapeHtml(logoSrc)}" alt="Logo" /></div>`;
    }

    const subtitleBlock = r.subtitle ? `<div class="struk-sub">${escapeHtml(r.subtitle)}</div>` : '';

    let metaLines = '';
    if (r.show_address && r.address.trim()) {
        metaLines += `<div class="struk-meta">${escapeHtml(r.address)}</div>`;
    }
    if (r.show_phone && r.phone.trim()) {
        metaLines += `<div class="struk-meta">Telp. ${escapeHtml(r.phone)}</div>`;
    }

    const rawItems = Array.isArray(tx.items) ? (tx.items as Array<Record<string, unknown>>) : [];
    const itemsHtml = rawItems
        .map((item) => {
            const name = escapeHtml(String(item.product_name ?? ''));
            const qty = Number(item.quantity ?? 0);
            const unit = formatCurrency(Number(item.unit_price ?? 0));
            const lineTot = formatCurrency(Number(item.total_price ?? 0));
            const freeQty = Number(item.free_quantity ?? 0);
            const freeLine = freeQty > 0
                ? `<div class="struk-small" style="color:#059669;font-weight:600">Gratis ${freeQty} unit</div>`
                : '';
            const mods = Array.isArray(item.modifiers)
                ? (item.modifiers as Array<{ label?: string; price?: number }>)
                : [];
            const modLines = mods.length
                ? mods
                      .map(
                          (m) =>
                              `<div class="struk-item-math struk-small" style="margin-top:0">+ ${escapeHtml(String(m.label ?? ''))} (+${formatCurrency(Number(m.price ?? 0))})</div>`,
                      )
                      .join('')
                : '';
            return `<div class="struk-item">
                <div class="struk-item-name">${name}</div>
                ${modLines}
                <div class="struk-item-math"><span>${qty} x ${unit}</span><span class="struk-item-tot">${lineTot}</span></div>
                ${freeLine}
            </div>`;
        })
        .join('');

    let discountBlock = '';
    if (Number(tx.discount_amount) > 0) {
        discountBlock = `<div class="struk-row"><span>${escapeHtml(discName)}</span><span>-${formatCurrency(Number(tx.discount_amount))}</span></div>`;
    }

    let fnbMeta = '';
    if (tx.order_type) {
        fnbMeta += `<div class="struk-row struk-small"><span>Pesanan</span><span>${escapeHtml(orderTypeLabel(String(tx.order_type)))}</span></div>`;
    }
    if (tx.table_reference && String(tx.table_reference).trim()) {
        fnbMeta += `<div class="struk-row struk-small"><span>Meja/No</span><span>${escapeHtml(String(tx.table_reference).trim())}</span></div>`;
    }

    let serviceBlock = '';
    if (Number(tx.service_charge_amount) > 0) {
        const p =
            tx.service_charge_percent != null && Number(tx.service_charge_percent) > 0
                ? ` (${Number(tx.service_charge_percent)}%)`
                : '';
        serviceBlock = `<div class="struk-row"><span>Biaya layanan${p}</span><span>${formatCurrency(Number(tx.service_charge_amount))}</span></div>`;
    }

    let taxBlock = '';
    if (Number(tx.tax_amount) > 0) {
        taxBlock = `<div class="struk-row"><span>${taxLabel}</span><span>${formatCurrency(Number(tx.tax_amount))}</span></div>`;
    }

    let tipBlock = '';
    if (Number(tx.tip_amount) > 0) {
        tipBlock = `<div class="struk-row"><span>Tip</span><span>${formatCurrency(Number(tx.tip_amount))}</span></div>`;
    }

    let splitBillBlock = '';
    const pdRaw = tx.payment_details as {
        split_bill?: { count?: number; shares?: number[] };
        pre_order?: { deposit_applied?: number; balance_due?: number; order_number?: string };
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
    } | null | undefined;
    const sbill = pdRaw?.split_bill;
    if (sbill && Array.isArray(sbill.shares) && sbill.shares.length >= 2) {
        const cnt = sbill.shares.length;
        splitBillBlock =
            `<div class="struk-small" style="margin-top:6px;font-weight:700">Bagi tagihan (${cnt} bagian)</div>` +
            sbill.shares
                .map(
                    (amt, i) =>
                        `<div class="struk-row struk-small"><span>Bagian ${i + 1}</span><span>${formatCurrency(Number(amt))}</span></div>`,
                )
                .join('');
    }

    let preOrderBlock = '';
    const po = pdRaw?.pre_order;
    if (po && Number(po.deposit_applied) > 0) {
        const bon = po.order_number ? ` (${escapeHtml(String(po.order_number))})` : '';
        preOrderBlock =
            `<div class="struk-small" style="margin-top:6px;font-weight:700">Pre-order${bon}</div>` +
            `<div class="struk-row struk-small"><span>DP dipakai</span><span>-${formatCurrency(Number(po.deposit_applied))}</span></div>` +
            `<div class="struk-row"><span>Sisa dibayar</span><span>${formatCurrency(Number(po.balance_due ?? 0))}</span></div>`;
    }

    let pharmacyBlock = '';
    const ph = pdRaw?.pharmacy;
    if (ph && (ph.sale_reference || ph.has_prescription_lines || ph.has_limited_lines)) {
        const tags: string[] = [];
        if (ph.has_prescription_lines) {
            tags.push('Resep');
        }
        if (ph.has_limited_lines) {
            tags.push('Terbatas');
        }
        const tagStr = tags.length ? escapeHtml(tags.join(', ')) : '';
        pharmacyBlock = `<div class="struk-small" style="margin-top:6px;font-weight:700">Penjualan apotek${tagStr ? ` (${tagStr})` : ''}</div>`;
        const prescriptionNo = ph.prescription?.number || ph.prescription?.reference || ph.sale_reference;
        if (prescriptionNo) {
            pharmacyBlock += `<div class="struk-row struk-small"><span>No. resep</span><span style="text-align:right;max-width:58%;word-break:break-word">${escapeHtml(String(prescriptionNo))}</span></div>`;
        }
        if (ph.prescription?.patient_name) {
            pharmacyBlock += `<div class="struk-row struk-small"><span>Pasien</span><span style="text-align:right;max-width:58%;word-break:break-word">${escapeHtml(ph.prescription.patient_name)}</span></div>`;
        }
        if (ph.prescription?.doctor_name) {
            pharmacyBlock += `<div class="struk-row struk-small"><span>Dokter</span><span style="text-align:right;max-width:58%;word-break:break-word">${escapeHtml(ph.prescription.doctor_name)}</span></div>`;
        }
    }

    const pharmacyDisclaimer = ctx.pharmacyEnabled
        ? `<div class="struk-small" style="margin-top:8px;text-align:center;line-height:1.35;opacity:.9">Obat dipakai sesuai resep dan aturan. Software ini tidak menggantikan kewajiban hukum atau kewenangan apoteker.</div>`
        : '';

    const payLabel = paymentMethodLabel(String(tx.payment_method));

    const barberName = String((tx.barber as { name?: string } | undefined)?.name ?? '').trim();
    const barberRow = barberName
        ? `<div class="struk-row struk-small"><span>Teknisi</span><span>${escapeHtml(barberName)}</span></div>`
        : '';
    const paymentLines = Array.isArray(tx.payments) ? (tx.payments as { method?: string; amount?: number | string }[]) : [];
    let payBlock = '';
    if (paymentLines.length > 1) {
        // Split tender: uang tunai diterima = total dibayar − porsi non-tunai.
        const nonCashTotal = paymentLines.filter((line) => line.method !== 'cash').reduce((sum, line) => sum + Number(line.amount ?? 0), 0);
        for (const line of paymentLines) {
            const method = String(line.method ?? '');
            const amount = method === 'cash' ? Number(tx.amount_paid ?? 0) - nonCashTotal : Number(line.amount ?? 0);
            payBlock += `<div class="struk-row"><span>Bayar (${escapeHtml(paymentMethodLabel(method))})</span><span>${formatCurrency(amount)}</span></div>`;
        }
    } else {
        payBlock = `<div class="struk-row"><span>Bayar (${escapeHtml(payLabel)})</span><span>${formatCurrency(Number(tx.amount_paid))}</span></div>`;
    }
    if (Number(tx.change_amount) > 0) {
        payBlock += `<div class="struk-row struk-bold"><span>Kembali</span><span>${formatCurrency(Number(tx.change_amount))}</span></div>`;
    }

    const foot2 = r.footer_line2.trim()
        ? `<div class="struk-foot2">${escapeHtml(r.footer_line2.trim())}</div>`
        : '';

    return `
            <div class="struk">
                ${logoBlock}
                <div class="struk-title">${escapeHtml(r.header_name)}</div>
                ${subtitleBlock}
                ${metaLines}
                <div class="struk-rule"></div>
                <div class="struk-success">Transaksi berhasil</div>
                <div class="struk-txn">${escapeHtml(String(tx.transaction_number ?? ''))}</div>
                <div class="struk-rule"></div>
                <div class="struk-row struk-small"><span>No. Struk</span><span>${escapeHtml(String(tx.transaction_number ?? ''))}</span></div>
                <div class="struk-row struk-small"><span>Tgl/Jam</span><span>${escapeHtml(tglStr)}</span></div>
                <div class="struk-row struk-small"><span>Kasir</span><span>${escapeHtml(String((tx.user as { name?: string } | undefined)?.name ?? ''))}</span></div>
                ${barberRow}
                ${fnbMeta}
                <div class="struk-rule"></div>
                ${itemsHtml}
                <div class="struk-rule"></div>
                <div class="struk-row"><span>Subtotal</span><span>${formatCurrency(Number(tx.subtotal))}</span></div>
                ${discountBlock}
                ${serviceBlock}
                ${taxBlock}
                ${tipBlock}
                <div class="struk-row struk-total"><span>TOTAL</span><span>${formatCurrency(Number(tx.total_amount))}</span></div>
                ${preOrderBlock}
                ${splitBillBlock}
                ${pharmacyBlock}
                <div class="struk-rule"></div>
                ${payBlock}
                <div class="struk-rule"></div>
                ${pharmacyDisclaimer}
                <div class="struk-foot">${escapeHtml(r.footer)}</div>
                ${foot2}
                <div class="struk-printed">Dicetak: ${escapeHtml(new Date().toLocaleString('id-ID'))}</div>
            </div>`;
}

function buildThermalReceiptPrintCss(mm: 58 | 80, baseFs: number, smallFs: number): string {
    return `
@page { margin: 0 !important; size: ${mm}mm auto; }
@page :first { margin-top: 0 !important; margin-bottom: 0 !important; }
html, body {
    width: ${mm}mm !important;
    max-width: ${mm}mm !important;
    margin: 0 !important;
    padding: 0 !important;
    background: #fff !important;
}
html, body, header, footer { margin: 0 !important; padding: 0 !important; height: 0 !important; }
.struk-print-root { display: block; margin: 0; padding: 0; }
.struk {
    box-sizing: border-box;
    width: ${mm}mm !important;
    max-width: ${mm}mm !important;
    margin: 0 !important;
    padding: ${mm === 58 ? '1mm 2mm 3mm' : '2.5mm 3mm 4mm'} !important;
    font-family: ui-monospace, "Cascadia Mono", "Consolas", "Courier New", monospace;
    font-size: ${baseFs}px;
    line-height: 1.35;
    color: #000;
    background: #fff;
}
.struk-success {
    text-align: center;
    font-weight: 800;
    font-size: ${baseFs + 2}px;
    letter-spacing: 0.02em;
    margin-bottom: 2px;
}
.struk-txn {
    text-align: center;
    font-family: ui-monospace, monospace;
    font-size: ${smallFs}px;
    margin-top: 2px;
    margin-bottom: 4px;
    word-break: break-all;
}
.struk-logo-wrap { text-align: center; margin-bottom: 6px; margin-top: 4px; }
.struk-logo { display: block; margin: 0 auto; max-height: ${mm === 58 ? '50px' : '60px'}; max-width: ${mm === 58 ? '80%' : '90%'}; width: auto; height: auto; object-fit: contain; }
.struk-title {
    text-align: center; font-weight: 700; font-size: ${baseFs + 1}px;
    text-transform: uppercase; letter-spacing: 0.02em; margin-bottom: 2px;
}
.struk-sub { text-align: center; font-size: ${smallFs}px; margin-bottom: 3px; }
.struk-meta { text-align: center; font-size: ${smallFs}px; white-space: pre-wrap; }
.struk-rule {
    border: 0; border-top: 1px dashed #000;
    margin: 5px 0;
}
.struk-row {
    display: flex; justify-content: space-between; align-items: baseline;
    gap: 6px; margin: 2px 0;
}
.struk-small { font-size: ${smallFs}px; }
.struk-item { margin: 5px 0 6px; }
.struk-item-name { font-weight: 600; word-wrap: break-word; }
.struk-item-math {
    display: flex; justify-content: space-between; font-size: ${smallFs}px; margin-top: 1px;
}
.struk-item-tot { font-weight: 700; }
.struk-total { font-weight: 800; font-size: ${baseFs + 1}px; margin-top: 4px; padding-top: 3px; border-top: 1px solid #000; }
.struk-bold { font-weight: 700; }
.struk-foot, .struk-foot2 {
    text-align: center; font-size: ${smallFs}px; margin: 4px 0 2px;
}
.struk-foot2 { font-size: ${smallFs - 1}px; }
.struk-printed {
    text-align: center; font-size: ${smallFs - 1}px; color: #444; margin-top: 6px;
}
@media print {
    html, body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}`;
}

async function convertImageToBase64(img: HTMLImageElement): Promise<string> {
    return new Promise((resolve, reject) => {
        const canvas = document.createElement('canvas');
        const ctx = canvas.getContext('2d');
        if (!ctx) {
            reject(new Error('Canvas not supported'));
            return;
        }
        
        canvas.width = img.naturalWidth;
        canvas.height = img.naturalHeight;
        ctx.drawImage(img, 0, 0);
        
        try {
            const dataUrl = canvas.toDataURL('image/png');
            resolve(dataUrl);
        } catch (e) {
            reject(e);
        }
    });
}

function printWhenImagesReady(root: HTMLElement, runPrint: () => void): void {
    const imgs = [...root.querySelectorAll('img')] as HTMLImageElement[];
    let pending = 0;
    
    const processImages = async () => {
        for (const img of imgs) {
            if (!img.complete || img.naturalWidth === 0) {
                continue;
            }
            
            try {
                // Konversi gambar ke base64 untuk memastikan bisa di-print
                const base64 = await convertImageToBase64(img);
                img.src = base64;
            } catch (e) {
                console.warn('Failed to convert image to base64:', e);
            }
        }
        
        // Delay tambahan untuk printer thermal
        setTimeout(() => {
            requestAnimationFrame(runPrint);
        }, 500);
    };
    
    for (const img of imgs) {
        if (img.complete && img.naturalWidth > 0) {
            continue;
        }
        pending++;
        const done = () => {
            pending--;
            if (pending <= 0) {
                processImages();
            }
        };
        img.addEventListener('load', done, { once: true });
        img.addEventListener('error', done, { once: true });
    }
    
    if (pending <= 0) {
        processImages();
    }
}

export function printThermalReceipt(
    tx: Record<string, unknown>,
    r: ReceiptConfig,
    ctx: ThermalReceiptContext,
): void {
    const mm: 58 | 80 = r.paper_width_mm === 58 ? 58 : 80;
    const baseFs = mm === 58 ? 9 : 11;
    const smallFs = mm === 58 ? 8 : 10;
    const inner = buildThermalReceiptInnerHtml(tx, r, ctx);
    const printCss = buildThermalReceiptPrintCss(mm, baseFs, smallFs);
    const txnTitle = escapeHtml(String(tx.transaction_number ?? 'struk'));
    const fullHtml = `<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"/><title>Struk ${txnTitle}</title><style>${printCss}</style></head><body><div class="struk-print-root">${inner}</div></body></html>`;

    const iframe = document.createElement('iframe');
    iframe.setAttribute('title', 'Cetak struk');
    iframe.setAttribute('aria-hidden', 'true');
    iframe.style.cssText =
        'position:fixed;right:0;bottom:0;width:0;height:0;border:0;opacity:0;pointer-events:none;';
    document.body.appendChild(iframe);

    const idoc = iframe.contentDocument;
    const iwin = iframe.contentWindow;
    if (!idoc || !iwin) {
        iframe.remove();
        return;
    }
    idoc.open();
    idoc.write(fullHtml);
    idoc.close();

    const root = idoc.body.querySelector('.struk-print-root');
    if (!root) {
        iframe.remove();
        return;
    }

    printWhenImagesReady(root as HTMLElement, () => {
        iwin.focus();
        iwin.print();
        setTimeout(() => iframe.remove(), 1000);
    });
}
