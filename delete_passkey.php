<?php
require_once 'db.php';
session_start();

// Prüfe ob Benutzer eingeloggt ist
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Nicht eingeloggt']);
    exit;
}

// Hole JSON-Daten aus dem Request
$json = file_get_contents('php://input');
$data = json_decode($json, true);

if (!isset($data['id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Keine Passkey-ID angegeben']);
    exit;
}

try {
    // Überprüfe, ob der Passkey dem Benutzer gehört
    $stmt = $pdo->prepare("SELECT id FROM passkeys WHERE id = ? AND user_id = ?");
    $stmt->execute([$data['id'], $_SESSION['user_id']]);
    
    if ($stmt->rowCount() === 0) {
        throw new Exception('Passkey nicht gefunden oder keine Berechtigung');
    }
    
    // Lösche den Passkey
    $stmt = $pdo->prepare("DELETE FROM passkeys WHERE id = ? AND user_id = ?");
    $stmt->execute([$data['id'], $_SESSION['user_id']]);
    
    if ($stmt->rowCount() === 0) {
        throw new Exception('Fehler beim Löschen des Passkeys');
    }
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => 'Passkey erfolgreich gelöscht'
    ]);
} catch (Exception $e) {
    error_log('Error deleting passkey: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Fehler beim Löschen des Passkeys: ' . $e->getMessage()
    ]);
} 