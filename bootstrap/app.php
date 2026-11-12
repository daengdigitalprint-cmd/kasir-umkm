<?php

// Load polyfills if extensions are not available
if (! extension_loaded('mbstring')) {
    require_once __DIR__.'/mbstring_polyfill.php';
}

use App\Http\Middleware\EnsureAdvancedPlan;
use App\Http\Middleware\EnsureAiChatEnabled;
use App\Http\Middleware\EnsureBackOfficeRole;
use App\Http\Middleware\EnsureInventoryRole;
use App\Http\Middleware\EnsureInvoiceViewAccess;
use App\Http\Middleware\EnsureMenuAccess;
use App\Http\Middleware\EnsureOwnerRole;
use App\Http\Middleware\EnsurePointOfSaleAccess;
use App\Http\Middleware\EnsureQueueAccess;
use App\Http\Middleware\EnsureScanMejaEnabled;
use App\Http\Middleware\EnsureStockAdjustmentRole;
use App\Http\Middleware\EnsureStockViewRole;
use App\Http\Middleware\EnsureStoreAdminRole;
use App\Http\Middleware\EnsureStoreIsActive;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SuperAdminMiddleware;
use App\Modules\Attendance\Http\Middleware\EnsureAttendanceEnabled;
use App\Modules\Payroll\Http\Middleware\EnsurePayrollEnabled;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // www/http -> https://APP_URL (301) supaya Google hanya melihat satu alamat.
        $middleware->prepend(\App\Http\Middleware\RedirectToCanonicalHost::class);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            // Modul berbayar (resep, akuntansi, KPI, AI, ...) dikunci untuk paket Gratis.
            \App\Http\Middleware\EnsurePlanModule::class,
        ]);

        $middleware->alias([
            'superadmin' => SuperAdminMiddleware::class,
            'ai.enabled' => EnsureAiChatEnabled::class,
            'store.active' => EnsureStoreIsActive::class,
            'role.backoffice' => EnsureBackOfficeRole::class,
            'role.inventory' => EnsureInventoryRole::class,
            'role.stock_adjustment' => EnsureStockAdjustmentRole::class,
            'role.pos' => EnsurePointOfSaleAccess::class,
            'role.invoice' => EnsureInvoiceViewAccess::class,
            'role.store_admin' => EnsureStoreAdminRole::class,
            'role.stock_view' => EnsureStockViewRole::class,
            'plan.advanced' => EnsureAdvancedPlan::class,
            'owner' => EnsureOwnerRole::class,
            'menu' => EnsureMenuAccess::class,
            'queue.access' => EnsureQueueAccess::class,
            'attendance.enabled' => EnsureAttendanceEnabled::class,
            'payroll.enabled' => EnsurePayrollEnabled::class,
            'scan_meja.enabled' => EnsureScanMejaEnabled::class,
        ]);
    })
    ->withSchedule(function (Schedule $schedule) {
        $schedule->command('batch:send-expiry-digest')->dailyAt('07:00');
        $schedule->command('stores:backup')->dailyAt('02:00');
        $schedule->command('backup:drive', ['--cleanup'])->dailyAt('02:30');
        // Hapus backup lokal lama sesuai retensi config/backup.php (cleanup).
        $schedule->command('backup:clean')->dailyAt('02:50');
        $schedule->command('loyalty:expire-points')->dailyAt('03:00');
        $schedule->command('loyalty:bonus-daily')->dailyAt('03:10');
        $schedule->command('trial:expire')->hourly();
        $schedule->command('kpi:snapshot')->dailyAt('00:30');
        $schedule->command('ai:proactive-alerts')->dailyAt('07:10');
        $schedule->command('ai:daily-digest')->dailyAt('08:00');
        // Shift kasir → absensi tiap jam (idempoten), sebelum penandaan alpa harian.
        $schedule->command('attendance:from-cash-shifts')->hourlyAt(45)->withoutOverlapping();
        $schedule->command('attendance:mark-absent')->dailyAt('23:59');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
