<?php
require_once 'db.php';

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Delete unverified subscribers older than 24 hours
    $stmt = $pdo->prepare("
        DELETE FROM email_subscribers 
        WHERE status = 'pending' 
        AND created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ");
    $stmt->execute();
    
    $deleted_count = $stmt->rowCount();
    
    // Log the cleanup
    error_log("Cleanup: Deleted $deleted_count unverified email subscribers older than 1 hours");
    
    echo "Successfully deleted $deleted_count unverified subscribers.\n";
    
} catch (PDOException $e) {
    error_log("Error cleaning up unverified subscribers: " . $e->getMessage());
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
} 