<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            if (! Schema::hasColumn('employees', 'pin_hash')) {
                $table->string('pin_hash')->nullable()->after('is_active');
            }
            if (! Schema::hasColumn('employees', 'pin_set_at')) {
                $table->timestamp('pin_set_at')->nullable()->after('pin_hash');
            }
            if (! Schema::hasColumn('employees', 'can_login_pos')) {
                $table->boolean('can_login_pos')->default(false)->after('pin_set_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['pin_hash', 'pin_set_at', 'can_login_pos']);
        });
    }
};
