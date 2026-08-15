<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('queue_entries', function (Blueprint $table) {
            $table->string('order_type', 20)->nullable()->after('status');
            $table->string('table_number', 20)->nullable()->after('order_type');
            $table->json('items')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('queue_entries', function (Blueprint $table) {
            $table->dropColumn(['order_type', 'table_number', 'items']);
        });
    }
};
