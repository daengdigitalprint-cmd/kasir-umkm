<?php

/**
 * Generate file SQL "cleanup" dari file backup hasil scripts/backup_account.php.
 * File cleanup berisi perintah DELETE untuk SEMUA baris milik akun tsb
 * (berdasarkan id yang ada di file backup), sehingga aman dijalankan di VPS
 * sebelum import ulang, tanpa menyentuh data akun lain.
 *
 * Cara pakai:
 *   php scripts/generate_cleanup.php <file-backup.sql> [file-cleanup.sql]
 *
 * Contoh:
 *   php scripts/generate_cleanup.php backups/backup_outletberkahf3_final.sql backups/cleanup_outletberkahf3.sql
 */
if ($argc < 2) {
    fwrite(STDERR, "Penggunaan: php scripts/generate_cleanup.php <file-backup.sql> [file-cleanup.sql]\n");
    exit(1);
}

$inFile = $argv[1];
if (! is_file($inFile)) {
    fwrite(STDERR, "File backup tidak ditemukan: $inFile\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Parse INSERT statements -> kumpulkan id per tabel
// ---------------------------------------------------------------------------
$idsByTable = [];
$sql = file_get_contents($inFile);

$offset = 0;
$len = strlen($sql);

while ($offset < $len) {
    // cari "INSERT INTO `tabel`"
    $pos = stripos($sql, 'INSERT INTO `', $offset);
    if ($pos === false) {
        break;
    }

    // ekstrak nama tabel
    $startTable = $pos + strlen('INSERT INTO `');
    $endTable = strpos($sql, '`', $startTable);
    $table = substr($sql, $startTable, $endTable - $startTable);

    // cari "VALUES" setelah tanda kurung kolom
    $valsPos = strpos($sql, 'VALUES', $endTable);
    $semiPos = strpos($sql, ';', $valsPos);
    $body = substr($sql, $valsPos + 6, $semiPos - $valsPos - 6);

    // ekstrak nilai pertama (id) dari setiap tuple
    $ids = [];
    $i = 0;
    $bodyLen = strlen($body);
    while ($i < $bodyLen) {
        if ($body[$i] !== '(') {
            $i++;

            continue;
        }
        $j = $i + 1;
        $inStr = false;
        $start = $j;
        $val = null;
        while ($j < $bodyLen) {
            $c = $body[$j];
            if ($inStr) {
                if ($c === '\\') {
                    $j += 2;

                    continue;
                }
                if ($c === "'") {
                    $inStr = false;
                }
            } else {
                if ($c === "'") {
                    $inStr = true;
                } elseif ($c === ',' || $c === ')') {
                    $val = trim(substr($body, $start, $j - $start));
                    break;
                }
            }
            $j++;
        }
        if ($val !== null && $val !== 'NULL') {
            $ids[] = (int) trim($val, "'");
        }
        // lompat ke akhir tuple (kurung tutup)
        while ($j < $bodyLen) {
            $c = $body[$j];
            if ($inStr) {
                if ($c === '\\') {
                    $j += 2;

                    continue;
                }
                if ($c === "'") {
                    $inStr = false;
                }
            } else {
                if ($c === "'") {
                    $inStr = true;
                } elseif ($c === ')') {
                    $j++;
                    break;
                }
            }
            $j++;
        }
        $i = $j;
    }

    $idsByTable[$table] = array_values(array_unique($ids));
    $offset = $semiPos + 1;
}

if (empty($idsByTable)) {
    fwrite(STDERR, "Tidak ada INSERT yang ditemukan di file backup.\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Tulis file cleanup
// ---------------------------------------------------------------------------
$outFile = $argv[2] ?? null;
if (! $outFile) {
    $outFile = preg_replace('/\.sql$/i', '', $inFile).'_cleanup.sql';
}

$fh = fopen($outFile, 'w');
if (! $fh) {
    fwrite(STDERR, "Tidak bisa menulis file: $outFile\n");
    exit(1);
}

fwrite($fh, "-- ================================================================\n");
fwrite($fh, '-- CLEANUP data akun (DELETE) berdasarkan file: '.basename($inFile)."\n");
fwrite($fh, '-- Dibuat: '.date('Y-m-d H:i:s')."\n");
fwrite($fh, "-- ================================================================\n");
fwrite($fh, "SET FOREIGN_KEY_CHECKS=0;\n");
fwrite($fh, "START TRANSACTION;\n\n");

$total = 0;
foreach ($idsByTable as $table => $ids) {
    if (empty($ids)) {
        continue;
    }
    $total += count($ids);
    // hapus dalam beberapa batch agar query tidak terlalu panjang
    foreach (array_chunk($ids, 500) as $chunk) {
        fwrite($fh, "DELETE FROM `$table` WHERE id IN (".implode(',', $chunk).");\n");
    }
}

fwrite($fh, "\nCOMMIT;\n");
fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
fwrite($fh, "-- Selesai. Total baris dihapus: $total\n");
fclose($fh);

echo "Cleanup SQL dibuat.\n";
echo 'Tabel : '.count($idsByTable)."\n";
echo "Baris : $total\n";
echo 'File  : '.realpath($outFile)."\n";
