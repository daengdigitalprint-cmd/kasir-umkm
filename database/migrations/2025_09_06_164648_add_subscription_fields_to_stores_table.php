<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->timestamp('subscription_start')->nullable()->after('is_active');
            $table->timestamp('subscription_end')->nullable()->after('subscription_start');
            $table->integer('subscription_days')->default(30)->after('subscription_end');
            $table->boolean('is_subscription_active')->default(true)->after('subscription_days');
            $table->text('subscription_notes')->nullable()->after('is_subscription_active');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn([
                'subscription_start',
                'subscription_end',
                'subscription_days',
                'is_subscription_active',
                'subscription_notes',
            ]);
        });
    }
};
