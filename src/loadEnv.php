<?php
if (!defined('APP_ACCESS') && php_sapi_name() !== 'cli') {
    die('Direct access not permitted');
}

function loadEnv($path = null) {
    if ($path === null) {
        $path = __DIR__ . '/../.env';
    }
    
    if (!file_exists($path)) {
        die(".env file not found");
    }
    
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            
            // Convert 'true'/'false' strings to actual booleans
            if (strtolower($value) === 'true') $value = '1';
            if (strtolower($value) === 'false') $value = '';
            
            putenv("$key=$value");
        }
    }
} 