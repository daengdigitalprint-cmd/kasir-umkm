<?php

// Script untuk setup storage folder di public
// Akses via: https://bayarki.web.id/public/create_storage_link.php

echo '<h2>Laravel Storage Setup - Alternative Method</h2>';

// Check if we're in Laravel environment
if (! file_exists(__DIR__.'/../artisan')) {
    echo "<p style='color: red;'>Error: Not in Laravel root directory</p>";
    exit;
}

// Get the paths
$publicStoragePath = __DIR__.'/storage';
$originalStoragePath = __DIR__.'/../storage/app/public';

echo '<p><strong>Public Storage Path:</strong> '.$publicStoragePath.'</p>';
echo '<p><strong>Original Storage Path:</strong> '.$originalStoragePath.'</p>';

// Create public storage directory
if (! is_dir($publicStoragePath)) {
    echo '<p>Creating public storage directory...</p>';
    if (mkdir($publicStoragePath, 0755, true)) {
        echo "<p style='color: green;'>Public storage directory created successfully</p>";
    } else {
        echo "<p style='color: red;'>Failed to create public storage directory</p>";
        exit;
    }
} else {
    echo "<p style='color: green;'>Public storage directory already exists</p>";
}

// Create products subdirectory
$productsPath = $publicStoragePath.'/products';
if (! is_dir($productsPath)) {
    echo '<p>Creating products directory...</p>';
    if (mkdir($productsPath, 0755, true)) {
        echo "<p style='color: green;'>Products directory created successfully</p>";
    } else {
        echo "<p style='color: red;'>Failed to create products directory</p>";
    }
} else {
    echo "<p style='color: green;'>Products directory already exists</p>";
}

// Create .htaccess for security
$htaccessPath = $publicStoragePath.'/.htaccess';
$htaccessContent = 'Options -Indexes
<Files "*">
    Order Allow,Deny
    Allow from all
</Files>';

if (! file_exists($htaccessPath)) {
    echo '<p>Creating .htaccess for security...</p>';
    if (file_put_contents($htaccessPath, $htaccessContent)) {
        echo "<p style='color: green;'>.htaccess created successfully</p>";
    } else {
        echo "<p style='color: orange;'>Failed to create .htaccess (not critical)</p>";
    }
} else {
    echo "<p style='color: green;'>.htaccess already exists</p>";
}

// Test if directory is writable
if (is_writable($publicStoragePath)) {
    echo "<p style='color: green;'>Storage directory is writable</p>";
} else {
    echo "<p style='color: red;'>Storage directory is not writable</p>";
    echo '<p>You may need to set proper permissions:</p>';
    echo '<code>chmod 755 '.$publicStoragePath.'</code>';
}

// Test if products directory is writable
if (is_writable($productsPath)) {
    echo "<p style='color: green;'>Products directory is writable</p>";
} else {
    echo "<p style='color: red;'>Products directory is not writable</p>";
    echo '<p>You may need to set proper permissions:</p>';
    echo '<code>chmod 755 '.$productsPath.'</code>';
}

// List contents
$contents = scandir($publicStoragePath);
echo '<p><strong>Storage contents:</strong></p>';
echo '<ul>';
foreach ($contents as $item) {
    if ($item != '.' && $item != '..') {
        $itemPath = $publicStoragePath.'/'.$item;
        $isDir = is_dir($itemPath) ? ' (directory)' : ' (file)';
        echo '<li>'.$item.$isDir.'</li>';
    }
}
echo '</ul>';

// Test URL access
$testUrl = 'https://bayarki.web.id/public/storage/';
echo "<p><strong>Test URL:</strong> <a href='".$testUrl."' target='_blank'>".$testUrl.'</a></p>';

echo '<hr>';
echo '<p><strong>Setup completed!</strong></p>';
echo '<p><strong>Next steps:</strong></p>';
echo '<ol>';
echo '<li>Test image upload in your application</li>';
echo '<li>Check if images are accessible via URL</li>';
echo '<li>Delete this file after successful testing</li>';
echo '</ol>';

echo '<hr>';
echo '<p><strong>Configuration updated:</strong></p>';
echo '<ul>';
echo '<li>Storage root: public/storage</li>';
echo '<li>Storage URL: '.(isset($_SERVER['HTTPS']) ? 'https' : 'http').'://'.$_SERVER['HTTP_HOST'].'/public/storage</li>';
echo '<li>Products path: public/storage/products</li>';
echo '</ul>';
