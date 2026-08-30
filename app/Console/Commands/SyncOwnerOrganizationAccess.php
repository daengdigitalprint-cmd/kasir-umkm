<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncOwnerOrganizationAccess extends Command
{
    protected $signature = 'owner:sync-organization';

    protected $description = 'Backfill akses owner ke semua outlet dalam satu organization (grup usaha)';

    public function handle(): int
    {
        $ownerUserIds = DB::table('store_user')
            ->where('role', 'owner')
            ->distinct()
            ->pluck('user_id')
            ->all();

        if (empty($ownerUserIds)) {
            $this->info('Tidak ada user dengan role owner. Tidak ada yang perlu disinkronkan.');

            return self::SUCCESS;
        }

        $this->info('Ditemukan '.count($ownerUserIds).' user dengan role owner. Memulai sinkronisasi...');
        $this->newLine();

        $totalAttached = 0;
        $processed = 0;
        $failed = 0;

        foreach ($ownerUserIds as $userId) {
            $user = User::find($userId);

            if (! $user) {
                continue;
            }

            try {
                // Semua store di mana user punya pivot role owner
                $ownerStores = $user->stores()->wherePivot('role', 'owner')->get();

                if ($ownerStores->isEmpty()) {
                    $this->warn("  {$user->name} (ID: {$user->id}): tidak punya pivot owner, dilewati.");

                    continue;
                }

                foreach ($ownerStores as $store) {
                    $user->attachAsOwnerToAllStoresInOrganization($store);
                }

                $processed++;
                $orgCount = $ownerStores->map->organization_id->filter()->unique()->count();
                $this->info("  ✓ {$user->name} (ID: {$user->id}) — {$orgCount} grup usaha, {$ownerStores->count()} outlet owner");
            } catch (\Throwable $e) {
                $failed++;
                $this->error("  ✗ {$user->name} (ID: {$user->id}): {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->info("Selesai. Diproses: {$processed}, Gagal: {$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
