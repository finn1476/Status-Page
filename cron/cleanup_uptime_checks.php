<?php
require_once '/var/www/html/db.php';

// Debug-Logging-Funktion
function debug_log($message, $type = 'INFO') {
    echo date('Y-m-d H:i:s') . " [$type] $message\n";
}

try {
    debug_log("Starting cleanup of old uptime checks");
    
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    debug_log("Database connection established");
    
    // Get the oldest entry before deletion
    $stmt = $pdo->prepare("
        SELECT check_time, service_url, status 
        FROM uptime_checks 
        ORDER BY check_time ASC 
        LIMIT 1
    ");
    $stmt->execute();
    $oldestEntry = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($oldestEntry) {
        debug_log("Oldest entry found: " . 
                 "Time: " . $oldestEntry['check_time'] . 
                 ", URL: " . $oldestEntry['service_url'] . 
                 ", Status: " . $oldestEntry['status']);
    } else {
        debug_log("No entries found in the database");
    }
    
    // Delete records older than 90 days
    $stmt = $pdo->prepare("
        DELETE FROM uptime_checks 
        WHERE check_time < DATE_SUB(NOW(), INTERVAL 90 DAY)
    ");
    
    $stmt->execute();
    $deletedCount = $stmt->rowCount();
    
    debug_log("Successfully deleted $deletedCount old uptime check records");
    
    // Optimize the table after deletion
    $pdo->exec("OPTIMIZE TABLE uptime_checks");
    debug_log("Table optimization completed");
    
    debug_log("Cleanup process completed successfully");
    
} catch (PDOException $e) {
    debug_log("Database error: " . $e->getMessage(), 'ERROR');
} catch (Exception $e) {
    debug_log("Error in cleanup process: " . $e->getMessage(), 'ERROR');
} 