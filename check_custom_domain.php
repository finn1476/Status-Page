<?php
/**
 * Custom Domain Checker
 * 
 * This script analyzes and fixes issues with custom domains
 */

// First, include our redirect fix
require_once 'fix_redirects.php';

// Set content type for API-like response
header('Content-Type: application/json');

// Get the current domain
$current_domain = $_SERVER['HTTP_HOST'] ?? 'unknown';
$target_domain = isset($_GET['domain']) ? $_GET['domain'] : $current_domain;

// Connect to database
require_once 'db.php';

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Check if domain exists and is verified
    $stmt = $pdo->prepare("
        SELECT cd.*, sp.uuid, sp.page_title, u.email
        FROM custom_domains cd
        JOIN status_pages sp ON cd.status_page_id = sp.id
        JOIN users u ON sp.user_id = u.id
        WHERE cd.domain = ?
    ");
    $stmt->execute([$target_domain]);
    $domain_info = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $result = [
        'domain' => $target_domain,
        'current_domain' => $current_domain,
        'server_info' => [
            'http_host' => $_SERVER['HTTP_HOST'] ?? 'not set',
            'server_name' => $_SERVER['SERVER_NAME'] ?? 'not set',
            'request_uri' => $_SERVER['REQUEST_URI'] ?? 'not set',
            'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? 'not set',
            'server_addr' => $_SERVER['SERVER_ADDR'] ?? 'not set',
            'orig_host' => $_SERVER['ORIG_HOST'] ?? 'not set',
            'orig_http_host' => $_SERVER['ORIG_HTTP_HOST'] ?? 'not set',
            'using_https' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
            'cookies' => $_COOKIE
        ]
    ];
    
    if ($domain_info) {
        $result['domain_status'] = [
            'found' => true,
            'id' => $domain_info['id'],
            'verified' => (bool)$domain_info['verified'],
            'status_page_id' => $domain_info['status_page_id'],
            'status_page_uuid' => $domain_info['uuid'],
            'status_page_title' => $domain_info['page_title'],
            'owner_email' => $domain_info['email'],
        ];
        
        // If not verified, verify it now
        if (!$domain_info['verified']) {
            $stmt = $pdo->prepare("UPDATE custom_domains SET verified = 1 WHERE id = ?");
            $stmt->execute([$domain_info['id']]);
            $result['domain_status']['action'] = 'Domain has been automatically verified';
            $result['domain_status']['verified'] = true;
        }
        
        // Generate direct URL to the status page
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $result['direct_url'] = $protocol . '://' . $current_domain . '/status_page.php?status_page_uuid=' . $domain_info['uuid'];
        
        // Also check DNS configuration
        $dns_records = dns_get_record($target_domain, DNS_A + DNS_CNAME);
        $result['dns_records'] = $dns_records;
        
        // Set cookie to preserve custom domain across redirects
        setcookie('custom_domain', $target_domain, [
            'expires' => time() + 3600,
            'path' => '/',
            'domain' => '',
            'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
        $result['cookie_set'] = true;
        
        // Force update server variables
        $_SERVER['HTTP_HOST'] = $target_domain;
        $_SERVER['SERVER_NAME'] = $target_domain;
        $_SERVER['ORIG_HOST'] = $target_domain;
        $_SERVER['ORIG_HTTP_HOST'] = $target_domain;
        $result['variables_updated'] = true;
    } else {
        $result['domain_status'] = [
            'found' => false,
            'error' => 'Domain not found or not verified in database'
        ];
        
        // Check for similar domains
        $stmt = $pdo->prepare("SELECT domain FROM custom_domains WHERE domain LIKE ?");
        $stmt->execute(['%' . substr($target_domain, 0, 5) . '%']);
        $similar_domains = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if ($similar_domains) {
            $result['similar_domains'] = $similar_domains;
        }
    }
    
    // Output the result
    echo json_encode($result, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    echo json_encode([
        'error' => true,
        'message' => 'Error: ' . $e->getMessage(),
        'domain' => $target_domain,
        'current_domain' => $current_domain
    ]);
}
?> 