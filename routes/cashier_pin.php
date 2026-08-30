<?php

use App\Modules\CashierPin\Http\Controllers\CashierPinLoginController;
use App\Modules\CashierPin\Http\Controllers\CashierSettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    // PIN Login (kasir masuk shift)
    Route::prefix('cashier-pin')->name('cashier-pin.')->group(function () {
        Route::get('/login', [CashierPinLoginController::class, 'show'])->name('login');
        Route::post('/login', [CashierPinLoginController::class, 'verify'])->name('verify');
        Route::post('/logout', [CashierPinLoginController::class, 'logout'])->name('logout');
    });

    // Settings (admin kelola mode + PIN karyawan)
    Route::prefix('settings/cashier-pin')->name('settings.cashier-pin.')
        ->middleware('role.store_admin')
        ->group(function () {
            Route::get('/', [CashierSettingsController::class, 'index'])->name('index');
            Route::put('/mode', [CashierSettingsController::class, 'updateMode'])->name('update-mode');
            Route::post('/{user}/pin', [CashierSettingsController::class, 'setPin'])->name('set-pin');
            Route::delete('/{user}/pin', [CashierSettingsController::class, 'clearPin'])->name('clear-pin');
            Route::put('/{user}/toggle-pos', [CashierSettingsController::class, 'togglePos'])->name('toggle-pos');
        });
});
