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

// Falls keine gültigen POST-Daten vorliegen, kann man optional auf GET umstellen oder abbrechen:
if (!$data) {
    echo json_encode(['error' => 'Keine gültigen POST-Daten erhalten']);
    exit;
}

// Parameter aus dem POST-Request
$status_page_uuid = isset($data['status_page_uuid']) ? $data['status_page_uuid'] : '';
$sensor_ids_csv    = isset($data['sensor_ids']) ? $data['sensor_ids'] : '';
$sort              = isset($data['sort']) ? $data['sort'] : '';
$userId            = isset($data['userId']) ? $data['userId'] : '';

// Hier könnten weitere Prüfungen erfolgen (z. B. Abgleich der userId mit der Statuspage),
// werden aber in diesem Beispiel nicht weiter berücksichtigt.

// Falls sensor_ids als CSV übergeben wurden, in ein Array umwandeln:
$sensorIdsArray = [];
if (!empty($sensor_ids_csv)) {
    $sensorIdsArray = array_filter(explode(',', $sensor_ids_csv), function($val) {
        return trim($val) !== '';
    });
}

// Hole alle zu überwachenden Services aus der Tabelle 'config'
// Falls sensorIdsArray befüllt ist, nur diese Services abrufen:
if (!empty($sensorIdsArray)) {
    // Erstelle Platzhalter für die IN-Klausel
    $placeholders = implode(',', array_fill(0, count($sensorIdsArray), '?'));
    $sql = "SELECT id, name, url FROM config WHERE id IN ($placeholders)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($sensorIdsArray);
} else {
    // Falls keine sensor_ids angegeben wurden, alle Services abrufen:
    $stmt = $pdo->prepare("SELECT id, name, url FROM config");
    $stmt->execute();
}
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

    // Status bestimmen: "up", wenn der letzte Check den Wert 1 hatte, sonst "down"
    $status = ($lastCheck && $lastCheck['status'] == 1) ? 'up' : 'down';

    // Tägliche Uptime (letzte 90 Tage) ermitteln:
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

    // Formatieren in ein Array, das an das Frontend übergeben wird:
    $dailyUptimeArray = [];
    foreach ($dailyUptime as $day => $uptimeVal) {
        // Falls keine Daten vorhanden sind, setzen wir den Wert auf 100%
        $dailyUptimeArray[] = ['date' => $day, 'uptime' => $uptimeVal !== null ? $uptimeVal : 100];
    }

    // Globale Uptime (letzte 30 Tage) ermitteln:
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
        'uptime' => $globalUptime, // Globale Uptime der letzten 30 Tage
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

// An das Frontend wird das Array unter dem Schlüssel "sensors" zurückgegeben,
 // damit es zu dem in index2.php erwarteten Format passt.
echo json_encode(['sensors' => $services]);
