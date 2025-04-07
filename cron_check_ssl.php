<?php
/**
 * Cron-Job zum Überprüfen der SSL-Zertifikate für alle HTTPS-Services
 * Empfohlene Ausführung: Täglich
 */

// CLI-Modus prüfen
if (PHP_SAPI !== 'cli') {
    header('HTTP/1.0 403 Forbidden');
    echo "Dieses Skript kann nur über die Kommandozeile ausgeführt werden.";
    exit;
}

// Fehlerbehandlung
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Datenbank-Verbindung
require_once 'db.php';
// PDO-Verbindung aus db.php ist jetzt als $pdo verfügbar

// Funktion zum Überprüfen eines SSL-Zertifikats
function checkSSLCertificate($url) {
    $urlParts = parse_url($url);
    
    // URL muss HTTPS sein
    if (!isset($urlParts['scheme']) || $urlParts['scheme'] !== 'https') {
        return null;
    }

    // Host aus URL entnehmen
    $host = $urlParts['host'];
    $port = isset($urlParts['port']) ? $urlParts['port'] : 443;
    
    echo "Überprüfe SSL für $host:$port...\n";
    
    // Verbindung zum Host herstellen
    $context = stream_context_create([
        'ssl' => [
            'capture_peer_cert' => true,
            'verify_peer' => false,
            'verify_peer_name' => false,
        ]
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
        echo "Fehler bei der Verbindung mit $host:$port: $errstr ($errno)\n";
        return null;
    }
    
    // Zertifikat abrufen
    $params = stream_context_get_params($socket);
    $cert = openssl_x509_parse($params['options']['ssl']['peer_certificate']);
    
    // Verbindung schließen
    fclose($socket);
    
    if (!$cert) {
        echo "Kein gültiges Zertifikat gefunden für $host:$port\n";
        return null;
    }
    
    // Ablaufdatum zurückgeben
    $expiryTimestamp = $cert['validTo_time_t'];
    $expiryDate = date('Y-m-d H:i:s', $expiryTimestamp);
    
    echo "SSL-Zertifikat für $host läuft ab am: $expiryDate\n";
    
    return $expiryDate;
}

// Alle HTTPS-Services abrufen
$stmt = $pdo->prepare("
    SELECT id, url 
    FROM config 
    WHERE url LIKE 'https://%'
");
$stmt->execute();
$services = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Starte SSL-Zertifikatsprüfung für " . count($services) . " HTTPS-Services...\n";

// Jeden Service prüfen und Datenbank aktualisieren
$updatedCount = 0;
$failedCount = 0;

foreach ($services as $service) {
    $expiryDate = checkSSLCertificate($service['url']);
    
    if ($expiryDate) {
        // SSL-Ablaufdatum in der Datenbank aktualisieren
        $updateStmt = $pdo->prepare("
            UPDATE config 
            SET ssl_expiry_date = ? 
            WHERE id = ?
        ");
        $result = $updateStmt->execute([$expiryDate, $service['id']]);
        
        if ($result) {
            $updatedCount++;
        } else {
            $failedCount++;
            echo "Fehler beim Aktualisieren der Datenbank für Service ID " . $service['id'] . "\n";
        }
    } else {
        $failedCount++;
    }
}

echo "SSL-Prüfung abgeschlossen: $updatedCount Services aktualisiert, $failedCount fehlgeschlagen.\n";
exit(0); 