<?php
// Ensure this is in the public directory that's web-accessible
require_once __DIR__ . '/../src/loadEnv.php';
loadEnv();

// Check if credentials are configured
$webUsername = getenv('WEB_USERNAME');
$webPassword = getenv('WEB_PASSWORD');

if (empty($webUsername) || empty($webPassword)) {
    die('Web access credentials not configured');
}

// Require authentication
if (!isset($_SERVER['PHP_AUTH_USER']) || 
    !isset($_SERVER['PHP_AUTH_PW']) ||
    $_SERVER['PHP_AUTH_USER'] !== $webUsername ||
    $_SERVER['PHP_AUTH_PW'] !== $webPassword) {
    header('WWW-Authenticate: Basic realm="Event Fetcher Access"');
    header('HTTP/1.0 401 Unauthorized');
    die('Access denied');
}

// Only allow POST requests to trigger fetches
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $startDate = $_POST['start_date'] ?? date('Y-m-d', strtotime('yesterday'));
    $endDate = $_POST['end_date'] ?? $startDate;

    if ($action === 'fetch_events') {
        $output = shell_exec("php ../src/EventFetcher.php " . escapeshellarg($startDate) . " " . escapeshellarg($endDate) . " 2>&1");
    } elseif ($action === 'fetch_members') {
        $output = shell_exec("php ../src/MemberFetcher.php 2>&1");
    } else {
        $output = "Invalid action";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Event Fetcher</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .form-group { margin: 10px 0; }
        pre { background: #f4f4f4; padding: 10px; }
    </style>
</head>
<body>
    <h1>Event Fetcher</h1>
    
    <form method="post">
        <div class="form-group">
            <label>Action:</label>
            <select name="action" required>
                <option value="fetch_events">Fetch Events</option>
                <option value="fetch_members">Fetch Members</option>
            </select>
        </div>
        
        <div class="form-group">
            <label>Start Date:</label>
            <input type="date" name="start_date" value="<?php echo date('Y-m-d'); ?>">
        </div>
        
        <div class="form-group">
            <label>End Date:</label>
            <input type="date" name="end_date" value="<?php echo date('Y-m-d'); ?>">
        </div>
        
        <button type="submit">Execute</button>
    </form>

    <?php if (isset($output)): ?>
        <h2>Output:</h2>
        <pre><?php echo htmlspecialchars($output); ?></pre>
    <?php endif; ?>
</body>
</html> 