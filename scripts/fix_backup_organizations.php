<?php

/**
 * Ubah file backup untuk handle organizatios duplicate & FK owner_user_id.
 * 1. Organisasi yang sudah ada di VPS (id 29) kita skip INSERT.
 * 2. Bila owner_user_id (54) belum ada di VPS, set ke NULL dulu, nanti setelah user imported baru update.
 *
 * Cara pakai:
 *   php scripts/fix_backup_organizations.php <file-backup.sql> [file-fixed.sql]
 *
 * Contoh:
 *   php scripts/fix_backup_organizations.php backups/backup_outletberkahf3_final.sql backups/backup_outletberkahf3_final_fixed.sql
 */
if ($argc < 2) {
    fwrite(STDERR, "Penggunaan: php scripts/fix_backup_organizations.php <file-backup.sql> [file-fixed.sql]\n");
    exit(1);
}

$inFile = $argv[1];
if (! is_file($inFile)) {
    fwrite(STDERR, "File backup tidak ditemukan: $inFile\n");
    exit(1);
}

$outFile = $argv[2] ?? null;
if (! $outFile) {
    $outFile = preg_replace('/\.sql$/i', '', $inFile).'_fixed.sql';
}

$sql = file_get_contents($inFile);

// 1. Cari INSERT INTO `organizations`
$orgInsertPos = stripos($sql, 'INSERT INTO `organizations`');
if ($orgInsertPos !== false) {
    $semiPos = strpos($sql, ';', $orgInsertPos);
    $orgInsert = substr($sql, $orgInsertPos, $semiPos - $orgInsertPos + 1);

    // Parse baris organisasi
    $valuesPos = strpos($orgInsert, 'VALUES');
    $body = substr($orgInsert, $valuesPos + 6, strlen($orgInsert) - $valuesPos - 7);

    // Ekstrak nilai
    $rows = [];
    $i = 0;
    $bodyLen = strlen($body);
    while ($i < $bodyLen) {
        if ($body[$i] !== '(') {
            $i++;

            continue;
        }
        $j = $i + 1;
        $inStr = false;
        $parenDepth = 1;
        while ($j < $bodyLen && $parenDepth > 0) {
            $c = $body[$j];
            if ($c === '\\') {
                $j += 2;

                continue;
            }
            if ($inStr) {
                if ($c === "'") {
                    $inStr = false;
                }
            } else {
                if ($c === "'") {
                    $inStr = true;
                } elseif ($c === '(') {
                    $parenDepth++;
                } elseif ($c === ')') {
                    $parenDepth--;
                }
            }
            $j++;
        }
        $tuple = substr($body, $i, $j - $i);
        $rows[] = $tuple;
        $i = $j;
    }

    // Filter: hapus baris dengan id = 29
    $filteredRows = [];
    foreach ($rows as $row) {
        // Cari id di posisi pertama (asumsi id adalah nilai pertama)
        $firstComma = strpos($row, ',');
        $idStr = trim(substr($row, 1, $firstComma - 1));
        $id = (int) trim($idStr, "'");
        if ($id !== 29) {
            $filteredRows[] = $row;
        }
    }

    if (empty($filteredRows)) {
        // hapus seluruh INSERT organizations
        $sql = substr_replace($sql, '', $orgInsertPos, $semiPos - $orgInsertPos + 1);
    } else {
        $newInsert = 'INSERT INTO `organizations` VALUES '.implode(',', $filteredRows).';';
        $sql = substr_replace($sql, $newInsert, $orgInsertPos, $semiPos - $orgInsertPos + 1);
    }

    echo 'Organizations: '.count($rows).' rows, filtered to '.count($filteredRows)."\n";
}

// 2. UPDATE owner_user_id menjadi NULL untuk organisasi 29 (jika ada)
$updatePos = stripos($sql, 'UPDATE `organizations`');
if ($updatePos === false) {
    // Tambah perintah UPDATE setelah INSERT stores atau sebelum COMMIT
    $commitPos = strrpos($sql, 'COMMIT;');
    if ($commitPos !== false) {
        $updateCmd = "\n-- Setelah user 54 di-import, baru update owner organisasi\n"
                   ."-- UPDATE `organizations` SET owner_user_id = 54 WHERE id = 29;\n";
        $sql = substr_replace($sql, $updateCmd, $commitPos, 0);
    }
}

// 3. Simpan file baru
$fh = fopen($outFile, 'w');
if (! $fh) {
    fwrite(STDERR, "Tidak bisa menulis file: $outFile\n");
    exit(1);
}
fwrite($fh, $sql);
fclose($fh);

echo 'File fixed: '.realpath($outFile)."\n";
