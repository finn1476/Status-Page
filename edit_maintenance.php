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

// ID des zu bearbeitenden Maintenance-Eintrags
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
    // Maintenance-Daten abrufen und prüfen, ob sie dem eingeloggten Benutzer gehören
    $stmt = $pdo->prepare("
        SELECT mh.*, c.name as service_name 
        FROM maintenance_history mh
        LEFT JOIN config c ON mh.service_id = c.id
        WHERE mh.id = ? AND mh.user_id = ?
    ");
    $stmt->execute([$id, $_SESSION['user_id']]);
    $maintenance = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$maintenance) {
        // Maintenance nicht gefunden oder gehört nicht dem eingeloggten Benutzer
        header('Location: dashboard2.php');
        exit;
    }

    // Alle Services für das Dropdown-Menü abrufen
    $stmt = $pdo->prepare("SELECT id, name FROM config WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Formular wurde abgesendet
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // CSRF-Überprüfung
        if (!check_csrf_token()) {
            $error = "Security verification failed. Please try again.";
        } else {
            $description = clean_input($_POST['description'] ?? '');
            $start_date = clean_input($_POST['start_date'] ?? '');
            $start_time = clean_input($_POST['start_time'] ?? '');
            $end_date = clean_input($_POST['end_date'] ?? '');
            $end_time = clean_input($_POST['end_time'] ?? '');
            $status = clean_input($_POST['status'] ?? '');
            $service_id = (int)($_POST['service_id'] ?? 0);

            if ($description && $start_date && $start_time && $end_date && $end_time && $status && $service_id) {
                // Überprüfen, ob der Service dem Benutzer gehört
                $serviceCheck = $pdo->prepare("SELECT id FROM config WHERE id = ? AND user_id = ?");
                $serviceCheck->execute([$service_id, $_SESSION['user_id']]);
                
                if ($serviceCheck->rowCount() === 0) {
                    $error = "You don't have permission to use this service.";
                } else {
                    // Datum und Uhrzeit zusammenführen
                    $start_datetime = $start_date . ' ' . $start_time;
                    $end_datetime = $end_date . ' ' . $end_time;
                    
                    // Prüfen, ob das End-Datum nach dem Start-Datum liegt
                    if (strtotime($end_datetime) <= strtotime($start_datetime)) {
                        $error = "End date must be after start date.";
                    } else {
                        // Maintenance aktualisieren
                        $stmt = $pdo->prepare("
                            UPDATE maintenance_history 
                            SET description = ?, start_date = ?, end_date = ?, status = ?, service_id = ? 
                            WHERE id = ? AND user_id = ?
                        ");
                        $stmt->execute([
                            $description, 
                            $start_datetime, 
                            $end_datetime, 
                            $status, 
                            $service_id, 
                            $id, 
                            $_SESSION['user_id']
                        ]);
                        
                        if ($stmt->rowCount() > 0) {
                            // Zurück zum Dashboard
                            header('Location: dashboard2.php?message=' . urlencode('Maintenance updated successfully.'));
                            exit;
                        } else {
                            $error = "Failed to update maintenance or no changes were made.";
                        }
                    }
                }
            } else {
                $error = "All fields are required.";
            }
        }
    }

} catch (PDOException $e) {
    die('Database error: ' . $e->getMessage());
}

// Datum und Uhrzeit für die Formulareingabe aufteilen
$startDateTime = explode(' ', $maintenance['start_date']);
$start_date = $startDateTime[0];
$start_time = $startDateTime[1];

$endDateTime = explode(' ', $maintenance['end_date']);
$end_date = $endDateTime[0];
$end_time = $endDateTime[1];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Maintenance</title>
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
        <h1>Edit Maintenance</h1>
        
        <?php if (isset($error)): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <div class="form-group">
                <label for="service_id">Service:</label>
                <select name="service_id" id="service_id" class="form-control" required>
                    <?php foreach ($services as $service): ?>
                        <option value="<?php echo $service['id']; ?>" <?php echo ($service['id'] == $maintenance['service_id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($service['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label for="description">Description:</label>
                <textarea name="description" id="description" class="form-control" rows="3" required><?php echo htmlspecialchars($maintenance['description']); ?></textarea>
            </div>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label for="start_date">Start Date:</label>
                        <input type="date" name="start_date" id="start_date" class="form-control" value="<?php echo $start_date; ?>" required>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label for="start_time">Start Time:</label>
                        <input type="time" name="start_time" id="start_time" class="form-control" value="<?php echo $start_time; ?>" required>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label for="end_date">End Date:</label>
                        <input type="date" name="end_date" id="end_date" class="form-control" value="<?php echo $end_date; ?>" required>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label for="end_time">End Time:</label>
                        <input type="time" name="end_time" id="end_time" class="form-control" value="<?php echo $end_time; ?>" required>
                    </div>
                </div>
            </div>
            
            <div class="form-group">
                <label for="status">Status:</label>
                <select name="status" id="status" class="form-control" required>
                    <option value="scheduled" <?php echo ($maintenance['status'] == 'scheduled') ? 'selected' : ''; ?>>Scheduled</option>
                    <option value="cancelled" <?php echo ($maintenance['status'] == 'cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                    <option value="completed" <?php echo ($maintenance['status'] == 'completed') ? 'selected' : ''; ?>>Completed</option>
                </select>
            </div>
            
            <div class="btn-container">
                <a href="dashboard2.php" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary">Update Maintenance</button>
            </div>
        </form>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 