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

    switch ($sensorType) {
        case 'http':
            // HTTP-Check via cURL
            $ch = curl_init($serviceUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            // Erlaubte HTTP-Codes: Standardmäßig 200, oder sensor_config als kommaseparierte Liste (z. B. "200,302")
            $allowedCodes = [200];
            if (!empty($sensorConfig)) {
                $allowedCodes = array_map('trim', explode(',', $sensorConfig));
            }
            $status  = in_array($httpCode, $allowedCodes);
            $details = "HTTP Code: $httpCode";
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
        
            $details = "Ping Output: " . ($pingResult ? $pingResult : "Kein Output");
            break;

        case 'port':
            // Port-Check: sensor_config sollte die zu prüfende Portnummer enthalten, ansonsten wird Port 80 genutzt
            $port = !empty($sensorConfig) ? intval($sensorConfig) : 80;
            $host = parse_url($serviceUrl, PHP_URL_HOST);
            $fp = @fsockopen($host, $port, $errno, $errstr, 10);
            if ($fp) {
                $status = true;
                fclose($fp);
            }
            $details = $fp ? "Port $port erreichbar" : "Port $port nicht erreichbar: $errstr";
            break;

        case 'dns':
            // DNS-Check: Auflösung des Domainnamens
            $host = parse_url($serviceUrl, PHP_URL_HOST);
            $ip = gethostbyname($host);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                $status  = true;
                $details = "Aufgelöste IP: $ip";
            } else {
                $details = "DNS-Auflösung fehlgeschlagen für $host";
            }
            break;

        case 'smtp':
            // SMTP-Check: Standardmäßig Port 25, sensor_config kann einen anderen Port angeben
            $port = !empty($sensorConfig) ? intval($sensorConfig) : 25;
            $host = parse_url($serviceUrl, PHP_URL_HOST);
            $fp = @fsockopen($host, $port, $errno, $errstr, 10);
            if ($fp) {
                $status = true;
                fclose($fp);
            }
            $details = $fp ? "SMTP-Port $port erreichbar" : "SMTP-Port $port nicht erreichbar: $errstr";
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

    // Ergebnis in die Datenbank schreiben inkl. user_id
    $insertStmt = $pdo->prepare("
        INSERT INTO uptime_checks (user_id, service_name, service_url, check_time, status)
        VALUES (:user_id, :service_name, :service_url, NOW(), :status)
    ");
    $insertStmt->execute([
        ':user_id'      => $userId, // Übernehmen der user_id aus der config Tabelle
        ':service_name' => $serviceName,
        ':service_url'  => $serviceUrl,
        ':status'       => $status ? 1 : 0
    ]);

    // Ergebnisse für die JSON-Ausgabe sammeln
    $results[] = [
        'service_name' => $serviceName,
        'service_url'  => $serviceUrl,
        'sensor_type'  => $sensorType,
        'status'       => $status ? 1 : 0,
        'details'      => $details
    ];
}

// JSON-Ausgabe
header('Content-Type: application/json');
echo json_encode($results, JSON_PRETTY_PRINT);