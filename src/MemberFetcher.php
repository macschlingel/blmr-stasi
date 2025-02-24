<?php
// Check if this is a CLI request or authorized web access
if (php_sapi_name() !== 'cli' && !defined('APP_ACCESS')) {
    die('This script can only be run from the command line or through the web interface');
}

require_once __DIR__ . '/loadEnv.php';

class MemberFetcher {
    private $apiToken;
    private $pdo;
    private $tablePrefix;
    private $tokenRefreshCallback;
    private $debug;

    public function __construct($apiToken, $dbHost, $dbName, $dbUser, $dbPass, $tokenRefreshCallback = null) {
        $this->apiToken = $apiToken;
        $this->tokenRefreshCallback = $tokenRefreshCallback;
        $this->tablePrefix = getenv('TABLE_PREFIX') ?: '';
        $this->debug = getenv('DEBUG') === '1';

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

        $maxRetries = 5;
        $attempt = 0;
        
        while ($attempt < $maxRetries) {
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

            if ($httpCode === 429) {
                $attempt++;
                $sleepTime = ($attempt * 5);
                echo "Rate limit hit, waiting {$sleepTime} seconds...\n";
                sleep($sleepTime);
                continue;
            }

            if ($httpCode !== 200) {
                throw new Exception("API request failed with code $httpCode: $response");
            }

            return json_decode($response, true);
        }
        
        throw new Exception("Failed after {$maxRetries} attempts due to rate limiting");
    }

    public function getMembers() {
        $params = [
            'has_left' => 'false',
            'limit' => 100,
            'page' => 1
        ];

        $allMembers = [];
        
        do {
            $response = $this->makeApiRequest('member', $params);
            
            if (!isset($response['results'])) {
                break;
            }

            $results = $response['results'];
            $allMembers = array_merge($allMembers, $results);
            echo "Fetched " . count($results) . " members (total: " . count($allMembers) . " of {$response['count']})\n";
            
            if (empty($results) || !isset($response['next'])) {
                break;
            }

            $params['page']++;
            sleep(1);
            
        } while (true);
        
        return $allMembers;
    }

    public function saveMember($member) {
        try {
            $this->pdo->beginTransaction();

            $stmt = $this->pdo->prepare("
                INSERT INTO {$this->tablePrefix}members (
                    id, payment_amount, payment_interval_months
                ) VALUES (
                    :id, :payment_amount, :payment_interval_months
                ) ON DUPLICATE KEY UPDATE
                    payment_amount = :payment_amount,
                    payment_interval_months = :payment_interval_months
            ");

            $result = $stmt->execute([
                ':id' => $member['id'],
                ':payment_amount' => $member['paymentAmount'] === null ? null : (float)$member['paymentAmount'],
                ':payment_interval_months' => $member['paymentIntervallMonths']
            ]);

            if (!$result) {
                throw new Exception("Failed to save member");
            }

            $this->pdo->commit();
            return true;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function updateMembers() {
        $totalMembers = 0;
        $updatedMembers = 0;
        $newMembers = 0;

        echo "\nFetching members...\n";
        
        try {
            $members = $this->getMembers();
            $totalMembers = count($members);
            
            foreach ($members as $member) {
                try {
                    $exists = $this->memberExists($member['id']);
                    $this->saveMember($member);

                    if ($exists) {
                        $updatedMembers++;
                    } else {
                        $newMembers++;
                    }
                } catch (Exception $e) {
                    echo "Error processing member {$member['id']}: " . $e->getMessage() . "\n";
                    continue;
                }
            }
            
            echo "\nMember processing complete:\n";
            echo "Total members processed: " . $totalMembers . "\n";
            echo "New members: " . $newMembers . "\n";
            echo "Updated members: " . $updatedMembers . "\n";

        } catch (Exception $e) {
            echo "Error fetching members: " . $e->getMessage() . "\n";
        }
    }

    public function memberExists($memberId) {
        $stmt = $this->pdo->prepare("SELECT id FROM {$this->tablePrefix}members WHERE id = ?");
        $stmt->execute([$memberId]);
        return $stmt->fetch() !== false;
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

        $fetcher = new MemberFetcher($apiToken, $dbHost, $dbName, $dbUser, $dbPass, $tokenRefreshCallback);
        $fetcher->updateMembers();

    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
} 