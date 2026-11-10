<?php

namespace App\Services;

use App\Models\BackupHistory;
use App\Models\StockTransfer;
use App\Models\Store;
use App\Support\CacheKeys;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Service untuk backup lengkap data toko dalam format JSON.
 * Mencakup: transaksi, produk, pelanggan, supplier, pembelian, stok, akunting, dll.
 */
class StoreBackupService
{
    private StoreDataSnapshot $snapshot;

    public function __construct(?StoreDataSnapshot $snapshot = null)
    {
        $this->snapshot = $snapshot ?? new StoreDataSnapshot;
    }

    /**
     * Generate backup lengkap untuk toko dalam format JSON.
     *
     * Bagian per-modul (products, transactions, ...) adalah ringkasan yang mudah dibaca;
     * restore memakai bagian 'tables' yang berisi baris mentah seluruh tabel data toko.
     *
     * @return array Data backup terstruktur
     */
    public function generateBackup(Store $store): array
    {
        $this->extendRuntimeLimits();

        $store->load('organization:id,name', 'subscriptionPlan:id,name,slug');

        $backup = [
            'backup_version' => '2.0.0',
            'generated_at' => now()->toIso8601String(),
            'store' => $this->exportStore($store),
            'products' => $this->exportProducts($store),
            'product_categories' => $this->exportProductCategories($store),
            'customers' => $this->exportCustomers($store),
            'suppliers' => $this->exportSuppliers($store),
            'transactions' => $this->exportTransactions($store),
            'purchases' => $this->exportPurchases($store),
            'purchase_returns' => $this->exportPurchaseReturns($store),
            'stock_movements' => $this->exportStockMovements($store),
            'stock_transfers' => $this->exportStockTransfers($store),
            'pre_orders' => $this->exportPreOrders($store),
            'discounts' => $this->exportDiscounts($store),
            'cash_shifts' => $this->exportCashShifts($store),
            'accounting' => $this->exportAccounting($store),
            'tables' => $this->snapshot->export($store->id),
        ];

        return $backup;
    }

