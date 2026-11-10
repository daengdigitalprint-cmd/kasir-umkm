<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * Ambil perintah process sebagai string (command bisa berupa array).
 */
function driveCommandString($process): string
{
    return is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
}

/**
 * Siapkan binary rclone dummy + file backup zip lokal.
 *
 * @return array{binary: string, zip: string}
 */
function prepareDriveBackup(): array
{
    config([
        'backup.rclone.remote' => 'gdrive:Backup/kasirumkm',
        'backup.rclone.retention_days' => 30,
    ]);

    $binary = base_path('rclone-test.exe');
    File::put($binary, 'dummy');

    $directory = Storage::disk('local')->path(config('backup.backup.name'));
    File::ensureDirectoryExists($directory);
    $zip = $directory.DIRECTORY_SEPARATOR.'2026-10-06-02-30-00.zip';
    File::put($zip, 'zip-dummy');

    return ['binary' => $binary, 'zip' => $zip];
}

afterEach(function () {
    @unlink(base_path('rclone-test.exe'));

    $directory = Storage::disk('local')->path(config('backup.backup.name'));
    if (is_dir($directory)) {
        File::deleteDirectory($directory);
    }
});

it('fails when rclone remote is not configured', function () {
    config(['backup.rclone.remote' => '']);

    $this->artisan('backup:drive')
        ->expectsOutputToContain('RCLONE_REMOTE belum dikonfigurasi')
        ->assertFailed();
});

it('uploads newest backup files to google drive', function () {
    $files = prepareDriveBackup();

    config(['backup.rclone.binary' => $files['binary']]);

    Process::fake([
        '*copyto*' => fn () => 0,
    ]);

    $this->artisan('backup:drive', ['--skip-backup' => true])
        ->assertSuccessful();

    Process::assertRan(function ($process) use ($files) {
        $command = driveCommandString($process);

        return str_contains($command, 'copyto')
            && str_contains($command, basename($files['zip']))
            && str_contains($command, 'gdrive:Backup/kasirumkm');
    });
});

it('fails when upload to google drive fails', function () {
    $files = prepareDriveBackup();

    config(['backup.rclone.binary' => $files['binary']]);

    Process::fake([
        '*copyto*' => fn () => new \Illuminate\Process\FakeProcessResult(
            exitCode: 1,
            errorOutput: 'authorization expired',
        ),
    ]);

    $this->artisan('backup:drive', ['--skip-backup' => true])
        ->expectsOutputToContain('Gagal mengunggah')
        ->assertFailed();
});

it('cleans up old copies on google drive when cleanup option is set', function () {
    $files = prepareDriveBackup();

    config(['backup.rclone.binary' => $files['binary']]);

    Process::fake([
        '*copyto*' => fn () => 0,
        '*delete*' => fn () => 0,
    ]);

    $this->artisan('backup:drive', ['--skip-backup' => true, '--cleanup' => true])
        ->assertSuccessful();

    Process::assertRan(function ($process) {
        $command = driveCommandString($process);

        return str_contains($command, 'delete')
            && str_contains($command, 'gdrive:Backup/kasirumkm')
            && str_contains($command, '--min-age 30d');
    });
});

it('fails when rclone binary is missing', function () {
    config([
        'backup.rclone.remote' => 'gdrive:Backup/kasirumkm',
        'backup.rclone.binary' => 'rclone-binary-tidak-ada.exe',
    ]);

    $this->artisan('backup:drive')
        ->expectsOutputToContain('rclone tidak ditemukan')
        ->assertFailed();
});

it('schedules backup drive daily after store backup', function () {
    $this->artisan('schedule:list')
        ->expectsOutputToContain('backup:drive');
});
