<?php

namespace App\Services\Printer;

use App\Models\Transaction;
use Illuminate\Support\Str;

class ReceiptBuilder
{
    protected Transaction $transaction;

    protected int $paperWidthMm;

    protected int $maxChars;

    public function __construct(Transaction $transaction, int $paperWidthMm = 58)
    {
        $this->transaction = $transaction;
        $this->paperWidthMm = $paperWidthMm;
        $this->maxChars = EscPosEncoder::getMaxChars($paperWidthMm);

        $this->transaction->loadMissing(['store', 'items']);
    }

    public function buildTextLines(): array
    {
        $lines = [];
        $storeName = $this->transaction->store->name ?? 'Toko Saya';
        $storeAddress = $this->transaction->store->address ?? '';

        // HEADER
        $lines[] = $this->centerText($storeName);
        if ($storeAddress) {
            $lines[] = $this->centerText(Str::limit($storeAddress, $this->maxChars, ''));
        }
        $lines[] = $this->centerText(str_repeat('-', $this->maxChars));

        // INFO
        $lines[] = 'No : '.$this->transaction->transaction_number;
        $lines[] = 'Tgl: '.$this->transaction->created_at->format('d/m/Y H:i');
        $lines[] = 'Kasir: '.($this->transaction->user->name ?? 'Kasir');
        $lines[] = str_repeat('-', $this->maxChars);

        // ITEMS
        foreach ($this->transaction->items as $item) {
            $name = Str::limit($item->product_name, $this->maxChars);
            $lines[] = $name;

            $qtyPrice = $item->quantity.' x '.number_format($item->unit_price, 0, ',', '.');
            $subtotal = number_format($item->total_price, 0, ',', '.');

            $lines[] = $this->justifyText($qtyPrice, $subtotal);
        }
        $lines[] = str_repeat('-', $this->maxChars);

        // TOTALS
        $sub = number_format($this->transaction->subtotal, 0, ',', '.');
        $lines[] = $this->justifyText('TOTAL', $sub);

        $paid = number_format($this->transaction->amount_paid, 0, ',', '.');
        $lines[] = $this->justifyText('BAYAR ('.strtoupper($this->transaction->payment_method).')', $paid);

        $change = number_format($this->transaction->change_amount, 0, ',', '.');
        $lines[] = $this->justifyText('KEMBALI', $change);

        // FOOTER
        $lines[] = '';
        $lines[] = $this->centerText('Terima Kasih');
        $lines[] = $this->centerText('Selamat Belanja Kembali');
        $lines[] = '';
        $lines[] = ''; // Extra space before cut

        return $lines;
    }

    protected function centerText(string $text): string
    {
        $len = strlen($text);
        if ($len >= $this->maxChars) {
            return $text;
        }
        $padLeft = floor(($this->maxChars - $len) / 2);

        return str_repeat(' ', (int) $padLeft).$text;
    }

    protected function justifyText(string $left, string $right): string
    {
        $lenLeft = strlen($left);
        $lenRight = strlen($right);
        $spaces = $this->maxChars - ($lenLeft + $lenRight);
        if ($spaces < 1) {
            $spaces = 1; // Minimal 1 spasi
        }

        return $left.str_repeat(' ', $spaces).$right;
    }
}
