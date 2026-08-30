<?php

use App\Models\Store;
use App\Modules\Payroll\Services\PayrollService;

it('menghitung tarif per jam dari gaji pokok', function () {
    $service = app(PayrollService::class);

    expect($service->hourlyRate(1_730_000))->toBe(10000.0)
        ->and($service->hourlyRate(0))->toBe(0.0);
});

it('menerapkan denda per hari saja', function () {
    $store = new Store([
        'late_penalty_method' => 'per_day',
        'late_penalty_per_day' => 10000,
        'late_penalty_per_minute' => 100,
    ]);

    expect(app(PayrollService::class)->latePenalty($store, 2, 30))->toBe(20000.0);
});

it('menerapkan denda per menit saja', function () {
    $store = new Store([
        'late_penalty_method' => 'per_minute',
        'late_penalty_per_day' => 10000,
        'late_penalty_per_minute' => 100,
    ]);

    expect(app(PayrollService::class)->latePenalty($store, 2, 30))->toBe(3000.0);
});

it('tanpa denda saat metode none', function () {
    $store = new Store([
        'late_penalty_method' => 'none',
        'late_penalty_per_day' => 10000,
        'late_penalty_per_minute' => 100,
    ]);

    expect(app(PayrollService::class)->latePenalty($store, 2, 30))->toBe(0.0);
});
