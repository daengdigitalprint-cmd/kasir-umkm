<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aplikasi Absensi Android: satu karyawan terikat ke satu HP.
 * HP pertama yang dipakai absen terdaftar otomatis; ganti HP harus dilepas admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('device_id', 100)->nullable()->after('can_login_pos');
            $table->string('device_name', 150)->nullable()->after('device_id');
            $table->timestamp('device_bound_at')->nullable()->after('device_name');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['device_id', 'device_name', 'device_bound_at']);
        });
    }
};
