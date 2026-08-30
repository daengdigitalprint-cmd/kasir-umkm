<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Catatan: migrasi pembuatan tabel `purchases` bertanggal lebih baru
        // (2026_04_10). Pada database baru, tabel belum ada saat migrasi ini
        // berjalan; kolom pajak sudah disertakan langsung di migrasi pembuatan.
        // Guard di bawah menjaga migrasi tetap idempoten & aman untuk DB lama.
        if (! Schema::hasTable('purchases')) {
            return;
        }

        Schema::table('purchases', function (Blueprint $table) {
            if (! Schema::hasColumn('purchases', 'dpp_amount')) {
                $table->decimal('dpp_amount', 15, 2)->nullable()->after('notes');
            }
            if (! Schema::hasColumn('purchases', 'ppn_amount')) {
                $table->decimal('ppn_amount', 15, 2)->nullable()->after('dpp_amount');
            }
            if (! Schema::hasColumn('purchases', 'total_amount')) {
                $table->decimal('total_amount', 15, 2)->nullable()->after('ppn_amount');
            }
            if (! Schema::hasColumn('purchases', 'supplier_tax_invoice_no')) {
                $table->string('supplier_tax_invoice_no', 120)->nullable()->after('total_amount');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('purchases')) {
            return;
        }

        Schema::table('purchases', function (Blueprint $table) {
            $table->dropColumn(['dpp_amount', 'ppn_amount', 'total_amount', 'supplier_tax_invoice_no']);
        });
    }
};
