<?php
// recent_incidents.php

require 'db.php';

// GET-Parameter einlesen
$status_page_uuid = isset($_GET['status_page_uuid']) ? $_GET['status_page_uuid'] : '';
$service_id = isset($_GET['service_id']) ? $_GET['service_id'] : '';

if (empty($status_page_uuid)) {
    die(json_encode(["error" => "status_page_uuid ist erforderlich"]));
}

if (!empty($status_page_uuid)) {
    // Holen der sensor_ids für die angegebene Statuspage
    $sql = "SELECT sensor_ids FROM status_pages WHERE uuid = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$status_page_uuid]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result) {
        $sensor_ids = json_decode($result['sensor_ids'], true);
        
        if (!empty($sensor_ids)) {
            // Falls eine service_id angegeben wurde, prüfen wir, ob sie zur Statuspage gehört
            if (!empty($service_id) && in_array($service_id, $sensor_ids)) {
                $sql = "
                    SELECT 
                        i.id, 
                        i.date, 
                        i.description, 
                        i.status, 
                        i.created_at, 
                        c.name AS service_name 
                    FROM incidents i
                    LEFT JOIN config c ON i.service_id = c.id
                    WHERE i.service_id = ?
                    ORDER BY i.date DESC
                    LIMIT 5
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$service_id]);
            } else {
                // Falls keine service_id angegeben wurde, geben wir alle passenden Services zurück
                $placeholders = implode(',', array_fill(0, count($sensor_ids), '?'));
                $sql = "
                    SELECT 
                        i.id, 
                        i.date, 
                        i.description, 
                        i.status, 
                        i.created_at, 
                        c.name AS service_name 
                    FROM incidents i
                    LEFT JOIN config c ON i.service_id = c.id
                    WHERE i.service_id IN ($placeholders)
                    ORDER BY i.date DESC
                    LIMIT 5
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($sensor_ids);
            }

            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $data = [];
        }
    } else {
        $data = [];
    }
} else {
    $data = [];
}

header('Content-Type: application/json');
echo json_encode($data);
?>
