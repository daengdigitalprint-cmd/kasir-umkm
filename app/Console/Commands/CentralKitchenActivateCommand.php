<?php

namespace App\Console\Commands;

use App\Models\Organization;
use Illuminate\Console\Command;

/**
 * Aktivasi manual Gudang Pusat Pro per grup usaha, dipakai sampai alur
 * pembayaran add-on (R2) tersedia. Contoh:
 *   php artisan central-kitchen:activate 12 --days=365
 *   php artisan central-kitchen:activate 12 --until=2027-11-30
 *   php artisan central-kitchen:activate 12 --off
 */
class CentralKitchenActivateCommand extends Command
{
    protected $signature = 'central-kitchen:activate
        {organization : ID grup usaha}
        {--days= : Tambah masa aktif sekian hari dari sekarang (atau dari masa aktif yang masih berjalan)}
        {--until= : Tanggal akhir masa aktif (YYYY-MM-DD)}
        {--off : Matikan modul sekarang}';

    protected $description = 'Aktifkan / perpanjang / matikan Gudang Pusat Pro untuk satu grup usaha';

    public function handle(): int
    {
        $organization = Organization::query()->find($this->argument('organization'));
        if (! $organization) {
            $this->error('Grup usaha tidak ditemukan.');

            return self::FAILURE;
        }

        if ($this->option('off')) {
            $organization->update(['central_kitchen_until' => null]);
            $this->info("Gudang Pusat Pro dimatikan untuk «{$organization->name}».");

            return self::SUCCESS;
        }

        if ($until = $this->option('until')) {
            $date = \Illuminate\Support\Carbon::parse($until)->endOfDay();
        } elseif ($days = (int) $this->option('days')) {
            $current = $organization->central_kitchen_until;
            $date = ($current && $current->isFuture() ? $current->copy() : now())->addDays($days);
        } else {
            $this->error('Isi --days, --until, atau --off.');

            return self::FAILURE;
        }

        $organization->update(['central_kitchen_until' => $date]);
        $this->info("Gudang Pusat Pro «{$organization->name}» aktif sampai {$date->format('d M Y')}.");

        return self::SUCCESS;
    }
}
