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

it('rekonsiliasi Desember memakai biaya jabatan, iuran pensiun, dan PKP dibulatkan ke ribuan', function () {
    $service = new TaxService;

    // TK/0, bruto 10 jt/bulan: bruto setahun 120 jt, JHT 2% + JP 1% = 3,6 jt setahun.
    // Neto = 120 − 6 (biaya jabatan maks) − 3,6 = 110,4 jt; PKP = 110,4 − 54 = 56,4 jt.
    // PPh setahun = 5% × 56,4 jt = 2.820.000; TER Jan–Nov = 2% × 10 jt × 11 = 2.200.000.
    $december = $service->reconcileDecember(120_000_000, 'TK/0', 2_200_000, 3_600_000);

    expect($december['biaya_jabatan'])->toEqual(6_000_000)
        ->and($december['pkp'])->toEqual(56_400_000)
        ->and($december['annual_tax'])->toEqual(2_820_000)
        ->and($december['monthly_tax'])->toEqual(620_000)
        ->and($december['refund'])->toEqual(0);
});

it('biaya jabatan 5% bila di bawah batas maksimal dan PKP dibulatkan ke bawah', function () {
    $service = new TaxService;

    // Bruto 70.123.456: biaya jabatan 3.506.172,8; neto 66.617.283,2 − PTKP 54 jt = 12.617.283,2 -> 12.617.000.
    $december = $service->reconcileDecember(70_123_456, 'TK/0', 0);

    expect($december['pkp'])->toEqual(12_617_000)
        ->and($december['annual_tax'])->toEqual(630_850);
});

it('kelebihan potong TER di Desember dikembalikan, bukan dibuang', function () {
    $service = new TaxService;

    $december = $service->reconcileDecember(50_000_000, 'TK/0', 300_000);

    expect($december['monthly_tax'])->toEqual(0)
        ->and($december['refund'])->toEqual(300_000);
});

it('tabel TER kategori C sesuai lampiran PP 58/2023', function () {
    $service = new TaxService;

    expect($service->calculateTerMonthly(17_050_000, 'K/3')['rate_percent'])->toBe(6.0)
        ->and($service->calculateTerMonthly(17_050_001, 'K/3')['rate_percent'])->toBe(7.0)
        ->and($service->calculateTerMonthly(83_200_000, 'K/3')['rate_percent'])->toBe(22.0)
        ->and($service->calculateTerMonthly(95_600_000, 'K/3')['rate_percent'])->toBe(23.0)
        ->and($service->calculateTerMonthly(110_000_000, 'K/3')['rate_percent'])->toBe(24.0)
        ->and($service->calculateTerMonthly(110_000_001, 'K/3')['rate_percent'])->toBe(25.0);
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
