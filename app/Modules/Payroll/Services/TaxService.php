<?php

namespace App\Modules\Payroll\Services;

/**
 * Hitung PPh 21 sesuai tarif progresif 2024.
 * Tarif: 5% (0-60jt), 15% (60-250jt), 25% (250-500jt), 30% (500jt-5M), 35% (>5M)
 * PTKP: TK/0=54jt, TK/1=58.5jt, K/0=58.5jt, K/1=63jt, K/2=67.5jt, K/3=72jt
 */
class TaxService
{
    /** Tarif PPh 21 progresif (per tahun). */
    public const TAX_BRACKETS = [
        ['max' => 60_000_000, 'rate' => 0.05],
        ['max' => 250_000_000, 'rate' => 0.15],
        ['max' => 500_000_000, 'rate' => 0.25],
        ['max' => 5_000_000_000, 'rate' => 0.30],
        ['max' => PHP_FLOAT_MAX, 'rate' => 0.35],
    ];

    /** PTKP per tahun (Penghasilan Tidak Kena Pajak). */
    public const PTKP = [
        'TK/0' => 54_000_000,
        'TK/1' => 58_500_000,
        'TK/2' => 63_000_000,
        'TK/3' => 67_500_000,
        'K/0' => 58_500_000,
        'K/1' => 63_000_000,
        'K/2' => 67_500_000,
        'K/3' => 72_000_000,
    ];

    /** Kategori TER 2024 berdasarkan status PTKP. */
    public const TER_CATEGORY = [
        'TK/0' => 'A',
        'TK/1' => 'A',
        'K/0' => 'A',
        'TK/2' => 'B',
        'TK/3' => 'B',
        'K/1' => 'B',
        'K/2' => 'B',
        'K/3' => 'C',
    ];

    /** Tabel TER bulanan PP 58/2023. Format: [batas atas bruto, tarif %]. */
    public const TER_TABLES = [
        'A' => [
            [5_400_000, 0.0], [5_650_000, 0.25], [5_950_000, 0.5], [6_300_000, 0.75],
            [6_750_000, 1.0], [7_500_000, 1.25], [8_550_000, 1.5], [9_650_000, 1.75],
            [10_050_000, 2.0], [10_350_000, 2.25], [10_700_000, 2.5], [11_050_000, 3.0],
            [11_600_000, 3.5], [12_500_000, 4.0], [13_750_000, 5.0], [15_100_000, 6.0],
            [16_950_000, 7.0], [19_750_000, 8.0], [24_150_000, 9.0], [26_450_000, 10.0],
            [28_000_000, 11.0], [30_050_000, 12.0], [32_400_000, 13.0], [35_400_000, 14.0],
            [39_100_000, 15.0], [43_850_000, 16.0], [47_800_000, 17.0], [51_400_000, 18.0],
            [56_300_000, 19.0], [62_200_000, 20.0], [68_600_000, 21.0], [77_500_000, 22.0],
            [89_000_000, 23.0], [103_000_000, 24.0], [125_000_000, 25.0], [157_000_000, 26.0],
            [206_000_000, 27.0], [337_000_000, 28.0], [454_000_000, 29.0], [550_000_000, 30.0],
            [695_000_000, 31.0], [910_000_000, 32.0], [1_400_000_000, 33.0], [PHP_FLOAT_MAX, 34.0],
        ],
        'B' => [
            [6_200_000, 0.0], [6_500_000, 0.25], [6_850_000, 0.5], [7_300_000, 0.75],
            [9_200_000, 1.0], [10_750_000, 1.5], [11_250_000, 2.0], [11_600_000, 2.5],
            [12_600_000, 3.0], [13_600_000, 4.0], [14_950_000, 5.0], [16_400_000, 6.0],
            [18_450_000, 7.0], [21_850_000, 8.0], [26_000_000, 9.0], [27_700_000, 10.0],
            [29_350_000, 11.0], [31_450_000, 12.0], [33_950_000, 13.0], [37_100_000, 14.0],
            [41_100_000, 15.0], [45_800_000, 16.0], [49_500_000, 17.0], [53_800_000, 18.0],
            [58_500_000, 19.0], [64_000_000, 20.0], [71_000_000, 21.0], [80_000_000, 22.0],
            [93_000_000, 23.0], [109_000_000, 24.0], [129_000_000, 25.0], [163_000_000, 26.0],
            [211_000_000, 27.0], [374_000_000, 28.0], [459_000_000, 29.0], [555_000_000, 30.0],
            [704_000_000, 31.0], [957_000_000, 32.0], [1_405_000_000, 33.0], [PHP_FLOAT_MAX, 34.0],
        ],
        'C' => [
            [6_600_000, 0.0], [6_950_000, 0.25], [7_350_000, 0.5], [7_800_000, 0.75],
            [8_850_000, 1.0], [9_800_000, 1.25], [10_950_000, 1.5], [11_200_000, 1.75],
            [12_050_000, 2.0], [12_950_000, 3.0], [14_150_000, 4.0], [15_550_000, 5.0],
            [17_000_000, 6.0], [19_500_000, 7.0], [22_700_000, 8.0], [26_600_000, 9.0],
            [28_100_000, 10.0], [30_100_000, 11.0], [32_600_000, 12.0], [35_400_000, 13.0],
            [38_900_000, 14.0], [43_000_000, 15.0], [47_400_000, 16.0], [51_200_000, 17.0],
            [55_800_000, 18.0], [60_400_000, 19.0], [66_700_000, 20.0], [74_500_000, 21.0],
            [84_000_000, 22.0], [97_000_000, 23.0], [113_500_000, 24.0], [134_000_000, 25.0],
            [169_000_000, 26.0], [221_000_000, 27.0], [390_000_000, 28.0], [463_000_000, 29.0],
            [561_000_000, 30.0], [709_000_000, 31.0], [965_000_000, 32.0], [1_419_000_000, 33.0], [PHP_FLOAT_MAX, 34.0],
        ],
    ];

