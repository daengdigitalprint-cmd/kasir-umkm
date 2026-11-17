<?php

namespace App\Console\Commands;

use Database\Seeders\DemoCentralKitchenSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Buat / reset akun demo Central Kitchen (1 CK + 2 outlet F&B) untuk presentasi.
 *
 *   php artisan demo:central-kitchen                 (local, kata sandi "password")
 *   php artisan demo:central-kitchen --production    (server, kata sandi acak kuat)
 *
 * Hanya menyentuh grup "demo-resto-nusantara" dan 5 akun demo.*@kasirumkm.test.
 */
class DemoCentralKitchenCommand extends Command
{
    protected $signature = 'demo:central-kitchen
        {--production : Izinkan berjalan di lingkungan production}
        {--password= : Kata sandi akun demo (minimal 12 karakter). Kosong = dibuat acak di production}';

    protected $description = 'Buat / reset akun demo Central Kitchen (1 CK + 2 outlet) dengan data dummy';

    public function handle(): int
    {
        $isLocal = app()->environment(['local', 'testing']);
        if (! $isLocal && ! $this->option('production')) {
            $this->error('Ini lingkungan '.app()->environment().'. Tambahkan --production bila memang ingin membuat akun demo di sini.');

            return self::FAILURE;
        }

        $password = $this->option('password');
        if ($password !== null && mb_strlen($password) < 12) {
            $this->error('Kata sandi minimal 12 karakter.');

            return self::FAILURE;
        }
        if ($password === null && ! $isLocal) {
            // Di server publik jangan pakai "password": buat acak & tampilkan sekali.
            $password = 'Demo-'.Str::password(14, symbols: false);
        }

        $seeder = app(DemoCentralKitchenSeeder::class);
        $seeder->password = $password;
        $seeder->allowProduction = ! $isLocal;
        $seeder->setContainer(app())->setCommand($this)->__invoke();

        if (! $isLocal) {
            $this->warn('Simpan kata sandi di atas. Jalankan ulang perintah ini untuk mereset data demo (kata sandi baru).');
        }

        return self::SUCCESS;
    }
}
