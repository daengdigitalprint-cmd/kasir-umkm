<?php

namespace App\NativeComponents\Kasir;

use App\Enums\SalesChannel;
use App\Models\CashShift;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Services\Api\Sync\MobileSyncService;
use App\Services\Printer\ReceiptBuilder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\NativeComponent;
use Nativephp\BluetoothPrinter\BluetoothPrinter;

class Index extends NativeComponent
{
    // Identitas kasir & shift
    public string $cashierName = 'Kasir';

    public ?int $activeShiftId = null;

    public bool $isShiftOpen = false;

    public string $openingCashInput = '';

    public string $closingCashInput = '';

    public string $shiftStatusText = 'Belum Ada Shift';

    // State POS & Pencarian
    public string $search = '';

    public ?int $selectedCategoryId = null;

    // Keranjang Belanja: array of [product_id, name, price, qty, subtotal]
    public array $cart = [];

    // Modal & Dialog States
    public bool $showShiftModal = false;

    public bool $showCheckoutModal = false;

    public bool $showSuccessModal = false;

    // State Pembayaran
    public string $paymentMethod = 'cash'; // 'cash' | 'qris'

    public string $cashReceivedInput = '';

    public float $changeAmount = 0.0;

    public string $lastTransactionNumber = '';

    public string $lastChangeFormatted = 'Rp 0';

    public string $errorMessage = '';

    public function navTitle(): string
    {
        return 'Kasir (POS)';
    }

    public function mount(): void
    {
        $this->resolveCashierAndShift();
    }

    public function resolveCashierAndShift(): void
    {
        $user = Auth::user();
        if ($user) {
            $this->cashierName = $user->name;
            $storeId = $user->store_id;

            $activeShift = CashShift::query()
                ->where('store_id', $storeId)
                ->where('user_id', $user->id)
                ->whereNull('closed_at')
                ->latest('opened_at')
                ->first();

            if ($activeShift) {
                $this->activeShiftId = $activeShift->id;
                $this->isShiftOpen = true;
                $this->shiftStatusText = 'Shift Aktif #'.($activeShift->shift_number ?? $activeShift->id);
            } else {
                $this->activeShiftId = null;
                $this->isShiftOpen = false;
                $this->shiftStatusText = 'Shift Tertutup';
            }
        }
    }

    public function openShiftModal(): void
    {
        $this->errorMessage = '';
        $this->showShiftModal = true;
    }

    public function closeShiftModal(): void
    {
        $this->showShiftModal = false;
    }

    public function startShift(): void
    {
        $user = Auth::user();
        if (! $user) {
            $this->errorMessage = 'Silakan login terlebih dahulu.';

            return;
        }

        $opening = (float) str_replace(['.', ','], ['', '.'], $this->openingCashInput);

        $shift = CashShift::create([
            'store_id' => $user->store_id,
            'user_id' => $user->id,
            'opened_at' => now(),
            'opening_cash' => max(0, $opening),
            'expected_cash' => max(0, $opening),
        ]);

        $this->activeShiftId = $shift->id;
        $this->isShiftOpen = true;
        $this->shiftStatusText = 'Shift Aktif #'.($shift->shift_number ?? $shift->id);
        $this->showShiftModal = false;
        $this->openingCashInput = '';
        $this->errorMessage = '';
    }

    public function endShift(): void
    {
        if (! $this->activeShiftId) {
            return;
        }

        $shift = CashShift::find($this->activeShiftId);
        if ($shift) {
            $counted = (float) str_replace(['.', ','], ['', '.'], $this->closingCashInput);
            $expected = (float) $shift->expected_cash;

            $shift->update([
                'closed_at' => now(),
                'closing_cash_counted' => $counted,
                'difference_amount' => $counted - $expected,
            ]);
        }

        $this->activeShiftId = null;
        $this->isShiftOpen = false;
        $this->shiftStatusText = 'Shift Tertutup';
        $this->showShiftModal = false;
        $this->closingCashInput = '';
    }

    public function selectCategory(?int $categoryId = null): void
    {
        $this->selectedCategoryId = $categoryId;
    }

