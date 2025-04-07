<?php
session_start();
require 'db.php';

// Überprüfen, ob der Benutzer eingeloggt ist
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Stellt sicher, dass ein CSRF-Token existiert
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Funktion zum Überprüfen des CSRF-Tokens
function check_csrf_token() {
    if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token'])) {
        return false;
    }
    
    if ($_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        return false;
    }
    
    return true;
}

// ID des zu bearbeitenden Incidents
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Funktion zum Bereinigen von Eingaben
function clean_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

try {
    // Incident-Daten abrufen
    $stmt = $pdo->prepare("
        SELECT i.*, c.name as service_name 
        FROM incidents i
        LEFT JOIN config c ON i.service_id = c.id
        WHERE i.id = ? AND i.user_id = ?
    ");
    $stmt->execute([$id, $_SESSION['user_id']]);
    $incident = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$incident) {
        // Incident nicht gefunden oder gehört nicht dem eingeloggten Benutzer
        header('Location: dashboard2.php');
        exit;
    }

    // Alle Services für das Dropdown-Menü abrufen
    $stmt = $pdo->prepare("SELECT id, name FROM config WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Hole alle Updates für diesen Incident
    $stmtUpdates = $pdo->prepare("
        SELECT iu.*, u.name as username 
        FROM incident_updates iu
        JOIN users u ON iu.created_by = u.id
        WHERE iu.incident_id = ?
        ORDER BY iu.update_time DESC
    ");
    $stmtUpdates->execute([$incident['id']]);
    $updates = $stmtUpdates->fetchAll(PDO::FETCH_ASSOC);

    // Formular wurde abgesendet
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $description = clean_input($_POST['description'] ?? '');
        $date = clean_input($_POST['date'] ?? '');
        $time = clean_input($_POST['time'] ?? '');
        $status = clean_input($_POST['status'] ?? '');
        $service_id = (int)($_POST['service_id'] ?? 0);

        if ($description && $date && $time && $status && $service_id) {
            // Datum und Uhrzeit zusammenführen
            $datetime = $date . ' ' . $time;

            // Incident aktualisieren
            $stmt = $pdo->prepare("
                UPDATE incidents 
                SET description = ?, date = ?, status = ?, service_id = ? 
                WHERE id = ? AND user_id = ?
            ");
            $stmt->execute([$description, $datetime, $status, $service_id, $id, $_SESSION['user_id']]);

            // Debug-Ausgabe für Änderungen
            error_log("Checking for changes:");
            error_log("Description changed: " . ($description !== $incident['description'] ? 'yes' : 'no'));
            error_log("Date changed: " . ($datetime !== $incident['date'] ? 'yes' : 'no'));
            error_log("Status changed: " . ($status !== $incident['status'] ? 'yes' : 'no'));
            error_log("Service changed: " . ($service_id !== $incident['service_id'] ? 'yes' : 'no'));

            // Sende E-Mail-Benachrichtigungen bei Änderungen
            if ($description !== $incident['description'] || 
                $datetime !== $incident['date'] || 
                $status !== $incident['status'] || 
                $service_id !== $incident['service_id']) {
                
                // Finde alle Status Pages, die diesen Service enthalten
                $stmt = $pdo->prepare("
                    SELECT id 
                    FROM status_pages 
                    WHERE JSON_CONTAINS(sensor_ids, ?) OR service_id = ?
                ");
                $stmt->execute([json_encode($service_id), $service_id]);
                $status_pages = $stmt->fetchAll(PDO::FETCH_COLUMN);
                
                error_log("Found status pages: " . print_r($status_pages, true));
                
                if (!empty($status_pages)) {
                    // Lade die E-Mail-Benachrichtigungsklasse
                    require_once 'email_notifications.php';
                    $emailNotifier = new EmailNotifications($pdo);
                    
                    // Sende Benachrichtigungen für jede betroffene Status Page
                    foreach ($status_pages as $status_page_id) {
                        error_log("Sending notification for status page ID: " . $status_page_id);
                        $result = $emailNotifier->sendIncidentNotification($id, $status_page_id);
                        error_log("Notification result: " . ($result ? 'success' : 'failed'));
                    }
                    
                    $message = "Incident updated and notifications sent.";
                } else {
                    error_log("No status pages found for service ID: " . $service_id);
                    $message = "Incident updated.";
                }
            } else {
                error_log("No changes detected in incident");
                $message = "Incident updated.";
            }

            // Zurück zum Dashboard
            header('Location: dashboard2.php?message=' . urlencode($message));
            exit;
        } else {
            $error = "All fields are required.";
        }
    }

    // Verarbeite das Hinzufügen eines neuen Updates
    if (isset($_POST['add_update']) && check_csrf_token()) {
        // Validiere und säubere die Eingaben
        $update_message = clean_input($_POST['update_message'] ?? '');
        $update_status = clean_input($_POST['update_status'] ?? '');
        
        // Debug-Ausgabe
        error_log("Status Value: " . $update_status);
        
        if ($update_message && $update_status && isset($incident['id'])) {
            // Füge das Update hinzu
            try {
                // Füge das Update hinzu
                $stmtAddUpdate = $pdo->prepare("
                    INSERT INTO incident_updates 
                    (incident_id, message, status, created_by) 
                    VALUES (?, ?, ?, ?)
                ");
                
                if ($stmtAddUpdate->execute([$incident['id'], $update_message, $update_status, $_SESSION['user_id']])) {
                    // Update hinzugefügt - aktualisiere auch den Hauptstatus des Incidents
                    $pdo->prepare("
                        UPDATE incidents 
                        SET status = ?, 
                            updated_at = NOW(),
                            resolved_at = CASE WHEN ? = 'resolved' THEN NOW() ELSE resolved_at END
                        WHERE id = ?
                    ")->execute([$update_status, $update_status, $incident['id']]);
                    
                    $message = "Update erfolgreich hinzugefügt.";
                    
                    // Hole die aktualisierten Updates
                    $stmtUpdates->execute([$incident['id']]);
                    $updates = $stmtUpdates->fetchAll(PDO::FETCH_ASSOC);
                    
                    // Lade den aktuellen Incident neu
                    $stmt->execute([$id]);
                    $incident = $stmt->fetch(PDO::FETCH_ASSOC);
                } else {
                    $error = "Fehler beim Hinzufügen des Updates.";
                }
            } catch (PDOException $e) {
                $error = "Datenbankfehler: " . $e->getMessage();
                error_log("Database Error in edit_incident.php: " . $e->getMessage());
            }
        } else {
            $error = "Bitte alle erforderlichen Felder ausfüllen.";
        }
    }

} catch (PDOException $e) {
    die('Database error: ' . $e->getMessage());
}

// Datum und Uhrzeit für die Formulareingabe aufteilen
$dateTime = explode(' ', $incident['date']);
$date = $dateTime[0];
$time = $dateTime[1];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Incident</title>
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
        <h1>Edit Incident</h1>
        
        <?php if (isset($error)): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="form-group">
                <label for="service_id">Service:</label>
                <select name="service_id" id="service_id" class="form-control" required>
                    <?php foreach ($services as $service): ?>
                        <option value="<?php echo $service['id']; ?>" <?php echo ($service['id'] == $incident['service_id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($service['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label for="description">Description:</label>
                <textarea name="description" id="description" class="form-control" rows="3" required><?php echo htmlspecialchars($incident['description']); ?></textarea>
            </div>
            
            <div class="row">
                <div class="col-md-6 form-group">
                    <label for="date">Date:</label>
                    <input type="date" name="date" id="date" class="form-control" value="<?php echo $date; ?>" required>
                </div>
                
                <div class="col-md-6 form-group">
                    <label for="time">Time:</label>
                    <input type="time" name="time" id="time" class="form-control" value="<?php echo $time; ?>" required>
                </div>
            </div>
            
            <div class="form-group">
                <label for="status">Status:</label>
                <select name="status" id="status" class="form-control" required>
                    <option value="reported" <?php echo ($incident['status'] == 'reported') ? 'selected' : ''; ?>>Reported</option>
                    <option value="in progress" <?php echo ($incident['status'] == 'in progress') ? 'selected' : ''; ?>>In Progress</option>
                    <option value="resolved" <?php echo ($incident['status'] == 'resolved') ? 'selected' : ''; ?>>Resolved</option>
                </select>
            </div>
            
            <div class="btn-container">
                <a href="dashboard2.php" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary">Update Incident</button>
            </div>
        </form>

        <!-- Hier die Anzeige für Updates im Formular einfügen -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="m-0">Incident Updates</h5>
            </div>
            <div class="card-body">
                <?php if (isset($updates) && count($updates) > 0): ?>
                    <div class="timeline mb-4">
                        <?php foreach ($updates as $update): ?>
                            <div class="timeline-item">
                                <div class="timeline-item-marker">
                                    <div class="timeline-item-marker-text"><?php echo date('d.m.y H:i', strtotime($update['update_time'])); ?></div>
                                    <div class="timeline-item-marker-indicator bg-<?php 
                                        echo match($update['status']) {
                                            'resolved' => 'success',
                                            'in progress' => 'primary',
                                            'investigating' => 'warning',
                                            'identified' => 'info',
                                            'monitoring' => 'secondary',
                                            default => 'danger'
                                        };
                                    ?>"></div>
                                </div>
                                <div class="timeline-item-content">
                                    <div class="fw-bold mb-2">
                                        Status: <span class="badge bg-<?php 
                                        echo match($update['status']) {
                                            'resolved' => 'success',
                                            'in progress' => 'primary',
                                            'investigating' => 'warning',
                                            'identified' => 'info',
                                            'monitoring' => 'secondary',
                                            default => 'danger'
                                        };
                                        ?>"><?php echo htmlspecialchars($update['status']); ?></span>
                                        <span class="ms-2 text-muted small">von <?php echo htmlspecialchars($update['username']); ?></span>
                                    </div>
                                    <p class="mb-0"><?php echo nl2br(htmlspecialchars($update['message'])); ?></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-muted">Keine Updates vorhanden.</p>
                <?php endif; ?>
                
                <hr class="my-4">
                
                <h6>Neues Update hinzufügen</h6>
                <form method="post" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    
                    <div class="mb-3">
                        <label for="update_status" class="form-label">Status</label>
                        <select name="update_status" id="update_status" class="form-select" required>
                            <option value="investigating">Untersuchung läuft</option>
                            <option value="identified">Ursache identifiziert</option>
                            <option value="in progress">Behebung läuft</option>
                            <option value="monitoring">Überwachung</option>
                            <option value="resolved">Behoben</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="update_message" class="form-label">Update-Nachricht</label>
                        <textarea name="update_message" id="update_message" class="form-control" rows="3" required></textarea>
                    </div>
                    
                    <button type="submit" name="add_update" class="btn btn-primary">Update hinzufügen</button>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 