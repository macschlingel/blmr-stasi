<?php
// Check if this is a CLI request or authorized web access
if (php_sapi_name() !== 'cli' && !defined('APP_ACCESS')) {
    die('This script can only be run from the command line or through the web interface');
}

function loadEnv() {
    $envFile = __DIR__ . '/../.env';
    if (!file_exists($envFile)) {
        throw new Exception(".env file not found");
    }

    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            
            if (!empty($key)) {
                putenv("$key=$value");
            }
        }
    }
}

class CalendarFetcher {
    private $apiToken;
    private $pdo;
    private $tablePrefix;
    private $tokenRefreshCallback;

    public function __construct($apiToken, $dbHost, $dbName, $dbUser, $dbPass, $tokenRefreshCallback = null) {
        $this->apiToken = $apiToken;
        $this->tokenRefreshCallback = $tokenRefreshCallback;
        $this->tablePrefix = getenv('TABLE_PREFIX') ?: '';

        try {
            $this->pdo = new PDO(
                "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
                $dbUser,
                $dbPass,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
                ]
            );
        } catch (PDOException $e) {
            throw new Exception("Database connection failed: " . $e->getMessage());
        }
    }

    protected function makeApiRequest($endpoint, $params = []) {
        $baseUrl = 'https://easyverein.com/api/v2.0/';
        $url = $baseUrl . $endpoint . '/?' . http_build_query($params);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->apiToken,
            'Accept: application/json'
        ]);
        
        // Add SSL verification options
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        
        // Add timeout
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($response === false) {
            $error = curl_error($ch);
            $errno = curl_errno($ch);
            curl_close($ch);
            throw new Exception("cURL Error ($errno): $error");
        }
        
        curl_close($ch);

        if ($httpCode === 401 && strpos($response, 'tokenRefreshNeeded') !== false) {
            throw new Exception("Token refresh needed");
        }

        if ($httpCode !== 200) {
            throw new Exception("API request failed with code $httpCode: $response");
        }

        $decodedResponse = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Failed to decode JSON response: " . json_last_error_msg());
        }

        return $decodedResponse;
    }

    protected function extractIdFromUrl($url) {
        if (empty($url)) return null;
        $parts = explode('/', rtrim($url, '/'));
        return end($parts);
    }

    public function getCalendars() {
        $params = [
            'limit' => 100
        ];
        
        $allCalendars = [];
        $hasMore = true;
        
        while ($hasMore) {
            $response = $this->makeApiRequest('calendar', $params);
            
            if (isset($response['results'])) {
                $allCalendars = array_merge($allCalendars, $response['results']);
            }
            
            // Check if there are more pages
            $hasMore = isset($response['next']) && $response['next'] !== null;
            if ($hasMore) {
                // Extract the offset from the next URL and add it to params
                parse_str(parse_url($response['next'], PHP_URL_QUERY), $nextParams);
                if (isset($nextParams['offset'])) {
                    $params['offset'] = $nextParams['offset'];
                }
            }

            // Wait for 1 second between requests
            sleep(1);
        }
        
        return $allCalendars;
    }

    public function saveCalendar($calendar) {
        try {
            $this->pdo->beginTransaction();

            $deleteAfterDate = !empty($calendar['_deleteAfterDate']) ? new DateTime($calendar['_deleteAfterDate']) : null;
            $orgId = $this->extractIdFromUrl($calendar['org']);
            $deletedBy = $this->extractIdFromUrl($calendar['_deletedBy']);

            $sql = "
                INSERT INTO {$this->tablePrefix}calendars (
                    id, org_id, name, description, color,
                    is_public, deleted_after, deleted_by
                ) VALUES (
                    :id, :org_id, :name, :description, :color,
                    :is_public, :deleted_after, :deleted_by
                ) ON DUPLICATE KEY UPDATE
                    org_id = :org_id,
                    name = :name,
                    description = :description,
                    color = :color,
                    is_public = :is_public,
                    deleted_after = :deleted_after,
                    deleted_by = :deleted_by
            ";

            $params = [
                ':id' => $calendar['id'],
                ':org_id' => $orgId,
                ':name' => $calendar['name'] ?? '',
                ':description' => $calendar['description'] ?? '',
                ':color' => $calendar['color'] ?? null,
                ':is_public' => isset($calendar['isPublic']) ? ($calendar['isPublic'] ? 1 : 0) : 0,
                ':deleted_after' => $deleteAfterDate?->format('Y-m-d H:i:s'),
                ':deleted_by' => $deletedBy
            ];

            $stmt = $this->pdo->prepare($sql);
            $result = $stmt->execute($params);

            if (!$result) {
                throw new Exception("Failed to save calendar");
            }

            $this->pdo->commit();
            return true;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}

// Usage example
if (php_sapi_name() === 'cli') {
    try {
        // Load environment variables from .env file
        loadEnv();

        // Load environment variables
        $apiToken = getenv('API_TOKEN');
        $dbHost = getenv('DB_HOST');
        $dbName = getenv('DB_NAME');
        $dbUser = getenv('DB_USER');
        $dbPass = getenv('DB_PASSWORD');

        if (empty($apiToken)) {
            throw new Exception("API token not found in environment variables");
        }

        // Create callback to save refreshed token
        $tokenRefreshCallback = function($newToken) {
            // Update .env file with new token
            $envFile = __DIR__ . '/../.env';
            $envContent = file_get_contents($envFile);
            $envContent = preg_replace('/API_TOKEN=.*/', 'API_TOKEN=' . $newToken, $envContent);
            file_put_contents($envFile, $envContent);
        };

        $fetcher = new CalendarFetcher($apiToken, $dbHost, $dbName, $dbUser, $dbPass, $tokenRefreshCallback);
        
        echo "\nFetching calendars...\n";
        $calendars = $fetcher->getCalendars();
        echo "Found " . count($calendars) . " calendars\n";
        
        foreach ($calendars as $calendar) {
            try {
                $fetcher->saveCalendar($calendar);
                echo "Processed calendar: " . $calendar['name'] . "\n";
            } catch (Exception $e) {
                echo "Error processing calendar {$calendar['id']}: " . $e->getMessage() . "\n";
                continue;
            }
        }
        
        echo "\nCalendar import completed successfully!\n";

    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
} 