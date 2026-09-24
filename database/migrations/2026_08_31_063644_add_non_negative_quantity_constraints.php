<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Menambah constraint CHECK >= 0 pada kolom kuantitas terkait pembelian & stok.
 *
 * Pertahanan tingkat database: mencegah nilai negatif masuk walau ada
 * penulisan bypass controller (seeder, job, artisan tinker, dll).
 */
return new class extends Migration
{
    /**
     * Daftar (tabel, kolom) yang akan ditambah constraint non-negatif.
     *
     * Catatan: stock_movements.quantity_change boleh negatif (retur/decrement),
     * dan products/product_variants.stock_quantity boleh negatif bila
     * allow_negative_stock aktif — keduanya TIDAK ditambah constraint di sini.
     *
     * @var array<int, array{table: string, column: string}>
     */
    private const NON_NEGATIVE_COLUMNS = [
        ['table' => 'purchase_lines', 'column' => 'quantity'],
        ['table' => 'purchase_lines', 'column' => 'quantity_input'],
        ['table' => 'purchase_lines', 'column' => 'qty_base'],
        ['table' => 'purchase_return_lines', 'column' => 'quantity'],
        ['table' => 'product_batches', 'column' => 'quantity_remaining'],
    ];

    public function up(): void
    {
        foreach (self::NON_NEGATIVE_COLUMNS as $target) {
            $table = $target['table'];
            $column = $target['column'];
            $constraintName = "{$table}_{$column}_nonneg";

            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            // Idempoten: lewati bila constraint sudah ada (MySQL / PostgreSQL).
            $driver = DB::getDriverName();

            if ($driver === 'sqlite') {
                // SQLite: tambah CHECK; tidak ada katalog nama, jadi langsung jalankan.
                DB::statement(
                    "ALTER TABLE \"{$table}\" ADD CONSTRAINT \"{$constraintName}\" CHECK (\"{$column}\" >= 0)"
                );
            } else {
                // MySQL 8.0.16+ / MariaDB 10.2+ / PostgreSQL mendukung ADD CONSTRAINT.
                // Cek katalog agar idempoten di lingkungan yang menjalankan ulang.
                $exists = $this->constraintExists($table, $constraintName, $driver);
                if (! $exists) {
                    DB::statement(
                        "ALTER TABLE `{$table}` ADD CONSTRAINT `{$constraintName}` CHECK (`{$column}` >= 0)"
                    );
                }
            }
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        foreach (self::NON_NEGATIVE_COLUMNS as $target) {
            $table = $target['table'];
            $column = $target['column'];
            $constraintName = "{$table}_{$column}_nonneg";

            if (! Schema::hasTable($table)) {
                continue;
            }

            try {
                if ($driver === 'pgsql') {
                    DB::statement("ALTER TABLE \"{$table}\" DROP CONSTRAINT IF EXISTS \"{$constraintName}\"");
                } else {
                    DB::statement("ALTER TABLE `{$table}` DROP CHECK `{$constraintName}`");
                }
            } catch (\Throwable) {
                // Constraint mungkin tidak ada / nama otomatis berbeda — abaikan.
            }
        }
    }

    /**
     * Cek apakah constraint sudah ada di katalog database.
     */
    private function constraintExists(string $table, string $constraintName, string $driver): bool
    {
        if ($driver === 'mysql' || $driver === 'mariadb') {
            return (bool) DB::table('information_schema.TABLE_CONSTRAINTS')
                ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
                ->where('TABLE_NAME', $table)
                ->where('CONSTRAINT_NAME', $constraintName)
                ->exists();
        }

        if ($driver === 'pgsql') {
            return (bool) DB::table('information_schema.table_constraints')
                ->where('table_name', $table)
                ->where('constraint_name', $constraintName)
                ->exists();
        }

        return false;
    }
};
