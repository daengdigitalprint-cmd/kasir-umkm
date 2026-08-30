<?php

echo '<h1>PHP Information</h1>';
echo '<p><strong>PHP Version:</strong> '.phpversion().'</p>';

echo '<h2>Required Extensions Status</h2>';
$extensions = [
    'mbstring' => 'Multibyte String Support',
    'pdo' => 'PDO Database Support',
    'pdo_mysql' => 'PDO MySQL Driver',
    'json' => 'JSON Support',
    'openssl' => 'OpenSSL Support',
    'tokenizer' => 'Tokenizer Support',
    'xml' => 'XML Support',
    'curl' => 'cURL Support',
    'fileinfo' => 'File Information Support',
    'gd' => 'GD Image Processing',
    'zip' => 'ZIP Archive Support',
];

echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
echo "<tr style='background: #f0f0f0;'><th>Extension</th><th>Description</th><th>Status</th></tr>";

foreach ($extensions as $ext => $desc) {
    $status = extension_loaded($ext) ?
        '<span style="color: green; font-weight: bold;">✓ LOADED</span>' :
        '<span style="color: red; font-weight: bold;">✗ MISSING</span>';
    echo "<tr><td>{$ext}</td><td>{$desc}</td><td>{$status}</td></tr>";
}

echo '</table>';

// Check critical extensions
$critical_missing = [];
foreach (['mbstring', 'pdo', 'pdo_mysql', 'json', 'openssl'] as $ext) {
    if (! extension_loaded($ext)) {
        $critical_missing[] = $ext;
    }
}

if (! empty($critical_missing)) {
    echo "<h2 style='color: red;'>CRITICAL ISSUES FOUND!</h2>";
    echo '<p>The following critical extensions are missing:</p>';
    echo '<ul>';
    foreach ($critical_missing as $ext) {
        echo "<li style='color: red;'><strong>{$ext}</strong></li>";
    }
    echo '</ul>';
    echo '<p><strong>Action Required:</strong> Contact your hosting provider to enable these extensions.</p>';
} else {
    echo "<h2 style='color: green;'>✓ All critical extensions are available!</h2>";
}

// Show full phpinfo if requested
if (isset($_GET['full']) && $_GET['full'] == '1') {
    echo '<hr><h2>Full PHP Information</h2>';
    phpinfo();
}