    /**
     * Generate backup, simpan ke storage, dan catat ke backup_histories.
     */
    public function saveAndRecord(
        Store $store,
        string $triggerType = 'manual',
        ?int $userId = null,
    ): BackupHistory {
        $start = microtime(true);

        $history = BackupHistory::create([
            'store_id' => $store->id,
            'user_id' => $userId,
            'filename' => '',
            'filepath' => '',
            'file_size' => 0,
            'status' => 'pending',
            'trigger_type' => $triggerType,
        ]);

        try {
            $backup = $this->generateBackup($store);

            $filename = sprintf(
                'backup-%s-%s.json',
                $store->slug,
                now()->format('Y-m-d_His')
            );
            $directory = "backups/store-{$store->id}";
            $filepath = "{$directory}/{$filename}";

            Storage::makeDirectory($directory);
            Storage::put($filepath, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            $durationMs = (int) ((microtime(true) - $start) * 1000);
            $fileSize = Storage::size($filepath);
            $recordsCount = $this->countRecords($backup);

            $history->update([
                'filename' => $filename,
                'filepath' => $filepath,
                'file_size' => $fileSize,
                'status' => 'completed',
                'duration_ms' => $durationMs,
                'records_count' => $recordsCount,
                'records_summary' => $this->buildSummary($backup),
            ]);

            $this->cleanOldBackups($store->id);

            return $history->refresh();
        } catch (\Throwable $e) {
            $history->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'duration_ms' => (int) ((microtime(true) - $start) * 1000),
            ]);

            throw $e;
        }
    }

    /**
     * Simpan file backup yang diunggah dari luar (mis. hasil unduhan sebelumnya)
     * ke storage toko, lalu catat ke backup_histories agar bisa di-restore.
     */
    public function storeUploadedBackup(Store $store, UploadedFile $file, ?int $userId = null): BackupHistory
    {
        $backup = json_decode((string) file_get_contents($file->getRealPath()), true);

        if (! is_array($backup) || ! isset($backup['backup_version'], $backup['store']) || ! is_array($backup['store'])) {
            throw new \InvalidArgumentException('File bukan backup yang valid. Gunakan file .json hasil unduhan backup aplikasi ini.');
        }

        if (! isset($backup['tables']) || ! is_array($backup['tables'])) {
            throw new \InvalidArgumentException(
                'File backup ini memakai format lama (v'.$backup['backup_version'].') yang tidak lengkap dan tidak bisa dipulihkan. '
                .'Buat backup baru, unduh, lalu unggah file tersebut.'
            );
        }

        $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $safeName = Str::limit(Str::slug($originalName) ?: 'backup', 80, '');
        $filename = sprintf('upload-%s-%s.json', now()->format('Y-m-d_His'), $safeName);
        $directory = "backups/store-{$store->id}";
        $filepath = "{$directory}/{$filename}";

        Storage::makeDirectory($directory);
        Storage::putFileAs($directory, $file, $filename);

        return BackupHistory::create([
            'store_id' => $store->id,
            'user_id' => $userId,
            'filename' => $filename,
            'filepath' => $filepath,
            'file_size' => Storage::size($filepath),
            'status' => 'completed',
            'trigger_type' => 'upload',
            'records_count' => $this->countRecords($backup),
            'records_summary' => $this->buildSummary($backup),
        ]);
    }

    /**
     * Restore data dari file backup ke database.
     */
    public function restoreFromBackup(Store $store, string $filepath, ?int $userId = null): array
    {
        if (! Storage::exists($filepath)) {
            throw new \RuntimeException('File backup tidak ditemukan.');
        }

        $this->extendRuntimeLimits();

        $json = Storage::get($filepath);
        $backup = json_decode($json, true);

        if (! $backup || ! isset($backup['backup_version'], $backup['store'])) {
            throw new \RuntimeException('File backup tidak valid atau rusak.');
        }

        // Backup v1 hanya berisi ringkasan tanpa ID relasi, sehingga transaksi, stok,
        // jurnal, dll. tidak bisa dipulihkan — restore dari file ini justru menghapus data.
        if (! isset($backup['tables']) || ! is_array($backup['tables'])) {
            throw new \RuntimeException(
                'File backup ini memakai format lama (v'.$backup['backup_version'].') yang tidak menyimpan data penjualan, stok, '
                .'dan jurnal secara lengkap, sehingga tidak bisa dipulihkan dengan aman. Buat backup baru lalu gunakan file tersebut.'
            );
        }

        $log = ['restored' => [], 'errors' => []];

        try {
            DB::transaction(function () use ($store, $backup, $userId, &$log) {
                $this->snapshot->restore(
                    $store->id,
                    $backup['tables'],
                    (int) ($backup['store']['id'] ?? $store->id),
                    $userId,
                    $log,
                );
            });
        } catch (UniqueConstraintViolationException $e) {
            throw new \RuntimeException(
                'Data di backup bentrok dengan data yang sudah ada (mis. nomor transaksi/pembelian yang sama milik toko lain). '
                .'Pemulihan dibatalkan dan data toko tidak berubah.',
                previous: $e,
            );
        }

        CacheKeys::forgetStoreReports($store->id);
        CacheKeys::forgetStoreMasters($store->id);

        // Catat restore sebagai backup trigger
        BackupHistory::create([
            'store_id' => $store->id,
            'user_id' => $userId,
            'filename' => basename($filepath),
            'filepath' => $filepath,
            'file_size' => Storage::size($filepath),
            'status' => 'completed',
            'trigger_type' => 'restore',
            'records_summary' => ['restore_log' => $log],
        ]);

        return $log;
    }

    /**
     * Bersihkan backup lama, simpan N terakhir per toko.
     */
    public function cleanOldBackups(int $storeId, int $keep = 7): void
    {
        $directory = "backups/store-{$storeId}";

        if (! Storage::exists($directory)) {
            return;
        }

        $files = collect(Storage::files($directory))
            ->map(fn ($path) => [
                'path' => $path,
                'time' => Storage::lastModified($path),
            ])
            ->sortByDesc('time')
            ->skip($keep)
            ->pluck('path');

        foreach ($files as $path) {
            Storage::delete($path);
        }
    }

    /**
     * Backup/restore toko besar bisa melewati batas default PHP (30 detik / 128 MB)
     * dan berakhir dengan halaman error HTML, bukan respons JSON.
     */
    private function extendRuntimeLimits(): void
    {
        @set_time_limit(300);

        $current = ini_get('memory_limit');
        if ($current !== '-1' && $this->bytes((string) $current) < 512 * 1024 * 1024) {
            @ini_set('memory_limit', '512M');
        }
    }

    private function bytes(string $size): int
    {
        $value = (int) $size;

        return match (strtolower(substr(trim($size), -1))) {
            'g' => $value * 1024 ** 3,
            'm' => $value * 1024 ** 2,
            'k' => $value * 1024,
            default => $value,
        };
    }

    private function countRecords(array $backup): int
    {
        if (isset($backup['tables']) && is_array($backup['tables'])) {
            return array_sum(array_map(fn ($rows) => is_array($rows) ? count($rows) : 0, $backup['tables']));
        }

        $count = 0;
        foreach ($backup as $key => $value) {
            if (is_array($value) && ! isset($value['id'])) {
                $count += count($value);
            } elseif (is_array($value) && $key === 'accounting') {
                foreach ($value as $sub => $items) {
                    if (is_array($items)) {
                        $count += count($items);
                    }
                }
            }
        }

        return $count;
    }

    private function buildSummary(array $backup): array
    {
        $summary = [];
        $mapping = [
            'products' => 'Produk',
            'customers' => 'Pelanggan',
            'suppliers' => 'Supplier',
            'transactions' => 'Transaksi',
            'purchases' => 'Pembelian',
            'purchase_returns' => 'Retur',
            'stock_movements' => 'Stok',
            'stock_transfers' => 'Transfer',
            'pre_orders' => 'Pre-order',
            'discounts' => 'Diskon',
            'cash_shifts' => 'Shift',
        ];

        foreach ($mapping as $key => $label) {
            if (isset($backup[$key]) && is_array($backup[$key])) {
                $summary[$label] = count($backup[$key]);
            }
        }

        if (isset($backup['accounting'])) {
            $coa = count($backup['accounting']['chart_of_accounts'] ?? []);
            $journals = count($backup['accounting']['journal_entries'] ?? []);
            $summary['Akun'] = $coa;
            $summary['Jurnal'] = $journals;
        }

        return $summary;
    }

    private function exportStore(Store $store): array
    {
        return [
            'id' => $store->id,
            'name' => $store->name,
            'slug' => $store->slug,
            'business_type' => $store->business_type,
            'description' => $store->description,
            'address' => $store->address,
            'phone' => $store->phone,
            'email' => $store->email,
            'currency' => $store->currency,
            'timezone' => $store->timezone,
            'settings' => $store->settings,
            'tax_enabled' => $store->tax_enabled,
            'tax_rate' => $store->tax_rate,
            'tax_name' => $store->tax_name,
            'organization' => $store->organization ? [
                'id' => $store->organization->id,
                'name' => $store->organization->name,
            ] : null,
            'subscription_plan' => $store->subscriptionPlan ? [
                'name' => $store->subscriptionPlan->name,
                'slug' => $store->subscriptionPlan->slug,
            ] : null,
            'subscription_end' => $store->subscription_end?->toIso8601String(),
            'created_at' => $store->created_at->toIso8601String(),
            'updated_at' => $store->updated_at->toIso8601String(),
        ];
    }

    private function exportProducts(Store $store): array
    {
        return $store->products()
            ->with(['variants', 'bundleItems.componentProduct', 'batches'])
            ->orderBy('id')
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'sku' => $p->sku,
                'barcode' => $p->barcode,
                'description' => $p->description,
                'category' => $p->category,
                'price' => $p->price,
                'cost_price' => $p->cost_price,
                'stock_quantity' => $p->stock_quantity,
                'track_stock' => $p->track_stock,
                'allows_decimal' => $p->allows_decimal,
                'is_active' => $p->is_active,
                'is_bundle' => $p->is_bundle,
                'has_variants' => $p->has_variants,
                'images' => $p->images,
                'unit' => $p->unit,
                'pharmacy_sale_class' => $p->pharmacy_sale_class,
                'pharmacy_registration_no' => $p->pharmacy_registration_no,
                'variants' => $p->variants->map(fn ($v) => [
                    'id' => $v->id,
                    'name' => $v->name,
                    'sku' => $v->sku,
                    'barcode' => $v->barcode,
                    'price' => $v->price,
                    'cost_price' => $v->cost_price,
                    'stock_quantity' => $v->stock_quantity,
                    'min_stock_level' => $v->min_stock_level,
                    'is_active' => $v->is_active,
                ])->toArray(),
                'bundle_items' => $p->bundleItems->map(fn ($bi) => [
                    'component_product_id' => $bi->component_product_id,
                    'component_product_name' => $bi->componentProduct?->name,
                    'component_variant_id' => $bi->component_product_variant_id,
                    'quantity' => $bi->quantity,
                    'sort_order' => $bi->sort_order,
                ])->toArray(),
                'batches' => $p->batches->map(fn ($b) => [
                    'id' => $b->id,
                    'batch_code' => $b->batch_code,
                    'quantity_remaining' => $b->quantity_remaining,
                    'expiry_date' => $b->expiry_date?->toDateString(),
                    'status' => $b->status,
                ])->toArray(),
                'created_at' => $p->created_at->toIso8601String(),
                'updated_at' => $p->updated_at->toIso8601String(),
            ])
            ->toArray();
    }

    private function exportProductCategories(Store $store): array
    {
        return $store->productCategories()
            ->orderBy('name')
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'description' => $c->description,
                'created_at' => $c->created_at->toIso8601String(),
            ])
            ->toArray();
    }

    private function exportCustomers(Store $store): array
    {
        return $store->customers()
            ->orderBy('id')
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'email' => $c->email,
                'phone' => $c->phone,
                'address' => $c->address,
                'created_at' => $c->created_at->toIso8601String(),
            ])
            ->toArray();
    }

    private function exportSuppliers(Store $store): array
    {
        return $store->suppliers()
            ->orderBy('id')
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'email' => $s->email,
                'phone' => $s->phone,
                'address' => $s->address,
                'notes' => $s->notes,
                'created_at' => $s->created_at->toIso8601String(),
            ])
            ->toArray();
    }

    private function exportTransactions(Store $store): array
    {
        return $store->transactions()
            ->with(['items.product', 'items.productVariant', 'customer', 'discount'])
            ->orderBy('id')
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'customer_name' => $t->customer?->name,
                'status' => $t->status,
                'payment_method' => $t->payment_method,
                'order_type' => $t->order_type,
                'table_reference' => $t->table_reference,
                'subtotal' => $t->subtotal,
                'tax_amount' => $t->tax_amount,
                'discount_amount' => $t->discount_amount,
                'service_charge_amount' => $t->service_charge_amount,
                'service_charge_percent' => $t->service_charge_percent,
                'tip_amount' => $t->tip_amount,
                'total_amount' => $t->total_amount,
                'amount_paid' => $t->amount_paid,
                'change_amount' => $t->change_amount,
                'payment_details' => $t->payment_details,
                'discount_code' => $t->discount?->code,
                'offline_sale_id' => $t->offline_sale_id,
                'items' => $t->items->map(fn ($i) => [
                    'product_name' => $i->product_name,
                    'product_sku' => $i->product_sku,
                    'variant_label' => $i->variant_label,
                    'quantity' => $i->quantity,
                    'unit_price' => $i->unit_price,
                    'total_price' => $i->total_price,
                    'modifiers' => $i->modifiers,
                ])->toArray(),
                'completed_at' => $t->completed_at?->toIso8601String(),
                'created_at' => $t->created_at->toIso8601String(),
            ])
            ->toArray();
    }

    private function exportPurchases(Store $store): array
    {
        return $store->purchases()
            ->with(['supplier', 'lines.product', 'lines.productVariant'])
            ->orderBy('id')
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'purchase_number' => $p->purchase_number,
                'supplier_name' => $p->supplier?->name,
                'purchased_at' => $p->purchased_at?->toIso8601String(),
                'notes' => $p->notes,
                'dpp_amount' => $p->dpp_amount,
                'ppn_amount' => $p->ppn_amount,
                'total_amount' => $p->total_amount,
                'supplier_tax_invoice_no' => $p->supplier_tax_invoice_no,
                'is_paid' => $p->is_paid,
                'payment_method' => $p->payment_method,
                'amount_paid' => $p->amount_paid,
                'due_date' => $p->due_date?->toDateString(),
                'lines' => $p->lines->map(fn ($l) => [
                    'product_name' => $l->product?->name,
                    'product_name_snapshot' => $l->product_name_snapshot,
                    'sku_snapshot' => $l->sku_snapshot,
                    'product_variant_name' => $l->productVariant?->name,
                    'quantity' => $l->quantity,
                    'unit_cost' => $l->unit_cost,
                    'subtotal' => $l->subtotal,
                    'batch_code' => $l->batch_code,
                    'batch_expiry_date' => $l->batch_expiry_date?->toDateString(),
                ])->toArray(),
                'created_at' => $p->created_at->toIso8601String(),
            ])
            ->toArray();
    }

    private function exportPurchaseReturns(Store $store): array
    {
        return $store->purchaseReturns()
            ->with(['supplier', 'lines.product'])
            ->orderBy('id')
            ->get()
            ->map(fn ($pr) => [
                'id' => $pr->id,
                'return_number' => $pr->return_number,
                'supplier_name' => $pr->supplier?->name,
                'returned_at' => $pr->returned_at?->toIso8601String(),
                'notes' => $pr->notes,
                'lines' => $pr->lines->map(fn ($l) => [
                    'product_name' => $l->product?->name,
                    'quantity' => $l->quantity,
                ])->toArray(),
                'created_at' => $pr->created_at->toIso8601String(),
            ])
            ->toArray();
    }

    private function exportStockMovements(Store $store): array
    {
        return $store->stockMovements()
            ->with(['product', 'productVariant'])
            ->orderBy('id')
            ->get()
            ->map(fn ($sm) => [
                'id' => $sm->id,
                'product_name' => $sm->product?->name,
                'variant_name' => $sm->productVariant?->name,
                'type' => $sm->type,
                'quantity_change' => $sm->quantity_change,
                'balance_after' => $sm->balance_after,
                'notes' => $sm->notes,
                'reference_type' => $sm->reference_type,
                'reference_id' => $sm->reference_id,
                'created_at' => $sm->created_at->toIso8601String(),
            ])
            ->toArray();
    }

    private function exportStockTransfers(Store $store): array
    {
        return StockTransfer::query()
            ->where('from_store_id', $store->id)
            ->orWhere('to_store_id', $store->id)
            ->with(['fromStore:id,name', 'toStore:id,name', 'lines.fromProduct'])
            ->orderBy('id')
            ->get()
            ->map(fn ($st) => [
                'id' => $st->id,
                'from_store_name' => $st->fromStore?->name,
                'to_store_name' => $st->toStore?->name,
                'notes' => $st->notes,
                'status' => $st->status,
                'lines' => $st->lines->map(fn ($l) => [
                    'product_name' => $l->fromProduct?->name,
                    'quantity' => $l->quantity,
                ])->toArray(),
                'sent_at' => $st->sent_at?->toIso8601String(),
                'received_at' => $st->received_at?->toIso8601String(),
                'created_at' => $st->created_at->toIso8601String(),
            ])
            ->toArray();
    }

    private function exportPreOrders(Store $store): array
    {
        return $store->preOrders()
            ->with(['customer', 'lines.product'])
            ->orderBy('id')
            ->get()
            ->map(fn ($po) => [
                'id' => $po->id,
                'order_number' => $po->order_number,
                'customer_name' => $po->customer?->name,
                'deposit_received' => $po->deposit_received,
                'estimated_total' => $po->estimated_total,
                'expected_pickup_at' => $po->expected_pickup_at?->toIso8601String(),
                'notes' => $po->notes,
                'status' => $po->status,
                'lines' => $po->lines->map(fn ($l) => [
                    'product_name' => $l->product_name,
                    'quantity' => $l->quantity,
                    'unit_price' => $l->unit_price,
                ])->toArray(),
                'created_at' => $po->created_at->toIso8601String(),
            ])
            ->toArray();
    }

    private function exportDiscounts(Store $store): array
    {
        return $store->discounts()
            ->orderBy('id')
            ->get()
            ->map(fn ($d) => [
                'id' => $d->id,
                'name' => $d->name,
                'code' => $d->code,
                'type' => $d->type,
                'value' => $d->value,
                'minimum_amount' => $d->minimum_amount,
                'maximum_uses' => $d->maximum_uses,
                'used_count' => $d->used_count,
                'starts_at' => $d->starts_at?->toIso8601String(),
                'expires_at' => $d->expires_at?->toIso8601String(),
                'is_active' => $d->is_active,
                'description' => $d->description,
                'created_at' => $d->created_at->toIso8601String(),
            ])
            ->toArray();
    }

    private function exportCashShifts(Store $store): array
    {
        return $store->cashShifts()
            ->with('user:id,name')
            ->orderBy('id')
            ->get()
            ->map(fn ($cs) => [
                'id' => $cs->id,
                'user_name' => $cs->user?->name,
                'opening_cash' => $cs->opening_cash,
                'expected_cash' => $cs->expected_cash,
                'closing_cash_counted' => $cs->closing_cash_counted,
                'difference_amount' => $cs->difference_amount,
                'closing_notes' => $cs->closing_notes,
                'opened_at' => $cs->opened_at?->toIso8601String(),
                'closed_at' => $cs->closed_at?->toIso8601String(),
                'created_at' => $cs->created_at->toIso8601String(),
            ])
            ->toArray();
    }

    private function exportAccounting(Store $store): array
    {
        return [
            'chart_of_accounts' => $store->chartOfAccounts()
                ->orderBy('code')
                ->get()
                ->map(fn ($coa) => [
                    'id' => $coa->id,
                    'code' => $coa->code,
                    'name' => $coa->name,
                    'type' => $coa->type,
                    'is_system' => $coa->is_system,
                    'created_at' => $coa->created_at->toIso8601String(),
                ])
                ->toArray(),
            'journal_entries' => $store->journalEntries()
                ->with('lines.chartOfAccount')
                ->orderBy('id')
                ->get()
                ->map(fn ($je) => [
                    'id' => $je->id,
                    'entry_date' => $je->entry_date?->toDateString(),
                    'reference_type' => $je->reference_type,
                    'reference_id' => $je->reference_id,
                    'description' => $je->description,
                    'lines' => $je->lines->map(fn ($l) => [
                        'account_code' => $l->chartOfAccount?->code,
                        'account_name' => $l->chartOfAccount?->name,
                        'debit' => $l->debit,
                        'credit' => $l->credit,
                        'memo' => $l->memo,
                    ])->toArray(),
                    'created_at' => $je->created_at->toIso8601String(),
                ])
                ->toArray(),
        ];
    }
}
