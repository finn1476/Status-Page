<?php
/**
 * AJAX endpoint to check SSL certificate status for a domain
 */
header('Content-Type: application/json');

// Check if user is logged in
session_start();
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

// Check for domain_id parameter
if (!isset($_GET['domain_id']) || !is_numeric($_GET['domain_id'])) {
    echo json_encode(['error' => 'Invalid domain ID']);
    exit;
}

$domain_id = (int)$_GET['domain_id'];

// Load database configuration
require_once 'db.php';

try {
    // Connect to database
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get domain info
    $stmt = $pdo->prepare("
        SELECT cd.* FROM custom_domains cd
        WHERE cd.id = ? AND cd.user_id = ?
    ");
    $stmt->execute([$domain_id, $_SESSION['user_id']]);
    $domain = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$domain) {
        echo json_encode(['error' => 'Domain not found']);
        exit;
    }
    
    // Get current status
    $current_status = $domain['ssl_status'];
    
    // If status is pending, check if certificate exists
    if ($current_status === 'pending') {
        $domain_name = $domain['domain'];
        $cert_path = "/var/www/html/certbot/config/live/{$domain_name}/fullchain.pem";
        $key_path = "/var/www/html/certbot/config/live/{$domain_name}/privkey.pem";
        
        if (file_exists($cert_path) && file_exists($key_path)) {
            // If certificate exists but status is pending, update to active
            $stmt = $pdo->prepare("UPDATE custom_domains SET ssl_status = 'active' WHERE id = ?");
            $stmt->execute([$domain_id]);
            $current_status = 'active';
        }
    }
    
    // Return the current status
    echo json_encode([
        'domain_id' => $domain_id,
        'domain' => $domain['domain'],
        'status' => $current_status
    ]);
    
} catch (PDOException $e) {
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    exit;
}
?> 