    /**
     * Hitung PPh 21 bulanan dari penghasilan bruto tahunan.
     *
     * @param  float  $annualIncome  Penghasilan bruto per tahun
     * @param  string  $taxStatus  Status PTKP (TK/0, K/1, dll)
     * @return array{annual_tax: float, monthly_tax: float, ptwp: float, pkp: float}
     */
    public function calculateAnnual(float $annualIncome, string $taxStatus = 'TK/0'): array
    {
        $ptwp = self::PTKP[$taxStatus] ?? self::PTKP['TK/0'];
        $pkp = max(0, $annualIncome - $ptwp);

        $annualTax = $this->calculateProgressiveTax($pkp);
        $monthlyTax = round($annualTax / 12, 2);

        return [
            'annual_income' => round($annualIncome, 2),
            'ptwp' => $ptwp,
            'pkp' => round($pkp, 2),
            'annual_tax' => round($annualTax, 2),
            'monthly_tax' => $monthlyTax,
        ];
    }

    /**
     * Hitung PPh 21 progresif dari PKP (Penghasilan Kena Pajak).
     */
    public function calculateProgressiveTax(float $taxableIncome): float
    {
        if ($taxableIncome <= 0) {
            return 0.0;
        }

        $tax = 0.0;
        $prevMax = 0;

        foreach (self::TAX_BRACKETS as $bracket) {
            $bracketSize = $bracket['max'] - $prevMax;

            if ($taxableIncome <= $bracketSize) {
                $tax += $taxableIncome * $bracket['rate'];
                break;
            }

            $tax += $bracketSize * $bracket['rate'];
            $taxableIncome -= $bracketSize;
            $prevMax = $bracket['max'];
        }

        return round($tax, 2);
    }

    /**
     * Kategori TER berdasarkan status PTKP.
     */
    public function terCategory(string $taxStatus): string
    {
        return self::TER_CATEGORY[$taxStatus] ?? 'A';
    }

