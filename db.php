<?php
// db.php – Datenbankverbindung für die Datenbank "monitoring"
$dbHost = 'localhost';
$dbName = 'monitoring';
$dbUser = 'root';
$dbPass = '';

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die('Datenbankverbindung fehlgeschlagen: ' . $e->getMessage());
}
?>
