<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chart_of_accounts', function (Blueprint $table) {
            $table->string('balance_sheet_group', 32)->nullable()->after('type');
            $table->index(['store_id', 'type', 'balance_sheet_group']);
        });
        DB::table('chart_of_accounts')->where('type', 'asset')->update(['balance_sheet_group' => 'current_asset']);
        DB::table('chart_of_accounts')->where('type', 'liability')->update(['balance_sheet_group' => 'current_liability']);
        DB::table('chart_of_accounts')->where('type', 'equity')->update(['balance_sheet_group' => 'equity']);
    }

    public function down(): void
    {
        Schema::table('chart_of_accounts', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'type', 'balance_sheet_group']);
            $table->dropColumn('balance_sheet_group');
        });
    }
};
