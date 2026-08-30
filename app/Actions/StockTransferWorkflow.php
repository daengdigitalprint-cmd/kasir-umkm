<?php

namespace App\Actions;

use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\StockTransferLine;
use App\Models\Store;
use App\Models\User;
use App\Support\InventoryCosting;
use App\Support\ProductQuantity;
use App\Support\StoreLocationType;
use App\Support\TransferStockOperations;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StockTransferWorkflow
{
    public function createDraft(User $user, Store $fromStore, Store $toStore, array $lines, ?string $notes = null): StockTransfer
    {
        $this->assertStoreAccess($user, $fromStore, 'asal');
        $this->assertStoreAccess($user, $toStore, 'tujuan');
        if ((int) $fromStore->id === (int) $toStore->id) {
            throw ValidationException::withMessages(['to_store_id' => 'Pilih lokasi tujuan yang berbeda.']);
        }
        if (! StoreLocationType::sharesOrganization($fromStore, $toStore)) {
            throw ValidationException::withMessages(['to_store_id' => 'Transfer hanya diperbolehkan antar lokasi dalam grup usaha yang sama.']);
        }

        $ids = collect($lines)->pluck('from_product_id')->map(fn ($id) => (int) $id);
        if ($ids->count() !== $ids->unique()->count()) {
            throw ValidationException::withMessages(['lines' => 'Produk yang sama tidak boleh duplikat. Gabung jumlahnya dalam satu baris.']);
        }

        return DB::transaction(function () use ($user, $fromStore, $toStore, $lines, $notes, $ids) {
            $sources = Product::query()->whereIn('id', $ids->sort()->values())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $resolved = [];

            foreach ($lines as $index => $row) {
                $source = $sources->get((int) $row['from_product_id']);
                $this->assertTransferProduct($source, $fromStore, $index, 'asal');
                $quantity = ProductQuantity::normalizeLineQuantity($source, $row['quantity']);
                $destination = $this->findDestinationProduct($toStore, $source);
                if (! $destination) {
                    throw ValidationException::withMessages(["lines.{$index}.from_product_id" => 'Tidak ada produk tujuan aktif dengan SKU atau barcode yang cocok.']);
                }
                $this->assertCompatible($source, $destination, $index);
                if (! $destination->allows_decimal && ProductQuantity::cmp($quantity, (string) (int) floor((float) $quantity)) !== 0) {
                    throw ValidationException::withMessages(["lines.{$index}.quantity" => 'Produk tujuan tidak mendukung kuantitas desimal.']);
                }

                $resolved[] = compact('source', 'destination', 'quantity');
            }

            Product::query()
                ->whereIn('id', collect($resolved)->pluck('destination.id')->unique()->sort()->values())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $transfer = StockTransfer::create([
                'from_store_id' => $fromStore->id,
                'to_store_id' => $toStore->id,
                'user_id' => $user->id,
                'notes' => $notes,
                'status' => StockTransfer::STATUS_DRAFT,
            ]);

            foreach ($resolved as $item) {
                StockTransferLine::create([
                    'stock_transfer_id' => $transfer->id,
                    'from_product_id' => $item['source']->id,
                    'to_product_id' => $item['destination']->id,
                    'quantity' => $item['quantity'],
                    'base_unit' => $item['source']->baseUnit(),
                    'inventory_type' => $item['source']->inventoryType(),
                ]);
            }

            return $transfer->load('lines');
        });
    }

    public function send(StockTransfer $transfer, User $user, Store $currentStore): StockTransfer
    {
        $this->assertStoreAccess($user, $currentStore, 'asal');

        return DB::transaction(function () use ($transfer, $user, $currentStore) {
            $locked = StockTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            if ((int) $locked->from_store_id !== (int) $currentStore->id) {
                throw ValidationException::withMessages(['transfer' => 'Aktifkan lokasi asal untuk mengirim transfer.']);
            }
            if (! $locked->canSend()) {
                throw ValidationException::withMessages(['transfer' => 'Hanya transfer berstatus draft yang dapat dikirim.']);
            }

            $lines = $locked->lines()->orderBy('from_product_id')->get();
            $products = Product::query()->whereIn('id', $lines->pluck('from_product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            foreach ($lines as $index => $line) {
                $product = $products->get($line->from_product_id);
                $this->assertTransferProduct($product, $currentStore, $index, 'asal');
                if ($product->baseUnit() !== $line->base_unit || $product->inventoryType() !== $line->inventory_type) {
                    throw ValidationException::withMessages(["lines.{$index}" => 'Satuan dasar atau tipe persediaan produk asal berubah sejak draft dibuat.']);
                }
                if (ProductQuantity::lt((string) $product->stock_quantity, $line->quantity)) {
                    throw ValidationException::withMessages(["lines.{$index}.quantity" => "Stok terbaru «{$product->name}» tidak mencukupi untuk dikirim."]);
                }
            }

            foreach ($lines as $line) {
                $product = $products->get($line->from_product_id);
                $unitCost = InventoryCosting::currentAverageCost($product);
                $line->update([
                    'unit_cost_snapshot' => $unitCost,
                    'total_cost_snapshot' => InventoryCosting::extendedCost($line->quantity, $unitCost, 2),
                ]);
                $line->setRelation('transfer', $locked);
                TransferStockOperations::deductAtSource($currentStore, $user, $product, $line->quantity, $line);
            }

            $locked->update([
                'status' => StockTransfer::STATUS_SENT,
                'sent_at' => now(),
                'sent_by' => $user->id,
            ]);

            return $locked->fresh('lines');
        });
    }

    public function receive(StockTransfer $transfer, User $user, Store $currentStore): StockTransfer
    {
        $this->assertStoreAccess($user, $currentStore, 'tujuan');

        return DB::transaction(function () use ($transfer, $user, $currentStore) {
            $locked = StockTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            if ((int) $locked->to_store_id !== (int) $currentStore->id) {
                throw ValidationException::withMessages(['transfer' => 'Aktifkan lokasi tujuan untuk menerima transfer.']);
            }
            if (! $locked->canReceive()) {
                throw ValidationException::withMessages(['transfer' => 'Hanya transfer berstatus terkirim yang dapat diterima.']);
            }

            $lines = $locked->lines()->orderBy('to_product_id')->get();
            $products = Product::query()->whereIn('id', $lines->pluck('to_product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            foreach ($lines as $index => $line) {
                $product = $products->get($line->to_product_id);
                $this->assertTransferProduct($product, $currentStore, $index, 'tujuan');
                if ($product->baseUnit() !== $line->base_unit || $product->inventoryType() !== $line->inventory_type) {
                    throw ValidationException::withMessages(["lines.{$index}" => 'Produk tujuan tidak lagi kompatibel dengan snapshot satuan atau tipe persediaan transfer.']);
                }
                if ($line->unit_cost_snapshot === null) {
                    throw ValidationException::withMessages(["lines.{$index}" => 'Snapshot biaya transfer belum tersedia.']);
                }
            }

            foreach ($lines as $line) {
                $product = $products->get($line->to_product_id);
                $averageCost = InventoryCosting::averageCostAfterInbound($product, $line->quantity, $line->unit_cost_snapshot);
                $product->update([
                    'average_cost' => $averageCost,
                    'cost_price' => round((float) $line->unit_cost_snapshot, 2),
                ]);
                $line->setRelation('transfer', $locked);
                TransferStockOperations::addAtDestination($currentStore, $user, $product, $line->quantity, $line);
            }

            $locked->update([
                'status' => StockTransfer::STATUS_RECEIVED,
                'received_at' => now(),
                'received_by' => $user->id,
            ]);

            return $locked->fresh('lines');
        });
    }

    public function cancel(StockTransfer $transfer, User $user, Store $currentStore, string $reason): StockTransfer
    {
        $this->assertStoreAccess($user, $currentStore, 'asal');

        return DB::transaction(function () use ($transfer, $user, $currentStore, $reason) {
            $locked = StockTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            if ((int) $locked->from_store_id !== (int) $currentStore->id) {
                throw ValidationException::withMessages(['transfer' => 'Aktifkan lokasi asal untuk membatalkan transfer.']);
            }
            if (! $locked->canCancel()) {
                throw ValidationException::withMessages(['transfer' => 'Transfer yang sudah diterima atau dibatalkan tidak dapat dibatalkan.']);
            }

            if ($locked->status === StockTransfer::STATUS_SENT) {
                $lines = $locked->lines()->orderBy('from_product_id')->get();
                $products = Product::query()->whereIn('id', $lines->pluck('from_product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                foreach ($lines as $index => $line) {
                    $this->assertTransferProduct($products->get($line->from_product_id), $currentStore, $index, 'asal');
                }
                foreach ($lines as $line) {
                    $line->setRelation('transfer', $locked);
                    TransferStockOperations::restoreAtSource($currentStore, $user, $products->get($line->from_product_id), $line->quantity, $line);
                }
            }

            $locked->update([
                'status' => StockTransfer::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $user->id,
                'cancellation_reason' => $reason,
            ]);

            return $locked->fresh('lines');
        });
    }

    public function findDestinationProduct(Store $store, Product $source): ?Product
    {
        $query = Product::query()
            ->where('store_id', $store->id)
            ->where('track_stock', true)
            ->where('has_variants', false)
            ->where('is_bundle', false)
            ->where('is_active', true);

        $sku = trim((string) $source->sku);
        if ($sku !== '') {
            $match = (clone $query)->whereRaw('LOWER(TRIM(sku)) = ?', [mb_strtolower($sku)])->first();
            if ($match) {
                return $match;
            }
        }

        $barcode = trim((string) $source->barcode);

        return $barcode === '' ? null : (clone $query)->where('barcode', $barcode)->first();
    }

    private function assertStoreAccess(User $user, Store $store, string $role): void
    {
        if (! $store->is_active) {
            throw ValidationException::withMessages(['store' => "Lokasi {$role} tidak aktif."]);
        }
        // SuperAdmin, owner, admin, manager, warehouse boleh mengakses semua toko aktif dalam grup.
        if ($user->isSuperAdmin() || $user->isOwner() || $user->isAdmin() || $user->isManager() || $user->isWarehouseStaff()) {
            return;
        }
        if (! $user->isMemberOfStore($store)) {
            throw ValidationException::withMessages(['store' => "Anda bukan anggota lokasi {$role}."]);
        }
    }

    private function assertTransferProduct(?Product $product, Store $store, int $index, string $role): void
    {
        if (! $product || (int) $product->store_id !== (int) $store->id) {
            throw ValidationException::withMessages(["lines.{$index}" => "Produk {$role} tidak valid untuk lokasi aktif."]);
        }
        if (! $product->track_stock || $product->has_variants || $product->is_bundle || ! $product->is_active) {
            throw ValidationException::withMessages(["lines.{$index}" => "Produk {$role} tidak didukung untuk transfer."]);
        }
    }

    private function assertCompatible(Product $source, Product $destination, int $index): void
    {
        if ($source->baseUnit() !== $destination->baseUnit()) {
            throw ValidationException::withMessages(["lines.{$index}.from_product_id" => 'Satuan dasar produk asal dan tujuan harus sama.']);
        }
        if ($source->inventoryType() !== $destination->inventoryType()) {
            throw ValidationException::withMessages(["lines.{$index}.from_product_id" => 'Tipe persediaan produk asal dan tujuan harus sama.']);
        }
    }
}
