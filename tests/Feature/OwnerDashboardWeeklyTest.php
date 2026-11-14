<?php

use App\Services\OwnerDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Periode "weekly" pernah menjatuhkan dashboard dengan 500:
 * format 'Y-W' menghasilkan kunci "2026-42" (huruf W adalah kode format,
 * bukan huruf literal), sehingga regex pencocok gagal dan Carbon::parse
 * menolak string itu. Pekan 01-12 malah lolos tanpa error tapi salah,
 * karena "2026-06" terbaca sebagai Juni.
 */
it('tidak error untuk periode mingguan pada rentang apa pun', function (string $start, string $end) {
    [$owner, $store1] = makeOwnerOrg('Weekly');
    createTransactions($store1, 2, 50000, $start.' 10:00:00');

    $hasil = app(OwnerDashboardService::class)->revenueData($owner, 'weekly', $start, $end);

    expect($hasil['labels'])->not->toBeEmpty()
        ->and($hasil['period_keys'])->not->toBeEmpty();
})->with([
    // Rentang persis dari laporan error pengguna.
    'laporan pengguna' => ['2026-10-15', '2026-11-14'],
    // Pekan 13+ dulu melempar exception.
    'pertengahan tahun' => ['2026-08-01', '2026-08-31'],
    // Pekan 01-12 dulu lolos tapi salah dibaca sebagai bulan.
    'awal tahun' => ['2026-02-01', '2026-02-28'],
    // Lintas pergantian tahun.
    'lintas tahun' => ['2026-12-20', '2027-01-10'],
]);

it('memakai kunci bertahun ISO sehingga pekan lintas tahun tidak terpecah', function () {
    [$owner, $store1] = makeOwnerOrg('IsoWeek');

    // 28 Des 2026 dan 1 Jan 2027 berada di pekan ISO yang sama (2026-W53).
    createTransactions($store1, 1, 10000, '2026-12-28 10:00:00');
    createTransactions($store1, 1, 20000, '2027-01-01 10:00:00');

    $hasil = app(OwnerDashboardService::class)->revenueData($owner, 'weekly', '2026-12-28', '2027-01-01');

    // Keduanya masuk satu ember, bukan terpecah jadi dua pekan berbeda.
    expect($hasil['period_keys'])->toBe(['2026-W53']);

    // summary dikunci per outlet.
    $total = collect($hasil['summary'])->sum('total');
    expect($total)->toBe(30000.0);
});

it('mengubah kunci pekan menjadi tanggal Senin yang benar', function (string $key, string $seninDiharapkan) {
    $service = app(OwnerDashboardService::class);
    $m = new ReflectionMethod($service, 'periodToCarbon');
    $m->setAccessible(true);

    expect($m->invoke($service, 'weekly', $key)->toDateString())->toBe($seninDiharapkan);
})->with([
    'pekan 1 2026' => ['2026-W01', '2025-12-29'],
    'pekan 42 2026' => ['2026-W42', '2026-10-12'],
    'pekan 53 2026' => ['2026-W53', '2026-12-28'],
    // Perhitungan lama (tambah pekan dari 1 Januari) meleset satu pekan di sini.
    'pekan 1 2027' => ['2027-W01', '2027-01-04'],
]);

it('tidak melempar error untuk kunci periode yang tidak dikenali', function () {
    $service = app(OwnerDashboardService::class);
    $m = new ReflectionMethod($service, 'periodToCarbon');
    $m->setAccessible(true);

    // Kunci rusak gaya lama; dashboard harus tetap hidup, bukan 500.
    expect($m->invoke($service, 'weekly', '2026-34'))->toBeInstanceOf(Carbon\Carbon::class);
});

it('melayani halaman owner dashboard dengan periode mingguan', function () {
    [$owner, $store1] = makeOwnerOrg('Route');
    createTransactions($store1, 1, 75000, '2026-10-20 10:00:00');

    $this->actingAs($owner)
        ->get('/owner/dashboard?period=weekly&start_date=2026-10-15&end_date=2026-11-14')
        ->assertOk();
});

/**
 * SQL mengelompokkan per hari, lalu PHP memetakannya ke kunci periode.
 * Dulu memakai penugasan biasa sehingga hari terakhir menimpa hari-hari
 * sebelumnya: pendapatan sepekan/sebulan hanya terbaca satu hari.
 */
it('menjumlahkan semua hari dalam satu periode, bukan menimpanya', function (string $period, array $tanggal, float $diharapkan) {
    [$owner, $store1] = makeOwnerOrg('Sum'.ucfirst($period));

    foreach ($tanggal as $t) {
        createTransactions($store1, 1, 10000, $t.' 10:00:00');
    }

    $hasil = app(OwnerDashboardService::class)->revenueData(
        $owner,
        $period,
        $tanggal[0],
        $tanggal[count($tanggal) - 1],
    );

    expect(collect($hasil['summary'])->sum('total'))->toBe($diharapkan)
        // Semua hari jatuh di satu periode, jadi hanya ada satu ember.
        ->and($hasil['period_keys'])->toHaveCount(1);
})->with([
    // Senin-Rabu pada pekan ISO yang sama.
    'mingguan' => ['weekly', ['2026-10-12', '2026-10-13', '2026-10-14'], 30000.0],
    // Tiga hari berbeda di bulan yang sama.
    'bulanan' => ['monthly', ['2026-10-05', '2026-10-15', '2026-10-25'], 30000.0],
    // Tiga bulan berbeda di tahun yang sama.
    'tahunan' => ['yearly', ['2026-03-10', '2026-07-10', '2026-11-10'], 30000.0],
]);
