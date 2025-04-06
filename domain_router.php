<?php
// domain_router.php - Router für benutzerdefinierte Domains
require_once 'db.php';

// Die aufgerufene Host-Domain ermitteln
$host = $_SERVER['HTTP_HOST'];

try {
    // Prüfe, ob es eine benutzerdefinierte Domain in der Datenbank gibt
    $stmt = $pdo->prepare("
        SELECT cd.*, sp.uuid as status_page_uuid, sp.page_title
        FROM custom_domains cd
        JOIN status_pages sp ON cd.status_page_id = sp.id
        WHERE cd.domain = ? AND cd.verified = 1
    ");
    $stmt->execute([$host]);
    $domain = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($domain) {
        // Domain gefunden, zur entsprechenden Statusseite weiterleiten
        // Aber ohne den Browser umzuleiten - das Skript direkt einbinden
        $_GET['status_page_uuid'] = $domain['status_page_uuid'];
        include 'status_page.php';
        exit;
    } else {
        // Keine benutzerdefinierte Domain gefunden, zur normalen Index-Seite
        include 'index.php';
        exit;
    }
} catch (PDOException $e) {
    // Bei einem Fehler zur normalen Index-Seite
    error_log("Domain Router Error: " . $e->getMessage());
    include 'index.php';
    exit;
} 