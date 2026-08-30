<?php

namespace App\Console\Commands;

use App\Models\RoleMenuPermission;
use Illuminate\Console\Command;

class AddOwnerMenuPermissions extends Command
{
    protected $signature = 'permission:add-owner-menus';

    protected $description = 'Menambahkan menu Laporan dan Biaya Usaha ke role Owner';

    public function handle()
    {
        try {
            // Tambahkan menu 'reports'
            RoleMenuPermission::updateOrCreate(
                ['role' => 'owner', 'menu_key' => 'reports']
            );
            $this->info('✓ Menu "Laporan" (reports) berhasil ditambahkan untuk role Owner');

            // Tambahkan menu 'expenses'
            RoleMenuPermission::updateOrCreate(
                ['role' => 'owner', 'menu_key' => 'expenses']
            );
            $this->info('✓ Menu "Biaya Usaha" (expenses) berhasil ditambahkan untuk role Owner');

            // Tampilkan semua menu yang dimiliki role owner
            $ownerMenus = RoleMenuPermission::where('role', 'owner')
                ->orderBy('menu_key')
                ->pluck('menu_key')
                ->toArray();

            $this->newLine();
            $this->info('Daftar lengkap menu untuk role Owner:');
            $this->table(['Menu Key'], array_map(fn ($menu) => [$menu], $ownerMenus));

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Error: '.$e->getMessage());

            return Command::FAILURE;
        }
    }
}
