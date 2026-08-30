<?php

use App\Modules\Payroll\Http\Controllers\PayrollController;
use App\Modules\Payroll\Http\Controllers\PayrollSalaryController;
use App\Modules\Payroll\Http\Controllers\PayrollSettingsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes — Modul Payroll (Addon)
|--------------------------------------------------------------------------
| Di-require dari routes/web.php.
| Dijaga middleware `payroll.enabled` agar nonaktif total saat addon off.
| Hanya peran admin/manager/owner yang diizinkan lewat AdminPayrollController.
*/

Route::middleware(['auth', 'verified', 'store.active', 'payroll.enabled'])
    ->prefix('payroll')
    ->name('payroll.')
    ->group(function () {
        Route::get('/', [PayrollController::class, 'index'])->name('index');
        Route::post('/', [PayrollController::class, 'store'])->name('store');

        // Komponen gaji didaftarkan sebelum {period} agar tidak tertangkap route show.
        Route::get('/salaries', [PayrollSalaryController::class, 'index'])->name('salaries.index');
        Route::post('/salaries', [PayrollSalaryController::class, 'store'])->name('salaries.store');
        Route::match(['put', 'patch'], '/salaries/{component}', [PayrollSalaryController::class, 'update'])->name('salaries.update');
        Route::delete('/salaries/{component}', [PayrollSalaryController::class, 'destroy'])->name('salaries.destroy');
        Route::put('/salaries/{employee}/tax', [PayrollSalaryController::class, 'updateTax'])->name('salaries.updateTax');

        // Pengaturan payroll
        Route::get('/settings', [PayrollSettingsController::class, 'index'])->name('settings.index');
        Route::put('/settings', [PayrollSettingsController::class, 'update'])->name('settings.update');

        Route::get('/{period}', [PayrollController::class, 'show'])->name('show');
        Route::post('/{period}/run', [PayrollController::class, 'run'])->name('run');
        Route::delete('/{period}', [PayrollController::class, 'destroy'])->name('destroy');
    });
