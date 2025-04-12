<?php
/**
 * Utility script to update SSL status for a domain
 * Usage: php update_ssl_status.php domain_name status
 * where status is 'active', 'pending', or 'failed'
 */

// Check for required arguments
if ($argc < 3) {
    echo "Usage: php update_ssl_status.php domain_name status\n";
    echo "Status must be one of: active, pending, failed\n";
    exit(1);
}

$domain = $argv[1];
$status = $argv[2];

// Validate status
$valid_statuses = ['active', 'pending', 'failed'];
if (!in_array($status, $valid_statuses)) {
    echo "Error: Status must be one of: active, pending, failed\n";
    exit(1);
}

// Load database configuration
require_once dirname(__DIR__) . '/db.php';

try {
    // Connect to database
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // First check if the domain exists in the database
    $check_stmt = $pdo->prepare("SELECT id, domain, ssl_status FROM custom_domains WHERE domain = ?");
    $check_stmt->execute([$domain]);
    $domain_record = $check_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($domain_record) {
        echo "Found domain in database: ID: {$domain_record['id']}, Current status: {$domain_record['ssl_status']}\n";
    } else {
        echo "Domain not found with exact match, trying case-insensitive search...\n";
        
        // Try case-insensitive search as a fallback
        $check_stmt = $pdo->prepare("SELECT id, domain, ssl_status FROM custom_domains WHERE LOWER(domain) = LOWER(?)");
        $check_stmt->execute([$domain]);
        $domain_record = $check_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($domain_record) {
            echo "Found domain with case-insensitive match: ID: {$domain_record['id']}, Current status: {$domain_record['ssl_status']}\n";
            // Use the exact domain name from the database for the update
            $domain = $domain_record['domain'];
        } else {
            echo "Error: Domain not found in the database\n";
            exit(1);
        }
    }
    
    // Update the status
    $stmt = $pdo->prepare("UPDATE custom_domains SET ssl_status = ? WHERE domain = ?");
    $result = $stmt->execute([$status, $domain]);
    $affected = $stmt->rowCount();
    
    if ($affected > 0) {
        echo "Success: Updated domain '$domain' status to '$status'\n";
    } else {
        echo "Warning: No rows were affected. Domain may already have status '$status'\n";
    }
    
} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage() . "\n";
    exit(1);
}
?> 