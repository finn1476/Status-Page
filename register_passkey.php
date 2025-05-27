<?php
require_once 'db.php';
session_start();

// Aktiviere Fehlerberichterstattung für Debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Prüfe ob Benutzer eingeloggt ist
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Nicht eingeloggt']);
    exit;
}

// Stelle sicher, dass die passkeys Tabelle existiert und die richtige Struktur hat
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS passkeys (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            credential_id VARCHAR(255) NOT NULL,
            public_key TEXT NOT NULL,
            counter INT NOT NULL DEFAULT 0,
            device_name VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_used_at TIMESTAMP NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ");
} catch (PDOException $e) {
    error_log('Error creating passkeys table: ' . $e->getMessage());
}

// Hole JSON-Daten aus dem Request
$json = file_get_contents('php://input');
$data = json_decode($json, true);

// Debug-Logging
error_log('Received data: ' . print_r($data, true));

// Wenn keine Credential-Daten vorhanden sind, generiere Challenge
if (!isset($data['credential'])) {
    try {
        // Generiere zufällige Challenge
        $challenge = random_bytes(32);
        
        // Speichere Challenge in der Session
        $_SESSION['passkey_challenge'] = base64_encode($challenge);
        
        // Erstelle PublicKeyCredentialCreationOptions
        $options = [
            'challenge' => base64_encode($challenge),
            'rp' => [
                'name' => 'Status Page System',
                'id' => $_SERVER['HTTP_HOST']
            ],
            'user' => [
                'id' => base64_encode(random_bytes(16)),
                'name' => $_SESSION['email'],
                'displayName' => $_SESSION['email']
            ],
            'pubKeyCredParams' => [
                ['type' => 'public-key', 'alg' => -7], // ES256
                ['type' => 'public-key', 'alg' => -257] // RS256
            ],
            'timeout' => 60000,
            'attestation' => 'none',
            'authenticatorSelection' => [
                'authenticatorAttachment' => 'platform',
                'userVerification' => 'preferred',
                'requireResidentKey' => false
            ],
            'excludeCredentials' => []
        ];
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'options' => $options
        ]);
    } catch (Exception $e) {
        error_log('Error generating passkey challenge: ' . $e->getMessage());
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Fehler beim Generieren der Challenge: ' . $e->getMessage()
        ]);
    }
    exit;
}

// Verarbeite Credential-Daten
try {
    $credential = $data['credential'];
    
    // Validiere Credential-Daten
    if (!isset($credential['id']) || !isset($credential['rawId']) || !isset($credential['response'])) {
        throw new Exception('Ungültige Credential-Daten: Fehlende erforderliche Felder');
    }
    
    // Debug-Logging für Credential-Daten
    error_log('Processing credential data: ' . print_r($credential, true));
    
    // Überprüfe, ob der Credential bereits existiert
    $stmt = $pdo->prepare("SELECT id FROM passkeys WHERE credential_id = ?");
    $stmt->execute([$credential['id']]);
    if ($stmt->rowCount() > 0) {
        throw new Exception('Dieser Passkey existiert bereits');
    }
    
    // Speichere Passkey in der Datenbank
    $stmt = $pdo->prepare("
        INSERT INTO passkeys (
            user_id, 
            credential_id, 
            public_key, 
            counter, 
            device_name,
            created_at,
            last_used_at
        ) VALUES (?, ?, ?, 0, ?, NOW(), NOW())
    ");
    
    $publicKey = json_encode($credential);
    error_log('Storing public key: ' . $publicKey);
    
    $stmt->execute([
        $_SESSION['user_id'],
        $credential['id'],
        $publicKey,
        'Neuer Passkey'
    ]);
    
    if ($stmt->rowCount() === 0) {
        throw new Exception('Fehler beim Speichern des Passkeys in der Datenbank');
    }
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => 'Passkey erfolgreich registriert'
    ]);
} catch (PDOException $e) {
    error_log('Database error during passkey registration: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Datenbankfehler: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log('Error registering passkey: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Fehler beim Registrieren des Passkeys: ' . $e->getMessage()
    ]);
} 