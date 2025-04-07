<?php
// Datenbankverbindung herstellen
require_once 'db.php';

// Führe die Datenbankoperationen aus
try {
    // Schritt 1: Erstelle die neue incident_updates Tabelle
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `incident_updates` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `incident_id` int(11) NOT NULL,
          `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `message` text NOT NULL,
          `status` enum('resolved','in progress','reported','investigating','identified','monitoring') NOT NULL DEFAULT 'investigating',
          `created_by` int(11) NOT NULL,
          PRIMARY KEY (`id`),
          KEY `incident_id` (`incident_id`),
          KEY `created_by` (`created_by`),
          CONSTRAINT `fk_incident_updates_incident` FOREIGN KEY (`incident_id`) REFERENCES `incidents` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
          CONSTRAINT `fk_incident_updates_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ");
    
    // Schritt 2: Füge das resolved_at Feld zur incidents Tabelle hinzu
    $pdo->exec("
        ALTER TABLE `incidents` 
        ADD COLUMN IF NOT EXISTS `resolved_at` datetime DEFAULT NULL AFTER `updated_at`;
    ");
    
    // Schritt 3: Migriere bestehende Incidents zu Updates
    // Hole alle bestehenden Incidents
    $incidents = $pdo->query("SELECT * FROM incidents")->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($incidents as $incident) {
        // Erstelle ein Update für jeden bestehenden Incident
        $stmt = $pdo->prepare("
            INSERT INTO incident_updates 
            (incident_id, message, status, created_by, update_time) 
            VALUES (?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $incident['id'],
            $incident['description'],
            $incident['status'],
            $incident['user_id'],
            $incident['created_at']
        ]);
        
        // Aktualisiere resolved_at für gelöste Incidents
        if ($incident['status'] === 'resolved') {
            $pdo->exec("
                UPDATE incidents 
                SET resolved_at = updated_at 
                WHERE id = {$incident['id']}
            ");
        }
    }
    
    // Schritt 4: Erstelle Trigger für zukünftige Incident-Updates
    $pdo->exec("
        DROP TRIGGER IF EXISTS update_incident_status;
        
        CREATE TRIGGER update_incident_status AFTER INSERT ON incident_updates
        FOR EACH ROW
        BEGIN
          -- Aktualisiere den Incident-Status basierend auf dem neuesten Update
          UPDATE incidents SET 
            status = NEW.status,
            updated_at = NOW(),
            resolved_at = CASE WHEN NEW.status = 'resolved' THEN NOW() ELSE resolved_at END
          WHERE id = NEW.incident_id;
        END;
    ");
    
    $pdo->exec("
        DROP TRIGGER IF EXISTS create_initial_incident_update;
        
        CREATE TRIGGER create_initial_incident_update AFTER INSERT ON incidents
        FOR EACH ROW
        BEGIN
          -- Erstelle ein initiales Update bei der Incident-Erstellung
          INSERT INTO incident_updates (
            incident_id, 
            message, 
            status, 
            created_by
          ) VALUES (
            NEW.id, 
            NEW.description,
            NEW.status,
            NEW.user_id
          );
        END;
    ");
    
    // Statusmeldung
    echo "Incident-Updates-System erfolgreich eingerichtet!";
    
} catch (PDOException $e) {
    die("Fehler bei der Einrichtung des Incident-Updates-Systems: " . $e->getMessage());
}
?> 