    /**
     * Hitung PPh 21 bulanan metode TER 2024 (Jan-Nov).
     *
     * @return array{method: string, category: string, rate_percent: float, monthly_gross: float, monthly_tax: float}
     */
    public function calculateTerMonthly(float $monthlyGross, string $taxStatus = 'TK/0'): array
    {
        $category = $this->terCategory($taxStatus);
        $rate = $this->terRate($category, $monthlyGross);
        $monthlyTax = round($monthlyGross * $rate / 100, 2);

        return [
            'method' => 'ter',
            'category' => $category,
            'rate_percent' => $rate,
            'monthly_gross' => round($monthlyGross, 2),
            'monthly_tax' => $monthlyTax,
        ];
    }

    /**
     * Rekonsiliasi PPh 21 Desember: pajak tahunan progresif dikurangi akumulasi TER Jan-Nov.
     * Kelebihan potong (negatif) dibulatkan ke 0 — ponytail: pengembalian (refund)
     * belum ditangani; tambahkan saat perlu menampilkan kredit pajak Desember.
     *
     * @param  float  $annualGross  Bruto kumulatif Jan-Des
     * @param  float  $accumulatedTer  Total TER Jan-Nov yang sudah dipotong
     * @return array{method: string, annual_gross: float, ptwp: float, pkp: float, annual_tax: float, accumulated_ter: float, monthly_tax: float}
     */
    public function reconcileDecember(float $annualGross, string $taxStatus = 'TK/0', float $accumulatedTer = 0): array
    {
        $annual = $this->calculateAnnual($annualGross, $taxStatus);
        $monthlyTax = max(0, $annual['annual_tax'] - $accumulatedTer);

        return [
            'method' => 'ter_december',
            'annual_gross' => round($annualGross, 2),
            'ptwp' => $annual['ptwp'],
            'pkp' => $annual['pkp'],
            'annual_tax' => $annual['annual_tax'],
            'accumulated_ter' => round($accumulatedTer, 2),
            'monthly_tax' => round($monthlyTax, 2),
        ];
    }

    /**
     * Ambil tarif efektif (%) dari tabel TER untuk bruto bulanan.
     */
    private function terRate(string $category, float $monthlyGross): float
    {
        foreach (self::TER_TABLES[$category] as [$max, $rate]) {
            if ($monthlyGross <= $max) {
                return (float) $rate;
            }
        }

        return 34.0;
    }

    /**
     * Generate status PTKP dari status pernikahan dan jumlah tanggungan.
     *
     * @param  bool  $married  Status pernikahan
     * @param  int  $dependents  Jumlah tanggungan (0-3)
     * @return string Status PTKP (TK/0, K/1, dll)
     */
    public function buildTaxStatus(bool $married, int $dependents = 0): string
    {
        $dependents = min(3, max(0, $dependents));
        $prefix = $married ? 'K' : 'TK';

        return "{$prefix}/{$dependents}";
    }

    /**
     * Ambil daftar opsi status PTKP untuk dropdown.
     */
    public static function getPtkpOptions(): array
    {
        return [
            'TK/0' => 'TK/0 - Tidak Kawin, 0 Tanggungan (Rp 54.000.000)',
            'TK/1' => 'TK/1 - Tidak Kawin, 1 Tanggungan (Rp 58.500.000)',
            'TK/2' => 'TK/2 - Tidak Kawin, 2 Tanggungan (Rp 63.000.000)',
            'TK/3' => 'TK/3 - Tidak Kawin, 3 Tanggungan (Rp 67.500.000)',
            'K/0' => 'K/0 - Kawin, 0 Tanggungan (Rp 58.500.000)',
            'K/1' => 'K/1 - Kawin, 1 Tanggungan (Rp 63.000.000)',
            'K/2' => 'K/2 - Kawin, 2 Tanggungan (Rp 67.500.000)',
            'K/3' => 'K/3 - Kawin, 3 Tanggungan (Rp 72.000.000)',
        ];
    }
}
