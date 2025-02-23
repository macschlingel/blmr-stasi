<?php

require_once __DIR__ . '/EventFetcher.php';

class MemberFetcher extends EventFetcher {
    public function getMembers() {
        $params = [
            'has_left' => 'false',
            'limit' => 100
        ];

        $allMembers = [];
        $hasMore = true;
        $page = 1;

        while ($hasMore) {
            $params['page'] = $page;
            $response = $this->makeApiRequest('member', $params);
            
            if (isset($response['results'])) {
                $allMembers = array_merge($allMembers, $response['results']);
                echo "Fetched " . count($response['results']) . " members (page $page)\n";
            }

            // Check if there are more pages
            $hasMore = isset($response['next']) && $response['next'] !== null;
            if ($hasMore) {
                $page++;
            }
        }

        return $allMembers;
    }

    public function saveMember($member) {
        try {
            $this->pdo->beginTransaction();

            $stmt = $this->pdo->prepare("
                INSERT INTO members (
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
                        echo "Updated member: {$member['membershipNumber']} ({$member['emailOrUserName']})\n";
                        $updatedMembers++;
                    } else {
                        echo "New member: {$member['membershipNumber']} ({$member['emailOrUserName']})\n";
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
        $stmt = $this->pdo->prepare("SELECT id FROM members WHERE id = ?");
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