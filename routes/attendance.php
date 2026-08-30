<?php

use App\Modules\Attendance\Http\Controllers\AttendanceController;
use App\Modules\Attendance\Http\Controllers\AttendanceReportController;
use App\Modules\Attendance\Http\Controllers\AttendanceSettingsController;
use App\Modules\Attendance\Http\Controllers\EmployeeAttendanceController;
use App\Modules\Attendance\Http\Controllers\EmployeeController;
use App\Modules\Attendance\Http\Controllers\LeaveRequestController;
use App\Modules\Attendance\Http\Controllers\OvertimeRequestController;
use App\Modules\Attendance\Http\Controllers\WorkScheduleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes — Modul Absensi (Addon)
|--------------------------------------------------------------------------
| Di-require dari routes/web.php.
| Dijaga middleware `attendance.enabled` agar nonaktif total saat addon off.
*/

Route::middleware(['auth', 'verified', 'store.active'])
    ->prefix('attendance')
    ->name('attendance.')
    ->group(function () {
        // Pengaturan tetap bisa dibuka saat addon off, agar pemilik bisa mengaktifkannya.
        Route::get('/settings', [AttendanceSettingsController::class, 'settings'])->name('settings');
        Route::post('/settings', [AttendanceSettingsController::class, 'update'])->name('settings.update');
    });

Route::middleware(['auth', 'verified', 'store.active', 'attendance.enabled'])
    ->prefix('attendance')
    ->name('attendance.')
    ->group(function () {
        Route::get('/', [AttendanceController::class, 'index'])->name('index');
        Route::get('/report', [AttendanceReportController::class, 'index'])->name('report');
        Route::post('/report/{attendance}', [AttendanceReportController::class, 'update'])->name('report.update');
        Route::delete('/report/{attendance}', [AttendanceReportController::class, 'destroy'])->name('report.destroy');
        Route::post('/clock-in', [AttendanceController::class, 'clockIn'])->name('clock-in');
        Route::post('/clock-out', [AttendanceController::class, 'clockOut'])->name('clock-out');

        Route::resource('employees', EmployeeController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::resource('schedules', WorkScheduleController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::resource('leaves', LeaveRequestController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::resource('overtimes', OvertimeRequestController::class)->only(['index', 'store', 'update', 'destroy']);

        // Employee self-service (riwayat, cuti, lembur untuk semua karyawan)
        Route::get('/history', [EmployeeAttendanceController::class, 'history'])->name('history');
        Route::get('/my-leaves', [EmployeeAttendanceController::class, 'myLeaves'])->name('my-leaves');
        Route::post('/my-leaves', [EmployeeAttendanceController::class, 'storeLeave'])->name('my-leaves.store');
        Route::get('/my-overtimes', [EmployeeAttendanceController::class, 'myOvertimes'])->name('my-overtimes');
        Route::post('/my-overtimes', [EmployeeAttendanceController::class, 'storeOvertime'])->name('my-overtimes.store');
    });
