<?php

echo '<h2>Create Sessions Table for Laravel</h2>';

// Database configuration - update these values
$host = 'localhost';
$dbname = 'juarauko_bayarki';
$username = 'juarauko_bayarki';
$password = 'Sembarang123';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "<p style='color: green;'>✓ Database connection successful!</p>";

    // Check if sessions table exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'sessions'");
    if ($stmt->rowCount() > 0) {
        echo "<p style='color: orange;'>⚠ Sessions table already exists</p>";

        // Show current structure
        $stmt = $pdo->query('DESCRIBE sessions');
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo '<h3>Current Sessions Table Structure:</h3>';
        echo "<table border='1' cellpadding='5'>";
        echo '<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>';
        foreach ($columns as $column) {
            echo '<tr>';
            echo '<td>'.$column['Field'].'</td>';
            echo '<td>'.$column['Type'].'</td>';
            echo '<td>'.$column['Null'].'</td>';
            echo '<td>'.$column['Key'].'</td>';
            echo '<td>'.$column['Default'].'</td>';
            echo '<td>'.$column['Extra'].'</td>';
            echo '</tr>';
        }
        echo '</table>';
    } else {
        // Create sessions table with Laravel structure
        $sql = 'CREATE TABLE sessions (
            id varchar(255) NOT NULL,
            user_id bigint unsigned NULL,
            ip_address varchar(45) NULL,
            user_agent text NULL,
            payload longtext NOT NULL,
            last_activity int NOT NULL,
            PRIMARY KEY (id),
            KEY sessions_user_id_index (user_id),
            KEY sessions_last_activity_index (last_activity)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        $pdo->exec($sql);
        echo "<p style='color: green;'>✓ Sessions table created successfully!</p>";
    }

    // Check if users table exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'users'");
    if ($stmt->rowCount() > 0) {
        echo "<p style='color: green;'>✓ Users table exists</p>";
    } else {
        echo "<p style='color: red;'>✗ Users table not found</p>";
    }

    // Check if migrations table exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'migrations'");
    if ($stmt->rowCount() > 0) {
        echo "<p style='color: green;'>✓ Migrations table exists</p>";
    } else {
        echo "<p style='color: orange;'>⚠ Migrations table not found - creating it...</p>";

        $sql = 'CREATE TABLE migrations (
            id int unsigned NOT NULL AUTO_INCREMENT,
            migration varchar(255) NOT NULL,
            batch int NOT NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        $pdo->exec($sql);
        echo "<p style='color: green;'>✓ Migrations table created!</p>";
    }

    // Show all tables
    $stmt = $pdo->query('SHOW TABLES');
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo '<h3>All Tables in Database:</h3>';
    echo '<ul>';
    foreach ($tables as $table) {
        echo "<li>$table</li>";
    }
    echo '</ul>';

    echo '<h3>Next Steps:</h3>';
    echo '<ol>';
    echo '<li>Update database credentials in this file</li>';
    echo '<li>Create .env file with correct database settings</li>';
    echo '<li>Run: <code>php artisan migrate</code></li>';
    echo '<li>Run: <code>php artisan key:generate</code></li>';
    echo '<li>Run: <code>php artisan config:cache</code></li>';
    echo '</ol>';

} catch (PDOException $e) {
    echo "<p style='color: red;'>✗ Error: ".$e->getMessage().'</p>';
    echo '<p>Please check your database credentials and make sure MySQL is running.</p>';
}
