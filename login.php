<?php
// Aktiviere Fehlerberichterstattung
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'db.php';
session_start();

// CSRF-Schutz
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Wenn bereits eingeloggt, zur Dashboard-Seite weiterleiten
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard2.php');
    exit;
}

$error = '';

// Verarbeite Login-Anfrage
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            throw new Exception('CSRF-Token ungültig');
        }

        // Prüfe ob es sich um einen Passkey-Login handelt
        if (isset($_POST['credential'])) {
            // Passkey-Authentifizierung
            $credential = json_decode($_POST['credential'], true);
            if (!$credential) {
                throw new Exception('Ungültiges Credential-Format');
            }
            
            // Finde den Benutzer anhand der Credential-ID
            $stmt = $pdo->prepare("SELECT user_id FROM passkeys WHERE credential_id = ?");
            $stmt->execute([$credential['id']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user) {
                // Hole Benutzerinformationen
                $stmt = $pdo->prepare("SELECT id, email, role, status FROM users WHERE id = ?");
                $stmt->execute([$user['user_id']]);
                $userData = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($userData && $userData['status'] === 'active') {
                    // Setze Session
                    $_SESSION['user_id'] = $userData['id'];
                    $_SESSION['email'] = $userData['email'];
                    $_SESSION['role'] = $userData['role'];
                    
                    // Aktualisiere Counter und last_used_at
                    $stmt = $pdo->prepare("UPDATE passkeys SET counter = counter + 1, last_used_at = CURRENT_TIMESTAMP WHERE credential_id = ?");
                    $stmt->execute([$credential['id']]);
                    
                    // Logge den Login
                    try {
                        $stmt = $pdo->prepare("
                            INSERT INTO admin_login_logs (user_id, ip_address, user_agent, login_time) 
                            VALUES (?, ?, ?, NOW())
                            ON DUPLICATE KEY UPDATE 
                            login_time = NOW(),
                            user_agent = VALUES(user_agent)
                        ");
                        $stmt->execute([$userData['id'], $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']]);
                    } catch (PDOException $e) {
                        error_log('Login Logging Error: ' . $e->getMessage());
                    }
                    
                    header('Location: dashboard2.php');
                    exit;
                }
            }
            
            // Wenn Passkey-Authentifizierung fehlschlägt
            $error = 'Ungültige Passkey-Authentifizierung';
        } else {
            // Traditioneller E-Mail/Passwort-Login
            $email = $_POST['email'] ?? '';
            $password = $_POST['password'] ?? '';
            
            if (empty($email) || empty($password)) {
                throw new Exception('Bitte E-Mail und Passwort eingeben');
            }

            $stmt = $pdo->prepare("SELECT id, email, password, role, status FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user && password_verify($password, $user['password'])) {
                if ($user['status'] === 'active') {
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['role'] = $user['role'];
                    
                    // Logge den Login
                    try {
                        $stmt = $pdo->prepare("
                            INSERT INTO admin_login_logs (user_id, ip_address, user_agent, login_time) 
                            VALUES (?, ?, ?, NOW())
                            ON DUPLICATE KEY UPDATE 
                            login_time = NOW(),
                            user_agent = VALUES(user_agent)
                        ");
                        $stmt->execute([$user['id'], $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']]);
                    } catch (PDOException $e) {
                        error_log('Login Logging Error: ' . $e->getMessage());
                    }
                    
                    header('Location: dashboard2.php');
                    exit;
                } else {
                    throw new Exception('Ihr Account ist nicht aktiv');
                }
            } else {
                throw new Exception('Ungültige E-Mail oder Passwort');
            }
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Status Page System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <style>
        body {
            background-color: #f8f9fa;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-container {
            max-width: 400px;
            width: 100%;
            padding: 20px;
        }
        .card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
        }
        .card-header {
            background: none;
            border-bottom: none;
            text-align: center;
            padding-top: 20px;
        }
        .btn-primary {
            width: 100%;
            padding: 10px;
        }
        .divider {
            display: flex;
            align-items: center;
            text-align: center;
            margin: 20px 0;
        }
        .divider::before,
        .divider::after {
            content: '';
            flex: 1;
            border-bottom: 1px solid #dee2e6;
        }
        .divider span {
            padding: 0 10px;
            color: #6c757d;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="card">
            <div class="card-header">
                <h4 class="mb-0">Login</h4>
            </div>
            <div class="card-body">
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                
                <form method="POST" id="loginForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    
                    <div class="mb-3">
                        <label for="email" class="form-label">E-Mail</label>
                        <input type="email" class="form-control" id="email" name="email" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="password" class="form-label">Passwort</label>
                        <input type="password" class="form-control" id="password" name="password" required>
                    </div>
                    
                    <button type="submit" class="btn btn-primary mb-3">Anmelden</button>
                </form>
                
                <div class="divider">
                    <span>oder</span>
                </div>
                
                <button type="button" class="btn btn-outline-primary" id="passkeyButton">
                    <i class="bi bi-key"></i> Mit Passkey anmelden
                </button>
                
                <div class="text-center mt-3">
                    <a href="register.php" class="text-decoration-none">Noch kein Account? Registrieren</a>
                </div>
            </div>
        </div>
    </div>

    <script>
    document.getElementById('passkeyButton').addEventListener('click', async function() {
        try {
            // Hole Challenge vom Server
            const response = await fetch('passkey_auth.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                }
            });
            
            const data = await response.json();
            if (!data.success) {
                throw new Error(data.error);
            }

            // Erstelle Credential
            const credential = await navigator.credentials.get({
                publicKey: data.options
            });

            // Sende Credential an Server
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';

            const csrfToken = document.createElement('input');
            csrfToken.type = 'hidden';
            csrfToken.name = 'csrf_token';
            csrfToken.value = '<?php echo $_SESSION['csrf_token']; ?>';
            form.appendChild(csrfToken);

            const credentialInput = document.createElement('input');
            credentialInput.type = 'hidden';
            credentialInput.name = 'credential';
            credentialInput.value = JSON.stringify({
                id: credential.id,
                rawId: Array.from(new Uint8Array(credential.rawId)),
                response: {
                    clientDataJSON: Array.from(new Uint8Array(credential.response.clientDataJSON)),
                    authenticatorData: Array.from(new Uint8Array(credential.response.authenticatorData)),
                    signature: Array.from(new Uint8Array(credential.response.signature)),
                    userHandle: credential.response.userHandle ? Array.from(new Uint8Array(credential.response.userHandle)) : null
                },
                type: credential.type
            });
            form.appendChild(credentialInput);

            document.body.appendChild(form);
            form.submit();
        } catch (error) {
            alert('Passkey-Authentifizierung fehlgeschlagen: ' + error.message);
        }
    });
    </script>
</body>
</html>
