<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

class BackupToGoogleDriveCommand extends Command
{
    protected $signature = 'backup:drive
        {--cleanup : Hapus salinan lama di Google Drive sesuai retensi}
        {--skip-backup : Pakai file backup yang sudah ada, tanpa membuat backup baru}';

    protected $description = 'Backup database + file aplikasi, lalu unggah ke Google Drive via rclone';

    public function handle(): int
    {
        $remote = (string) config('backup.rclone.remote');

        if ($remote === '') {
            $this->error('RCLONE_REMOTE belum dikonfigurasi (contoh: gdrive:Backup/kasirumkm).');

            return self::FAILURE;
        }

        $binary = $this->rcloneBinary();

        if (! $this->binaryExists($binary)) {
            $this->error("Binary rclone tidak ditemukan ({$binary}). Install rclone atau set RCLONE_BINARY.");

            return self::FAILURE;
        }

        if (! $this->option('skip-backup')) {
            $exitCode = Artisan::call('backup:run');

            if ($exitCode !== 0) {
                $this->error('Backup lokal gagal.');

                return self::FAILURE;
            }

            $this->info('Backup lokal selesai.');
        }

        $files = $this->newestBackupFiles();

        if ($files === []) {
            $this->error('Tidak ada file backup ditemukan di disk '.config('backup.backup.destination.disks.0').'/'.config('backup.backup.name').'.');

            return self::FAILURE;
        }

        $uploadFailed = 0;

        foreach ($files as $file) {
            if ($this->upload($binary, $file)) {
                $this->info('Terunggah: '.basename($file));
            } else {
                $uploadFailed++;
            }
        }

        if ($this->option('cleanup')) {
            $this->cleanupRemote($binary);
        }

        return $uploadFailed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Daftar file backup (zip) terbaru pada disk tujuan.
     *
     * @return array<int, string>
     */
    protected function newestBackupFiles(): array
    {
        $diskName = (string) config('backup.backup.destination.disks.0', 'local');
        $directory = Storage::disk($diskName)->path(config('backup.backup.name'));

        if (! is_dir($directory)) {
            return [];
        }

        $files = glob($directory.DIRECTORY_SEPARATOR.'*.zip') ?: [];
        rsort($files);

        return array_slice($files, 0, 3);
    }

    protected function upload(string $binary, string $path): bool
    {
        $target = sprintf('%s/%s', rtrim((string) config('backup.rclone.remote'), '/'), basename($path));

        $result = Process::run([$binary, 'copyto', $path, $target]);

        if (! $result->successful()) {
            $this->error('Gagal mengunggah '.basename($path).': '.$result->errorOutput());

            return false;
        }

        return true;
    }

    protected function cleanupRemote(string $binary): void
    {
        $remote = rtrim((string) config('backup.rclone.remote'), '/');
        $days = max(1, (int) config('backup.rclone.retention_days', 30));

        $result = Process::run([$binary, 'delete', $remote, '--min-age', "{$days}d", '--rmdirs']);

        if (! $result->successful()) {
            $this->warn('Pembersihan salinan lama gagal: '.$result->errorOutput());

            return;
        }

        $this->info("Salinan lama di Google Drive (lebih dari {$days} hari) dihapus.");
    }

    protected function rcloneBinary(): string
    {
        $binary = (string) config('backup.rclone.binary', 'rclone');

        if (is_file($binary)) {
            return $binary;
        }

        $relative = base_path($binary);

        return is_file($relative) ? $relative : $binary;
    }

    protected function binaryExists(string $binary): bool
    {
        $isPath = preg_match('/\.(exe|bat|cmd)$/i', $binary) === 1 || str_contains($binary, '/') || str_contains($binary, '\\');

        if ($isPath) {
            return is_file($binary);
        }

        return Process::run([$this->pathLookupCommand(), $binary])->successful();
    }

    protected function pathLookupCommand(): string
    {
        return DIRECTORY_SEPARATOR === '\\' ? 'where' : 'which';
    }
}
