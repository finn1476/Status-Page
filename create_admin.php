<?php
require_once 'db.php';

// Admin-Benutzer Daten
$email = 'admin@example.com';
$password = 'admin123'; // Einfaches Passwort für Test-Zwecke
$name = 'Administrator';

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Prüfe, ob der Benutzer bereits existiert
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    if ($user) {
        // Benutzer existiert bereits, aktualisiere ihn
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password = ?, name = ?, role = 'admin', is_admin = 1, status = 'active', verified = 1 WHERE email = ?");
        $stmt->execute([$hashedPassword, $name, $email]);
        echo "<h1>Admin-Benutzer aktualisiert</h1>";
    } else {
        // Neuen Benutzer erstellen
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (email, password, name, role, is_admin, status, verified, created_at) VALUES (?, ?, ?, 'admin', 1, 'active', 1, NOW())");
        $stmt->execute([$email, $hashedPassword, $name]);
        echo "<h1>Admin-Benutzer erstellt</h1>";
    }
    
    echo "<p>E-Mail: {$email}</p>";
    echo "<p>Passwort: {$password}</p>";
    echo "<p><a href='login.php'>Zur Login-Seite</a></p>";
    
} catch (PDOException $e) {
    echo "<h1>Fehler</h1>";
    echo "<p>Datenbankfehler: " . $e->getMessage() . "</p>";
}
?> 