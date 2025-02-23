<?php
if (php_sapi_name() !== 'cli') {
    die('This script can only be run from the command line');
}

require_once __DIR__ . '/loadEnv.php';
loadEnv();

$prefix = getenv('TABLE_PREFIX') ?: '';
$schema = file_get_contents(__DIR__ . '/../schema/init.sql');
$schema = str_replace('{prefix}', $prefix, $schema);

try {
    $pdo = new PDO(
        "mysql:host=" . getenv('DB_HOST') . ";dbname=" . getenv('DB_NAME'),
        getenv('DB_USER'),
        getenv('DB_PASSWORD')
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    foreach (explode(';', $schema) as $query) {
        $query = trim($query);
        if (!empty($query)) {
            $pdo->exec($query);
        }
    }
    echo "Database initialized successfully with prefix '$prefix'\n";
} catch (Exception $e) {
    die("Error initializing database: " . $e->getMessage() . "\n");
} 