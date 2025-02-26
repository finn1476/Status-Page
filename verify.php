<?php
// verify.php

require 'db.php'; // Datenbankverbindung

if (isset($_GET['token'])) {
    $token = $_GET['token'];
    
    // Suche den Nutzer anhand des Tokens, der noch nicht verifiziert ist
    $stmt = $pdo->prepare("SELECT id FROM users WHERE token = ? AND verified = 0");
    $stmt->execute([$token]);
    $user = $stmt->fetch();
    
    if ($user) {
        // Markiere den Nutzer als verifiziert
        $updateStmt = $pdo->prepare("UPDATE users SET verified = 1 WHERE id = ?");
        $updateStmt->execute([$user['id']]);
        echo "Deine E-Mail-Adresse wurde erfolgreich verifiziert!";
    } else {
        echo "Ungültiger oder bereits verwendeter Verifizierungscode.";
    }
} else {
    echo "Kein Verifizierungstoken angegeben.";
}
?>
