<?php

use App\Http\Controllers\Settings\BackupController;
use App\Http\Controllers\Settings\FeatureController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\StoreSettingsController;
use App\Http\Controllers\TaxSettingsController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('auth')->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Pengaturan toko dipecah per bagian, masing-masing punya alamat sendiri.
    Route::redirect('settings/store', '/settings/store/profil')->middleware('role.store_admin');
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
    Route::redirect('settings/features', '/settings/store/fitur')
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
    Route::redirect('settings/backup', '/settings/store/backup')
        ->middleware('role.store_admin');
    Route::get('settings/backup/history', [BackupController::class, 'history'])
        ->name('backup.history');
    Route::post('settings/backup/create', [BackupController::class, 'createBackup'])
        ->name('backup.create');
    Route::post('settings/backup/download', [BackupController::class, 'download'])
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
