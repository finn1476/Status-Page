<?php
// admin.php

// Datenbankkonfiguration – bitte anpassen!
$dbHost = 'localhost';
$dbName = 'monitoring';
$dbUser = 'root';
$dbPass = '';

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die('Datenbankverbindung fehlgeschlagen: ' . $e->getMessage());
}

// Variable für Statusmeldungen
$message = '';

// Hilfsfunktion zum Säubern der Eingaben
function clean_input($data) {
    return trim(htmlspecialchars(strip_tags($data), ENT_QUOTES, 'UTF-8'));
}

// -----------------------------
// Service bearbeiten
// -----------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_service'])) {
    $id = $_POST['id'] ?? '';
    $name = clean_input($_POST['name'] ?? '');
    $url = clean_input($_POST['url'] ?? '');
    $sensor_type = clean_input($_POST['sensor_type'] ?? '');
    $sensor_config = clean_input($_POST['sensor_config'] ?? '');
    
    if ($id && $name && $url && $sensor_type) {
        $stmt = $pdo->prepare("UPDATE config SET name = ?, url = ?, sensor_type = ?, sensor_config = ? WHERE id = ?");
        $stmt->execute([$name, $url, $sensor_type, $sensor_config, $id]);
        $message = "Service erfolgreich bearbeitet!";
    } else {
        $message = "Bitte alle Felder ausfüllen.";
    }
}

// -----------------------------
// Service hinzufügen
// -----------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_service'])) {
    $name = clean_input($_POST['name'] ?? '');
    $url = clean_input($_POST['url'] ?? '');
    $sensor_type = clean_input($_POST['sensor_type'] ?? '');
    $sensor_config = clean_input($_POST['sensor_config'] ?? '');
    
    if ($name && $url && $sensor_type) {
        $stmt = $pdo->prepare("INSERT INTO config (name, url, sensor_type, sensor_config) VALUES (?, ?, ?, ?)");
        $stmt->execute([$name, $url, $sensor_type, $sensor_config]);
        $message = "Service erfolgreich hinzugefügt!";
    } else {
        $message = "Bitte alle Felder ausfüllen.";
    }
}

// -----------------------------
// Maintenance History hinzufügen
// -----------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_maintenance'])) {
    $description = clean_input($_POST['description'] ?? '');
    $date = clean_input($_POST['date'] ?? '');
    $time = clean_input($_POST['time'] ?? '');
    $service_id = clean_input($_POST['service_id'] ?? '');
    
    if ($description && $date && $time && $service_id) {
        $datetime = $date . ' ' . $time;
        $stmt = $pdo->prepare("INSERT INTO maintenance_history (description, date, status, service_id) VALUES (?, ?, ?, ?)");
        $stmt->execute([$description, $datetime, 'scheduled', $service_id]);
        $message = "Wartungseintrag erfolgreich hinzugefügt!";
    } else {
        $message = "Bitte alle Felder ausfüllen.";
    }
}

// -----------------------------
// Recent Incident hinzufügen
// -----------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_incident'])) {
    $incidentDescription = clean_input($_POST['incident_description'] ?? '');
    $incidentDate = clean_input($_POST['incident_date'] ?? '');
    $incidentTime = clean_input($_POST['incident_time'] ?? '');
    $service_id = clean_input($_POST['service_id'] ?? '');
    
    if ($incidentDescription && $incidentDate && $incidentTime && $service_id) {
        $datetime = $incidentDate . ' ' . $incidentTime;
        $stmt = $pdo->prepare("INSERT INTO incidents (description, date, status, service_id) VALUES (?, ?, ?, ?)");
        $stmt->execute([$incidentDescription, $datetime, 'unresolved', $service_id]);
        $message = "Vorfall erfolgreich hinzugefügt!";
    } else {
        $message = "Bitte alle Felder ausfüllen.";
    }
}

// -----------------------------
// Maintenance History bearbeiten
// -----------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_maintenance'])) {
    $id = $_POST['id'] ?? '';
    $description = clean_input($_POST['description'] ?? '');
    $date = clean_input($_POST['date'] ?? '');
    $time = clean_input($_POST['time'] ?? '');
    $status = clean_input($_POST['status'] ?? '');
    $service_id = clean_input($_POST['service_id'] ?? '');
    
    if ($id && $description && $date && $time && $service_id) {
        $datetime = $date . ' ' . $time;
        $stmt = $pdo->prepare("UPDATE maintenance_history SET description = ?, date = ?, status = ?, service_id = ? WHERE id = ?");
        $stmt->execute([$description, $datetime, $status, $service_id, $id]);
        $message = "Wartungseintrag erfolgreich bearbeitet!";
    } else {
        $message = "Bitte alle Felder ausfüllen.";
    }
}

