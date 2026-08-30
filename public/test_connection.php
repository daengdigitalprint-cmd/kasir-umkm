<?php

echo '<h2>Database Connection Test</h2>';

$hosts = ['localhost', '127.0.0.1', 'mysql'];
$dbname = 'juarauko_bayarki';
$username = 'juarauko_bayarki';
$password = 'your_password_here';

foreach ($hosts as $host) {
    echo "<h3>Testing host: $host</h3>";

    try {
        $dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";
        $pdo = new PDO($dsn, $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        echo "<p style='color: green;'>✓ Connection successful to $host</p>";

        // Test query
        $stmt = $pdo->query('SELECT VERSION() as version');
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        echo '<p>MySQL Version: '.$result['version'].'</p>';

        // Check tables
        $stmt = $pdo->query('SHOW TABLES');
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo '<p>Tables found: '.count($tables).'</p>';

        if (count($tables) > 0) {
            echo '<p>Table list: '.implode(', ', $tables).'</p>';
        }

        break; // Stop on first successful connection

    } catch (PDOException $e) {
        echo "<p style='color: red;'>✗ Connection failed to $host: ".$e->getMessage().'</p>';
    }
}

echo '<h3>Network Test:</h3>';
$test_hosts = ['localhost', '127.0.0.1', 'mysql'];
foreach ($test_hosts as $host) {
    $port = 3306;
    $connection = @fsockopen($host, $port, $errno, $errstr, 5);
    if (is_resource($connection)) {
        echo "<p style='color: green;'>✓ Port $port is open on $host</p>";
        fclose($connection);
    } else {
        echo "<p style='color: red;'>✗ Port $port is closed on $host ($errstr)</p>";
    }
}
