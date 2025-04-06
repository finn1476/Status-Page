<?php
// maintenance_history.php

require 'db.php';
// GET-Parameter einlesen
$status_page_uuid = isset($_GET['status_page_uuid']) ? $_GET['status_page_uuid'] : '';
$service_id = isset($_GET['service_id']) ? $_GET['service_id'] : '';

if (empty($status_page_uuid)) {
    die(json_encode(["error" => "status_page_uuid ist erforderlich"]));
}

// Die zur UUID gehörenden service_ids abrufen
$sql = "SELECT sensor_ids FROM status_pages WHERE uuid = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$status_page_uuid]);
$statusPage = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$statusPage) {
    die(json_encode(["error" => "Statuspage nicht gefunden"]));
}

$sensor_ids = json_decode($statusPage['sensor_ids'], true);

if (empty($sensor_ids) || !is_array($sensor_ids)) {
    die(json_encode([]));
}

// Falls eine service_id angegeben wurde, prüfen, ob sie zur Statuspage gehört
if (!empty($service_id) && !in_array($service_id, $sensor_ids)) {
    die(json_encode(["error" => "Service-ID nicht erlaubt"]));
}

// Wartungshistorie für die zugehörigen Services abrufen
$sql = "
    SELECT 
        mh.id,
        mh.start_date,
        mh.end_date,
        mh.description, 
        mh.status, 
        mh.created_at, 
        c.name AS service_name 
    FROM maintenance_history mh
    LEFT JOIN config c ON mh.service_id = c.id
    WHERE mh.service_id IN (" . implode(',', array_map('intval', $sensor_ids)) . " ) 
    ORDER BY mh.start_date DESC
    LIMIT 5
";

$stmt = $pdo->query($sql);
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/json');
echo json_encode($data);
?>
