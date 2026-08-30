<?php

use App\Modules\Payroll\Services\TaxService;

it('menentukan kategori TER dari status PTKP', function () {
    $service = new TaxService;

    expect($service->terCategory('TK/0'))->toBe('A')
        ->and($service->terCategory('K/0'))->toBe('A')
        ->and($service->terCategory('K/2'))->toBe('B')
        ->and($service->terCategory('K/3'))->toBe('C')
        ->and($service->terCategory('tidak-valid'))->toBe('A');
});

it('menghitung PPh21 bulanan metode TER', function () {
    $service = new TaxService;

    expect($service->calculateTerMonthly(5_000_000, 'TK/0')['monthly_tax'])->toBe(0.0)
        ->and($service->calculateTerMonthly(5_600_000, 'TK/0')['monthly_tax'])->toBe(14000.0)
        ->and($service->calculateTerMonthly(10_000_000, 'TK/0')['monthly_tax'])->toBe(200000.0)
        ->and($service->calculateTerMonthly(10_000_000, 'K/3')['monthly_tax'])->toBe(150000.0);
});

it('rekonsiliasi Desember mengurangkan akumulasi TER', function () {
    $service = new TaxService;

    // Bruto 120jt, PTKP TK/0 54jt -> PKP 66jt -> pajak tahunan 3.9jt
    $december = $service->reconcileDecember(120_000_000, 'TK/0', 3_000_000);

    expect($december['annual_tax'])->toBe(3_900_000.0)
        ->and($december['monthly_tax'])->toBe(900_000.0);
});

it('rekonsiliasi Desember tidak menghasilkan pajak negatif', function () {
    $service = new TaxService;

    expect($service->reconcileDecember(50_000_000, 'TK/0', 0)['monthly_tax'])->toBe(0.0);
});

it('menghitung pajak progresif tahunan', function () {
    $service = new TaxService;

    expect($service->calculateProgressiveTax(0))->toBe(0.0)
        ->and($service->calculateProgressiveTax(60_000_000))->toBe(3_000_000.0)
        ->and($service->calculateProgressiveTax(70_000_000))->toBe(4_500_000.0);
});

it('membangun status PTKP dari status nikah dan tanggungan', function () {
    $service = new TaxService;

    expect($service->buildTaxStatus(false, 0))->toBe('TK/0')
        ->and($service->buildTaxStatus(true, 2))->toBe('K/2')
        ->and($service->buildTaxStatus(false, 5))->toBe('TK/3');
});
