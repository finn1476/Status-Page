<?php
// certbot_callback.php - Callback for Certbot verification
require_once 'db.php';

// Start with error logging
error_log("Certbot callback received for domain: " . ($_GET['domain'] ?? 'none') . " status: " . ($_GET['status'] ?? 'none'));

// Read token from file
$token_file = '/var/www/html/.certbot_token';
$token = file_exists($token_file) ? trim(file_get_contents($token_file)) : '';

// Only allow Certbot's own requests
$auth_header = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
$expected_auth = 'Basic ' . base64_encode('certbot:' . $token);

if ($auth_header !== $expected_auth) {
    // For debugging
    error_log("Auth header: $auth_header");
    error_log("Expected: $expected_auth");
    header('HTTP/1.1 403 Forbidden');
    exit('Access denied');
}

// Read domain from GET parameter
$domain = isset($_GET['domain']) ? $_GET['domain'] : '';
$status = isset($_GET['status']) ? $_GET['status'] : '';

if (empty($domain) || empty($status)) {
    header('HTTP/1.1 400 Bad Request');
    exit('Missing parameters');
}

// Log file for callback debugging
$callback_log = '/var/www/html/logs/certbot_callback.log';
file_put_contents($callback_log, date('Y-m-d H:i:s') . " - Callback for domain: $domain with status: $status\n", FILE_APPEND);

try {
    // Update status in database
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // First check if the domain exists in the database
    $check_stmt = $pdo->prepare("SELECT id, domain, ssl_status FROM custom_domains WHERE domain = ?");
    $check_stmt->execute([$domain]);
    $domain_record = $check_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($domain_record) {
        file_put_contents($callback_log, date('Y-m-d H:i:s') . " - Found domain in database: ID: {$domain_record['id']}, Current status: {$domain_record['ssl_status']}\n", FILE_APPEND);
    } else {
        file_put_contents($callback_log, date('Y-m-d H:i:s') . " - Domain not found in database\n", FILE_APPEND);
        
        // Try case-insensitive search as a fallback
        $check_stmt = $pdo->prepare("SELECT id, domain, ssl_status FROM custom_domains WHERE LOWER(domain) = LOWER(?)");
        $check_stmt->execute([$domain]);
        $domain_record = $check_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($domain_record) {
            file_put_contents($callback_log, date('Y-m-d H:i:s') . " - Found domain with case-insensitive match: ID: {$domain_record['id']}, Current status: {$domain_record['ssl_status']}\n", FILE_APPEND);
            // Use the exact domain name from the database for the update
            $domain = $domain_record['domain'];
        }
    }
    
    if ($status === 'success') {
        $stmt = $pdo->prepare("UPDATE custom_domains SET ssl_status = 'active' WHERE domain = ?");
        $result = $stmt->execute([$domain]);
        $affected = $stmt->rowCount();
        
        // Log successful SSL generation
        $log_message = date('Y-m-d H:i:s') . " - SSL certificate successfully created for $domain\n";
        file_put_contents('/var/www/html/logs/ssl_success.log', $log_message, FILE_APPEND);
        file_put_contents($callback_log, date('Y-m-d H:i:s') . " - Update result: " . ($result ? "Success" : "Failed") . ", Rows affected: $affected\n", FILE_APPEND);
    } else {
        $stmt = $pdo->prepare("UPDATE custom_domains SET ssl_status = 'failed' WHERE domain = ?");
        $result = $stmt->execute([$domain]);
        $affected = $stmt->rowCount();
        
        // Log failed SSL generation
        $log_message = date('Y-m-d H:i:s') . " - SSL certificate generation failed for $domain\n";
        file_put_contents('/var/www/html/logs/ssl_error.log', $log_message, FILE_APPEND);
        file_put_contents($callback_log, date('Y-m-d H:i:s') . " - Update result: " . ($result ? "Success" : "Failed") . ", Rows affected: $affected\n", FILE_APPEND);
    }
    
    echo "Status updated for $domain: $status";
    
} catch (PDOException $e) {
    $error_message = "Database error: " . $e->getMessage();
    file_put_contents($callback_log, date('Y-m-d H:i:s') . " - $error_message\n", FILE_APPEND);
    header('HTTP/1.1 500 Internal Server Error');
    exit($error_message);
}
?> 