<?php

namespace Database\Seeders;

use App\Models\Store;
use Illuminate\Database\Seeder;

class StoreSubscriptionSeeder extends Seeder
{
    public function run(): void
    {
        $stores = Store::all();

        foreach ($stores as $store) {
            $store->update([
                'subscription_start' => now(),
                'subscription_end' => now()->addMonth(),
                'subscription_days' => 30,
                'is_subscription_active' => true,
                'subscription_notes' => 'Seeder: Masa aktif 1 bulan diberikan otomatis',
            ]);
        }

        $this->command->info("Berhasil memberikan masa aktif 1 bulan untuk {$stores->count()} toko.");
    }
}
