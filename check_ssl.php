<?php
/**
 * SSL Certificate Expiration Checker
 * 
 * Dieses Skript überprüft die SSL-Zertifikate für alle konfigurierten URLs und 
 * speichert das Ablaufdatum in der Datenbank
 */

require 'db.php';

// Function to check SSL certificate expiration date
function checkSSLCertificate($url) {
    
    // Extract hostname from URL
    $parsedUrl = parse_url($url);
    if (!isset($parsedUrl['host'])) {
        return null;
    }
    
    $host = $parsedUrl['host'];
    $port = isset($parsedUrl['port']) ? $parsedUrl['port'] : 443;
    
    // Create socket connection to get SSL certificate
    $context = stream_context_create([
        'ssl' => [
            'capture_peer_cert' => true,
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ]);
    
    $socket = @stream_socket_client(
        "ssl://$host:$port", 
        $errno, 
        $errstr, 
        30, 
        STREAM_CLIENT_CONNECT, 
        $context
    );
    
    if (!$socket) {
        error_log("SSL check failed for $url: $errstr ($errno)");
        return null;
    }
    
    // Get certificate information
    $params = stream_context_get_params($socket);
    $cert = openssl_x509_parse($params['options']['ssl']['peer_certificate']);
    
    fclose($socket);
    
    if (!isset($cert['validTo_time_t'])) {
        return null;
    }
    
    // Return expiry date as Y-m-d format
    return date('Y-m-d', $cert['validTo_time_t']);
}

try {
    // Get all configured URLs
    $stmt = $pdo->query("SELECT id, url FROM config WHERE url LIKE 'https://%'");
    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Starting SSL certificate check for " . count($services) . " services...\n";
    
    foreach ($services as $service) {
        $id = $service['id'];
        $url = $service['url'];
        
        echo "Checking SSL for $url...\n";
        $expiryDate = checkSSLCertificate($url);
        
        if ($expiryDate) {
            // Update the database with expiry date
            $updateStmt = $pdo->prepare("UPDATE config SET ssl_expiry_date = ? WHERE id = ?");
            $updateStmt->execute([$expiryDate, $id]);
            
            echo "Updated SSL expiry date for $url: $expiryDate\n";
        } else {
            echo "Could not determine SSL expiry date for $url\n";
        }
    }
    
    echo "SSL certificate check completed.\n";
} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage() . "\n";
}
?> 