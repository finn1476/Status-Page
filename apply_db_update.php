<?php
// Apply database updates for email unsubscribe functionality

require_once 'db.php';

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Check if the columns already exist
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as column_exists
        FROM information_schema.COLUMNS 
        WHERE TABLE_SCHEMA = ? 
        AND TABLE_NAME = 'email_subscribers' 
        AND COLUMN_NAME = 'unsubscribe_token'
    ");
    $stmt->execute([$dbName]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result['column_exists'] == 0) {
        // Apply the update from the SQL file
        $sql = file_get_contents('db_update_for_unsubscribe.sql');
        $pdo->exec($sql);
        
        echo "<p>Database update successful! Unsubscribe functionality has been added.</p>";
    } else {
        echo "<p>No updates needed. Unsubscribe columns already exist in the database.</p>";
    }
} catch (PDOException $e) {
    echo "<p>Error: " . $e->getMessage() . "</p>";
    exit(1);
}
?> 