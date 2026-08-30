<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->updateOrCreate(
            ['email' => 'superadmin@umkm-kasir.com'],
            [
                'name' => 'Super Admin',
                'password' => 'password',
                'business_type' => 'general',
                'store_id' => null,
                'role' => 'admin',
                'is_active' => true,
                'is_super_admin' => true,
                'email_verified_at' => now(),
            ],
        );
    }
}
