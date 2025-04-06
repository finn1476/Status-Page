<?php
session_start();
require 'db.php';

// Überprüfen, ob der Benutzer eingeloggt ist
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Initialize CSRF token if it doesn't exist
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ID des zu bearbeitenden Sensors
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Funktion zum Bereinigen von Eingaben
function clean_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

// CSRF-Token-Überprüfung
function check_csrf_token() {
    if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token'])) {
        return false;
    }
    
    if ($_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        return false;
    }
    
    return true;
}

try {
    // Sensor-Daten abrufen und prüfen, ob sie dem eingeloggten Benutzer gehören
    $stmt = $pdo->prepare("
        SELECT * FROM config
        WHERE id = ? AND user_id = ?
    ");
    $stmt->execute([$id, $_SESSION['user_id']]);
    $sensor = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$sensor) {
        // Sensor nicht gefunden oder gehört nicht dem eingeloggten Benutzer
        header('Location: dashboard2.php?error=' . urlencode('No permission or sensor not found'));
        exit;
    }

    // Formular wurde abgesendet
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // CSRF-Überprüfung
        if (!check_csrf_token()) {
            $error = "Security verification failed. Please try again.";
        } else {
            $name = clean_input($_POST['name'] ?? '');
            $url = clean_input($_POST['url'] ?? '');
            $sensor_type = clean_input($_POST['sensor_type'] ?? '');
            $sensor_config = clean_input($_POST['sensor_config'] ?? '');

            if ($name && $url && $sensor_type) {
                // Überprüfen des Limits für Sensoren
                $stmt = $pdo->prepare("
                    SELECT 
                        COUNT(c.id) as current_sensors,
                        ut.max_sensors
                    FROM config c
                    JOIN user_subscriptions us ON c.user_id = us.user_id
                    JOIN user_tiers ut ON us.tier_id = ut.id
                    WHERE c.user_id = ? AND us.status = 'active'
                    GROUP BY ut.max_sensors
                    ORDER BY us.end_date DESC
                    LIMIT 1
                ");
                $stmt->execute([$_SESSION['user_id']]);
                $limitInfo = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Fallback für kostenlose Nutzer
                if (!$limitInfo) {
                    $freeLimit = $pdo->prepare("
                        SELECT 
                            COUNT(c.id) as current_sensors,
                            (SELECT max_sensors FROM user_tiers WHERE name = 'Free' LIMIT 1) as max_sensors
                        FROM config c
                        WHERE c.user_id = ?
                    ");
                    $freeLimit->execute([$_SESSION['user_id']]);
                    $limitInfo = $freeLimit->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$limitInfo) {
                        $limitInfo = [
                            'current_sensors' => 0,
                            'max_sensors' => 5 // Default für Free Tier
                        ];
                    }
                }

                // Sensor aktualisieren (zählt nicht als neuer Sensor)
                $stmt = $pdo->prepare("
                    UPDATE config 
                    SET name = ?, url = ?, sensor_type = ?, sensor_config = ? 
                    WHERE id = ? AND user_id = ?
                ");
                $stmt->execute([$name, $url, $sensor_type, $sensor_config, $id, $_SESSION['user_id']]);

                if ($stmt->rowCount() > 0) {
                    // Erfolgreich aktualisiert
                    header('Location: dashboard2.php?message=' . urlencode('Sensor updated successfully.'));
                    exit;
                } else {
                    $error = "Failed to update sensor or no changes were made.";
                }
            } else {
                $error = "Name, URL and Sensor Type are required.";
            }
        }
    }

} catch (PDOException $e) {
    die('Database error: ' . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Sensor</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f5f5f5;
            padding: 20px;
        }
        .container {
            max-width: 800px;
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        h1 {
            margin-bottom: 30px;
            color: #0d6efd;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .btn-container {
            margin-top: 30px;
            display: flex;
            justify-content: space-between;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Edit Sensor</h1>
        
        <?php if (isset($error)): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <div class="form-group">
                <label for="name">Name:</label>
                <input type="text" name="name" id="name" class="form-control" value="<?php echo htmlspecialchars($sensor['name']); ?>" required>
            </div>
            
            <div class="form-group">
                <label for="url">URL:</label>
                <input type="url" name="url" id="url" class="form-control" value="<?php echo htmlspecialchars($sensor['url']); ?>" required>
            </div>
            
            <div class="form-group">
                <label for="sensor_type">Sensor Type:</label>
                <select name="sensor_type" id="sensor_type" class="form-control" required>
                    <option value="http" <?php echo ($sensor['sensor_type'] == 'http') ? 'selected' : ''; ?>>HTTP</option>
                    <option value="ping" <?php echo ($sensor['sensor_type'] == 'ping') ? 'selected' : ''; ?>>Ping</option>
                    <option value="dns" <?php echo ($sensor['sensor_type'] == 'dns') ? 'selected' : ''; ?>>DNS</option>
                    <option value="ssl" <?php echo ($sensor['sensor_type'] == 'ssl') ? 'selected' : ''; ?>>SSL Certificate</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="sensor_config">Additional Configuration:</label>
                <textarea name="sensor_config" id="sensor_config" class="form-control" rows="3"><?php echo htmlspecialchars($sensor['sensor_config']); ?></textarea>
                <small class="form-text text-muted">Optional: JSON configuration for specific sensor settings.</small>
            </div>
            
            <div class="btn-container">
                <a href="dashboard2.php" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary">Update Sensor</button>
            </div>
        </form>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 