    public function addToCart(int $productId): void
    {
        if (! $this->isShiftOpen) {
            $this->errorMessage = 'Buka shift kasir terlebih dahulu sebelum memulai transaksi.';
            $this->showShiftModal = true;

            return;
        }

        $product = Product::find($productId);
        if (! $product) {
            return;
        }

        $found = false;
        foreach ($this->cart as $index => $item) {
            if ($item['product_id'] === $productId) {
                $this->cart[$index]['qty'] += 1;
                $this->cart[$index]['subtotal'] = $this->cart[$index]['qty'] * $item['price'];
                $found = true;
                break;
            }
        }

        if (! $found) {
            $this->cart[] = [
                'product_id' => $product->id,
                'name' => $product->name,
                'price' => (float) $product->price,
                'qty' => 1,
                'subtotal' => (float) $product->price,
            ];
        }
    }

    public function incrementCart(int $productId): void
    {
        foreach ($this->cart as $index => $item) {
            if ($item['product_id'] === $productId) {
                $this->cart[$index]['qty'] += 1;
                $this->cart[$index]['subtotal'] = $this->cart[$index]['qty'] * $item['price'];
                break;
            }
        }
    }

    public function decrementCart(int $productId): void
    {
        foreach ($this->cart as $index => $item) {
            if ($item['product_id'] === $productId) {
                if ($item['qty'] > 1) {
                    $this->cart[$index]['qty'] -= 1;
                    $this->cart[$index]['subtotal'] = $this->cart[$index]['qty'] * $item['price'];
                } else {
                    array_splice($this->cart, $index, 1);
                }
                break;
            }
        }
    }

    public function clearCart(): void
    {
        $this->cart = [];
    }

    public function getSubtotal(): float
    {
        return array_reduce($this->cart, fn ($sum, $item) => $sum + $item['subtotal'], 0.0);
    }

    public function openCheckout(): void
    {
        if (empty($this->cart)) {
            return;
        }
        $subtotal = $this->getSubtotal();
        $this->cashReceivedInput = (string) (int) $subtotal;
        $this->calculateChange();
        $this->showCheckoutModal = true;
    }

    public function closeCheckout(): void
    {
        $this->showCheckoutModal = false;
    }

    public function setPaymentMethod(string $method): void
    {
        $this->paymentMethod = $method;
        if ($method === 'qris') {
            $this->cashReceivedInput = (string) (int) $this->getSubtotal();
        }
        $this->calculateChange();
    }

    public function calculateChange(): void
    {
        $subtotal = $this->getSubtotal();
        $received = (float) str_replace(['.', ','], ['', '.'], $this->cashReceivedInput);
        $this->changeAmount = max(0.0, $received - $subtotal);
    }

