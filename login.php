<?php
session_start();
require_once 'db.php'; // Datenbankverbindung einbinden

// Bereits eingeloggte Benutzer werden weitergeleitet
if (isset($_SESSION['user_id']) && isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true) {
    header('Location: admin.php');
    exit();
} elseif (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit();
}

// Initialisierung von Variablen
$error = '';
$email = '';

// Verarbeite Login-Anfrage
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF-Schutz
    if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = 'Sicherheitsüberprüfung fehlgeschlagen. Bitte versuche es erneut.';
    } else {
        if (!isset($_POST['email'], $_POST['password'])) {
            $error = 'Bitte alle Felder ausfüllen.';
        } else {
            $email = trim(filter_var($_POST['email'], FILTER_SANITIZE_EMAIL));
            $password = $_POST['password'];
            
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Ungültige E-Mail-Adresse.';
            } else {
                try {
                    // Suche den Nutzer anhand der E-Mail
                    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
                    $stmt->execute([$email]);
                    $user = $stmt->fetch();
                    
                    if ($user && password_verify($password, $user['password'])) {
                        // Prüfe, ob der Benutzer verifiziert ist (falls erforderlich)
                        if (isset($user['verified']) && $user['verified'] != 1) {
                            $error = 'Bitte verifiziere deine E-Mail-Adresse zuerst.';
                        } else {
                            // Prüfe, ob der Benutzer aktiv ist
                            if (isset($user['status']) && $user['status'] !== 'active') {
                                $error = 'Dein Konto ist nicht aktiv. Bitte kontaktiere den Administrator.';
                            } else {
                                // Login erfolgreich – Sitzung starten
                                $_SESSION['user_id'] = $user['id'];
                                $_SESSION['user_email'] = $user['email'];
                                $_SESSION['user_name'] = $user['name'] ?? 'Benutzer';
                                
                                // Prüfe Administratorrechte (entweder über role oder is_admin)
                                if ((isset($user['role']) && $user['role'] === 'admin') || (isset($user['is_admin']) && $user['is_admin'] == 1)) {
                                    $_SESSION['is_admin'] = true;
                                    
                                    // Protokolliere den Admin-Login
                                    $ip = $_SERVER['REMOTE_ADDR'];
                                    $userAgent = $_SERVER['HTTP_USER_AGENT'];
                                    $stmt = $pdo->prepare("INSERT INTO admin_login_logs (user_id, ip_address, user_agent, login_time) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE login_time = NOW(), login_count = login_count + 1");
                                    $stmt->execute([$user['id'], $ip, $userAgent]);
                                    
                                    header('Location: admin.php');
                                    exit();
                                } else {
                                    $_SESSION['is_admin'] = false;
                                    header('Location: dashboard.php');
                                    exit();
                                }
                            }
                        }
                    } else {
                        $error = 'Falsche E-Mail oder Passwort.';
                        // Verzögerung gegen Brute-Force
                        sleep(1);
                    }
                } catch (PDOException $e) {
                    $error = 'Datenbankfehler: ' . $e->getMessage();
                }
            }
        }
    }
}

// Generiere CSRF-Token
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Status Page Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
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
            padding: 30px;
            background-color: #fff;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        
        .login-logo {
            text-align: center;
            margin-bottom: 25px;
        }
        
        .login-logo i {
            font-size: 3em;
            color: #0d6efd;
        }
        
        h1 {
            text-align: center;
            margin-bottom: 25px;
            color: #333;
            font-size: 1.8em;
        }
        
        .form-floating {
            margin-bottom: 15px;
        }
        
        .btn-login {
            width: 100%;
            padding: 12px;
            margin-top: 10px;
        }
        
        .login-footer {
            text-align: center;
            margin-top: 20px;
            font-size: 0.9em;
            color: #6c757d;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-logo">
            <i class="bi bi-shield-lock"></i>
        </div>
        
        <h1>Status Page Admin</h1>
        
        <?php if($error): ?>
            <div class="alert alert-danger" role="alert">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            
            <div class="form-floating mb-3">
                <input type="email" class="form-control" id="email" name="email" placeholder="name@example.com" value="<?php echo htmlspecialchars($email); ?>" required>
                <label for="email">E-Mail-Adresse</label>
            </div>
            
            <div class="form-floating mb-3">
                <input type="password" class="form-control" id="password" name="password" placeholder="Passwort" required>
                <label for="password">Passwort</label>
            </div>
            
            <button type="submit" class="btn btn-primary btn-login">Anmelden</button>
        </form>
        
        <div class="login-footer">
            <p>Status Page System &copy; <?php echo date('Y'); ?></p>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