// -----------------------------
// Incident bearbeiten
// -----------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_incident'])) {
    $id = $_POST['id'] ?? '';
    $description = clean_input($_POST['description'] ?? '');
    $date = clean_input($_POST['date'] ?? '');
    $time = clean_input($_POST['time'] ?? '');
    $status = clean_input($_POST['status'] ?? '');
    $service_id = clean_input($_POST['service_id'] ?? '');
    
    if ($id && $description && $date && $time && $service_id) {
        $datetime = $date . ' ' . $time;
        $stmt = $pdo->prepare("UPDATE incidents SET description = ?, date = ?, status = ?, service_id = ? WHERE id = ?");
        $stmt->execute([$description, $datetime, $status, $service_id, $id]);
        $message = "Vorfall erfolgreich bearbeitet!";
    } else {
        $message = "Bitte alle Felder ausfüllen.";
    }
}

// -----------------------------
// Service löschen
// -----------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['delete_service'])) {
    $id = $_GET['delete_service'] ?? '';
    if ($id) {
        $stmt = $pdo->prepare("DELETE FROM config WHERE id = ?");
        $stmt->execute([$id]);
        $message = "Service erfolgreich gelöscht!";
    }
}

// -----------------------------
// Maintenance History löschen
// -----------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['delete_maintenance'])) {
    $id = $_GET['delete_maintenance'] ?? '';
    if ($id) {
        $stmt = $pdo->prepare("DELETE FROM maintenance_history WHERE id = ?");
        $stmt->execute([$id]);
        $message = "Wartungseintrag erfolgreich gelöscht!";
    }
}

// -----------------------------
// Incident löschen
// -----------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['delete_incident'])) {
    $id = $_GET['delete_incident'] ?? '';
    if ($id) {
        $stmt = $pdo->prepare("DELETE FROM incidents WHERE id = ?");
        $stmt->execute([$id]);
        $message = "Vorfall erfolgreich gelöscht!";
    }
}

