<?php
// check.php

// Datenbankkonfiguration – bitte anpassen!
$dbHost = 'localhost';
$dbName = 'monitoring';
$dbUser = 'root';
$dbPass = '';

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die('Datenbankverbindung fehlgeschlagen: ' . $e->getMessage());
}

// Alle Services aus der Konfigurationstabelle holen
$stmt = $pdo->query("SELECT * FROM config");
$services = $stmt->fetchAll(PDO::FETCH_ASSOC);

$results = [];

// Funktion zum Prüfen des SSL-Zertifikats
function checkSSLCertificate($url) {
    $parsedUrl = parse_url($url);
    if (!isset($parsedUrl['host']) || !str_starts_with($url, 'https://')) {
        return null; // Nur HTTPS-URLs haben SSL-Zertifikate
    }
    
    $host = $parsedUrl['host'];
    $port = isset($parsedUrl['port']) ? $parsedUrl['port'] : 443;
    
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
        5, // Kurzes Timeout
        STREAM_CLIENT_CONNECT, 
        $context
    );
    
    if (!$socket) {
        return null;
    }
    
    $params = stream_context_get_params($socket);
    $cert = openssl_x509_parse($params['options']['ssl']['peer_certificate']);
    fclose($socket);
    
    return isset($cert['validTo_time_t']) ? date('Y-m-d', $cert['validTo_time_t']) : null;
}

foreach ($services as $service) {
    // Basisdaten
    $userId      = $service['user_id']; // Hier holen wir die user_id aus der config Tabelle
    $serviceName = $service['name'];
    $serviceUrl  = $service['url'];
    $sensorType  = isset($service['sensor_type']) ? $service['sensor_type'] : 'http';
    $sensorConfig = isset($service['sensor_config']) ? $service['sensor_config'] : '';

    // Standardmäßig wird der Service als "down" gewertet (status = false)
    $status  = false;
    $details = '';
    $responseTime = null;

    // Startzeit für Antwortzeitmessung
    $startTime = microtime(true);

    switch ($sensorType) {
        case 'http':
            // HTTP-Check via cURL mit Antwortzeitmessung
            $ch = curl_init($serviceUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $responseTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
            curl_close($ch);
            
            // Erlaubte HTTP-Codes: Standardmäßig 200, oder sensor_config als kommaseparierte Liste (z. B. "200,302")
            $allowedCodes = [200];
            if (!empty($sensorConfig)) {
                $allowedCodes = array_map('trim', explode(',', $sensorConfig));
            }
            $status  = in_array($httpCode, $allowedCodes);
            $details = "HTTP Code: $httpCode, Zeit: " . round($responseTime, 3) . "s";
            
            // Prüfe SSL-Zertifikat, wenn URL mit https:// beginnt
            if (str_starts_with($serviceUrl, 'https://')) {
                $expiryDate = checkSSLCertificate($serviceUrl);
                
                if ($expiryDate) {
                    // Aktualisiere die SSL-Ablaufdaten in der Datenbank
                    $updateStmt = $pdo->prepare("UPDATE config SET ssl_expiry_date = ? WHERE id = ?");
                    $updateStmt->execute([$expiryDate, $service['id']]);
                }
            }
            break;

        case 'ping':
            // Ping-Check (funktioniert unter Linux und Windows)
            $host = parse_url($serviceUrl, PHP_URL_HOST);
            $pingCmd = (stripos(PHP_OS, 'WIN') === 0) ? "ping -n 1 " : "ping -c 1 ";
            $pingResult = shell_exec($pingCmd . escapeshellarg($host));
        
            // Linux-Check (1 received) oder Windows-Check (Received = 1)
            if (
                strpos($pingResult, '1 received') !== false || 
                strpos($pingResult, '1 packets received') !== false || 
                (preg_match('/Received = (\d+)/', $pingResult, $matches) && intval($matches[1]) > 0)
            ) {
                $status = true;
            }
        
            // Extrahiere die Ping-Zeit
            if (preg_match('/time=([0-9.]+) ms/i', $pingResult, $matches)) {
                $responseTime = floatval($matches[1]) / 1000; // Konvertiere ms in Sekunden
            }
        
            $details = "Ping Output: " . ($pingResult ? substr($pingResult, 0, 100) . "... Zeit: " . round($responseTime, 3) . "s" : "Kein Output");
            break;

        case 'port':
            // Port-Check mit Zeitmessung
            $port = !empty($sensorConfig) ? intval($sensorConfig) : 80;
            $host = parse_url($serviceUrl, PHP_URL_HOST);
            
            $startTimeLocal = microtime(true);
            $fp = @fsockopen($host, $port, $errno, $errstr, 10);
            $responseTime = microtime(true) - $startTimeLocal;
            
            if ($fp) {
                $status = true;
                fclose($fp);
            }
            $details = $fp ? "Port $port erreichbar in " . round($responseTime, 3) . "s" : "Port $port nicht erreichbar: $errstr";
            break;

        case 'dns':
            // DNS-Check mit Zeitmessung
            $host = parse_url($serviceUrl, PHP_URL_HOST);
            
            $startTimeLocal = microtime(true);
            $ip = gethostbyname($host);
            $responseTime = microtime(true) - $startTimeLocal;
            
            if (filter_var($ip, FILTER_VALIDATE_IP) && $ip !== $host) {
                $status  = true;
                $details = "Aufgelöste IP: $ip in " . round($responseTime, 3) . "s";
            } else {
                $details = "DNS-Auflösung fehlgeschlagen für $host";
            }
            break;

        case 'smtp':
            // SMTP-Check mit Zeitmessung
            $port = !empty($sensorConfig) ? intval($sensorConfig) : 25;
            $host = parse_url($serviceUrl, PHP_URL_HOST);
            
            $startTimeLocal = microtime(true);
            $fp = @fsockopen($host, $port, $errno, $errstr, 10);
            $responseTime = microtime(true) - $startTimeLocal;
            
            if ($fp) {
                $status = true;
                fclose($fp);
            }
            $details = $fp ? "SMTP-Port $port erreichbar in " . round($responseTime, 3) . "s" : "SMTP-Port $port nicht erreichbar: $errstr";
            break;

        case 'custom':
            // Für benutzerdefinierte Checks – hier eigene Logik implementieren
            $status  = false;
            $details = "Custom-Check nicht implementiert";
            break;

        default:
            $status  = false;
            $details = "Unbekannter Sensortyp";
    }

    // Endzeit und Gesamtzeit berechnen, falls noch nicht gesetzt
    if ($responseTime === null) {
        $responseTime = microtime(true) - $startTime;
    }

    // Ergebnis in die Datenbank schreiben inkl. user_id und response_time
    $insertStmt = $pdo->prepare("
        INSERT INTO uptime_checks (user_id, service_name, service_url, check_time, status, response_time)
        VALUES (:user_id, :service_name, :service_url, NOW(), :status, :response_time)
    ");
    $insertStmt->execute([
        ':user_id'      => $userId,
        ':service_name' => $serviceName,
        ':service_url'  => $serviceUrl,
        ':status'       => $status ? 1 : 0,
        ':response_time' => $responseTime
    ]);

    // Ergebnisse für die JSON-Ausgabe sammeln
    $results[] = [
        'service_name'  => $serviceName,
        'service_url'   => $serviceUrl,
        'sensor_type'   => $sensorType,
        'status'        => $status ? 1 : 0,
        'response_time' => round($responseTime, 3),
        'details'       => $details
    ];
}

// JSON-Ausgabe
header('Content-Type: application/json');
echo json_encode($results, JSON_PRETTY_PRINT);