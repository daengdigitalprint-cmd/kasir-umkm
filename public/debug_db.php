<?php

echo '<h2>Database Connection Debug</h2>';

// Test basic PDO connection
$host = 'localhost';
$dbname = 'juarauko_bayarki';
$username = 'juarauko_bayarki';
$password = 'your_password_here';

echo '<h3>Testing PDO Connection:</h3>';
echo "<p>Host: $host</p>";
echo "<p>Database: $dbname</p>";
echo "<p>Username: $username</p>";

try {
    $dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";
    echo "<p>DSN: $dsn</p>";

    $pdo = new PDO($dsn, $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "<p style='color: green;'>✓ PDO Connection successful!</p>";

    // Test query
    $stmt = $pdo->query('SELECT 1 as test');
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<p style='color: green;'>✓ Query test successful: ".$result['test'].'</p>';

} catch (PDOException $e) {
    echo "<p style='color: red;'>✗ PDO Connection failed: ".$e->getMessage().'</p>';
    echo '<p>Error Code: '.$e->getCode().'</p>';
}

echo '<h3>PHP Extensions:</h3>';
echo '<p>PDO: '.(extension_loaded('pdo') ? '✓ Loaded' : '✗ Missing').'</p>';
echo '<p>PDO MySQL: '.(extension_loaded('pdo_mysql') ? '✓ Loaded' : '✗ Missing').'</p>';
echo '<p>MySQLi: '.(extension_loaded('mysqli') ? '✓ Loaded' : '✗ Missing').'</p>';

echo '<h3>Environment Variables:</h3>';
echo '<p>DB_CONNECTION: '.(getenv('DB_CONNECTION') ?: 'Not set').'</p>';
echo '<p>DB_HOST: '.(getenv('DB_HOST') ?: 'Not set').'</p>';
echo '<p>DB_DATABASE: '.(getenv('DB_DATABASE') ?: 'Not set').'</p>';
echo '<p>DB_USERNAME: '.(getenv('DB_USERNAME') ?: 'Not set').'</p>';

echo '<h3>Laravel Config:</h3>';
if (file_exists(__DIR__.'/../.env')) {
    echo "<p style='color: green;'>✓ .env file exists</p>";
    $env_content = file_get_contents(__DIR__.'/../.env');
    $lines = explode("\n", $env_content);
    foreach ($lines as $line) {
        if (strpos($line, 'DB_') === 0) {
            echo '<p>'.htmlspecialchars($line).'</p>';
        }
    }
} else {
    echo "<p style='color: red;'>✗ .env file not found</p>";
}

echo '<h3>Possible Solutions:</h3>';
echo '<ul>';
echo '<li>Check if MySQL server is running on hosting</li>';
echo '<li>Verify database credentials in .env file</li>';
echo '<li>Check if database exists and user has permissions</li>';
echo '<li>Try different host (127.0.0.1 instead of localhost)</li>';
echo '<li>Check firewall settings</li>';
echo '</ul>';
