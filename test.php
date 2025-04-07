<?php
// Debugging aktivieren
error_reporting(E_ALL);
ini_set('display_errors', 1);

// DB-Verbindung
require_once 'db.php';

// Test-Status-Page UUID
$uuid = 'sp_67e326194aead6.88891103';

try {
    echo "<h2>Teste Status Page Lookup</h2>";
    
    // Status Page suchen
    $stmt = $pdo->prepare("SELECT * FROM status_pages WHERE uuid = ?");
    $stmt->execute([$uuid]);
    $statusPage = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($statusPage) {
        echo "<p>Status Page gefunden: ID=" . $statusPage['id'] . "</p>";
        
        // Incidents suchen
        echo "<h2>Teste Incidents Lookup</h2>";
        $query = "
            SELECT i.*, c.name as service_name 
            FROM incidents i 
            LEFT JOIN config c ON i.service_id = c.id 
            WHERE i.status_page_id = ?
            ORDER BY i.date DESC LIMIT 10
        ";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$statusPage['id']]);
        $incidents = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($incidents) > 0) {
            echo "<p>Incidents gefunden: " . count($incidents) . "</p>";
            echo "<pre>" . print_r($incidents, true) . "</pre>";
            
            // Updates für einen Incident testen
            echo "<h2>Teste Updates Lookup</h2>";
            $incident = $incidents[0];
            
            $stmtUpdates = $pdo->prepare("
                SELECT iu.*, u.name as username 
                FROM incident_updates iu
                JOIN users u ON iu.created_by = u.id
                WHERE iu.incident_id = ?
                ORDER BY iu.update_time DESC
            ");
            $stmtUpdates->execute([$incident['id']]);
            $updates = $stmtUpdates->fetchAll(PDO::FETCH_ASSOC);
            
            echo "<p>Updates gefunden für Incident " . $incident['id'] . ": " . count($updates) . "</p>";
            if (count($updates) > 0) {
                echo "<pre>" . print_r($updates, true) . "</pre>";
            } else {
                echo "<p>Keine Updates gefunden. Prüfe SQL:</p>";
                echo "<pre>SELECT iu.*, u.name as username 
FROM incident_updates iu
JOIN users u ON iu.created_by = u.id
WHERE iu.incident_id = " . $incident['id'] . "</pre>";
                
                // Prüfe, ob überhaupt Updates in der Tabelle sind
                $stmt = $pdo->query("SELECT COUNT(*) FROM incident_updates");
                $count = $stmt->fetchColumn();
                echo "<p>Anzahl aller Updates in der Tabelle: $count</p>";
            }
        } else {
            echo "<p>Keine Incidents gefunden. Prüfe SQL:</p>";
            echo "<pre>SELECT i.*, c.name as service_name 
FROM incidents i 
LEFT JOIN config c ON i.service_id = c.id 
WHERE i.status_page_id = " . $statusPage['id'] . "
ORDER BY i.date DESC LIMIT 10</pre>";
        }
    } else {
        echo "<p>Status Page nicht gefunden für UUID: $uuid</p>";
    }
} catch (Exception $e) {
    echo "<h2>Error</h2>";
    echo "<p>" . $e->getMessage() . "</p>";
}
?> 