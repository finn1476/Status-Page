<?php
require_once 'db.php';
session_start();

// Prüfe ob Benutzer eingeloggt ist
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Nicht eingeloggt']);
    exit;
}

try {
    // Hole alle Passkeys des Benutzers
    $stmt = $pdo->prepare("
        SELECT 
            credential_id,
            device_name,
            created_at,
            last_used_at
        FROM passkeys 
        WHERE user_id = ? 
        ORDER BY created_at DESC
    ");
    
    $stmt->execute([$_SESSION['user_id']]);
    $passkeys = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Formatiere die Daten für die Ausgabe
    $formattedPasskeys = array_map(function($passkey) {
        return [
            'credential_id' => $passkey['credential_id'],
            'name' => $passkey['device_name'] ?? 'Unnamed Passkey',
            'created_at' => $passkey['created_at'],
            'last_used_at' => $passkey['last_used_at'] ?? $passkey['created_at']
        ];
    }, $passkeys);
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'passkeys' => $formattedPasskeys
    ]);
} catch (PDOException $e) {
    error_log('Database error in get_passkeys: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Datenbankfehler: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log('Error in get_passkeys: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Fehler beim Abrufen der Passkeys: ' . $e->getMessage()
    ]);
} 