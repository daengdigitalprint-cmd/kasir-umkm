<script setup lang="ts">
/**
 * BarcodeGenerator.vue
 *
 * Generate barcode Code 128 untuk produk yang belum memiliki barcode.
 * - Kode unik di-generate otomatis (prefix toko + timestamp + random)
 * - Render ke SVG via JsBarcode
 * - Tombol salin kode dan cetak label
 *
 * Props:
 *   productId   : number          — id produk (untuk prefix kode)
 *   productName : string          — nama produk (tampil di label cetak)
 *   storePrefix : string          — prefix 2-3 huruf kode toko (opsional)
 *   initialCode : string          — jika produk sudah punya barcode, tampilkan ini
 *
 * Emits:
 *   generated(code: string)   — kode barcode baru siap digunakan
 *   saved(code: string)       — setelah pengguna menekan "Simpan ke produk"
 */
import JsBarcode from 'jsbarcode';
import { Check, Copy, Printer, RefreshCw, Save } from 'lucide-vue-next';
import { nextTick, onMounted, ref, watch } from 'vue';

const props = withDefaults(
    defineProps<{
        productId?: number | null;
        productName?: string;
        storePrefix?: string;
        initialCode?: string;
    }>(),
    {
        productId: null,
        productName: '',
        storePrefix: 'PRD',
        initialCode: '',
    },
);

const emit = defineEmits<{
    generated: [code: string];
    saved: [code: string];
}>();

// ─── State
const barcodeCode = ref('');
const svgRef = ref<SVGSVGElement | null>(null);
const copied = ref(false);
const renderError = ref('');

// ─── Generate kode unik Code 128
function generateCode(): string {
    const prefix = props.storePrefix.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 4) || 'PRD';
    const ts = Date.now().toString(36).toUpperCase(); // base-36 timestamp
    const rnd = Math.floor(Math.random() * 9999)
        .toString()
        .padStart(4, '0');
    // Format: PRD-TS-RNDM (maks 20 karakter, valid Code 128)
    return `${prefix}${ts}${rnd}`.slice(0, 20);
}

// ─── Render SVG
async function renderBarcode(code: string) {
    renderError.value = '';
    await nextTick();
    if (!svgRef.value || !code) return;
    try {
        JsBarcode(svgRef.value, code, {
            format: 'CODE128',
            width: 2,
            height: 60,
            displayValue: true,
            fontSize: 13,
            margin: 10,
            background: '#ffffff',
            lineColor: '#000000',
        });
    } catch (e) {
        renderError.value = e instanceof Error ? e.message : 'Gagal render barcode';
    }
}

// ─── Generate baru
async function handleGenerate() {
    barcodeCode.value = generateCode();
    await renderBarcode(barcodeCode.value);
    emit('generated', barcodeCode.value);
}

// ─── Salin ke clipboard
async function copyCode() {
    if (!barcodeCode.value) return;
    try {
        await navigator.clipboard.writeText(barcodeCode.value);
        copied.value = true;
        setTimeout(() => (copied.value = false), 2000);
    } catch {
        // Fallback: execCommand
        const el = document.createElement('textarea');
        el.value = barcodeCode.value;
        document.body.appendChild(el);
        el.select();
        document.execCommand('copy');
        document.body.removeChild(el);
        copied.value = true;
        setTimeout(() => (copied.value = false), 2000);
    }
}

// ─── Cetak label barcode
function printBarcode() {
    if (!svgRef.value || !barcodeCode.value) return;
    const svgHtml = svgRef.value.outerHTML;
    const win = window.open('', '_blank', 'width=400,height=300');
    if (!win) return;
    win.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>Label Barcode — ${props.productName || barcodeCode.value}</title>
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body { font-family: sans-serif; display: flex; flex-direction: column;
                       align-items: center; justify-content: center; min-height: 100vh;
                       padding: 16px; background: #fff; }
                .label { border: 1px solid #ccc; border-radius: 6px;
                         padding: 12px 16px; text-align: center; max-width: 280px; }
                .product-name { font-size: 12px; font-weight: 600; color: #1e293b;
                                margin-bottom: 6px; word-break: break-word; }
                svg { max-width: 100%; height: auto; display: block; margin: 0 auto; }
                @media print { @page { margin: 0; } body { padding: 4px; } }
            </style>
        </head>
        <body>
            <div class="label">
                ${props.productName ? `<p class="product-name">${props.productName}</p>` : ''}
                ${svgHtml}
            </div>
            <script>window.onload = function() { window.print(); window.close(); }<\/script>
        </body>
        </html>
    `);
    win.document.close();
}

// ─── Simpan ke produk
function handleSave() {
    if (!barcodeCode.value) return;
    emit('saved', barcodeCode.value);
}

// ─── Init: jika ada kode awal, render langsung
onMounted(async () => {
    if (props.initialCode) {
        barcodeCode.value = props.initialCode;
        await renderBarcode(props.initialCode);
    }
});

watch(
    () => props.initialCode,
    async (val) => {
        if (val && val !== barcodeCode.value) {
            barcodeCode.value = val;
            await renderBarcode(val);
        }
    },
);
</script>

<template>
    <div class="barcode-generator space-y-3">
        <!-- Tombol aksi -->
        <div class="flex flex-wrap gap-2">
            <button
                type="button"
                class="inline-flex items-center gap-1.5 rounded-lg border border-teal-200 bg-teal-50 px-3 py-1.5 text-sm font-medium text-teal-700 transition-colors hover:bg-teal-100"
                :aria-label="barcodeCode ? 'Generate ulang barcode baru' : 'Generate barcode otomatis'"
                @click="handleGenerate"
            >
                <RefreshCw class="h-4 w-4" />
                {{ barcodeCode ? 'Generate ulang' : 'Generate barcode' }}
            </button>

            <button
                v-if="barcodeCode"
                type="button"
                class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-slate-50 px-3 py-1.5 text-sm font-medium text-slate-700 transition-colors hover:bg-slate-100"
                aria-label="Salin kode barcode"
                @click="copyCode"
            >
                <Check v-if="copied" class="h-4 w-4 text-green-600" />
                <Copy v-else class="h-4 w-4" />
                {{ copied ? 'Tersalin!' : 'Salin kode' }}
            </button>

            <button
                v-if="barcodeCode"
                type="button"
                class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-slate-50 px-3 py-1.5 text-sm font-medium text-slate-700 transition-colors hover:bg-slate-100"
                aria-label="Cetak label barcode"
                @click="printBarcode"
            >
                <Printer class="h-4 w-4" />
                Cetak label
            </button>

            <button
                v-if="barcodeCode"
                type="button"
                class="inline-flex items-center gap-1.5 rounded-lg border border-blue-200 bg-blue-50 px-3 py-1.5 text-sm font-medium text-blue-700 transition-colors hover:bg-blue-100"
                aria-label="Simpan barcode ke produk"
                @click="handleSave"
            >
                <Save class="h-4 w-4" />
                Simpan ke produk
            </button>
        </div>

        <!-- Preview barcode -->
        <div
            v-if="barcodeCode"
            class="inline-block rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm"
        >
            <p v-if="renderError" class="text-sm text-red-600">{{ renderError }}</p>
            <svg
                v-show="!renderError"
                ref="svgRef"
                aria-label="Preview barcode Code 128"
                role="img"
            />
            <p class="mt-1 text-center text-xs text-slate-400">Code 128 · {{ barcodeCode }}</p>
        </div>

        <p v-if="!barcodeCode" class="text-xs text-slate-400">
            Klik &ldquo;Generate barcode&rdquo; untuk membuat kode unik otomatis.
        </p>
    </div>
</template>
