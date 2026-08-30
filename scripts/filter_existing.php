<?php

/**
 * Filter file backup: hapus INSERT untuk baris yang PRIMARY KEY-nya sudah ada di VPS.
 * Memerlukan koneksi ke VPS untuk cek existing ID.
 *
 * Cara pakai:
 *   php scripts/filter_existing.php <file-backup.sql> [file-filtered.sql]
 *
 * Setel env:
 *   DB_VPS_HOST=127.0.0.1
 *   DB_VPS_PORT=3306
 *   DB_VPS_DATABASE=kasirumkm
 *   DB_VPS_USERNAME=kasirumkm
 *   DB_VPS_PASSWORD=Sembarang123
 */
if ($argc < 2) {
    fwrite(STDERR, "Penggunaan: php scripts/filter_existing.php <file-backup.sql> [file-filtered.sql]\n");
    exit(1);
}

$inFile = $argv[1];
if (! is_file($inFile)) {
    fwrite(STDERR, "File backup tidak ditemukan: $inFile\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Koneksi ke VPS
// ---------------------------------------------------------------------------
$host = getenv('DB_VPS_HOST') ?: '127.0.0.1';
$port = (int) (getenv('DB_VPS_PORT') ?: 3306);
$db = getenv('DB_VPS_DATABASE') ?: 'kasirumkm';
$user = getenv('DB_VPS_USERNAME') ?: 'kasirumkm';
$pass = getenv('DB_VPS_PASSWORD') ?: 'Sembarang123';

try {
    $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, 'Koneksi ke VPS gagal: '.$e->getMessage()."\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Parse INSERT, cek existing ID, tulis hanya baris yang belum ada
// ---------------------------------------------------------------------------
$sql = file_get_contents($inFile);
$outFile = $argv[2] ?? null;
if (! $outFile) {
    $outFile = preg_replace('/\.sql$/i', '', $inFile).'_filtered.sql';
}

$fh = fopen($outFile, 'w');
if (! $fh) {
    fwrite(STDERR, "Tidak bisa menulis file: $outFile\n");
    exit(1);
}

$offset = 0;
$len = strlen($sql);
$skipped = 0;
$written = 0;

while ($offset < $len) {
    $pos = stripos($sql, 'INSERT INTO `', $offset);
    if ($pos === false) {
        fwrite($fh, substr($sql, $offset));
        break;
    }

    fwrite($fh, substr($sql, $offset, $pos - $offset));

    $startTable = $pos + strlen('INSERT INTO `');
    $endTable = strpos($sql, '`', $startTable);
    $table = substr($sql, $startTable, $endTable - $startTable);

    // cari kurung kolom
    $parenOpen = strpos($sql, '(', $endTable);
    $parenClose = strpos($sql, ')', $parenOpen) + 1;
    $colPart = substr($sql, $parenOpen, $parenClose - $parenOpen);

    $valsPos = strpos($sql, 'VALUES', $parenClose);
    $semiPos = strpos($sql, ';', $valsPos);
    $body = substr($sql, $valsPos + 6, $semiPos - $valsPos - 6);

    // ambil semua id (nilai pertama dalam tuple)
    $ids = [];
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
        // ambil seluruh tuple
        $endTuple = $j;
        while ($endTuple < $bodyLen) {
            $c = $body[$endTuple];
            if ($c === ')') {
                $endTuple++;
                break;
            }
            $endTuple++;
        }
        $rows[] = substr($body, $i, $endTuple - $i);
        $i = $endTuple;
    }

    if (empty($ids)) {
        // table tanpa primary key numeric? tulis semua
        fwrite($fh, 'INSERT INTO `'.$table.'` '.$colPart.' VALUES '.$body.';');
        $written += count($rows);
    } else {
        // cek existing ID di VPS
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT id FROM `$table` WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        $existing = array_flip(array_column($stmt->fetchAll(), 'id'));

        $filteredRows = [];
        foreach ($rows as $idx => $row) {
            if (! isset($existing[$ids[$idx]])) {
                $filteredRows[] = $row;
            } else {
                $skipped++;
            }
        }

        if (empty($filteredRows)) {
            // semua baris sudah ada, skip INSERT
            // tidak tulis apa-apa
        } else {
            fwrite($fh, 'INSERT INTO `'.$table.'` '.$colPart.' VALUES '.implode(',', $filteredRows).';');
            $written += count($filteredRows);
        }
    }

    $offset = $semiPos + 1;
}

fclose($fh);

echo "Filter selesai.\n";
echo "Baris di-skip  : $skipped\n";
echo "Baris ditulis  : $written\n";
echo 'File          : '.realpath($outFile)."\n";
