<?php
// get_response_times.php - API zur Abfrage von Reaktionszeiten für Sparkline-Diagramme

// Fehlerbehandlung einschalten
header('Content-Type: application/json');

// Datenbankverbindung herstellen
require_once 'db.php';

// Sicherheitsüberprüfung - überprüfe die PHP-Session
session_start();
if (!isset($_SESSION['user_id'])) {
    // Wenn der Benutzer nicht über die Session authentifiziert ist, Cookie-Parameter prüfen
    if (isset($_COOKIE['PHPSESSID'])) {
        // Session-Cookie existiert, versuche die Session neu zu starten
        session_write_close();
        session_id($_COOKIE['PHPSESSID']);
        session_start();
    }
    
    // Endgültige Überprüfung
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized access']);
        exit;
    }
}

// Parameter validieren
$sensor_id = isset($_GET['sensor_id']) ? (int)$_GET['sensor_id'] : 0;
$days = isset($_GET['days']) ? (int)$_GET['days'] : 7;

// Begrenzung der Tagesanzahl auf 90 setzen
$days = min(90, max(1, $days));

if ($sensor_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid sensor ID']);
    exit;
}

try {
    // Überprüfen, ob der Sensor dem angemeldeten Benutzer gehört
    $stmt = $pdo->prepare("SELECT id, url FROM config WHERE id = ? AND user_id = ?");
    $stmt->execute([$sensor_id, $_SESSION['user_id']]);
    $sensor = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$sensor) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied to this sensor']);
        exit;
    }
    
    // Reaktionszeiten für den angegebenen Zeitraum abrufen (aus uptime_checks)
    // Versuche zuerst über service_id
    $stmt = $pdo->prepare("
        SELECT 
            check_time,
            response_time,
            CASE WHEN status = 1 THEN 'up' ELSE 'down' END as status
        FROM 
            uptime_checks
        WHERE 
            service_name = (SELECT name FROM config WHERE id = ? AND user_id = ?)
            AND user_id = ?
            AND check_time >= DATE_SUB(NOW(), INTERVAL ? DAY)
        ORDER BY 
            check_time ASC
    ");
    
    $stmt->execute([$sensor_id, $_SESSION['user_id'], $_SESSION['user_id'], $days]);
    $response_times = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Wenn keine Daten gefunden wurden, versuche es mit der URL
    if (empty($response_times)) {
        $stmt = $pdo->prepare("
            SELECT 
                check_time,
                response_time,
                CASE WHEN status = 1 THEN 'up' ELSE 'down' END as status
            FROM 
                uptime_checks
            WHERE 
                service_url = ? 
                AND user_id = ?
                AND check_time >= DATE_SUB(NOW(), INTERVAL ? DAY)
            ORDER BY 
                check_time ASC
        ");
        
        $stmt->execute([$sensor['url'], $_SESSION['user_id'], $days]);
        $response_times = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Falls noch immer keine Daten, gib ein Dummy-Ergebnis zurück
    if (empty($response_times)) {
        // Erstelle ein leeres Array mit einem Dummy-Eintrag
        $now = date('Y-m-d H:i:s');
        $response_times = [
            [
                'check_time' => $now,
                'response_time' => 0,
                'status' => 'up'
            ]
        ];
    }
    
    // JSON-Antwort ausgeben
    echo json_encode($response_times);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    exit;
}
?> 