<?php
// API Domain Fix, wenn benötigt
require_once 'api_domain_fix.php';

// Fehlerbehandlung
header('Content-Type: application/json');

function handleError($message, $status = 500) {
    http_response_code($status);
    echo json_encode(['error' => $message]);
    exit;
}

// Überprüfe Parameter
if (!isset($_GET['status_page_id'])) {
    handleError('Status page ID is required', 400);
}

$statusPageId = $_GET['status_page_id'];

// DB Verbindung
try {
    require_once 'db.php';
    // PDO-Verbindung aus db.php ist jetzt als $pdo verfügbar
} catch (PDOException $e) {
    handleError('Database connection failed: ' . $e->getMessage());
}

// Überprüfe, ob Status-Seite existiert
$pageCheckQuery = "SELECT id FROM status_pages WHERE id = ?";
$pageCheckStmt = $pdo->prepare($pageCheckQuery);
$pageCheckStmt->execute([$statusPageId]);

if ($pageCheckStmt->rowCount() === 0) {
    handleError('Status page not found', 404);
}

// Services für diese Status-Seite abrufen
$servicesQuery = "SELECT id, name FROM config WHERE status_page_id = ?";
$servicesStmt = $pdo->prepare($servicesQuery);
$servicesStmt->execute([$statusPageId]);
$services = $servicesStmt->fetchAll(PDO::FETCH_ASSOC);

if (count($services) === 0) {
    echo json_encode([
        'dates' => [],
        'services' => []
    ]);
    exit;
}

// Reaktionszeiten der letzten 30 Tage abrufen
$days = 30;
$responseData = [
    'dates' => [],
    'services' => []
];

// Daten für die letzten $days Tage sammeln
$endDate = new DateTime();
$startDate = clone $endDate;
$startDate->modify("-{$days} days");

// Daten für jeden Service sammeln
foreach ($services as $service) {
    $serviceId = $service['id'];
    $serviceName = $service['name'];
    $responseData['services'][$serviceName] = [];

    // Reaktionszeiten für jeden Tag abrufen
    $timeQuery = "
        SELECT 
            DATE(check_time) as check_date,
            AVG(response_time) as avg_response_time
        FROM 
            uptime_checks
        WHERE 
            service_id = ? 
            AND check_time >= ?
            AND check_time <= ?
        GROUP BY 
            DATE(check_time)
        ORDER BY 
            check_date ASC
    ";
    
    $timeStmt = $pdo->prepare($timeQuery);
    $timeStmt->execute([
        $serviceId,
        $startDate->format('Y-m-d'),
        $endDate->format('Y-m-d')
    ]);
    $timesData = $timeStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Erstelle ein assoziatives Array für leichteren Zugriff
    $dateResponses = [];
    foreach ($timesData as $data) {
        $dateResponses[$data['check_date']] = round($data['avg_response_time'] * 1000, 2); // In ms umwandeln
    }
    
    // Stelle sicher, dass alle Tage abgedeckt sind (auch ohne Daten)
    $currentDate = clone $startDate;
    while ($currentDate <= $endDate) {
        $dateStr = $currentDate->format('Y-m-d');
        
        // Füge das Datum zum Array hinzu, wenn es noch nicht existiert
        if (!in_array($dateStr, $responseData['dates'])) {
            $responseData['dates'][] = $dateStr;
        }
        
        // Füge die Reaktionszeit hinzu (oder null, wenn keine Daten)
        if (isset($dateResponses[$dateStr])) {
            $responseData['services'][$serviceName][] = $dateResponses[$dateStr];
        } else {
            $responseData['services'][$serviceName][] = null;
        }
        
        $currentDate->modify('+1 day');
    }
}

// Sortiere die Daten nach Datum
array_multisort($responseData['dates'], SORT_ASC, $responseData['services']);

// Gib JSON zurück
echo json_encode($responseData); 