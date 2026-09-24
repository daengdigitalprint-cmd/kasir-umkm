<?php

namespace App\Http\Requests\Purchases;

use App\Support\BaseUnit;
use App\Support\InventoryType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StorePurchaseRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'supplier_id' => 'nullable|exists:suppliers,id',
            'purchased_at' => 'nullable|date',
            'notes' => 'nullable|string|max:2000',
            'lines' => 'nullable|array',
            'lines.*.product_id' => 'nullable|integer|exists:products,id',
            'lines.*.new_product' => 'nullable|array',
            'lines.*.new_product.name' => 'required_with:lines.*.new_product|string|max:255',
            'lines.*.new_product.sku' => 'nullable|string|max:255',
            'lines.*.new_product.barcode' => 'nullable|string|max:255',
            'lines.*.new_product.inventory_type' => 'nullable|string|in:'.implode(',', array_keys(InventoryType::LABELS)),
            'lines.*.new_product.base_unit' => 'nullable|string|in:'.implode(',', array_keys(BaseUnit::LABELS)),
            'lines.*.new_product.unit' => 'nullable|string|max:50',
            'lines.*.new_product.allows_decimal' => 'nullable|boolean',
            'lines.*.product_variant_id' => 'nullable|integer|exists:product_variants,id',
            'lines.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'lines.*.input_unit' => 'required|string|max:20',
            'lines.*.unit_cost' => 'nullable|numeric|min:0',
            'lines.*.batch_expiry_date' => 'nullable|date',
            'lines.*.batch_code' => 'nullable|string|max:120',
            'charges' => 'nullable|array',
            'charges.*.name' => 'required|string|max:255',
            'charges.*.amount' => 'required|numeric|min:0.01',
            'charges.*.notes' => 'nullable|string|max:1000',
            'ppn_amount' => 'nullable|numeric|min:0',
            'supplier_tax_invoice_no' => 'nullable|string|max:120',
            'is_paid' => 'nullable|boolean',
            'payment_method' => 'nullable|string|in:cash,transfer,ewallet',
            'amount_paid' => 'nullable|numeric|min:0',
            'due_date' => 'nullable|date',
        ];
    }

    /**
     * Get custom validation error messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.*.quantity.required' => 'Jumlah wajib diisi untuk setiap baris barang.',
            'lines.*.quantity.numeric' => 'Jumlah harus berupa angka.',
            'lines.*.quantity.min' => 'Jumlah harus lebih dari 0 (tidak boleh nol atau negatif).',
            'lines.*.input_unit.required' => 'Satuan wajib dipilih untuk setiap baris barang.',
            'lines.*.product_id.exists' => 'Produk yang dipilih tidak valid.',
            'lines.*.new_product.name.required_with' => 'Nama produk baru wajib diisi.',
            'lines.*.new_product.name.max' => 'Nama produk maksimal 255 karakter.',
            'charges.*.name.required' => 'Nama biaya wajib diisi.',
            'charges.*.amount.required' => 'Nominal biaya wajib diisi.',
            'charges.*.amount.numeric' => 'Nominal biaya harus berupa angka.',
            'charges.*.amount.min' => 'Nominal biaya harus lebih dari 0.',
            'supplier_id.exists' => 'Supplier yang dipilih tidak terdaftar.',
            'purchased_at.date' => 'Tanggal pembelian tidak valid.',
            'notes.max' => 'Catatan maksimal 2000 karakter.',
            'ppn_amount.numeric' => 'PPN harus berupa angka.',
            'ppn_amount.min' => 'PPN tidak boleh negatif.',
            'amount_paid.numeric' => 'Jumlah bayar harus berupa angka.',
            'amount_paid.min' => 'Jumlah bayar tidak boleh negatif.',
            'due_date.date' => 'Tanggal jatuh tempo tidak valid.',
            'supplier_tax_invoice_no.max' => 'Nomor faktur pajak maksimal 120 karakter.',
            'payment_method.in' => 'Metode pembayaran harus cash, transfer, atau ewallet.',
        ];
    }
}
