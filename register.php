<?php
// register.php

require 'db.php'; // Datenbankverbindung
require 'vendor/autoload.php'; // Falls du Composer verwendest

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['name'], $_POST['email'], $_POST['password'])) {
        die('Bitte alle Felder ausfüllen.');
    }

    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        die('Ungültige E-Mail-Adresse.');
    }

    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    $token = bin2hex(random_bytes(16));

    $stmt = $pdo->prepare("INSERT INTO users (name, email, password, token, verified) VALUES (?, ?, ?, ?, 0)");
    try {
        $stmt->execute([$name, $email, $hashedPassword, $token]);
    } catch (PDOException $e) {
        die("Fehler bei der Registrierung: " . $e->getMessage());
    }

    $verificationLink = "https://deinedomain.de/verify.php?token=" . $token;
    $subject = "Bitte verifiziere deine E-Mail-Adresse";
    $message = "Hallo $name,\n\nbitte klicke auf den folgenden Link, um deine E-Mail-Adresse zu verifizieren:\n$verificationLink\n\nVielen Dank!";

    // PHPMailer initialisieren
    $mail = new PHPMailer(true);
    
    try {
        $mail->isSMTP();
        $mail->Host = '';  // Dein SMTP-Server
        $mail->SMTPAuth = true;
        $mail->Username = ''; // Deine SMTP-Login-Daten
        $mail->Password = ''; // Dein SMTP-Passwort
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // Oder PHPMailer::ENCRYPTION_SMTPS für SSL
        $mail->Port = 587; // 465 für SSL, 587 für STARTTLS

        $mail->setFrom('info@anonfile.de', 'AnonFile');
        $mail->addAddress($email, $name);
        $mail->Subject = $subject;
        $mail->Body = $message;

        $mail->send();
        echo "Registrierung erfolgreich. Bitte überprüfe deinen Posteingang, um deine E-Mail-Adresse zu verifizieren.";
    } catch (Exception $e) {
        echo "Registrierung erfolgreich, aber die E-Mail konnte nicht gesendet werden. Fehler: {$mail->ErrorInfo}";
    }
} else {
    echo "Ungültige Anfrage.";
}
?>
