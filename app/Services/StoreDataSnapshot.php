<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Snapshot mentah (baris apa adanya) seluruh tabel data milik satu toko.
 *
 * Dipakai StoreBackupService (backup v2) supaya restore bisa memulihkan data
 * secara utuh: transaksi, stok, pembelian, jurnal, shift kas, dll — lengkap
 * dengan relasi antar-tabel. ID asli dipertahankan bila tidak bentrok; bila
 * bentrok (mis. restore ke toko/server lain) ID baru dibuat dan semua kolom
 * relasi (FK, kolom *_id tanpa FK, dan referensi polimorfik) dipetakan ulang.
 */
class StoreDataSnapshot
{
    /**
     * Urutan tabel = urutan restore (induk sebelum anak). Wipe berjalan terbalik.
     * Nilai: 'store' = punya kolom store_id, [kolom, tabel induk] = tabel anak,
     * 'transfer' = stock_transfers (from_store_id / to_store_id).
     */
    private const TABLES = [
        'product_categories' => 'store',
        'products' => 'store',
        'product_variants' => 'store',
        'product_bundle_items' => ['bundle_product_id', 'products'],
        'product_batches' => 'store',
        'customers' => 'store',
        'suppliers' => 'store',
        'discounts' => 'store',
        'chart_of_accounts' => 'store',
        'journal_entries' => 'store',
        'journal_lines' => ['journal_entry_id', 'journal_entries'],
        'accounting_periods' => 'store',
        'financial_report_statuses' => 'store',
        'cash_shifts' => 'store',
        'cashiers' => 'store',
        'tables' => 'store',
        'queues' => 'store',
        'loyalty_rewards' => 'store',
        'transactions' => 'store',
        'transaction_items' => ['transaction_id', 'transactions'],
        'transaction_payments' => 'store',
        'receivable_payments' => 'store',
        'sales_returns' => 'store',
        'sales_return_lines' => ['sales_return_id', 'sales_returns'],
        'purchases' => 'store',
        'purchase_lines' => ['purchase_id', 'purchases'],
        'purchase_charges' => 'store',
        'purchase_payments' => ['purchase_id', 'purchases'],
        'purchase_returns' => 'store',
        'purchase_return_lines' => ['purchase_return_id', 'purchase_returns'],
        'stock_adjustments' => 'store',
        'stock_adjustment_lines' => ['stock_adjustment_id', 'stock_adjustments'],
        'stock_opname_imports' => 'store',
        'stock_transfers' => 'transfer',
        'stock_transfer_lines' => ['stock_transfer_id', 'stock_transfers'],
        'stock_transfer_line_batches' => ['stock_transfer_line_id', 'stock_transfer_lines'],
        'recipes' => 'store',
        'recipe_ingredients' => ['recipe_id', 'recipes'],
        'production_batches' => 'store',
        'production_batch_lines' => ['production_batch_id', 'production_batches'],
        'product_sale_consumptions' => 'store',
        'sale_consumption_allocations' => ['transaction_item_id', 'transaction_items'],
        'stock_movements' => 'store',
        'pre_orders' => 'store',
        'pre_order_lines' => ['pre_order_id', 'pre_orders'],
        'cash_transactions' => 'store',
        'operational_expenses' => 'store',
        'loyalty_point_ledgers' => 'store',
        'loyalty_redemptions' => 'store',
        'loyalty_notifications' => 'store',
        'laundry_orders' => 'store',
        'laundry_order_lines' => ['laundry_order_id', 'laundry_orders'],
        'queue_entries' => 'store',
    ];

    /** Kolom relasi yang tidak punya foreign key constraint di database. */
    private const IMPLICIT_REFERENCES = [
        'cash_shifts' => ['employee_id' => 'employees'],
        'transactions' => ['cash_shift_id' => 'cash_shifts'],
        'sales_returns' => ['transaction_id' => 'transactions', 'customer_id' => 'customers'],
        'sales_return_lines' => ['product_variant_id' => 'product_variants'],
        'purchase_returns' => ['purchase_id' => 'purchases', 'supplier_id' => 'suppliers'],
        'purchase_return_lines' => ['product_variant_id' => 'product_variants', 'product_batch_id' => 'product_batches'],
    ];

    /** Referensi polimorfik: tabel => [kolom tipe, kolom id]. */
    private const MORPH_REFERENCES = [
        'journal_entries' => ['reference_type', 'reference_id'],
        'stock_movements' => ['reference_type', 'reference_id'],
        'loyalty_point_ledgers' => ['source_type', 'source_id'],
    ];

