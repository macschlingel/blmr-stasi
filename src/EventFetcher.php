<?php
// Check if this is a CLI request or authorized web access
if (php_sapi_name() !== 'cli' && !defined('APP_ACCESS')) {
    die('This script can only be run from the command line or through the web interface');
}

// Load .env file
function loadEnv($path = null) {
    if ($path === null) {
        $path = __DIR__ . '/../.env';
    }
    
    if (!file_exists($path)) {
        echo "Tried to load .env from: " . $path . "\n";
        throw new Exception(".env file not found");
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

class EventFetcher {
    protected $apiBaseUrl = 'https://easyverein.com/api/v2.0';
    protected $apiToken;
    protected $pdo;
    protected $tokenRefreshCallback;
    protected $debug;
    protected $tablePrefix;

    public function __construct($apiToken, $dbHost, $dbName, $dbUser, $dbPass, $tokenRefreshCallback = null) {
        $this->apiToken = $apiToken;
        $this->tokenRefreshCallback = $tokenRefreshCallback;
        $this->debug = getenv('DEBUG') === '1';
        $this->tablePrefix = getenv('TABLE_PREFIX') ?: '';
        
        // Initialize database connection
        $dsn = "mysql:host={$dbHost};port=3306;dbname={$dbName};charset=utf8mb4";
        $this->pdo = new PDO($dsn, $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
    }

    protected function log($message, $isDebug = false) {
        if (!$isDebug || ($isDebug && $this->debug)) {
            echo $message . "\n";
        }
    }

    protected function refreshToken() {
        echo "Token needs refreshing...\n";
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "{$this->apiBaseUrl}/refresh-token");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer {$this->apiToken}",
            'Accept: application/json'
        ]);

        $response = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($statusCode === 200) {
            $data = json_decode($response, true);
            $this->apiToken = $data['token'];
            
            if ($this->tokenRefreshCallback) {
                call_user_func($this->tokenRefreshCallback, $this->apiToken);
            }
            
            echo "Token refreshed successfully\n";
        }
    }

    protected function makeApiRequest($endpoint, $params = []) {
        $url = "{$this->apiBaseUrl}/{$endpoint}";
        
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $this->log("Making request to: {$url}", true);

        $maxRetries = 5;
        $attempt = 0;
        
        while ($attempt < $maxRetries) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: Bearer {$this->apiToken}",
                'Accept: application/json'
            ]);

            $response = curl_exec($ch);
            $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if (curl_errno($ch)) {
                throw new Exception("Curl error: " . curl_error($ch));
            }

            curl_close($ch);

            // Check if token refresh is needed
            $headers = [];
            if (isset($response['tokenRefreshNeeded']) && $response['tokenRefreshNeeded'] === true) {
                $this->refreshToken();
                continue; // Retry the request with new token
            }

            if ($statusCode === 429) {
                $attempt++;
                $sleepTime = ($attempt * 5);
                echo "Rate limit hit, waiting {$sleepTime} seconds (attempt {$attempt} of {$maxRetries})...\n";
                sleep($sleepTime);
                continue;
            }

            if ($statusCode !== 200) {
                throw new Exception("API request failed with status code: {$statusCode}, Response: {$response}");
            }

            $decodedResponse = json_decode($response, true);
            
            // Only print detailed response in debug mode
            if ($this->debug) {
                $this->log("\nAPI Response:", true);
                $this->log("Status Code: {$statusCode}", true);
                $this->log("Response Body:", true);
                $this->log(json_encode($decodedResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", true);
            }

            return $decodedResponse;
        }
        
        throw new Exception("Failed after {$maxRetries} attempts due to rate limiting");
    }

    public function getEventParticipations($eventId) {
        $params = [
            'deleted' => 'false',
            'limit' => 100
        ];
        
        $allParticipations = [];
        $hasMore = true;
        $url = "event/{$eventId}/participation";
        
        while ($hasMore) {
            $response = $this->makeApiRequest($url, $params);
            
            if (isset($response['results'])) {
                $allParticipations = array_merge($allParticipations, $response['results']);
            }
            
            // Check if there are more pages
            $hasMore = isset($response['next']) && $response['next'] !== null;
            if ($hasMore) {
                // Extract the next URL path
                $nextUrl = parse_url($response['next'], PHP_URL_PATH);
                $url = trim($nextUrl, '/');
                // Clear params as they're now in the URL
                $params = [];
            }
        }
        
        return $allParticipations;
    }

    private function extractMemberId($participationAddress) {
        if (preg_match('/\/contact-details\/(\d+)$/', $participationAddress, $matches)) {
            return (int)$matches[1];
        }
        return null;
    }

    public function saveParticipation($participation, $eventId) {
        $memberId = $this->extractMemberId($participation['participationAddress']);
        
        if (!$memberId) {
            throw new Exception("Could not extract member ID from: " . $participation['participationAddress']);
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO {$this->tablePrefix}participations (
                id, event_id, member_id, state
            ) VALUES (
                :id, :event_id, :member_id, :state
            ) ON DUPLICATE KEY UPDATE
                event_id = :event_id,
                member_id = :member_id,
                state = :state
        ");

        return $stmt->execute([
            ':id' => $participation['id'],
            ':event_id' => $eventId,
            ':member_id' => $memberId,
            ':state' => $participation['state']
        ]);
    }

    public function getEventsForDay($startDate, $endDate) {
        $calendarIds = getenv('CALENDAR_IDS');

        if ($this->debug) {
            $this->log("Filtering for calendar IDs: " . ($calendarIds ?: 'none'), true);
        }

        $params = [
            'start__gte' => $startDate,
            'start__lte' => $endDate,
            'limit' => 100
        ];

        // Only add calendar filter if calendar IDs are specified
        if (!empty($calendarIds)) {
            $params['calendar__in'] = $calendarIds;
            echo "Filtering for calendar IDs: $calendarIds\n";
        } else {
            echo "No calendar filter applied - fetching all events\n";
        }

        $allEvents = [];
        $hasMore = true;

        while ($hasMore) {
            $response = $this->makeApiRequest('event', $params);
            
            if (isset($response['results'])) {
                $allEvents = array_merge($allEvents, $response['results']);
                if ($this->debug) {
                    $this->log("Fetched " . count($response['results']) . " events", true);
                    if (count($response['results']) > 0) {
                        $this->log("\nSample event structure:", true);
                        $this->log(print_r($response['results'][0], true), true);
                    }
                }
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
        }

        return $allEvents;
    }

    public function saveEvent($event, $participantCount) {
        try {
            $this->pdo->beginTransaction();

            // Convert ISO 8601 dates to MySQL datetime format
            $startTime = new DateTime($event['start']);
            $endTime = new DateTime($event['end']);

            // Extract calendar ID from URL if present
            $calendarId = null;
            if (!empty($event['calendar'])) {
                if (preg_match('/\/calendar\/(\d+)$/', $event['calendar'], $matches)) {
                    $calendarId = (int)$matches[1];
                }
            }

            $sql = "
                INSERT INTO {$this->tablePrefix}events (
                    id, calendar_id, name, description,
                    location_name, location_object,
                    start_time, end_time, max_participants, actual_participants
                ) VALUES (
                    :id, :calendar_id, :name, :description,
                    :location_name, :location_object,
                    :start_time, :end_time, :max_participants, :actual_participants
                ) ON DUPLICATE KEY UPDATE
                    calendar_id = :calendar_id,
                    name = :name,
                    description = :description,
                    location_name = :location_name,
                    location_object = :location_object,
                    start_time = :start_time,
                    end_time = :end_time,
                    max_participants = :max_participants,
                    actual_participants = :actual_participants
            ";

            $params = [
                ':id' => $event['id'],
                ':calendar_id' => $calendarId,
                ':name' => $event['name'],
                ':description' => $event['description'] ?? '',
                ':location_name' => $event['locationName'] ?? null,
                ':location_object' => $event['locationObject'] ? json_encode($event['locationObject']) : null,
                ':start_time' => $startTime->format('Y-m-d H:i:s'),
                ':end_time' => $endTime->format('Y-m-d H:i:s'),
                ':max_participants' => $event['maxParticipators'] ?? null,
                ':actual_participants' => $participantCount
            ];

            // Only log detailed event data in debug mode
            if ($this->debug) {
                $this->log("\nEvent data:", true);
                $this->log("ID: " . $event['id'], true);
                $this->log("Calendar URL: " . ($event['calendar'] ?? 'null'), true);
                $this->log("Extracted Calendar ID: " . ($calendarId ?? 'null'), true);
                $this->log("\nSQL Parameters:", true);
                $this->log(print_r($params, true), true);
            }

            // Always log basic event info
            $this->log(sprintf(
                "Event: %s (Max: %d, Current: %d)",
                $event['name'],
                $event['maxParticipators'] ?? 0,
                $participantCount
            ));

            $stmt = $this->pdo->prepare($sql);
            $result = $stmt->execute($params);

            if (!$result) {
                throw new Exception("Failed to save event");
            }

            $this->pdo->commit();
            return true;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function eventExists($eventId) {
        $stmt = $this->pdo->prepare("SELECT id FROM {$this->tablePrefix}events WHERE id = ?");
        $stmt->execute([$eventId]);
        return $stmt->fetch() !== false;
    }
}

// Usage example
try {
    // Load environment variables from .env file
    loadEnv();

    // Get date range from command line arguments
    $startArg = $argv[1] ?? null;
    $endArg = $argv[2] ?? null;

    // Validate and parse dates
    try {
        if ($startArg && $endArg) {
            // If both dates provided, use them
            $currentDate = new DateTime($startArg);
            $endDate = new DateTime($endArg);
        } elseif ($startArg) {
            // If only start date provided, use it as single day
            $currentDate = new DateTime($startArg);
            $endDate = clone $currentDate;
        } else {
            // Default: process previous day
            $currentDate = new DateTime('yesterday');
            $endDate = clone $currentDate;
        }
    } catch (Exception $e) {
        die("Invalid date format. Please use YYYY-MM-DD format.\n");
    }

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
    $fetcher = new EventFetcher($apiToken, $dbHost, $dbName, $dbUser, $dbPass, $tokenRefreshCallback);
    
    $totalEvents = 0;
    $updatedEvents = 0;
    $newEvents = 0;

    while ($currentDate <= $endDate) {
        $startDate = $currentDate->format('Y-m-d 00:00:00');
        $dayEndDate = $currentDate->format('Y-m-d 23:59:59');
        
        echo "\nProcessing day: " . $currentDate->format('Y-m-d') . "\n";
        
        $events = $fetcher->getEventsForDay($startDate, $dayEndDate);
        $totalEvents += count($events);
        
        foreach ($events as $event) {
            try {
                $exists = $fetcher->eventExists($event['id']);
                $fetcher->saveEvent($event, 0);

                $participations = $fetcher->getEventParticipations($event['id']);
                $confirmedParticipants = 0;
                
                foreach ($participations as $participation) {
                    $fetcher->saveParticipation($participation, $event['id']);
                    if ($participation['state'] === 1) {
                        $confirmedParticipants++;
                    }
                }

                if ($confirmedParticipants > 0) {
                    $fetcher->saveEvent($event, $confirmedParticipants);
                }

                if ($exists) {
                    echo "Updated: " . $event['name'] . " (" . $event['start'] . ")\n";
                    $updatedEvents++;
                } else {
                    echo "New: " . $event['name'] . " (" . $event['start'] . ")\n";
                    $newEvents++;
                }
            } catch (Exception $e) {
                echo "Error processing event {$event['id']}: " . $e->getMessage() . "\n";
                continue;
            }
        }
        
        $currentDate->modify('+1 day');
    }
    
    echo "\nSummary:\n";
    echo "Total events processed: " . $totalEvents . "\n";
    echo "New events: " . $newEvents . "\n";
    echo "Updated events: " . $updatedEvents . "\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
} 