// -----------------------------
// Daten abrufen
// -----------------------------
// Alle Services
$stmt = $pdo->query("SELECT * FROM config");
$services = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Maintenance History (mit JOIN zu config, um den Servicenamen zu erhalten)
$maintenanceHistoryStmt = $pdo->query("
    SELECT m.*, c.name AS service_name, c.id AS service_id 
    FROM maintenance_history m
    LEFT JOIN config c ON m.service_id = c.id
    ORDER BY m.date DESC LIMIT 5
");
$maintenanceHistory = $maintenanceHistoryStmt->fetchAll(PDO::FETCH_ASSOC);

// Recent Incidents (mit JOIN zu config)
$recentIncidentsStmt = $pdo->query("
    SELECT i.*, c.name AS service_name, c.id AS service_id 
    FROM incidents i
    LEFT JOIN config c ON i.service_id = c.id
    ORDER BY i.date DESC LIMIT 5
");
$recentIncidents = $recentIncidentsStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin – Services verwalten</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f5f5f5;
            margin: 0;
            padding: 20px;
        }
        .container {
            max-width: 1000px;
            margin: 0 auto;
            background-color: #fff;
            padding: 20px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        }
        h1, h2 {
            color: #333;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        table, th, td {
            border: 1px solid #ddd;
        }
        th, td {
            padding: 10px;
            text-align: left;
        }
        .form-group {
            margin-bottom: 15px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            color: #555;
        }
        input[type="text"],
        input[type="url"],
        input[type="date"],
        input[type="time"],
        select {
            width: 100%;
            padding: 8px;
            border: 1px solid #ccc;
            border-radius: 4px;
        }
        button {
            padding: 8px 16px;
            border: none;
            background-color: #007BFF;
            color: #fff;
            border-radius: 4px;
            cursor: pointer;
            margin-top: 5px;
        }
        button:hover {
            background-color: #0056b3;
        }
        .action-btn {
            margin-right: 5px;
        }
        .message {
            padding: 10px;
            margin-bottom: 15px;
            border-radius: 4px;
        }
        .success {
            background-color: #d4edda;
            color: #155724;
        }
        .error {
            background-color: #f8d7da;
            color: #721c24;
        }
        .section {
            margin-top: 40px;
        }
        form.inline {
            display: inline;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Admin – Services verwalten</h1>
        <?php if($message): ?>
            <div class="message success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <!-- Service hinzufügen -->
        <h2>Service hinzufügen</h2>
        <form method="POST">
            <div class="form-group">
                <label for="name">Service Name</label>
                <input type="text" id="name" name="name" required>
            </div>
            <div class="form-group">
                <label for="url">Service URL</label>
                <input type="url" id="url" name="url" required>
            </div>
            <div class="form-group">
                <label for="sensor_type">Sensor Typ</label>
                <select id="sensor_type" name="sensor_type" required>
                    <option value="">-- Bitte Sensor Typ wählen --</option>
                    <option value="http">HTTP</option>
                    <option value="ping">Ping</option>
                    <option value="port">Port Check</option>
                    <option value="dns">DNS Check</option>
                    <option value="smtp">SMTP Check</option>
                    <option value="custom">Custom</option>
                </select>
            </div>
            <div class="form-group">
                <label for="sensor_config">Sensor Konfiguration</label>
                <input type="text" id="sensor_config" name="sensor_config" placeholder="z. B. erlaubte HTTP-Codes, Portnummer etc." required>
            </div>
            <button type="submit" name="add_service">Hinzufügen</button>
        </form>

        <!-- Bestehende Services -->
        <h2>Bestehende Services</h2>
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>URL</th>
                    <th>Sensor Typ</th>
                    <th>Sensor Konfiguration</th>
                    <th>Aktionen</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($services as $service): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($service['name']); ?></td>
                        <td><?php echo htmlspecialchars($service['url']); ?></td>
                        <td><?php echo htmlspecialchars($service['sensor_type']); ?></td>
                        <td><?php echo htmlspecialchars($service['sensor_config']); ?></td>
                        <td>
                            <!-- Service bearbeiten -->
                            <form method="POST" class="inline">
                                <input type="hidden" name="id" value="<?php echo $service['id']; ?>">
                                <input type="text" name="name" value="<?php echo htmlspecialchars($service['name']); ?>" required>
                                <input type="url" name="url" value="<?php echo htmlspecialchars($service['url']); ?>" required>
                                <select name="sensor_type" required>
                                    <option value="http" <?php echo ($service['sensor_type'] === 'http') ? 'selected' : ''; ?>>HTTP</option>
                                    <option value="ping" <?php echo ($service['sensor_type'] === 'ping') ? 'selected' : ''; ?>>Ping</option>
                                    <option value="port" <?php echo ($service['sensor_type'] === 'port') ? 'selected' : ''; ?>>Port Check</option>
                                    <option value="dns" <?php echo ($service['sensor_type'] === 'dns') ? 'selected' : ''; ?>>DNS Check</option>
                                    <option value="smtp" <?php echo ($service['sensor_type'] === 'smtp') ? 'selected' : ''; ?>>SMTP Check</option>
                                    <option value="custom" <?php echo ($service['sensor_type'] === 'custom') ? 'selected' : ''; ?>>Custom</option>
                                </select>
                                <input type="text" name="sensor_config" value="<?php echo htmlspecialchars($service['sensor_config']); ?>" placeholder="Sensor Konfiguration" required>
                                <button type="submit" name="edit_service" class="action-btn">Bearbeiten</button>
                            </form>
                            <!-- Service löschen -->
                            <a href="?delete_service=<?php echo $service['id']; ?>" onclick="return confirm('Möchten Sie diesen Service wirklich löschen?');">
                                <button class="action-btn">Löschen</button>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Maintenance History -->
        <div class="section">
            <h2>Maintenance History</h2>
            <form method="POST">
                <div class="form-group">
                    <label for="description">Beschreibung</label>
                    <input type="text" id="description" name="description" required>
                </div>
                <div class="form-group">
                    <label for="date">Datum</label>
                    <input type="date" id="date" name="date" required>
                </div>
                <div class="form-group">
                    <label for="time">Zeit</label>
                    <input type="time" id="time" name="time" required>
                </div>
                <div class="form-group">
                    <label for="service_id">Service</label>
                    <select id="service_id" name="service_id" required>
                        <option value="">-- Bitte Service wählen --</option>
                        <?php foreach($services as $service): ?>
                            <option value="<?php echo $service['id']; ?>"><?php echo htmlspecialchars($service['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" name="add_maintenance">Hinzufügen</button>
            </form>

            <table>
                <thead>
                    <tr>
                        <th>Datum</th>
                        <th>Zeit</th>
                        <th>Service</th>
                        <th>Beschreibung</th>
                        <th>Status</th>
                        <th>Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($maintenanceHistory as $history): 
                        $historyDate = date('Y-m-d', strtotime($history['date']));
                        $historyTime = date('H:i', strtotime($history['date']));
                    ?>
                        <tr>
                            <td><?php echo htmlspecialchars($historyDate); ?></td>
                            <td><?php echo htmlspecialchars($historyTime); ?></td>
                            <td><?php echo htmlspecialchars($history['service_name']); ?></td>
                            <td><?php echo htmlspecialchars($history['description']); ?></td>
                            <td><?php echo htmlspecialchars($history['status']); ?></td>
                            <td>
                                <!-- Wartungseintrag bearbeiten -->
                                <form method="POST" class="inline">
                                    <input type="hidden" name="id" value="<?php echo $history['id']; ?>">
                                    <input type="text" name="description" value="<?php echo htmlspecialchars($history['description']); ?>" required>
                                    <input type="date" name="date" value="<?php echo htmlspecialchars($historyDate); ?>" required>
                                    <input type="time" name="time" value="<?php echo htmlspecialchars($historyTime); ?>" required>
                                    <select name="status" required>
                                        <option value="scheduled" <?php echo $history['status'] == 'scheduled' ? 'selected' : ''; ?>>Geplant</option>
                                        <option value="completed" <?php echo $history['status'] == 'completed' ? 'selected' : ''; ?>>Abgeschlossen</option>
                                        <option value="cancelled" <?php echo $history['status'] == 'cancelled' ? 'selected' : ''; ?>>Abgebrochen</option>
                                    </select>
                                    <select name="service_id" required>
                                        <option value="">-- Bitte Service wählen --</option>
                                        <?php foreach($services as $service): ?>
                                            <option value="<?php echo $service['id']; ?>" <?php echo ($service['id'] == $history['service_id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($service['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" name="edit_maintenance" class="action-btn">Bearbeiten</button>
                                </form>
                                <!-- Wartungseintrag löschen -->
                                <a href="?delete_maintenance=<?php echo $history['id']; ?>" onclick="return confirm('Möchten Sie diesen Wartungseintrag wirklich löschen?');">
                                    <button class="action-btn">Löschen</button>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Recent Incidents -->
        <div class="section">
            <h2>Recent Incidents</h2>
            <form method="POST">
                <div class="form-group">
                    <label for="incident_description">Beschreibung</label>
                    <input type="text" id="incident_description" name="incident_description" required>
                </div>
                <div class="form-group">
                    <label for="incident_date">Datum</label>
                    <input type="date" id="incident_date" name="incident_date" required>
                </div>
                <div class="form-group">
                    <label for="incident_time">Zeit</label>
                    <input type="time" id="incident_time" name="incident_time" required>
                </div>
                <div class="form-group">
                    <label for="service_id_incident">Service</label>
                    <select id="service_id_incident" name="service_id" required>
                        <option value="">-- Bitte Service wählen --</option>
                        <?php foreach($services as $service): ?>
                            <option value="<?php echo $service['id']; ?>"><?php echo htmlspecialchars($service['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" name="add_incident">Hinzufügen</button>
            </form>

            <table>
                <thead>
                    <tr>
                        <th>Datum</th>
                        <th>Zeit</th>
                        <th>Service</th>
                        <th>Beschreibung</th>
                        <th>Status</th>
                        <th>Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentIncidents as $incident): 
                        $incidentDate = date('Y-m-d', strtotime($incident['date']));
                        $incidentTime = date('H:i', strtotime($incident['date']));
                    ?>
                        <tr>
                            <td><?php echo htmlspecialchars($incidentDate); ?></td>
                            <td><?php echo htmlspecialchars($incidentTime); ?></td>
                            <td><?php echo htmlspecialchars($incident['service_name']); ?></td>
                            <td><?php echo htmlspecialchars($incident['description']); ?></td>
                            <td><?php echo htmlspecialchars($incident['status']); ?></td>
                            <td>
                                <!-- Vorfall bearbeiten -->
                                <form method="POST" class="inline">
                                    <input type="hidden" name="id" value="<?php echo $incident['id']; ?>">
                                    <input type="text" name="description" value="<?php echo htmlspecialchars($incident['description']); ?>" required>
                                    <input type="date" name="date" value="<?php echo htmlspecialchars($incidentDate); ?>" required>
                                    <input type="time" name="time" value="<?php echo htmlspecialchars($incidentTime); ?>" required>
                                    <select name="status" required>
                                        <option value="resolved" <?php echo $incident['status'] == 'resolved' ? 'selected' : ''; ?>>Gelöst</option>
                                        <option value="unresolved" <?php echo $incident['status'] == 'unresolved' ? 'selected' : ''; ?>>Ungelöst</option>
                                    </select>
                                    <select name="service_id" required>
                                        <option value="">-- Bitte Service wählen --</option>
                                        <?php foreach($services as $service): ?>
                                            <option value="<?php echo $service['id']; ?>" <?php echo ($service['id'] == $incident['service_id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($service['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" name="edit_incident" class="action-btn">Bearbeiten</button>
                                </form>
                                <!-- Vorfall löschen -->
                                <a href="?delete_incident=<?php echo $incident['id']; ?>" onclick="return confirm('Möchten Sie diesen Vorfall wirklich löschen?');">
                                    <button class="action-btn">Löschen</button>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
