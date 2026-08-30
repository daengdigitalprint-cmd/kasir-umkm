<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Separator } from '@/components/ui/separator';
import {
    Printer,
    Download,
    ArrowLeft,
    Store,
    User,
    CreditCard,
    Package,
    ChefHat,
    Bluetooth,
} from 'lucide-vue-next';
import { printKitchenTicket } from '@/lib/kitchenTicketPrint';
import { receiptLinesToEscPos } from '@/lib/escPosEncode';
import { buildReceiptPlainLinesFromInvoicePage } from '@/lib/receiptPlainText';
import { modalError, toastInfo, toastSuccess } from '@/lib/swal';
import { computed, onMounted } from 'vue';
import { usePrinter } from '@/composables/usePrinter';

interface TransactionItem {
    id: number;
    product_name: string;
    product_sku: string;
    quantity: number;
    unit_price: number;
    total_price: number;
    modifiers?: Array<{ key?: string; label?: string; price?: number }> | null;
}

interface Transaction {
    id: number;
    transaction_number: string;
    status: string;
    payment_method: string;
    subtotal: number;
    tax_amount: number;
    discount_amount: number;
    service_charge_amount?: number;
    service_charge_percent?: number | null;
    tip_amount?: number;
    order_type?: string | null;
    table_reference?: string | null;
    total_amount: number;
    amount_paid: number;
    change_amount: number;
    /** Rincian pembayaran tambahan: bagi tagihan (F&B), pre-order / DP, dll. */
    payment_details?: {
        split_bill?: { count?: number; shares?: number[] };
        pre_order?: {
            id?: number;
            order_number?: string;
            deposit_applied?: number;
            balance_due?: number;
        };
        pharmacy?: {
            sale_reference?: string | null;
            has_prescription_lines?: boolean;
            has_limited_lines?: boolean;
        };
    } | null;
    completed_at: string;
    items: TransactionItem[];
    store: {
        name: string;
        address?: string;
        phone?: string;
        email?: string;
    };
    customer?: {
        name: string;
        phone?: string;
        email?: string;
    };
    user: {
        name: string;
    };
}

interface Props {
    transaction: Transaction;
    fnb_enabled?: boolean;
    pharmacy_enabled?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    fnb_enabled: false,
    pharmacy_enabled: false,
});

const { transaction } = props;

const splitBillShares = computed(() => {
    const pd = transaction.payment_details;
    const shares = pd?.split_bill?.shares;
    if (!Array.isArray(shares) || shares.length < 2) {
        return null;
    }
    return shares.map((n) => Number(n));
});

const preOrderSettlement = computed(() => {
    const po = transaction.payment_details?.pre_order;
    if (!po || Number(po.deposit_applied) <= 0) {
        return null;
    }
    return {
        orderNumber: po.order_number ?? null,
        depositApplied: Number(po.deposit_applied),
        balanceDue: Number(po.balance_due ?? 0),
    };
});

const pharmacySaleAudit = computed(() => {
    const ph = transaction.payment_details?.pharmacy;
    if (!ph || (!ph.sale_reference && !ph.has_prescription_lines && !ph.has_limited_lines)) {
        return null;
    }
    return {
        reference: ph.sale_reference ? String(ph.sale_reference) : null,
        hasPrescription: !!ph.has_prescription_lines,
        hasLimited: !!ph.has_limited_lines,
    };
});

