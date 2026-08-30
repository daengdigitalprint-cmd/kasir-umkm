<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_menu_permissions', function (Blueprint $table) {
            $table->id();
            $table->string('role', 32);
            $table->string('menu_key', 64);
            $table->timestamps();

            $table->unique(['role', 'menu_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_menu_permissions');
    }
};
