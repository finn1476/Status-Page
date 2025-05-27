<?php
require_once 'db.php';
session_start();

// Aktiviere Fehlerberichterstattung für Debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Hole JSON-Daten aus dem Request
$json = file_get_contents('php://input');
$data = json_decode($json, true);

// Debug-Logging
error_log('Received auth data: ' . print_r($data, true));

// Wenn keine Credential-Daten vorhanden sind, generiere Challenge
if (!isset($data['credential'])) {
    try {
        // Generiere zufällige Challenge
        $challenge = random_bytes(32);
        
        // Speichere Challenge in der Session
        $_SESSION['passkey_challenge'] = base64_encode($challenge);
        
        // Hole alle verfügbaren Passkeys für die Authentifizierung
        $stmt = $pdo->prepare("
            SELECT 
                p.credential_id,
                p.public_key,
                p.counter,
                u.email
            FROM passkeys p
            JOIN users u ON p.user_id = u.id
        ");
        $stmt->execute();
        $passkeys = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Formatiere die Passkeys für die Authentifizierung
        $allowCredentials = array_map(function($passkey) {
            return [
                'type' => 'public-key',
                'id' => $passkey['credential_id'],
                'transports' => ['internal']
            ];
        }, $passkeys);
        
        // Erstelle PublicKeyCredentialRequestOptions
        $options = [
            'challenge' => base64_encode($challenge),
            'rpId' => $_SERVER['HTTP_HOST'],
            'allowCredentials' => $allowCredentials,
            'userVerification' => 'preferred',
            'timeout' => 60000
        ];
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'options' => $options
        ]);
    } catch (Exception $e) {
        error_log('Error generating auth challenge: ' . $e->getMessage());
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Fehler beim Generieren der Challenge: ' . $e->getMessage()
        ]);
    }
    exit;
}

// Verarbeite Authentifizierungsdaten
try {
    $credential = $data['credential'];
    
    // Validiere Credential-Daten
    if (!isset($credential['id']) || !isset($credential['rawId']) || !isset($credential['response'])) {
        throw new Exception('Ungültige Credential-Daten: Fehlende erforderliche Felder');
    }
    
    // Debug-Logging für Credential-Daten
    error_log('Processing auth credential data: ' . print_r($credential, true));
    
    // Finde den zugehörigen Passkey
    $stmt = $pdo->prepare("
        SELECT 
            p.*,
            u.email
        FROM passkeys p
        JOIN users u ON p.user_id = u.id
        WHERE p.credential_id = ?
    ");
    $stmt->execute([$credential['id']]);
    $passkey = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$passkey) {
        throw new Exception('Passkey nicht gefunden');
    }
    
    // Aktualisiere den Counter und last_used_at
    $stmt = $pdo->prepare("
        UPDATE passkeys 
        SET counter = counter + 1,
            last_used_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$passkey['id']]);
    
    // Setze Session-Daten
    $_SESSION['user_id'] = $passkey['user_id'];
    $_SESSION['email'] = $passkey['email'];
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => 'Erfolgreich eingeloggt',
        'user' => [
            'email' => $passkey['email']
        ]
    ]);
} catch (PDOException $e) {
    error_log('Database error during passkey authentication: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Datenbankfehler: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log('Error during passkey authentication: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Fehler bei der Passkey-Authentifizierung: ' . $e->getMessage()
    ]);
} 