// Format currency helper
const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('id-ID', {
        style: 'decimal',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(amount);
};

// Format date helper
const formatDate = (dateString: string) => {
    const date = new Date(dateString);
    return date.toLocaleDateString('id-ID', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
};

// Print invoice
const printInvoice = () => {
    window.print();
};

const {
    isAvailable: canPrintBluetooth,
    isConnected: isPrinterConnected,
    isPrinting: isBluetoothPrinting,
    initialize: initializePrinter,
    printEscPosBytes,
    forgetPrinter,
} = usePrinter();

onMounted(() => {
    void initializePrinter();
});

async function printInvoiceBluetooth(forcePicker = false) {
    if (!canPrintBluetooth.value) {
        await modalError(
            'Bluetooth tidak tersedia',
            'Gunakan aplikasi Android (APK) untuk printer Classic, atau Chrome + printer BLE.',
        );
        return;
    }
    try {
        const lines = buildReceiptPlainLinesFromInvoicePage(props.transaction, props.pharmacy_enabled);
        const bytes = receiptLinesToEscPos(lines);
        const result = await printEscPosBytes(bytes, 'cashier');
        if (result.success) {
            await toastSuccess('Struk Bluetooth terkirim ke printer.', 4500);
        } else {
            await modalError('Gagal cetak Bluetooth', result.error ?? 'Terjadi kesalahan');
        }
    } catch (e) {
        const msg = e instanceof Error ? e.message : String(e);
        await modalError('Gagal cetak Bluetooth', msg);
    }
}

function forgetBluetoothPrinter() {
    forgetPrinter();
    void toastInfo('Printer Bluetooth di-reset; cetak berikutnya akan minta pilih perangkat lagi.', 4000);
}

const printKitchenTicketFromInvoice = () => {
    const tx = props.transaction;
    printKitchenTicket({
        storeName: tx.store.name,
        transactionNumber: tx.transaction_number,
        completedAt: tx.completed_at,
        orderType: tx.order_type,
        tableReference: tx.table_reference,
        customerName: tx.customer?.name ?? null,
        cashierName: tx.user.name,
        items: tx.items.map((i) => ({
            product_name: i.product_name,
            quantity: i.quantity,
            modifiers: i.modifiers,
        })),
        paperWidthMm: 80,
    });
};

const downloadPDF = () => {
    window.location.href = `/invoice/${transaction.id}/pdf`;
};

// Get payment method display name
const getPaymentMethodName = (method: string) => {
    const methods: Record<string, string> = {
        'cash': 'Tunai',
        'card': 'Kartu Kredit/Debit',
        'ewallet': 'E-Wallet',
        'transfer': 'Transfer Bank',
        'other': 'Lainnya',
    };
    return methods[method as keyof typeof methods] || method;
};

// Get status display name
const getStatusName = (status: string) => {
    const statuses = {
        'completed': 'Selesai',
        'pending': 'Menunggu',
        'cancelled': 'Dibatalkan'
    };
    return statuses[status as keyof typeof statuses] || status;
};

// Get status variant
const getStatusVariant = (status: string): "default" | "secondary" | "destructive" | "outline" | null | undefined => {
    const variants = {
        'completed': 'default' as const,
        'pending': 'secondary' as const,
        'cancelled': 'destructive' as const
    };
    return variants[status as keyof typeof variants] || 'default';
};
</script>

<template>
    <Head title="Invoice - UMKM Kasir" />

    <div class="min-h-screen bg-gray-50">
        <!-- Header -->
        <div class="bg-white border-b border-gray-200 sticky top-0 z-10 no-print">
            <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex items-center justify-between h-16">
                    <div class="flex flex-wrap items-center gap-2">
                        <Button
                            as="a"
                            href="/dashboard"
                            variant="ghost"
                            size="sm"
                            class="flex items-center gap-2"
                        >
                            <ArrowLeft class="h-4 w-4" />
                            Dashboard
                        </Button>
                        <Button
                            as="a"
                            href="/pos"
                            variant="ghost"
                            size="sm"
                            class="flex items-center gap-2"
                        >
                            <ArrowLeft class="h-4 w-4" />
                            POS
                        </Button>
                    </div>
                    <div class="flex items-center gap-3">
                        <Button
                            v-if="fnb_enabled"
                            type="button"
                            variant="secondary"
                            size="sm"
                            class="flex items-center gap-2"
                            @click="printKitchenTicketFromInvoice"
                        >
                            <ChefHat class="h-4 w-4" />
                            Cetak KOT
                        </Button>
                        <Button
                            v-if="canPrintBluetooth"
                            type="button"
                            variant="outline"
                            size="sm"
                            class="flex items-center gap-2 border-violet-300 text-violet-900"
                            :disabled="isBluetoothPrinting"
                            @click="printInvoiceBluetooth(false)"
                        >
                            <Bluetooth class="h-4 w-4" />
                            {{ isBluetoothPrinting ? '…' : isPrinterConnected ? 'Cetak Bluetooth' : 'Bluetooth' }}
                        </Button>
                        <Button
                            v-if="canPrintBluetooth"
                            type="button"
                            variant="ghost"
                            size="sm"
                            class="hidden text-violet-700 sm:inline"
                            :disabled="isBluetoothPrinting"
                            title="Buka dialog dan pilih printer (ganti perangkat)"
                            @click="printInvoiceBluetooth(true)"
                        >
                            Ganti printer
                        </Button>
                        <Button
                            v-if="canPrintBluetooth"
                            type="button"
                            variant="ghost"
                            size="sm"
                            class="hidden text-slate-600 sm:inline"
                            :disabled="isBluetoothPrinting"
                            title="Hapus printer yang diingat"
                            @click="forgetBluetoothPrinter"
                        >
                            Lupa printer
                        </Button>
                        <Button
                            @click="printInvoice"
                            variant="outline"
                            size="sm"
                            class="flex items-center gap-2"
                        >
                            <Printer class="h-4 w-4" />
                            Cetak
                        </Button>
                        <Button
                            @click="downloadPDF"
                            variant="outline"
                            size="sm"
                            class="flex items-center gap-2"
                        >
                            <Download class="h-4 w-4" />
                            Download PDF
                        </Button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Invoice Content -->
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <Card class="shadow-lg invoice-container">
                <CardContent>
                    <div v-if="transaction.customer" class="mb-6 p-4 bg-gray-50 rounded-lg">
                        <h3 class="font-semibold text-gray-900 mb-2 flex items-center gap-2">
                            <User class="h-4 w-4" />
                            Informasi Pelanggan
                        </h3>
                        <div class="text-sm text-gray-600">
                            <p><strong>Nama:</strong> {{ transaction.customer.name }}</p>
                            <p v-if="transaction.customer.phone"><strong>Telepon:</strong> {{ transaction.customer.phone }}</p>
                            <p v-if="transaction.customer.email"><strong>Email:</strong> {{ transaction.customer.email }}</p>
                        </div>
                    </div>

                    <!-- Items Table -->
                    <div class="mb-6">
                        <h3 class="font-semibold text-gray-900 mb-4 flex items-center gap-2">
                            <Package class="h-4 w-4" />
                            Daftar Produk
                        </h3>

                        <div class="overflow-x-auto">
                            <table class="w-full border-collapse invoice-items">
                                <thead>
                                    <tr class="border-b border-gray-200">
                                        <th class="text-left py-3 px-4 font-semibold text-gray-900">Produk</th>
                                        <th class="text-center py-3 px-4 font-semibold text-gray-900">SKU</th>
                                        <th class="text-center py-3 px-4 font-semibold text-gray-900">Qty</th>
                                        <th class="text-right py-3 px-4 font-semibold text-gray-900">Harga</th>
                                        <th class="text-right py-3 px-4 font-semibold text-gray-900">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="item in transaction.items" :key="item.id" class="border-b border-gray-100">
                                        <td class="py-3 px-4">
                                            <div>
                                                <p class="font-medium text-gray-900">{{ item.product_name }}</p>
                                                <ul
                                                    v-if="item.modifiers && item.modifiers.length > 0"
                                                    class="text-xs text-violet-700 mt-1 space-y-0.5"
                                                >
                                                    <li v-for="(m, mi) in item.modifiers" :key="mi">
                                                        + {{ m.label }} (+{{ formatCurrency(Number(m.price ?? 0)) }})
                                                    </li>
                                                </ul>
                                            </div>
                                        </td>
                                        <td class="py-3 px-4 text-center text-sm text-gray-600">
                                            {{ item.product_sku || '-' }}
                                        </td>
                                        <td class="py-3 px-4 text-center text-sm text-gray-900">
                                            {{ item.quantity }}
                                        </td>
                                        <td class="py-3 px-4 text-right text-sm text-gray-900">
                                            {{ formatCurrency(item.unit_price) }}
                                        </td>
                                        <td class="py-3 px-4 text-right font-medium text-gray-900">
                                            {{ formatCurrency(item.total_price) }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Summary -->
                    <div class="space-y-3 invoice-total">
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-600">Subtotal:</span>
                            <span class="font-medium">{{ formatCurrency(transaction.subtotal) }}</span>
                        </div>

                        <div v-if="transaction.discount_amount > 0" class="flex justify-between text-sm">
                            <span class="text-gray-600">Diskon:</span>
                            <span class="font-medium text-red-600">-{{ formatCurrency(transaction.discount_amount) }}</span>
                        </div>

                        <div
                            v-if="transaction.service_charge_amount && transaction.service_charge_amount > 0"
                            class="flex justify-between text-sm"
                        >
                            <span class="text-gray-600">
                                Biaya layanan<span v-if="transaction.service_charge_percent"> ({{ transaction.service_charge_percent }}%)</span>:
                            </span>
                            <span class="font-medium">{{ formatCurrency(transaction.service_charge_amount) }}</span>
                        </div>

                        <div v-if="transaction.tax_amount > 0" class="flex justify-between text-sm">
                            <span class="text-gray-600">PPN:</span>
                            <span class="font-medium">{{ formatCurrency(transaction.tax_amount) }}</span>
                        </div>

                        <div v-if="transaction.tip_amount && transaction.tip_amount > 0" class="flex justify-between text-sm">
                            <span class="text-gray-600">Tip:</span>
                            <span class="font-medium">{{ formatCurrency(transaction.tip_amount) }}</span>
                        </div>

                        <Separator />

                        <div class="flex justify-between text-lg font-bold">
                            <span class="text-gray-900">Total:</span>
                            <span class="text-blue-600">{{ formatCurrency(transaction.total_amount) }}</span>
                        </div>

                        <div
                            v-if="splitBillShares && splitBillShares.length"
                            class="rounded-md border border-gray-200 bg-gray-50/80 px-3 py-2 text-sm space-y-1.5"
                        >
                            <p class="font-semibold text-gray-800">
                                Bagi tagihan ({{ splitBillShares.length }} bagian)
                            </p>
                            <ul class="space-y-0.5 tabular-nums text-gray-700">
                                <li v-for="(amt, i) in splitBillShares" :key="i" class="flex justify-between gap-4">
                                    <span>Bagian {{ i + 1 }}</span>
                                    <span class="font-medium">{{ formatCurrency(amt) }}</span>
                                </li>
                            </ul>
                        </div>

                        <div
                            v-if="preOrderSettlement"
                            class="rounded-md border border-amber-200 bg-amber-50/90 px-3 py-2 text-sm space-y-1.5"
                        >
                            <p class="font-semibold text-amber-950">
                                Pelunasan pre-order
                                <span v-if="preOrderSettlement.orderNumber" class="font-normal text-amber-900">
                                    ({{ preOrderSettlement.orderNumber }})
                                </span>
                            </p>
                            <div class="flex justify-between gap-4 tabular-nums text-amber-950">
                                <span>DP dipotong</span>
                                <span class="font-medium">−{{ formatCurrency(preOrderSettlement.depositApplied) }}</span>
                            </div>
                            <div class="flex justify-between gap-4 font-semibold tabular-nums text-amber-950">
                                <span>Sisa yang dibayar di kasir</span>
                                <span>{{ formatCurrency(preOrderSettlement.balanceDue) }}</span>
                            </div>
                        </div>

                        <div
                            v-if="pharmacySaleAudit"
                            class="rounded-md border border-violet-200 bg-violet-50/90 px-3 py-2 text-sm space-y-1.5"
                        >
                            <p class="font-semibold text-violet-950">Pencatatan penjualan apotek</p>
                            <p v-if="pharmacySaleAudit.reference" class="text-violet-900">
                                <span class="text-violet-800">Rujukan resep: </span>
                                <span class="font-medium break-words">{{ pharmacySaleAudit.reference }}</span>
                            </p>
                            <p class="text-xs text-violet-800">
                                <span v-if="pharmacySaleAudit.hasPrescription">Mengandung baris obat resep. </span>
                                <span v-if="pharmacySaleAudit.hasLimited">Mengandung obat golongan terbatas. </span>
                            </p>
                        </div>

                        <div class="flex justify-between text-sm">
                            <span class="text-gray-600">Dibayar:</span>
                            <span class="font-medium">{{ formatCurrency(transaction.amount_paid) }}</span>
                        </div>

                        <div v-if="transaction.change_amount > 0" class="flex justify-between text-sm">
                            <span class="text-gray-600">Kembalian:</span>
                            <span class="font-medium text-green-600">{{ formatCurrency(transaction.change_amount) }}</span>
                        </div>

                        <div class="flex justify-between text-sm">
                            <span class="text-gray-600">Metode Pembayaran:</span>
                            <span class="font-medium flex items-center gap-1">
                                <CreditCard class="h-4 w-4" />
                                {{ getPaymentMethodName(transaction.payment_method) }}
                            </span>
                        </div>
                    </div>

                    <!-- Footer -->
                    <div class="mt-8 pt-6 border-t border-gray-200 text-center text-sm text-gray-500 invoice-footer">
                        <p>Terima kasih telah berbelanja di {{ transaction.store.name }}!</p>
                        <p class="mt-1">Invoice ini dicetak pada {{ formatDate(new Date().toISOString()) }}</p>
                        <p
                            v-if="pharmacy_enabled"
                            class="mt-3 max-w-lg mx-auto text-xs leading-relaxed text-amber-900/90 border border-amber-200/80 rounded-lg bg-amber-50/80 px-3 py-2"
                        >
                            Penggunaan obat mengikuti resep dan peraturan yang berlaku. Fitur ini tidak menggantikan
                            kewajiban hukum, izin usaha, maupun kewenangan apoteker sesuai ketentuan setempat.
                        </p>
                    </div>
                </CardContent>
        </Card>
        </div>
    </div>
</template>

<style scoped>
@media print {
    @page {
        size: 75mm 60mm;
        margin: 0;
    }

    * {
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }

    .no-print {
        display: none !important;
    }

    html, body {
        width: 75mm !important;
        height: 60mm !important;
        margin: 0 !important;
        padding: 0 !important;
        background: white !important;
        font-size: 8px !important;
        line-height: 1.2 !important;
        overflow: hidden !important;
    }

    .min-h-screen {
        width: 75mm !important;
        height: 60mm !important;
        margin: 0 !important;
        padding: 0 !important;
        background: white !important;
    }

    .max-w-4xl {
        width: 75mm !important;
        height: 60mm !important;
        max-width: 75mm !important;
        margin: 0 !important;
        padding: 0 !important;
    }

    .shadow-lg {
        box-shadow: none !important;
    }

    .bg-gray-50 {
        background: white !important;
    }

    .invoice-container {
        width: 75mm !important;
        height: 60mm !important;
        max-width: 75mm !important;
        max-height: 60mm !important;
        padding: 2mm !important;
        margin: 0 !important;
        box-sizing: border-box !important;
        overflow: hidden !important;
    }

    .invoice-header h1 {
        font-size: 8px !important;
        margin-bottom: 0.5mm !important;
    }

    .invoice-header p {
        font-size: 6px !important;
        margin-bottom: 0.3mm !important;
    }

    .invoice-details {
        font-size: 5px !important;
        margin: 0.5mm 0 !important;
    }

    .invoice-items {
        font-size: 5px !important;
    }

    .invoice-items th,
    .invoice-items td {
        padding: 0.3mm !important;
        font-size: 5px !important;
    }

    .invoice-total {
        font-size: 6px !important;
        margin-top: 0.5mm !important;
    }

    .invoice-footer {
        font-size: 4px !important;
        margin-top: 0.5mm !important;
    }

    .pb-8 {
        padding-bottom: 1mm !important;
    }

    .mb-4 {
        margin-bottom: 0.5mm !important;
    }

    .mb-2 {
        margin-bottom: 0.3mm !important;
    }

    .space-y-3 > * + * {
        margin-top: 0.3mm !important;
    }

    .space-y-1 > * + * {
        margin-top: 0.1mm !important;
    }
}
</style>
