<?php
// status.php
header('Content-Type: application/json');

// Datenbankkonfiguration – bitte anpassen!
$dbHost = 'localhost';
$dbName = 'monitoring';
$dbUser = 'root';
$dbPass = '';

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    echo json_encode(['error' => 'Datenbankverbindung fehlgeschlagen: ' . $e->getMessage()]);
    exit;
}

// POST-Daten einlesen
$postData = file_get_contents('php://input');
$data = json_decode($postData, true);

if (!$data) {
    echo json_encode(['error' => 'Keine gültigen POST-Daten erhalten']);
    exit;
}

// Parameter aus dem POST-Request
$status_page_uuid = isset($data['status_page_uuid']) ? $data['status_page_uuid'] : '';
$sensor_ids_csv   = isset($data['sensor_ids']) ? $data['sensor_ids'] : '';
$sort             = isset($data['sort']) ? $data['sort'] : '';
$userId           = isset($data['userId']) ? $data['userId'] : '';

// Überprüfung: UUID muss übergeben werden
if (empty($status_page_uuid)) {
    echo json_encode(['error' => 'Keine UUID übergeben']);
    exit;
}

// Überprüfen, ob die übergebene UUID zu einer Status Page gehört
$stmtUuid = $pdo->prepare("SELECT id, sensor_ids FROM status_pages WHERE uuid = ?");
$stmtUuid->execute([$status_page_uuid]);
$statusPageRecord = $stmtUuid->fetch(PDO::FETCH_ASSOC);

if (!$statusPageRecord) {
    echo json_encode(['error' => 'Die angegebene UUID gehört keiner Status Page.']);
    exit;
}

// Decodierung der in der Status Page hinterlegten Sensor-IDs (im JSON-Format)
$allowedSensorIds = json_decode($statusPageRecord['sensor_ids'], true);
if (!is_array($allowedSensorIds)) {
    $allowedSensorIds = [];
}

// Verarbeitung der übergebenen sensor_ids (CSV)
// Falls sensor_ids übergeben wurden, werden diese nur berücksichtigt, wenn sie auch in der Status Page erlaubt sind.
$requestSensorIds = [];
if (!empty($sensor_ids_csv)) {
    $requestSensorIds = array_filter(explode(',', $sensor_ids_csv), function($val) {
        return trim($val) !== '';
    });
    // Überschneidung berechnen: nur erlaubte Sensoren
    $sensorIdsArray = array_intersect($requestSensorIds, $allowedSensorIds);
} else {
    // Falls keine sensor_ids angegeben wurden, alle erlaubten Sensoren nutzen
    $sensorIdsArray = $allowedSensorIds;
}

// Falls sensorIdsArray leer ist, sind keine Sensoren zum Überwachen vorhanden
if (empty($sensorIdsArray)) {
    echo json_encode(['error' => 'Keine gültigen Sensor-IDs angegeben oder Sensor nicht erlaubt.']);
    exit;
}

// Hole alle zu überwachenden Services aus der Tabelle 'config'
// Falls sensorIdsArray befüllt ist, nur diese Services abrufen:
$placeholders = implode(',', array_fill(0, count($sensorIdsArray), '?'));
$sql = "SELECT id, name, url FROM config WHERE id IN ($placeholders)";
$stmt = $pdo->prepare($sql);
$stmt->execute($sensorIdsArray);
$monitoredServices = $stmt->fetchAll(PDO::FETCH_ASSOC);

$services = [];
foreach ($monitoredServices as $service) {
    $id   = $service['id'];
    $name = $service['name'];
    $url  = $service['url'];

    // Den letzten Check für den Service abfragen
    $stmtLast = $pdo->prepare("SELECT status FROM uptime_checks WHERE service_url = ? ORDER BY check_time DESC LIMIT 1");
    $stmtLast->execute([$url]);
    $lastCheck = $stmtLast->fetch(PDO::FETCH_ASSOC);
    $status = ($lastCheck && $lastCheck['status'] == 1) ? 'up' : 'down';

    // Tägliche Uptime (letzte 90 Tage) ermitteln
    $stmtDaily = $pdo->prepare("
        SELECT DATE(check_time) AS day, AVG(status)*100 AS uptime 
        FROM uptime_checks 
        WHERE service_url = ? 
          AND check_time >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
        GROUP BY day 
        ORDER BY day DESC
    ");
    $stmtDaily->execute([$url]);
    $dailyData = $stmtDaily->fetchAll(PDO::FETCH_ASSOC);

    // Array für die letzten 90 Tage aufbauen – auch für Tage ohne Daten
    $dailyUptime = [];
    for ($i = 0; $i < 90; $i++) {
        $day = date('Y-m-d', strtotime("-$i days"));
        $dailyUptime[$day] = null;
    }
    foreach ($dailyData as $row) {
        $dailyUptime[$row['day']] = round($row['uptime'], 2);
    }
    $dailyUptimeArray = [];
    foreach ($dailyUptime as $day => $uptimeVal) {
        $dailyUptimeArray[] = ['date' => $day, 'uptime' => $uptimeVal !== null ? $uptimeVal : 100];
    }

    // Globale Uptime (letzte 30 Tage) ermitteln
    $stmtGlobal = $pdo->prepare("
        SELECT COUNT(*) AS totalChecks, SUM(status) AS successfulChecks
        FROM uptime_checks 
        WHERE service_url = ? 
          AND check_time >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    ");
    $stmtGlobal->execute([$url]);
    $globalData = $stmtGlobal->fetch(PDO::FETCH_ASSOC);
    $globalUptime = 0;
    if ($globalData && $globalData['totalChecks'] > 0) {
        $globalUptime = round(($globalData['successfulChecks'] / $globalData['totalChecks']) * 100, 2);
    }

    $services[] = [
        'name'   => $name,
        'status' => $status,
        'uptime' => $globalUptime,
        'daily'  => $dailyUptimeArray
    ];
}

// Optionale Sortierung der Ergebnisse anhand des übergebenen Parameters
if ($sort === 'name') {
    usort($services, function($a, $b) {
        return strcmp($a['name'], $b['name']);
    });
} elseif ($sort === 'status') {
    usort($services, function($a, $b) {
        return strcmp($a['status'], $b['status']);
    });
} elseif ($sort === 'uptime') {
    usort($services, function($a, $b) {
        return $b['uptime'] <=> $a['uptime'];
    });
}

echo json_encode(['sensors' => $services]);
