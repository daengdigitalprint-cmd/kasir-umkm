<?php

use App\Http\Controllers\Api\V1\AttendanceApiController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ProductSyncController;
use App\Http\Controllers\Api\V1\TransactionSyncController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes (Mobile NativePHP Sync & Integrations)
|--------------------------------------------------------------------------
|
| Rute API V1 khusus sinkronisasi perangkat mobile NativePHP (Sanctum Token).
| Dilindungi dengan rate limiting throttle:api dan pemisahan dari rute web.
|
*/

Route::prefix('v1')->middleware(['throttle:60,1'])->group(function (): void {
    // Autentikasi Publik API
    Route::post('/auth/login', [AuthController::class, 'login'])->name('api.v1.auth.login');

    // Endpoint Terproteksi Token Sanctum
    Route::middleware(['auth:sanctum'])->group(function (): void {
        Route::get('/auth/me', [AuthController::class, 'me'])->name('api.v1.auth.me');
        Route::post('/auth/logout', [AuthController::class, 'logout'])->name('api.v1.auth.logout');

        // Master Data Pull Sync
        Route::get('/sync/products', [ProductSyncController::class, 'index'])->name('api.v1.sync.products');

        // Transaction Push Sync (Idempotent)
        Route::post('/sync/transactions', [TransactionSyncController::class, 'store'])->name('api.v1.sync.transactions');

        // Aplikasi Absensi Android (karyawan)
        Route::prefix('attendance')->name('api.v1.attendance.')->group(function (): void {
            Route::get('/me', [AttendanceApiController::class, 'me'])->name('me');
            Route::post('/clock-in', [AttendanceApiController::class, 'clockIn'])->name('clock-in');
            Route::post('/clock-out', [AttendanceApiController::class, 'clockOut'])->name('clock-out');
            Route::get('/history', [AttendanceApiController::class, 'history'])->name('history');
            Route::get('/leaves', [AttendanceApiController::class, 'leaves'])->name('leaves');
            Route::post('/leaves', [AttendanceApiController::class, 'storeLeave'])->name('leaves.store');
            Route::get('/overtimes', [AttendanceApiController::class, 'overtimes'])->name('overtimes');
            Route::post('/overtimes', [AttendanceApiController::class, 'storeOvertime'])->name('overtimes.store');
            Route::get('/profile/photo', [AttendanceApiController::class, 'profilePhoto'])->name('profile.photo');
            Route::post('/profile/photo', [AttendanceApiController::class, 'uploadProfilePhoto'])->name('profile.photo.upload');
            Route::delete('/profile/photo', [AttendanceApiController::class, 'deleteProfilePhoto'])->name('profile.photo.delete');
            Route::get('/payslips', [AttendanceApiController::class, 'payslips'])->name('payslips');
            Route::get('/payslips/{run}/pdf', [AttendanceApiController::class, 'payslipPdf'])->name('payslips.pdf');
        });
    });
});
