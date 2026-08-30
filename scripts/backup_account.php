<?php

/**
 * Backup data SATU akun (berdasarkan email) ke file SQL.
 * Hanya tabel & baris yang terkait dengan store milik akun tersebut yang diekspor.
 *
 * Cara pakai:
 *   php scripts/backup_account.php <email> [file-output.sql]
 *
 * Contoh:
 *   php scripts/backup_account.php outletberkahf3@gmail.com
 *   php scripts/backup_account.php outletberkahf3@gmail.com backups/backup_outlet.sql
 */

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$email = $argv[1] ?? null;
if (! $email) {
    fwrite(STDERR, "Penggunaan: php scripts/backup_account.php <email> [file-output.sql]\n");
    exit(1);
}

$pdo = DB::connection()->getPdo();
$dbName = DB::connection()->getDatabaseName();

// ---------------------------------------------------------------------------
// 1. Identifikasi user + store milik akun
// ---------------------------------------------------------------------------
$user = DB::table('users')->where('email', $email)->first();
if (! $user) {
    fwrite(STDERR, "User dengan email '$email' tidak ditemukan di database.\n");
    exit(1);
}

$storeIds = DB::table('store_user')->where('user_id', $user->id)->pluck('store_id')->all();
if ($user->store_id && ! in_array((int) $user->store_id, $storeIds, true)) {
    $storeIds[] = (int) $user->store_id;
}
$storeIds = array_values(array_unique(array_map('intval', $storeIds)));

if (! $storeIds) {
    fwrite(STDERR, "User '$email' tidak memiliki store sama sekali. Backup dibatalkan.\n");
    exit(1);
}

$userIdList = [(int) $user->id];
foreach (DB::table('store_user')->whereIn('store_id', $storeIds)->pluck('user_id') as $uid) {
    $userIdList[] = (int) $uid;
}
$userIdList = array_values(array_unique($userIdList));

echo "User  : $email (id={$user->id})\n";
echo 'Store : '.implode(', ', $storeIds)."\n";
echo 'Staff : '.implode(', ', $userIdList)."\n";

// ---------------------------------------------------------------------------
// 2. Metadata skema (tabel, kolom, foreign key)
// ---------------------------------------------------------------------------
$tables = [];
foreach ($pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'") as $row) {
    $tables[] = array_values($row)[0];
}

$colsByTable = [];
$stmt = $pdo->query(
    'SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS '.
    'WHERE TABLE_SCHEMA = '.$pdo->quote($dbName)
);
foreach ($stmt as $row) {
    $colsByTable[$row['TABLE_NAME']][] = $row['COLUMN_NAME'];
}

$fks = [];      // fks[childTable][fkColumn] = refTable
$children = []; // children[refTable][] = childTable
$stmt = $pdo->query(
    'SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE '.
    'WHERE TABLE_SCHEMA = '.$pdo->quote($dbName).' AND REFERENCED_TABLE_NAME IS NOT NULL'
);
foreach ($stmt as $row) {
    $fks[$row['TABLE_NAME']][$row['COLUMN_NAME']] = $row['REFERENCED_TABLE_NAME'];
    $children[$row['REFERENCED_TABLE_NAME']][] = $row['TABLE_NAME'];
}

// Tabel sistem yang TIDAK ikut dibackup
$skipTables = [
    'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
    'sessions', 'migrations', 'password_reset_tokens', 'personal_access_tokens',
];

// ---------------------------------------------------------------------------
// 3. BFS: cari semua tabel/baris milik akun
//    - root: tabel yang punya kolom store_id
//    - users: semua user yang terhubung ke store akun
//    - anak: tabel yang punya FK ke tabel yang sudah terpilih
// ---------------------------------------------------------------------------
$selected = []; // tabel => set id (primary key)
$exported = []; // tabel => baris[]
$queue = ['stores'];

foreach ($colsByTable as $t => $cols) {
    if (in_array($t, $skipTables, true)) {
        continue;
    }
    if (in_array('store_id', $cols)) {
        $queue[] = $t;
    }
}
$queue[] = 'users';
$queue = array_values(array_unique($queue));

