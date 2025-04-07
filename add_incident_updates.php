<?php
require_once 'db.php';

try {
    // Erstelle die neue incident_updates Tabelle
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `incident_updates` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `incident_id` int(11) NOT NULL,
          `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `message` text NOT NULL,
          `status` enum('resolved','in progress','reported','investigating','identified','monitoring') NOT NULL,
          `created_by` int(11) NOT NULL,
          PRIMARY KEY (`id`),
          KEY `incident_id` (`incident_id`),
          KEY `created_by` (`created_by`),
          CONSTRAINT `fk_incident_updates_incident` FOREIGN KEY (`incident_id`) REFERENCES `incidents` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
          CONSTRAINT `fk_incident_updates_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ");
    
    // Füge das resolved_at Feld zur incidents Tabelle hinzu
    $pdo->exec("
        ALTER TABLE `incidents` 
        ADD COLUMN IF NOT EXISTS `resolved_at` datetime DEFAULT NULL AFTER `updated_at`;
    ");
    
    echo "Die Incident-Updates-Tabelle wurde erfolgreich erstellt!";
    
} catch (PDOException $e) {
    die("Fehler bei der Erstellung der Incident-Updates-Tabelle: " . $e->getMessage());
}
?> 