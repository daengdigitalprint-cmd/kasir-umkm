<?php

use App\Http\Controllers\Settings\BackupController;
use App\Http\Controllers\Settings\FeatureController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\StoreSettingsController;
use App\Http\Controllers\TaxSettingsController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Pengalihan alamat lama memakai redirectGet (hanya GET/HEAD). Route::redirect() menangkap SEMUA
// metode: dengan route:cache di produksi, PUT ke alamat yang sama ikut dialihkan dan tidak pernah
// sampai ke controller (sakelar Pengaturan toko tampak "Tersimpan" padahal tidak tersimpan).
$redirectGet = fn (string $uri, string $to) => Route::get($uri, '\Illuminate\Routing\RedirectController')
    ->defaults('destination', $to)
    ->defaults('status', 302);

Route::middleware('auth')->group(function () use ($redirectGet) {
    $redirectGet('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Pengaturan toko dipecah per bagian, masing-masing punya alamat sendiri.
    $redirectGet('settings/store', '/settings/store/profil')->middleware('role.store_admin');
    Route::get('settings/store/{section}', [ProfileController::class, 'editStore'])
        ->middleware('role.store_admin')
        ->whereIn('section', ProfileController::STORE_SECTIONS)
        ->name('settings.store.section');

    Route::put('settings/store', [StoreSettingsController::class, 'update'])
        ->middleware('role.store_admin')
        ->name('settings.store.update');

    Route::put('settings/ui-mode', [StoreSettingsController::class, 'updateUiMode'])
        ->middleware('role.store_admin')
        ->name('settings.ui-mode.update');

    // Fitur Tambahan kini satu tempat dengan modul lain di Pengaturan → Fitur.
    $redirectGet('settings/features', '/settings/store/fitur')
        ->middleware('role.store_admin');
    Route::put('settings/features/{feature}', [FeatureController::class, 'update'])
        ->middleware('role.store_admin')
        ->name('settings.features.update');

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('password.edit');

    Route::put('settings/password', [PasswordController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('password.update');

    Route::get('settings/appearance', function () {
        return Inertia::render('settings/Appearance');
    })->name('appearance');

    Route::get('settings/tax', function () {
        return redirect()->route('profile.edit');
    })->name('tax.index');

    Route::put('settings/tax', [TaxSettingsController::class, 'update'])
        ->middleware('role.store_admin')
        ->name('tax.update');

    Route::get('settings/bluetooth-printer', function () {
        return Inertia::render('settings/BluetoothPrinter');
    })->name('bluetooth-printer');

    // Backup routes
    $redirectGet('settings/backup', '/settings/store/backup')
        ->middleware('role.store_admin');
    // Backup berisi seluruh data toko (transaksi, pelanggan, akuntansi): hanya pemilik/admin toko.
    Route::get('settings/backup/history', [BackupController::class, 'history'])
        ->middleware('role.store_admin')
        ->name('backup.history');
    Route::post('settings/backup/create', [BackupController::class, 'createBackup'])
        ->middleware('role.store_admin')
        ->name('backup.create');
    Route::post('settings/backup/download', [BackupController::class, 'download'])
        ->middleware('role.store_admin')
        ->name('backup.download');
    Route::post('settings/backup/upload', [BackupController::class, 'upload'])
        ->middleware('role.store_admin')
        ->name('backup.upload');
    Route::post('settings/backup/restore', [BackupController::class, 'restore'])
        ->middleware('role.store_admin')
        ->name('backup.restore');
    Route::delete('settings/backup/{id}', [BackupController::class, 'deleteBackup'])
        ->middleware('role.store_admin')
        ->name('backup.delete');
    Route::get('settings/backup/scheduled/{filename}', [BackupController::class, 'downloadScheduled'])
        ->middleware('role.store_admin')
        ->name('backup.download-scheduled');
});
