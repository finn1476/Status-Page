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

// ID der zu bearbeitenden Status Page
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
    // Status Page-Daten abrufen und prüfen, ob sie dem eingeloggten Benutzer gehören
    $stmt = $pdo->prepare("
        SELECT * FROM status_pages
        WHERE id = ? AND user_id = ?
    ");
    $stmt->execute([$id, $_SESSION['user_id']]);
    $status_page = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$status_page) {
        // Status Page nicht gefunden oder gehört nicht dem eingeloggten Benutzer
        header('Location: dashboard2.php?error=' . urlencode('No permission or status page not found'));
        exit;
    }

    // Alle Sensoren für das Dropdown-Menü abrufen
    $stmt = $pdo->prepare("SELECT id, name FROM config WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $sensors = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Aktuell ausgewählte Sensoren
    $selected_sensors = [];
    if (!empty($status_page['sensor_ids'])) {
        $selected_sensors = json_decode($status_page['sensor_ids'], true);
    }

    // Formular wurde abgesendet
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // CSRF-Überprüfung
        if (!check_csrf_token()) {
            $error = "Security verification failed. Please try again.";
        } else {
            $page_title = clean_input($_POST['page_title'] ?? '');
            $custom_css = $_POST['custom_css'] ?? ''; // CSS wird nicht gefiltert
            $sensor_ids = isset($_POST['sensor_ids']) ? $_POST['sensor_ids'] : [];
            
            // Validieren der sensor_ids - prüfen, ob alle angegebenen Sensoren dem Benutzer gehören
            if (!empty($sensor_ids)) {
                $sensorCheck = $pdo->prepare("
                    SELECT COUNT(id) as sensor_count 
                    FROM config 
                    WHERE id IN (" . implode(',', array_fill(0, count($sensor_ids), '?')) . ") 
                    AND user_id = ?
                ");
                
                $params = array_merge($sensor_ids, [$_SESSION['user_id']]);
                $sensorCheck->execute($params);
                $result = $sensorCheck->fetch(PDO::FETCH_ASSOC);
                
                if ($result['sensor_count'] !== count($sensor_ids)) {
                    $error = "One or more selected sensors are not available or don't belong to you.";
                }
            }
            
            if (!isset($error) && $page_title) {
                // Überprüfen des Limits für Status Pages
                $stmt = $pdo->prepare("
                    SELECT 
                        COUNT(sp.id) as current_pages,
                        ut.max_status_pages
                    FROM status_pages sp
                    JOIN user_subscriptions us ON sp.user_id = us.user_id
                    JOIN user_tiers ut ON us.tier_id = ut.id
                    WHERE sp.user_id = ? AND us.status = 'active'
                    GROUP BY ut.max_status_pages
                    ORDER BY us.end_date DESC
                    LIMIT 1
                ");
                $stmt->execute([$_SESSION['user_id']]);
                $limitInfo = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Fallback für kostenlose Nutzer
                if (!$limitInfo) {
                    $freeLimit = $pdo->prepare("
                        SELECT 
                            COUNT(sp.id) as current_pages,
                            (SELECT max_status_pages FROM user_tiers WHERE name = 'Free' LIMIT 1) as max_status_pages
                        FROM status_pages sp
                        WHERE sp.user_id = ?
                    ");
                    $freeLimit->execute([$_SESSION['user_id']]);
                    $limitInfo = $freeLimit->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$limitInfo) {
                        $limitInfo = [
                            'current_pages' => 0,
                            'max_status_pages' => 1 // Default für Free Tier
                        ];
                    }
                }
                
                // JSON-encode der sensor_ids
                $sensor_ids_json = json_encode($sensor_ids);
                
                // Status Page aktualisieren (zählt nicht als neue Status Page)
                $stmt = $pdo->prepare("
                    UPDATE status_pages 
                    SET page_title = ?, custom_css = ?, sensor_ids = ? 
                    WHERE id = ? AND user_id = ?
                ");
                $stmt->execute([$page_title, $custom_css, $sensor_ids_json, $id, $_SESSION['user_id']]);

                if ($stmt->rowCount() > 0) {
                    // Zurück zum Dashboard
                    header('Location: dashboard2.php?message=' . urlencode('Status Page updated successfully.'));
                    exit;
                } else {
                    $error = "Failed to update status page or no changes were made.";
                }
            } else if (!isset($error)) {
                $error = "Page title is required.";
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
    <title>Edit Status Page</title>
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
        .form-check {
            margin-bottom: 10px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Edit Status Page</h1>
        
        <?php if (isset($error)): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <div class="form-group">
                <label for="page_title">Page Title:</label>
                <input type="text" name="page_title" id="page_title" class="form-control" value="<?php echo htmlspecialchars($status_page['page_title']); ?>" required>
            </div>
            
            <div class="form-group">
                <label>Select Sensors:</label>
                <div class="card p-3">
                    <?php foreach ($sensors as $sensor): ?>
                        <div class="form-check">
                            <input 
                                type="checkbox" 
                                name="sensor_ids[]" 
                                value="<?php echo $sensor['id']; ?>" 
                                id="sensor_<?php echo $sensor['id']; ?>"
                                <?php echo (in_array($sensor['id'], $selected_sensors)) ? 'checked' : ''; ?>
                                class="form-check-input"
                            >
                            <label for="sensor_<?php echo $sensor['id']; ?>" class="form-check-label">
                                <?php echo htmlspecialchars($sensor['name']); ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <div class="form-group">
                <label for="custom_css">Custom CSS:</label>
                <textarea name="custom_css" id="custom_css" class="form-control" rows="10"><?php echo $status_page['custom_css']; ?></textarea>
                <small class="form-text text-muted">Optional: Add custom CSS to style your status page.</small>
            </div>
            
            <div class="form-group">
                <label>Status Page URL:</label>
                <div class="input-group">
                    <input type="text" class="form-control" value="<?php echo $_SERVER['HTTP_HOST']; ?>/status_page.php?status_page_uuid=<?php echo $status_page['uuid']; ?>" readonly>
                    <button class="btn btn-outline-secondary" type="button" onclick="copyToClipboard(this)">Copy</button>
                </div>
                <small class="form-text text-muted">Share this URL to allow others to view your status page.</small>
            </div>
            
            <div class="btn-container">
                <a href="dashboard2.php" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary">Update Status Page</button>
            </div>
        </form>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function copyToClipboard(button) {
            const input = button.previousElementSibling;
            input.select();
            document.execCommand('copy');
            button.textContent = 'Copied!';
            setTimeout(() => {
                button.textContent = 'Copy';
            }, 2000);
        }
    </script>
</body>
</html> 