    private const LABELS = [
        'products' => 'Produk',
        'product_categories' => 'Kategori produk',
        'customers' => 'Pelanggan',
        'suppliers' => 'Supplier',
        'transactions' => 'Transaksi penjualan',
        'transaction_items' => 'Item penjualan',
        'purchases' => 'Pembelian',
        'stock_movements' => 'Pergerakan stok',
        'stock_adjustments' => 'Penyesuaian/opname stok',
        'cash_shifts' => 'Shift kas',
        'cash_transactions' => 'Kas masuk/keluar',
        'chart_of_accounts' => 'Akun (COA)',
        'journal_entries' => 'Jurnal',
        'operational_expenses' => 'Biaya operasional',
    ];

    /** @var array<string, array<int|string, int|string>> id lama => id baru per tabel */
    private array $idMap = [];

    /** @var array<string, array<int|string, bool>> */
    private array $existsCache = [];

    /** @var array<string, array<string, string>>|null */
    private ?array $foreignKeys = null;

    /** @var array<string, array<int, string>>|null */
    private ?array $uniqueColumns = null;

    /**
     * Ambil semua baris data toko per tabel.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function export(int $storeId): array
    {
        $tables = [];

        foreach ($this->availableTables() as $table) {
            $tables[$table] = $this->scopedQuery($table, $storeId)
                ->orderBy("{$table}.id")
                ->get()
                ->map(fn ($row) => (array) $row)
                ->all();
        }

        return $tables;
    }

    /**
     * Hapus data toko lalu masukkan kembali baris dari snapshot.
     * Harus dipanggil di dalam DB::transaction.
     *
     * @param  array<string, array<int, array<string, mixed>>>  $tables
     */
    public function restore(int $storeId, array $tables, int $backupStoreId, ?int $fallbackUserId, array &$log): void
    {
        $this->idMap = [];
        $this->existsCache = [];
        $available = $this->availableTables();

        foreach (array_reverse($available) as $table) {
            $deleted = $this->scopedQuery($table, $storeId)->delete();
            if ($deleted > 0) {
                $log['restored'][] = "Dihapus {$table}: {$deleted} baris";
            }
        }

        $deferred = [];

        foreach ($available as $table) {
            $rows = $tables[$table] ?? [];
            $this->idMap[$table] = [];

            if ($rows === []) {
                continue;
            }

            $count = $this->restoreTable($table, $rows, $storeId, $backupStoreId, $fallbackUserId, $deferred, $log);

            $label = self::LABELS[$table] ?? $table;
            $log['restored'][] = "{$label}: {$count} baris dipulihkan";
        }

        $this->applyDeferredReferences($deferred);
        $this->remapMorphReferences($tables);

        foreach (array_diff(array_keys($tables), $available) as $missing) {
            $log['errors'][] = "Tabel {$missing} tidak ada di database ini, dilewati.";
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function restoreTable(
        string $table,
        array $rows,
        int $storeId,
        int $backupStoreId,
        ?int $fallbackUserId,
        array &$deferred,
        array &$log,
    ): int {
        $columns = collect(Schema::getColumns($table))->keyBy('name');
        $references = $this->referencesFor($table);
        $keepIds = $this->canKeepIds($table, array_column($rows, 'id'));
        $replacements = $this->uniqueReplacements($table, $rows, $columns->all(), $log);
        $skipped = 0;
        $buffer = [];

        foreach ($rows as $row) {
            $oldId = $row['id'];
            $row = array_intersect_key($row, $columns->all());

            foreach ($replacements[$oldId] ?? [] as $column => $value) {
                $row[$column] = $value;
            }

            foreach ($references as $column => $target) {
                if (! array_key_exists($column, $row) || $row[$column] === null) {
                    continue;
                }

                $nullable = (bool) ($columns[$column]['nullable'] ?? true);

                // Target belum dipulihkan (self-reference / urutan maju): isi null dulu, update nanti.
                if ($target === $table || (isset(self::TABLES[$target]) && ! isset($this->idMap[$target]))) {
                    $deferred[] = [$table, $column, $target, $oldId, $row[$column]];
                    $row[$column] = null;

                    continue;
                }

                $resolved = $this->resolveReference($target, $row[$column], $storeId, $backupStoreId, $fallbackUserId, $nullable);

                if ($resolved === false) {
                    $skipped++;

                    continue 2;
                }

                $row[$column] = $resolved;
            }

            if ($columns->has('store_id') && array_key_exists('store_id', $row) && ! isset($references['store_id'])) {
                $row['store_id'] = $storeId;
            }

            if ($keepIds) {
                $buffer[] = $row;
                $this->idMap[$table][$oldId] = $oldId;

                if (count($buffer) >= 500) {
                    DB::table($table)->insert($buffer);
                    $buffer = [];
                }
            } else {
                unset($row['id']);
                $this->idMap[$table][$oldId] = DB::table($table)->insertGetId($row);
            }
        }

        if ($buffer !== []) {
            DB::table($table)->insert($buffer);
        }

        if ($skipped > 0) {
            $log['errors'][] = "{$table}: {$skipped} baris dilewati karena data relasinya tidak ditemukan.";
        }

        return count($this->idMap[$table]);
    }

    /**
     * Tentukan nilai baru kolom relasi. false = baris harus dilewati.
     */
    private function resolveReference(
        string $target,
        int|string $oldId,
        int $storeId,
        int $backupStoreId,
        ?int $fallbackUserId,
        bool $nullable,
    ): int|string|null|false {
        if ($target === 'stores' && (int) $oldId === $backupStoreId) {
            return $storeId;
        }

        if (isset($this->idMap[$target]) && array_key_exists($oldId, $this->idMap[$target])) {
            return $this->idMap[$target][$oldId];
        }

        // Baris milik toko lain (mis. lawan transfer stok) atau user/karyawan yang masih ada.
        if ($this->rowExists($target, $oldId)) {
            return $oldId;
        }

        if ($nullable) {
            return null;
        }

        if ($target === 'users' && $fallbackUserId) {
            return $fallbackUserId;
        }

        return false;
    }

    /**
     * Isi kolom relasi yang ditunda (self-reference / target dipulihkan belakangan).
     */
    private function applyDeferredReferences(array $deferred): void
    {
        foreach ($deferred as [$table, $column, $target, $oldRowId, $oldRefId]) {
            $newRowId = $this->idMap[$table][$oldRowId] ?? null;
            $newRefId = $this->idMap[$target][$oldRefId] ?? null;

            if ($newRowId === null) {
                continue;
            }

            if ($newRefId === null && $this->rowExists($target, $oldRefId)) {
                $newRefId = $oldRefId;
            }

            if ($newRefId !== null) {
                DB::table($table)->where('id', $newRowId)->update([$column => $newRefId]);
            }
        }
    }

    /**
     * Petakan ulang reference_id polimorfik (mis. jurnal → transaksi) bila ID berubah.
     */
    private function remapMorphReferences(array $tables): void
    {
        foreach (self::MORPH_REFERENCES as $table => [$typeColumn, $idColumn]) {
            foreach ($tables[$table] ?? [] as $row) {
                $target = $this->morphTable($row[$typeColumn] ?? null);
                $newRowId = $this->idMap[$table][$row['id']] ?? null;
                $oldRefId = $row[$idColumn] ?? null;

                if (! $target || $newRowId === null || $oldRefId === null) {
                    continue;
                }

                $newRefId = $this->idMap[$target][$oldRefId] ?? null;

                if ($newRefId !== null && (string) $newRefId !== (string) $oldRefId) {
                    DB::table($table)->where('id', $newRowId)->update([$idColumn => $newRefId]);
                }
            }
        }
    }

    private function morphTable(?string $type): ?string
    {
        if (! $type) {
            return null;
        }

        $class = Relation::getMorphedModel($type) ?? $type;

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        $table = (new $class)->getTable();

        return isset(self::TABLES[$table]) ? $table : null;
    }

    /**
     * Nomor/token yang wajib unik di seluruh database (nomor transaksi, nomor shift,
     * status_token, dll.) dibuat baru bila sudah dipakai toko lain — terjadi saat
     * backup satu toko dipulihkan ke toko lain di server yang sama.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, array<string, mixed>>  $columns
     * @return array<int|string, array<string, string>> id baris lama => [kolom => nilai baru]
     */
    private function uniqueReplacements(string $table, array $rows, array $columns, array &$log): array
    {
        $this->uniqueColumns ??= $this->loadUniqueColumns();
        $replacements = [];

        foreach ($this->uniqueColumns[$table] ?? [] as $column) {
            $values = array_filter(
                array_column($rows, $column, 'id'),
                fn ($value) => $value !== null && $value !== ''
            );

            if ($values === []) {
                continue;
            }

            // Collation MySQL umumnya case-insensitive, jadi bandingkan dalam huruf kecil.
            $taken = [];
            foreach (array_chunk(array_values(array_unique($values)), 1000) as $chunk) {
                foreach (DB::table($table)->whereIn($column, $chunk)->pluck($column) as $value) {
                    $taken[mb_strtolower((string) $value)] = true;
                }
            }

            if ($taken === []) {
                continue;
            }

            $used = $taken + array_fill_keys(array_map(fn ($value) => mb_strtolower((string) $value), $values), true);
            $maxLength = $this->columnLength($columns[$column]['type'] ?? '');
            $changed = 0;

            foreach ($values as $id => $value) {
                if (! isset($taken[mb_strtolower((string) $value)])) {
                    continue;
                }

                do {
                    $fresh = $this->freshUniqueValue($column, (string) $value, $maxLength);
                } while (isset($used[mb_strtolower($fresh)]) || DB::table($table)->where($column, $fresh)->exists());

                $used[mb_strtolower($fresh)] = true;
                $replacements[$id][$column] = $fresh;
                $changed++;
            }

            $log['restored'][] = "{$table}: {$changed} {$column} dibuat baru karena sudah dipakai toko lain";
        }

        return $replacements;
    }

    private function freshUniqueValue(string $column, string $value, int $maxLength): string
    {
        if (Str::isUuid($value)) {
            return (string) Str::uuid();
        }

        // Token acak (status_token, dll.) cukup diganti token acak baru dengan panjang sama.
        if (str_ends_with($column, 'token')) {
            return Str::random(min(max(strlen($value), 16), $maxLength));
        }

        // Nomor dokumen tetap terbaca: TXN-20260901-J7RXU7 → TXN-20260901-J7RXU7-K3PQ
        $suffix = '-'.Str::upper(Str::random(4));

        return mb_substr($value, 0, max(1, $maxLength - strlen($suffix))).$suffix;
    }

    private function columnLength(string $type): int
    {
        return preg_match('/\((\d+)\)/', $type, $match) ? (int) $match[1] : 255;
    }

    /**
     * Kolom dengan unique index satu kolom (bukan id) per tabel.
     *
     * @return array<string, array<int, string>>
     */
    private function loadUniqueColumns(): array
    {
        $unique = [];
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $rows = DB::select(
                "select table_name as tbl, min(column_name) as col
                from information_schema.statistics
                where table_schema = database() and non_unique = 0 and index_name <> 'PRIMARY'
                group by table_name, index_name
                having count(*) = 1"
            );

            foreach ($rows as $row) {
                if ($row->col !== 'id') {
                    $unique[$row->tbl][] = $row->col;
                }
            }

            return $unique;
        }

        foreach ($this->availableTables() as $table) {
            foreach (Schema::getIndexes($table) as $index) {
                if ($index['unique'] && ! $index['primary'] && count($index['columns']) === 1 && $index['columns'][0] !== 'id') {
                    $unique[$table][] = $index['columns'][0];
                }
            }
        }

        return $unique;
    }

    /**
     * ID asli dipakai ulang hanya bila tidak ada satu pun yang sudah terpakai.
     */
    private function canKeepIds(string $table, array $ids): bool
    {
        foreach (array_chunk($ids, 1000) as $chunk) {
            if (DB::table($table)->whereIn('id', $chunk)->exists()) {
                return false;
            }
        }

        return true;
    }

    private function rowExists(string $table, int|string $id): bool
    {
        if (! isset($this->existsCache[$table][$id])) {
            $this->existsCache[$table][$id] = Schema::hasTable($table)
                && DB::table($table)->where('id', $id)->exists();
        }

        return $this->existsCache[$table][$id];
    }

    /**
     * @return array<string, string> kolom => tabel tujuan
     */
    private function referencesFor(string $table): array
    {
        $this->foreignKeys ??= $this->loadForeignKeys();

        return ($this->foreignKeys[$table] ?? []) + (self::IMPLICIT_REFERENCES[$table] ?? []);
    }

    /**
     * Semua foreign key satu kolom per tabel. Di MySQL diambil sekali jalan karena
     * Schema::getForeignKeys() per tabel bisa makan beberapa detik per panggilan.
     *
     * @return array<string, array<string, string>>
     */
    private function loadForeignKeys(): array
    {
        $references = [];
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $rows = DB::select(
                'select table_name as tbl, column_name as col, referenced_table_name as ref
                from information_schema.key_column_usage
                where table_schema = database() and referenced_table_name is not null'
            );

            foreach ($rows as $row) {
                $references[$row->tbl][$row->col] = $row->ref;
            }

            return $references;
        }

        foreach ($this->availableTables() as $table) {
            foreach (Schema::getForeignKeys($table) as $foreignKey) {
                if (count($foreignKey['columns']) === 1) {
                    $references[$table][$foreignKey['columns'][0]] = $foreignKey['foreign_table'];
                }
            }
        }

        return $references;
    }

    /**
     * @return array<int, string>
     */
    private function availableTables(): array
    {
        return array_values(array_filter(array_keys(self::TABLES), fn ($table) => Schema::hasTable($table)));
    }

    private function scopedQuery(string $table, int $storeId): Builder
    {
        $scope = self::TABLES[$table];

        if ($scope === 'store') {
            return DB::table($table)->where("{$table}.store_id", $storeId);
        }

        if ($scope === 'transfer') {
            return DB::table($table)->where(fn ($q) => $q
                ->where("{$table}.from_store_id", $storeId)
                ->orWhere("{$table}.to_store_id", $storeId));
        }

        [$column, $parent] = $scope;

        return DB::table($table)->whereIn(
            "{$table}.{$column}",
            $this->scopedQuery($parent, $storeId)->select("{$parent}.id")
        );
    }
}
