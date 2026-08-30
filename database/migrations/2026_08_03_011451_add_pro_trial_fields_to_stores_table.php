<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->boolean('pro_trial_used')->default(false)->after('subscription_notes');
            $table->boolean('pro_trial_active')->default(false)->after('pro_trial_used');
            $table->timestamp('pro_trial_start')->nullable()->after('pro_trial_active');
            $table->timestamp('pro_trial_end')->nullable()->after('pro_trial_start');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['pro_trial_used', 'pro_trial_active', 'pro_trial_start', 'pro_trial_end']);
        });
    }
};
