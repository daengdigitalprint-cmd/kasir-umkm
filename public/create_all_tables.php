<?php

echo '<h2>Create All Laravel Tables</h2>';

// Database configuration - update these values
$host = 'localhost';
$dbname = 'juarauko_bayarki';
$username = 'juarauko_bayarki';
$password = 'your_password_here';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "<p style='color: green;'>✓ Database connection successful!</p>";

    // Create sessions table
    $sql = 'CREATE TABLE IF NOT EXISTS sessions (
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
    echo "<p style='color: green;'>✓ Sessions table created/verified</p>";

    // Create migrations table
    $sql = 'CREATE TABLE IF NOT EXISTS migrations (
        id int unsigned NOT NULL AUTO_INCREMENT,
        migration varchar(255) NOT NULL,
        batch int NOT NULL,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    $pdo->exec($sql);
    echo "<p style='color: green;'>✓ Migrations table created/verified</p>";

    // Create users table
    $sql = "CREATE TABLE IF NOT EXISTS users (
        id bigint unsigned NOT NULL AUTO_INCREMENT,
        name varchar(255) NOT NULL,
        email varchar(255) NOT NULL,
        email_verified_at timestamp NULL DEFAULT NULL,
        password varchar(255) NOT NULL,
        remember_token varchar(100) NULL DEFAULT NULL,
        created_at timestamp NULL DEFAULT NULL,
        updated_at timestamp NULL DEFAULT NULL,
        store_id bigint unsigned NULL DEFAULT NULL,
        is_super_admin tinyint(1) NOT NULL DEFAULT '0',
        PRIMARY KEY (id),
        UNIQUE KEY users_email_unique (email),
        KEY users_store_id_foreign (store_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $pdo->exec($sql);
    echo "<p style='color: green;'>✓ Users table created/verified</p>";

    // Create stores table
    $sql = "CREATE TABLE IF NOT EXISTS stores (
        id bigint unsigned NOT NULL AUTO_INCREMENT,
        name varchar(255) NOT NULL,
        address text NULL DEFAULT NULL,
        phone varchar(255) NULL DEFAULT NULL,
        email varchar(255) NULL DEFAULT NULL,
        created_at timestamp NULL DEFAULT NULL,
        updated_at timestamp NULL DEFAULT NULL,
        tax_rate decimal(5,2) NULL DEFAULT NULL,
        tax_name varchar(255) NULL DEFAULT NULL,
        subscription_status varchar(255) NULL DEFAULT 'trial',
        subscription_expires_at timestamp NULL DEFAULT NULL,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $pdo->exec($sql);
    echo "<p style='color: green;'>✓ Stores table created/verified</p>";

    // Create products table
    $sql = "CREATE TABLE IF NOT EXISTS products (
        id bigint unsigned NOT NULL AUTO_INCREMENT,
        store_id bigint unsigned NOT NULL,
        name varchar(255) NOT NULL,
        description text NULL DEFAULT NULL,
        price decimal(10,2) NOT NULL,
        stock int NOT NULL DEFAULT '0',
        sku varchar(255) NULL DEFAULT NULL,
        created_at timestamp NULL DEFAULT NULL,
        updated_at timestamp NULL DEFAULT NULL,
        PRIMARY KEY (id),
        KEY products_store_id_foreign (store_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $pdo->exec($sql);
    echo "<p style='color: green;'>✓ Products table created/verified</p>";

    // Create customers table
    $sql = 'CREATE TABLE IF NOT EXISTS customers (
        id bigint unsigned NOT NULL AUTO_INCREMENT,
        store_id bigint unsigned NOT NULL,
        name varchar(255) NOT NULL,
        email varchar(255) NULL DEFAULT NULL,
        phone varchar(255) NULL DEFAULT NULL,
        address text NULL DEFAULT NULL,
        created_at timestamp NULL DEFAULT NULL,
        updated_at timestamp NULL DEFAULT NULL,
        PRIMARY KEY (id),
        KEY customers_store_id_foreign (store_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    $pdo->exec($sql);
    echo "<p style='color: green;'>✓ Customers table created/verified</p>";

    // Create transactions table
    $sql = "CREATE TABLE IF NOT EXISTS transactions (
        id bigint unsigned NOT NULL AUTO_INCREMENT,
        store_id bigint unsigned NOT NULL,
        customer_id bigint unsigned NULL DEFAULT NULL,
        total_amount decimal(10,2) NOT NULL,
        tax_amount decimal(10,2) NOT NULL DEFAULT '0.00',
        discount_amount decimal(10,2) NOT NULL DEFAULT '0.00',
        payment_method varchar(255) NULL DEFAULT NULL,
        status varchar(255) NOT NULL DEFAULT 'completed',
        created_at timestamp NULL DEFAULT NULL,
        updated_at timestamp NULL DEFAULT NULL,
        PRIMARY KEY (id),
        KEY transactions_store_id_foreign (store_id),
        KEY transactions_customer_id_foreign (customer_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $pdo->exec($sql);
    echo "<p style='color: green;'>✓ Transactions table created/verified</p>";

    // Create transaction_items table
    $sql = 'CREATE TABLE IF NOT EXISTS transaction_items (
        id bigint unsigned NOT NULL AUTO_INCREMENT,
        transaction_id bigint unsigned NOT NULL,
        product_id bigint unsigned NOT NULL,
        quantity int NOT NULL,
        price decimal(10,2) NOT NULL,
        total decimal(10,2) NOT NULL,
        created_at timestamp NULL DEFAULT NULL,
        updated_at timestamp NULL DEFAULT NULL,
        PRIMARY KEY (id),
        KEY transaction_items_transaction_id_foreign (transaction_id),
        KEY transaction_items_product_id_foreign (product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    $pdo->exec($sql);
    echo "<p style='color: green;'>✓ Transaction items table created/verified</p>";

    // Show all tables
    $stmt = $pdo->query('SHOW TABLES');
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo '<h3>All Tables Created:</h3>';
    echo '<ul>';
    foreach ($tables as $table) {
        echo "<li>$table</li>";
    }
    echo '</ul>';

    echo "<h3 style='color: green;'>✓ All tables created successfully!</h3>";
    echo '<p>Now you can access your Laravel application.</p>';

} catch (PDOException $e) {
    echo "<p style='color: red;'>✗ Error: ".$e->getMessage().'</p>';
    echo '<p>Please check your database credentials and make sure MySQL is running.</p>';
}
