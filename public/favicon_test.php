<?php

// Simple favicon test script
// Akses via: https://bayarki.web.id/favicon_test.php

echo '<!DOCTYPE html>';
echo '<html><head><title>Favicon Test</title></head><body>';
echo '<h2>🔍 Favicon Test & Cache Clear</h2>';

$baseUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http').'://'.$_SERVER['HTTP_HOST'];

echo '<h3>📁 Checking Favicon Files:</h3>';
$faviconFiles = ['favicon.ico', 'favicon.svg', 'favicon.png', 'apple-touch-icon.png'];

foreach ($faviconFiles as $file) {
    $filePath = __DIR__.'/'.$file;
    if (file_exists($filePath)) {
        $fileSize = filesize($filePath);
        $fileTime = date('Y-m-d H:i:s', filemtime($filePath));
        echo "<p style='color: green;'>✅ <strong>{$file}</strong> - {$fileSize} bytes - Modified: {$fileTime}</p>";
    } else {
        echo "<p style='color: red;'>❌ <strong>{$file}</strong> - Not found</p>";
    }
}

echo '<h3>🔗 Favicon URLs:</h3>';
foreach ($faviconFiles as $file) {
    $url = $baseUrl.'/'.$file;
    echo "<p><strong>{$file}:</strong> <a href='{$url}' target='_blank'>{$url}</a></p>";
}

echo '<h3>🖼️ Test Favicon Images:</h3>';
foreach ($faviconFiles as $file) {
    if (file_exists(__DIR__.'/'.$file)) {
        $url = $baseUrl.'/'.$file;
        echo "<div style='margin: 10px 0; padding: 10px; border: 1px solid #ddd; display: inline-block;'>";
        echo "<p><strong>{$file}</strong></p>";
        echo "<a href='{$url}' target='_blank'><img src='{$url}' style='width: 32px; height: 32px; border: 1px solid #ccc;' alt='{$file}'></a>";
        echo '</div> ';
    }
}

echo '<h3>🔄 Clear Cache Instructions:</h3>';
echo '<ol>';
echo '<li><strong>Hard Refresh:</strong> Press <kbd>Ctrl+F5</kbd> (Windows) or <kbd>Cmd+Shift+R</kbd> (Mac)</li>';
echo '<li><strong>Clear Browser Cache:</strong> Go to browser settings > Privacy > Clear browsing data</li>';
echo '<li><strong>Incognito Mode:</strong> Test in private/incognito browsing window</li>';
echo '<li><strong>Different Browser:</strong> Try in a different browser</li>';
echo '</ol>';

echo '<h3>📝 Current Favicon Meta Tags:</h3>';
echo "<pre style='background: #f5f5f5; padding: 10px; border-radius: 5px;'>";
echo "&lt;link rel=\"icon\" href=\"/public/favicon.ico\" sizes=\"any\"&gt;\n";
echo "&lt;link rel=\"icon\" href=\"/public/favicon.svg\" type=\"image/svg+xml\"&gt;\n";
echo "&lt;link rel=\"apple-touch-icon\" href=\"/public/apple-touch-icon.png\"&gt;\n";
echo '</pre>';

echo '<h3>🚀 Quick Actions:</h3>';
echo "<p><a href='{$baseUrl}' target='_blank' style='background: #007cba; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>🏠 Go to Homepage</a></p>";
echo "<p><a href='{$baseUrl}/favicon.ico' target='_blank' style='background: #28a745; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>🔗 Test Favicon Direct</a></p>";

echo '<hr>';
echo '<p><strong>💡 Tips:</strong></p>';
echo '<ul>';
echo '<li>Make sure your new favicon files are in the <code>public/</code> directory</li>';
echo '<li>Use different file names to force cache refresh</li>';
echo '<li>Test in incognito mode to bypass cache</li>';
echo '<li>Wait a few minutes for CDN to update (if using CDN)</li>';
echo '</ul>';

echo "<p style='color: #666; font-size: 12px; margin-top: 30px;'>Delete this file after testing: <code>favicon_test.php</code></p>";
echo '</body></html>';
