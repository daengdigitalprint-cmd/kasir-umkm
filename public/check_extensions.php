<?php

echo '<h2>PHP Extensions Check</h2>';
echo '<p>PHP Version: '.phpversion().'</p>';

// Check if PHP version is 8.3 or higher
$php_version = phpversion();
$required_version = '8.3.0';
if (version_compare($php_version, $required_version, '<')) {
    echo "<h3 style='color: red;'>CRITICAL: PHP version {$php_version} is too old!</h3>";
    echo "<p>Required: PHP {$required_version} or higher</p>";
} else {
    echo "<h3 style='color: green;'>✓ PHP version is compatible</h3>";
}

$required_extensions = [
    'mbstring' => 'Multibyte String Support',
    'json' => 'JSON Support',
    'openssl' => 'OpenSSL Support',
    'pdo' => 'PDO Database Support',
    'tokenizer' => 'Tokenizer Support',
    'xml' => 'XML Support',
    'curl' => 'cURL Support',
    'fileinfo' => 'File Information Support',
    'gd' => 'GD Image Processing',
    'zip' => 'ZIP Archive Support',
];

echo "<table border='1' cellpadding='5'>";
echo '<tr><th>Extension</th><th>Description</th><th>Status</th></tr>';

foreach ($required_extensions as $ext => $desc) {
    $status = extension_loaded($ext) ? '<span style="color: green;">✓ Loaded</span>' : '<span style="color: red;">✗ Missing</span>';
    echo "<tr><td>{$ext}</td><td>{$desc}</td><td>{$status}</td></tr>";
}

echo '</table>';

if (! extension_loaded('mbstring')) {
    echo "<h3 style='color: red;'>CRITICAL: mbstring extension is missing!</h3>";
    echo '<p>Please contact your hosting provider to enable the mbstring extension.</p>';
}