$guard = 0;
while ($queue && $guard++ < 1000) {
    $t = array_shift($queue);
    if (in_array($t, $skipTables, true) || isset($exported[$t])) {
        continue;
    }

    $cols = $colsByTable[$t] ?? [];
    $where = null;

    if ($t === 'users') {
        // Ekspor pemilik akun + staff biasa. Super Admin global (is_super_admin=1)
        // di-skip karena sudah ada di VPS dan bukan bagian dari data akun ini.
        // Catatan: users punya kolom store_id, jadi filter ini HARUS sebelum store_id.
        $where = 'id IN ('.implode(',', $userIdList).') '
               .'AND (id = '.(int) $user->id.' OR COALESCE(is_super_admin, 0) = 0)';
    } elseif (in_array('store_id', $cols)) {
        $where = 'store_id IN ('.implode(',', $storeIds).')';
    } elseif (in_array('user_id', $cols)) {
        $where = 'user_id IN ('.implode(',', $userIdList).')';
    } else {
        // Anak: cari FK yang mengarah ke tabel yang sudah terpilih
        foreach (($fks[$t] ?? []) as $fkCol => $refTable) {
            if (! empty($selected[$refTable])) {
                $ids = array_slice(array_keys($selected[$refTable]), 0, 50000);
                $where = $fkCol.' IN ('.implode(',', $ids).')';
                break;
            }
        }
        if (! $where) {
            continue; // tabel global / tidak relevan untuk akun ini
        }
    }

    $rows = $pdo->query('SELECT * FROM `'.$t.'` WHERE '.$where)->fetchAll(PDO::FETCH_ASSOC);
    $exported[$t] = $rows;

    $selected[$t] = [];
    if (in_array('id', $cols, true)) {
        foreach ($rows as $r) {
            $selected[$t][(int) $r['id']] = true;
        }
    }

    // Enqueue tabel anak yang mereferensikan tabel ini
    foreach (($children[$t] ?? []) as $child) {
        if (! in_array($child, $skipTables, true) && ! isset($exported[$child])) {
            $queue[] = $child;
        }
    }
}

$totalRows = array_sum(array_map('count', $exported));

if ($totalRows === 0) {
    fwrite(STDERR, "Tidak ada data yang ditemukan untuk akun ini.\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// 4. Tulis file SQL
// ---------------------------------------------------------------------------
function escSql($v)
{
    if ($v === null) {
        return 'NULL';
    }
    if (is_int($v) || is_float($v)) {
        return (string) $v;
    }
    $s = (string) $v;
    $s = str_replace(['\\', "'"], ['\\\\', "\\'"], $s);

    return "'".$s."'";
}

$outFile = $argv[2] ?? null;
if (! $outFile) {
    $dir = __DIR__.'/../backups';
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    $safeEmail = preg_replace('/[^a-zA-Z0-9]/', '_', $email);
    $outFile = $dir.'/backup_'.$safeEmail.'_'.date('Ymd_His').'.sql';
}

$fh = fopen($outFile, 'w');
if (! $fh) {
    fwrite(STDERR, "Tidak bisa menulis file: $outFile\n");
    exit(1);
}

fwrite($fh, "-- ================================================================\n");
fwrite($fh, "-- Backup akun: $email\n");
fwrite($fh, '-- Store ID   : '.implode(', ', $storeIds)."\n");
fwrite($fh, '-- User ID    : '.implode(', ', $userIdList)."\n");
fwrite($fh, '-- Dibuat     : '.date('Y-m-d H:i:s')."\n");
fwrite($fh, "-- Database   : $dbName\n");
fwrite($fh, "-- ================================================================\n");
fwrite($fh, "SET NAMES utf8mb4;\n");
fwrite($fh, "SET FOREIGN_KEY_CHECKS=0;\n");
fwrite($fh, "START TRANSACTION;\n\n");

foreach ($exported as $t => $rows) {
    if (empty($rows)) {
        continue;
    }
    $cols = $colsByTable[$t];
    $colList = '`'.implode('`,`', $cols).'`';

    foreach (array_chunk($rows, 200) as $chunk) {
        $values = [];
        foreach ($chunk as $r) {
            $vals = [];
            foreach ($cols as $c) {
                $vals[] = escSql($r[$c]);
            }
            $values[] = '('.implode(',', $vals).')';
        }
        fwrite($fh, "INSERT INTO `$t` ($colList) VALUES\n".implode(",\n", $values).";\n");
    }
    fwrite($fh, "\n");
}

fwrite($fh, "COMMIT;\n");
fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
fwrite($fh, "-- Selesai. Total baris: $totalRows\n");
fclose($fh);

echo "\nBackup selesai!\n";
echo "Total baris: $totalRows\n";
echo 'File       : '.realpath($outFile)."\n";
