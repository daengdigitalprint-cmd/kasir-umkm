<?php

namespace App\Http\Requests\Purchases;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StorePurchaseReturnRequest extends FormRequest
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
            'purchase_id' => 'nullable|integer|exists:purchases,id',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'returned_at' => 'nullable|date',
            'notes' => 'nullable|string|max:2000',
            'lines' => 'required|array|min:1',
            'lines.*.product_id' => 'required|exists:products,id',
            'lines.*.product_variant_id' => 'nullable|integer|exists:product_variants,id',
            'lines.*.product_batch_id' => 'nullable|integer|exists:product_batches,id',
            'lines.*.quantity' => ['required', 'numeric', 'min:0.0001'],
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
            'lines.required' => 'Minimal satu baris barang harus diisi untuk retur.',
            'lines.min' => 'Minimal satu baris barang harus diisi untuk retur.',
            'lines.*.product_id.required' => 'Produk wajib dipilih untuk setiap baris retur.',
            'lines.*.product_id.exists' => 'Produk yang dipilih tidak valid.',
            'lines.*.quantity.required' => 'Jumlah retur wajib diisi.',
            'lines.*.quantity.numeric' => 'Jumlah retur harus berupa angka.',
            'lines.*.quantity.min' => 'Jumlah retur harus lebih dari 0 (tidak boleh nol atau negatif).',
            'purchase_id.integer' => 'ID pembelian tidak valid.',
            'purchase_id.exists' => 'Pembelian sumber tidak ditemukan.',
            'supplier_id.exists' => 'Supplier yang dipilih tidak terdaftar.',
            'returned_at.date' => 'Tanggal retur tidak valid.',
            'notes.max' => 'Catatan maksimal 2000 karakter.',
        ];
    }
}
