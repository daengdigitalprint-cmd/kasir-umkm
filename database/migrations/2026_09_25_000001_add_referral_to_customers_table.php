<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // Kode referral unik milik pelanggan (dibagikan ke calon pelanggan baru).
            $table->string('referral_code', 20)->nullable()->unique()->after('gender');
            // Pelanggan yang mendaftarkan pelanggan ini lewat kode referral.
            $table->foreignId('referred_by')->nullable()->constrained('customers')->nullOnDelete()->after('referral_code');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('referred_by');
            $table->dropColumn('referral_code');
        });
    }
};