    public function processCheckout(): void
    {
        $user = Auth::user();
        if (! $user) {
            return;
        }

        $subtotal = $this->getSubtotal();
        $amountPaid = $this->paymentMethod === 'cash'
            ? (float) str_replace(['.', ','], ['', '.'], $this->cashReceivedInput)
            : $subtotal;

        if ($this->paymentMethod === 'cash' && $amountPaid < $subtotal) {
            $this->errorMessage = 'Nominal pembayaran kurang dari total belanja.';

            return;
        }

        $clientUuid = (string) Str::uuid();
        $txNumber = 'TRX-'.strtoupper(Str::random(8));

        DB::transaction(function () use ($user, $subtotal, $amountPaid, $clientUuid, $txNumber) {
            $change = max(0.0, $amountPaid - $subtotal);

            $trx = Transaction::create([
                'store_id' => $user->store_id,
                'user_id' => $user->id,
                'cash_shift_id' => $this->activeShiftId,
                'transaction_number' => $txNumber,
                'offline_sale_id' => $clientUuid,
                'status' => 'completed',
                'order_status' => 'completed',
                'payment_method' => $this->paymentMethod,
                'payment_status' => 'paid',
                'subtotal' => $subtotal,
                'discount_amount' => 0,
                'tax_amount' => 0,
                'total_amount' => $subtotal,
                'amount_paid' => $amountPaid,
                'change_amount' => $change,
                'sales_channel' => SalesChannel::WalkIn,
                'completed_at' => now(),
            ]);

            foreach ($this->cart as $item) {
                $product = Product::find($item['product_id']);

                TransactionItem::create([
                    'transaction_id' => $trx->id,
                    'product_id' => $item['product_id'],
                    'product_name' => $item['name'],
                    'product_sku' => $product?->sku,
                    'quantity' => $item['qty'],
                    'unit_price' => $item['price'],
                    'total_price' => $item['subtotal'],
                ]);

                // Kurangi stok produk lokal
                Product::where('id', $item['product_id'])->decrement('stock_quantity', $item['qty']);
            }

            // Update expected_cash pada cash_shift jika tunai
            if ($this->activeShiftId && $this->paymentMethod === 'cash') {
                CashShift::where('id', $this->activeShiftId)->increment('expected_cash', $subtotal);
            }

            // Antrikan ke outbox untuk sinkronisasi otomatis ke server
            $syncService = new MobileSyncService;
            $syncService->queue('transaction', $clientUuid, [
                'client_uuid' => $clientUuid,
                'transaction_number' => $txNumber,
                'payment_method' => $this->paymentMethod,
                'subtotal' => $subtotal,
                'total_amount' => $subtotal,
                'amount_paid' => $amountPaid,
                'change_amount' => $change,
                'items' => array_map(fn ($i) => [
                    'product_id' => $i['product_id'],
                    'quantity' => $i['qty'],
                    'unit_price' => $i['price'],
                ], $this->cart),
            ], $user->store_id);

            $this->lastTransactionNumber = $txNumber;
            $this->lastChangeFormatted = 'Rp '.number_format($change, 0, ',', '.');
        });

        // Reset keranjang dan tampilkan konfirmasi sukses
        $this->cart = [];
        $this->showCheckoutModal = false;
        $this->showSuccessModal = true;
    }

    public function closeSuccessModal(): void
    {
        $this->showSuccessModal = false;
    }

    public function printReceipt(string $transactionNumber): void
    {
        try {
            $transaction = Transaction::where('transaction_number', $transactionNumber)->first();
            if (! $transaction) {
                $this->errorMessage = 'Transaksi tidak ditemukan.';

                return;
            }

            // Dapatkan perangkat yang terhubung
            $devices = BluetoothPrinter::getPairedDevices();
            if (is_string($devices)) {
                $devices = json_decode($devices, true) ?? [];
            }

            // Asumsikan printer pertama yang ada sebagai default
            if (empty($devices)) {
                $this->errorMessage = 'Tidak ada printer Bluetooth terpasang.';

                return;
            }
            $targetDevice = is_array($devices[0]) ? $devices[0]['address'] : $devices[0]->address;

            // Bangun struk
            $builder = new ReceiptBuilder($transaction);
            $bytes = $builder->build();

            // Kirim ke printer
            $success = BluetoothPrinter::print($targetDevice, $bytes);

            if (! $success) {
                $this->errorMessage = 'Gagal mencetak struk. Periksa koneksi printer.';
            }

        } catch (\Exception $e) {
            $this->errorMessage = 'Error mencetak: '.$e->getMessage();
        }
    }

    public function render(): Element|View
    {
        $this->resolveCashierAndShift();

        $user = Auth::user();
        $storeId = $user?->store_id;

        $categories = $storeId
            ? ProductCategory::query()->where('store_id', $storeId)->orderBy('sort_order')->get()
            : collect();

        $productsQuery = Product::query()
            ->where('is_active', true)
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->when($this->selectedCategoryId, fn ($q) => $q->where('product_category_id', $this->selectedCategoryId))
            ->when($this->search !== '', fn ($q) => $q->where('name', 'like', "%{$this->search}%"));

        $products = $productsQuery->orderBy('name')->limit(30)->get();

        $subtotal = $this->getSubtotal();
        $cartCount = array_reduce($this->cart, fn ($sum, $item) => $sum + $item['qty'], 0);

        return view('native.kasir.index', [
            'categories' => $categories,
            'products' => $products,
            'subtotal' => $subtotal,
            'subtotalFormatted' => 'Rp '.number_format($subtotal, 0, ',', '.'),
            'cartCount' => $cartCount,
        ]);
    }
}
