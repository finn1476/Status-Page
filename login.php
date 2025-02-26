<?php
session_start();
require 'db.php'; // Datenbankverbindung einbinden

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['email'], $_POST['password'])) {
        die('Bitte alle Felder ausfüllen.');
    }
    
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        die('Ungültige E-Mail-Adresse.');
    }
    
    // Suche den Nutzer anhand der E-Mail
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    if ($user && password_verify($password, $user['password'])) {
        if (!$user['verified']) {
            die('Bitte verifiziere deine E-Mail-Adresse zuerst.');
        }
        // Login erfolgreich – Sitzung starten
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['name'];
        header('Location: dashboard2.php');
        exit();
    } else {
        echo 'Falsche E-Mail oder Passwort.';
    }
} else {
    echo "Ungültige Anfrage.";
}
?>
