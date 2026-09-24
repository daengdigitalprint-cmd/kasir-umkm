/**
 * Utility Cetak Label Cup / Stiker Pesanan per item (F&B / Warkop / Cafe)
 */

function escapeHtml(text: string): string {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

export interface CupLabelItem {
    product_name: string;
    quantity: number | string;
    variant_label?: string | null;
    modifiers?: Array<{ label?: string }> | null;
    notes?: string | null;
}

export function printCupLabels(opts: {
    storeName: string;
    transactionNumber: string;
    completedAt?: string | null;
    orderType?: string | null;
    tableReference?: string | null;
    customerName?: string | null;
    items: CupLabelItem[];
    labelWidthMm?: number;
    labelHeightMm?: number;
}): void {
    const w = opts.labelWidthMm || 50;
    const h = opts.labelHeightMm || 30;

    const ORDER_TYPE_LABELS: Record<string, string> = {
        dine_in: 'Dine-In',
        takeaway: 'Takeaway',
        delivery: 'Delivery',
    };

    const rawDate = opts.completedAt;
    const tgl = rawDate ? new Date(rawDate) : new Date();
    const tglStr = Number.isFinite(tgl.getTime())
        ? tgl.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })
        : '';

    const otRaw = opts.orderType;
    const ot = otRaw && ORDER_TYPE_LABELS[otRaw] ? ORDER_TYPE_LABELS[otRaw] : otRaw || '';

    // Hitung total unit cup/item
    let totalCups = 0;
    const expandedItems: Array<{
        product_name: string;
        variant_label?: string | null;
        modifiers?: Array<{ label?: string }> | null;
        notes?: string | null;
        cupIndex: number;
    }> = [];

    opts.items.forEach((item) => {
        const q = typeof item.quantity === 'number' ? Math.max(1, Math.floor(item.quantity)) : Math.max(1, parseInt(String(item.quantity)) || 1);
        for (let i = 1; i <= q; i++) {
            totalCups++;
            expandedItems.push({
                product_name: item.product_name,
                variant_label: item.variant_label,
                modifiers: item.modifiers,
                notes: item.notes,
                cupIndex: 0, // diupdate nanti
            });
        }
    });

    expandedItems.forEach((it, idx) => {
        it.cupIndex = idx + 1;
    });

    const labelsHtml = expandedItems
        .map((item) => {
            const name = escapeHtml(String(item.product_name ?? ''));
            const variant = item.variant_label ? escapeHtml(String(item.variant_label)) : '';
            const mods = Array.isArray(item.modifiers) ? item.modifiers : [];
            const modStr = mods
                .filter((m) => m && String(m.label ?? '').trim())
                .map((m) => escapeHtml(String(m.label ?? '').trim()))
                .join(', ');
            const notes = item.notes ? escapeHtml(String(item.notes)) : '';

            const tableStr = opts.tableReference ? `Meja: ${escapeHtml(String(opts.tableReference))}` : '';
            const custStr = opts.customerName ? escapeHtml(String(opts.customerName)) : '';

            return `
        <div class="cup-label-page">
            <div class="cup-header">
                <span class="cup-store">${escapeHtml(opts.storeName)}</span>
                <span class="cup-count">${item.cupIndex}/${totalCups}</span>
            </div>
            <div class="cup-meta">
                <span>#${escapeHtml(opts.transactionNumber.slice(-6))} ${ot ? `• ${ot}` : ''}</span>
                <span>${tglStr}</span>
            </div>
            ${tableStr || custStr ? `<div class="cup-cust">${custStr} ${tableStr ? `(${tableStr})` : ''}</div>` : ''}
            <div class="cup-divider"></div>
            <div class="cup-title">${name} ${variant ? `<span class="cup-variant">[${variant}]</span>` : ''}</div>
            ${modStr ? `<div class="cup-detail">+ ${modStr}</div>` : ''}
            ${notes ? `<div class="cup-notes">* ${notes}</div>` : ''}
        </div>
        `;
        })
        .join('');

    const printContent = document.createElement('div');
    printContent.className = 'cup-label-print-root';
    printContent.innerHTML = labelsHtml;

    const printStyles = document.createElement('style');
    printStyles.textContent = `
      @media print {
        @page { margin: 0; size: ${w}mm ${h}mm; }
        html, body {
          width: ${w}mm !important;
          height: ${h}mm !important;
          margin: 0 !important;
          padding: 0 !important;
          background: #fff !important;
        }
        body > *:not(.cup-label-print-root) {
          display: none !important;
        }
        .cup-label-print-root {
          display: block !important;
          width: 100% !important;
          margin: 0 !important;
          padding: 0 !important;
        }
        .cup-label-page {
          box-sizing: border-box !important;
          width: ${w}mm !important;
          height: ${h}mm !important;
          padding: 2mm 3mm !important;
          font-family: system-ui, -apple-system, sans-serif !important;
          color: #000 !important;
          page-break-after: always;
          overflow: hidden;
          display: flex;
          flex-direction: column;
          justify-content: flex-start;
        }
        .cup-header {
          display: flex;
          justify-content: space-between;
          align-items: center;
          font-size: 9px;
          font-weight: 800;
          line-height: 1.1;
        }
        .cup-store {
          white-space: nowrap;
          overflow: hidden;
          text-overflow: ellipsis;
          max-width: 70%;
        }
        .cup-count {
          font-size: 10px;
          font-weight: 900;
        }
        .cup-meta {
          display: flex;
          justify-content: space-between;
          font-size: 8px;
          color: #222;
          margin-top: 1px;
        }
        .cup-cust {
          font-size: 9px;
          font-weight: 700;
          margin-top: 1px;
          white-space: nowrap;
          overflow: hidden;
          text-overflow: ellipsis;
        }
        .cup-divider {
          border-top: 1px dashed #000;
          margin: 2px 0;
        }
        .cup-title {
          font-size: 11px;
          font-weight: 800;
          line-height: 1.2;
          word-break: break-word;
        }
        .cup-variant {
          font-size: 9px;
          font-weight: 700;
        }
        .cup-detail {
          font-size: 8px;
          font-weight: 600;
          margin-top: 1px;
          line-height: 1.15;
        }
        .cup-notes {
          font-size: 8px;
          font-style: italic;
          margin-top: 1px;
        }
      }
    `;

    document.head.appendChild(printStyles);
    document.body.appendChild(printContent);

    window.print();

    setTimeout(() => {
        document.head.removeChild(printStyles);
        document.body.removeChild(printContent);
    }, 1000);
}
