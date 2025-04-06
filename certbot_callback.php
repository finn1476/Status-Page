<?php
// certbot_callback.php - Callback für die Certbot-Verifizierung
require_once 'db.php';

// Nur Certbot-eigene Anfragen erlauben
$auth_header = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
$expected_auth = 'Basic ' . base64_encode('certbot:' . getenv('CERTBOT_AUTH_TOKEN'));

if ($auth_header !== $expected_auth) {
    header('HTTP/1.1 403 Forbidden');
    exit('Access denied');
}

// Domain aus GET-Parameter lesen
$domain = isset($_GET['domain']) ? $_GET['domain'] : '';
$status = isset($_GET['status']) ? $_GET['status'] : '';

if (empty($domain) || empty($status)) {
    header('HTTP/1.1 400 Bad Request');
    exit('Missing parameters');
}

try {
    // Status in der Datenbank aktualisieren
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    if ($status === 'success') {
        $stmt = $pdo->prepare("UPDATE custom_domains SET ssl_status = 'active' WHERE domain = ?");
        $stmt->execute([$domain]);
        
        // Log-Eintrag für erfolgreiche SSL-Generierung
        $log_message = date('Y-m-d H:i:s') . " - SSL certificate successfully created for $domain\n";
        file_put_contents('/var/www/html/logs/ssl_success.log', $log_message, FILE_APPEND);
    } else {
        $stmt = $pdo->prepare("UPDATE custom_domains SET ssl_status = 'failed' WHERE domain = ?");
        $stmt->execute([$domain]);
        
        // Log-Eintrag für fehlgeschlagene SSL-Generierung
        $log_message = date('Y-m-d H:i:s') . " - SSL certificate generation failed for $domain\n";
        file_put_contents('/var/www/html/logs/ssl_error.log', $log_message, FILE_APPEND);
    }
    
    echo "Status updated";
    
} catch (PDOException $e) {
    header('HTTP/1.1 500 Internal Server Error');
    exit('Database error: ' . $e->getMessage());
}
?> 