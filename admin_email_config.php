<?php
session_start();
require_once 'db.php';
require_once 'email_config.php';

// Überprüfe, ob der Benutzer ein Admin ist
if (!isset($_SESSION['user_id']) || !isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    // Wenn nicht eingeloggt oder kein Admin, zur Login-Seite umleiten
    header('Location: login.php');
    exit();
}

// Initialize CSRF token if it doesn't exist
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$emailConfig = new EmailConfig($pdo);
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $message_type = 'error';
        $message = 'Security verification failed. Please try again.';
    } else {
        $settings = [
            'smtp_host' => $_POST['smtp_host'],
            'smtp_port' => $_POST['smtp_port'],
            'smtp_username' => $_POST['smtp_username'],
            'smtp_password' => $_POST['smtp_password'],
            'smtp_encryption' => $_POST['smtp_encryption'],
            'smtp_from_email' => $_POST['smtp_from_email'],
            'smtp_from_name' => $_POST['smtp_from_name']
        ];

        if ($emailConfig->updateSettings($settings)) {
            $message_type = 'success';
            $message = 'Email settings updated successfully.';
        } else {
            $message_type = 'error';
            $message = 'Failed to update email settings. Please check the error logs for details.';
        }
    }
}

$current_settings = $emailConfig->getSettings();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Configuration - Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="bi bi-envelope"></i> Email Configuration</h2>
            <div>
                <a href="admin.php" class="btn btn-outline-primary btn-sm me-2">
                    <i class="bi bi-arrow-left"></i> Zurück
                </a>
                <a href="logout.php" class="btn btn-outline-danger btn-sm">
                    <i class="bi bi-box-arrow-right"></i> Logout
                </a>
            </div>
        </div>
        
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type === 'success' ? 'success' : 'danger'; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <div class="mb-3">
                        <label for="smtp_host" class="form-label">SMTP Host</label>
                        <input type="text" class="form-control" id="smtp_host" name="smtp_host" 
                               value="<?php echo htmlspecialchars($current_settings['smtp_host'] ?? ''); ?>" required>
                    </div>

                    <div class="mb-3">
                        <label for="smtp_port" class="form-label">SMTP Port</label>
                        <input type="number" class="form-control" id="smtp_port" name="smtp_port" 
                               value="<?php echo htmlspecialchars($current_settings['smtp_port'] ?? ''); ?>" required>
                    </div>

                    <div class="mb-3">
                        <label for="smtp_username" class="form-label">SMTP Username</label>
                        <input type="text" class="form-control" id="smtp_username" name="smtp_username" 
                               value="<?php echo htmlspecialchars($current_settings['smtp_username'] ?? ''); ?>" required>
                    </div>

                    <div class="mb-3">
                        <label for="smtp_password" class="form-label">SMTP Password</label>
                        <input type="password" class="form-control" id="smtp_password" name="smtp_password" 
                               value="<?php echo htmlspecialchars($current_settings['smtp_password'] ?? ''); ?>" required>
                    </div>

                    <div class="mb-3">
                        <label for="smtp_encryption" class="form-label">Encryption</label>
                        <select class="form-select" id="smtp_encryption" name="smtp_encryption">
                            <option value="tls" <?php echo ($current_settings['smtp_encryption'] ?? '') === 'tls' ? 'selected' : ''; ?>>TLS</option>
                            <option value="ssl" <?php echo ($current_settings['smtp_encryption'] ?? '') === 'ssl' ? 'selected' : ''; ?>>SSL</option>
                            <option value="" <?php echo ($current_settings['smtp_encryption'] ?? '') === '' ? 'selected' : ''; ?>>None</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="smtp_from_email" class="form-label">From Email</label>
                        <input type="email" class="form-control" id="smtp_from_email" name="smtp_from_email" 
                               value="<?php echo htmlspecialchars($current_settings['smtp_from_email'] ?? ''); ?>" required>
                    </div>

                    <div class="mb-3">
                        <label for="smtp_from_name" class="form-label">From Name</label>
                        <input type="text" class="form-control" id="smtp_from_name" name="smtp_from_name" 
                               value="<?php echo htmlspecialchars($current_settings['smtp_from_name'] ?? ''); ?>" required>
                    </div>

                    <button type="submit" class="btn btn-primary">Save Settings</button>
                </form>
            </div>
        </div>

        <div class="mt-4">
            <a href="admin.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Back to Admin Panel
            </a>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 