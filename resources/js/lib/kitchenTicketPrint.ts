/**
 * Cetak tiket dapur (KOT) — Fase D5, tanpa harga, untuk koordinasi dapur.
 */

function escapeHtml(text: string): string {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

const ORDER_TYPE_LABELS: Record<string, string> = {
    dine_in: 'Makan di tempat',
    takeaway: 'Bungkus',
    delivery: 'Antar',
};

export interface KitchenTicketItem {
    product_name: string;
    quantity: number | string;
    modifiers?: Array<{ label?: string }> | null;
}

export function printKitchenTicket(opts: {
    storeName: string;
    transactionNumber: string;
    completedAt?: string | null;
    orderType?: string | null;
    tableReference?: string | null;
    customerName?: string | null;
    cashierName?: string | null;
    items: KitchenTicketItem[];
    paperWidthMm?: 58 | 80;
}): void {
    const mm = opts.paperWidthMm === 58 ? 58 : 80;
    const baseFs = mm === 58 ? 10 : 12;
    const smallFs = mm === 58 ? 9 : 10;

    const rawDate = opts.completedAt;
    const tgl = rawDate ? new Date(rawDate) : new Date();
    const tglStr = Number.isFinite(tgl.getTime())
        ? tgl.toLocaleString('id-ID', {
              day: '2-digit',
              month: '2-digit',
              year: 'numeric',
              hour: '2-digit',
              minute: '2-digit',
          })
        : '—';

    const otRaw = opts.orderType;
    const ot =
        otRaw && ORDER_TYPE_LABELS[otRaw] ? ORDER_TYPE_LABELS[otRaw] : otRaw ? otRaw : '—';

    const itemsHtml = opts.items
        .map((item) => {
            const name = escapeHtml(String(item.product_name ?? ''));
            const q = typeof item.quantity === 'number' ? item.quantity : parseFloat(String(item.quantity));
            const qtyStr = Number.isFinite(q) ? String(q) : String(item.quantity ?? '');
            const mods = Array.isArray(item.modifiers) ? item.modifiers : [];
            const modLines = mods
                .filter((m) => m && String(m.label ?? '').trim())
                .map((m) => `<div class="kot-mod">• ${escapeHtml(String(m.label ?? '').trim())}</div>`)
                .join('');
            return `<div class="kot-item">
        <div class="kot-item-head"><span class="kot-qty">${escapeHtml(qtyStr)}×</span><span class="kot-name">${name}</span></div>
        ${modLines}
      </div>`;
        })
        .join('');

    const tableRow =
        opts.tableReference && String(opts.tableReference).trim()
            ? `<div class="kot-row"><span>Meja/No</span><span>${escapeHtml(String(opts.tableReference).trim())}</span></div>`
            : '';

    const custRow =
        opts.customerName && String(opts.customerName).trim()
            ? `<div class="kot-row"><span>Pelanggan</span><span>${escapeHtml(String(opts.customerName).trim())}</span></div>`
            : '';

    const inner = `
    <div class="kot">
      <div class="kot-banner">KOT — DAPUR</div>
      <div class="kot-store">${escapeHtml(opts.storeName)}</div>
      <div class="kot-rule"></div>
      <div class="kot-row kot-mono"><span>No.</span><span>${escapeHtml(opts.transactionNumber)}</span></div>
      <div class="kot-row"><span>Waktu</span><span>${escapeHtml(tglStr)}</span></div>
      <div class="kot-row"><span>Pesanan</span><span>${escapeHtml(ot)}</span></div>
      ${tableRow}
      ${custRow}
      <div class="kot-row"><span>Kasir</span><span>${escapeHtml(String(opts.cashierName ?? '—'))}</span></div>
      <div class="kot-rule"></div>
      <div class="kot-items-label">ITEM</div>
      ${itemsHtml}
      <div class="kot-rule"></div>
      <div class="kot-note">Tidak untuk pembayaran — kerjakan sesuai urutan jika antrian ramai</div>
      <div class="kot-foot">Dicetak ${escapeHtml(new Date().toLocaleString('id-ID'))}</div>
    </div>`;

    const printContent = document.createElement('div');
    printContent.className = 'kot-print-root';
    printContent.innerHTML = inner;

    const printStyles = document.createElement('style');
    printStyles.textContent = `
      @media print {
        @page { margin: 0; size: ${mm}mm auto; }
        html, body {
          width: ${mm}mm !important;
          max-width: ${mm}mm !important;
          height: auto !important;
          min-height: 0 !important;
          margin: 0 !important;
          padding: 0 !important;
          background: #fff !important;
          overflow: visible !important;
        }
        body > *:not(.kot-print-root) {
          display: none !important;
        }
        .kot-print-root {
          display: block !important;
          width: 100% !important;
          margin: 0 !important;
          padding: 0 !important;
        }
        .kot {
          box-sizing: border-box !important;
          width: ${mm}mm !important;
          max-width: ${mm}mm !important;
          margin: 0 !important;
          padding: 2.5mm 3mm 4mm !important;
          font-family: ui-monospace, "Cascadia Mono", "Consolas", "Courier New", monospace !important;
          font-size: ${baseFs}px !important;
          line-height: 1.35 !important;
          color: #000 !important;
          background: #fff !important;
        }
        .kot-banner {
          text-align: center;
          font-weight: 800;
          font-size: ${baseFs + 2}px;
          letter-spacing: 0.06em;
          margin-bottom: 4px;
        }
        .kot-store {
          text-align: center;
          font-weight: 700;
          margin-bottom: 2px;
        }
        .kot-rule {
          border: 0;
          border-top: 2px solid #000 !important;
          margin: 6px 0;
        }
        .kot-row {
          display: flex;
          justify-content: space-between;
          gap: 8px;
          margin: 3px 0;
        }
        .kot-mono span:last-child {
          font-weight: 700;
        }
        .kot-items-label {
          font-weight: 800;
          margin: 4px 0 2px;
          letter-spacing: 0.04em;
        }
        .kot-item {
          margin: 8px 0;
          padding-bottom: 4px;
          border-bottom: 1px dashed #333;
        }
        .kot-item:last-of-type {
          border-bottom: 0;
        }
        .kot-item-head {
          display: flex;
          gap: 6px;
          align-items: baseline;
        }
        .kot-qty {
          font-weight: 800;
          flex-shrink: 0;
        }
        .kot-name {
          font-weight: 700;
          word-break: break-word;
        }
        .kot-mod {
          font-size: ${smallFs}px;
          padding-left: 1.2em;
          margin-top: 2px;
        }
        .kot-note {
          text-align: center;
          font-size: ${smallFs}px;
          font-weight: 600;
          margin: 6px 0;
        }
        .kot-foot {
          text-align: center;
          font-size: ${smallFs - 1}px;
          color: #333 !important;
          margin-top: 4px;
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
