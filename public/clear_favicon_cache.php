<?php

// Script untuk clear cache favicon dan test favicon baru
// Akses via: https://bayarki.web.id/clear_favicon_cache.php

echo '<h2>Favicon Cache Clear & Test</h2>';

// Check if we're in Laravel environment
if (! file_exists(__DIR__.'/../artisan')) {
    echo "<p style='color: red;'>Error: Not in Laravel root directory</p>";
    exit;
}

$faviconFiles = [
    'favicon.ico',
    'favicon.svg',
    'favicon.png',
    'apple-touch-icon.png',
];

echo '<h3>Checking Favicon Files:</h3>';
foreach ($faviconFiles as $file) {
    $filePath = __DIR__.'/'.$file;
    if (file_exists($filePath)) {
        $fileSize = filesize($filePath);
        $fileTime = date('Y-m-d H:i:s', filemtime($filePath));
        echo "<p style='color: green;'>✅ {$file} - {$fileSize} bytes - Modified: {$fileTime}</p>";
    } else {
        echo "<p style='color: red;'>❌ {$file} - Not found</p>";
    }
}

echo '<h3>Favicon URLs:</h3>';
$baseUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http').'://'.$_SERVER['HTTP_HOST'];
foreach ($faviconFiles as $file) {
    $url = $baseUrl.'/public/'.$file;
    echo "<p><strong>{$file}:</strong> <a href='{$url}' target='_blank'>{$url}</a></p>";
}

echo '<h3>Cache Busting Headers:</h3>';
echo '<p>Add these headers to force favicon reload:</p>';
echo '<pre>';
echo "Cache-Control: no-cache, no-store, must-revalidate\n";
echo "Pragma: no-cache\n";
echo "Expires: 0\n";
echo '</pre>';

echo '<h3>HTML Meta Tags:</h3>';
echo '<p>Current favicon meta tags in app.blade.php:</p>';
echo '<pre>';
echo "&lt;link rel=\"icon\" href=\"/public/favicon.ico\" sizes=\"any\"&gt;\n";
echo "&lt;link rel=\"icon\" href=\"/public/favicon.svg\" type=\"image/svg+xml\"&gt;\n";
echo "&lt;link rel=\"apple-touch-icon\" href=\"/public/apple-touch-icon.png\"&gt;\n";
echo '</pre>';

echo '<h3>Browser Cache Clear Instructions:</h3>';
echo '<ol>';
echo '<li><strong>Hard Refresh:</strong> Ctrl+F5 (Windows) or Cmd+Shift+R (Mac)</li>';
echo '<li><strong>Clear Browser Cache:</strong> Settings > Privacy > Clear browsing data</li>';
echo '<li><strong>Incognito/Private Mode:</strong> Test in private browsing window</li>';
echo '<li><strong>Different Browser:</strong> Test in different browser</li>';
echo '</ol>';

echo '<h3>Test Favicon:</h3>';
echo '<p>Click these links to test favicon directly:</p>';
foreach ($faviconFiles as $file) {
    if (file_exists(__DIR__.'/'.$file)) {
        $url = $baseUrl.'/public/'.$file;
        echo "<p><a href='{$url}' target='_blank'><img src='{$url}' style='width: 32px; height: 32px; border: 1px solid #ccc; margin-right: 10px;' alt='{$file}'> {$file}</a></p>";
    }
}

echo '<hr>';
echo '<p><strong>Next steps:</strong></p>';
echo '<ol>';
echo '<li>Upload your new favicon files to public/ directory</li>';
echo '<li>Clear browser cache (Ctrl+F5)</li>';
echo '<li>Test in incognito/private mode</li>';
echo '<li>Delete this file after testing</li>';
echo '</ol>';

echo '<hr>';
echo '<p><strong>Common Issues:</strong></p>';
echo '<ul>';
echo '<li>Browser cache - try hard refresh</li>';
echo '<li>CDN cache - wait for CDN to update</li>';
echo '<li>File permissions - check if files are readable</li>';
echo '<li>Path issues - ensure paths are correct</li>';
echo '</ul>';
