<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: index.html');
    exit();
}

require 'db.php'; // Datenbankverbindung einbinden

// Variable für Statusmeldungen
$message = '';

// Hilfsfunktion zum Säubern der Eingaben
function clean_input($data) {
    return trim(htmlspecialchars(strip_tags($data), ENT_QUOTES, 'UTF-8'));
}

// -----------------------------
// Service bearbeiten (nur, wenn der Service dem Nutzer gehört)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_service'])) {
    $id = $_POST['id'] ?? '';
    $name = clean_input($_POST['name'] ?? '');
    $url = clean_input($_POST['url'] ?? '');
    $sensor_type = clean_input($_POST['sensor_type'] ?? '');
    $sensor_config = clean_input($_POST['sensor_config'] ?? '');
    
    if ($id && $name && $url && $sensor_type) {
        $stmt = $pdo->prepare("UPDATE config SET name = ?, url = ?, sensor_type = ?, sensor_config = ? WHERE id = ? AND user_id = ?");
        $stmt->execute([$name, $url, $sensor_type, $sensor_config, $id, $_SESSION['user_id']]);
        $message = "Service erfolgreich bearbeitet!";
    } else {
        $message = "Bitte alle Felder ausfüllen.";
    }
}

// -----------------------------
// Service hinzufügen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_service'])) {
    $name = clean_input($_POST['name'] ?? '');
    $url = clean_input($_POST['url'] ?? '');
    $sensor_type = clean_input($_POST['sensor_type'] ?? '');
    $sensor_config = clean_input($_POST['sensor_config'] ?? '');
    
    if ($name && $url && $sensor_type) {
        $stmt = $pdo->prepare("INSERT INTO config (name, url, sensor_type, sensor_config, user_id) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$name, $url, $sensor_type, $sensor_config, $_SESSION['user_id']]);
        $message = "Service erfolgreich hinzugefügt!";
    } else {
        $message = "Bitte alle Felder ausfüllen.";
    }
}

// -----------------------------
// Maintenance History hinzufügen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_maintenance'])) {
    $description = clean_input($_POST['description'] ?? '');
    $date = clean_input($_POST['date'] ?? '');
    $time = clean_input($_POST['time'] ?? '');
    $service_id = clean_input($_POST['service_id'] ?? '');
    
    if ($description && $date && $time && $service_id) {
        $datetime = $date . ' ' . $time;
        $stmt = $pdo->prepare("INSERT INTO maintenance_history (description, date, status, service_id, user_id) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$description, $datetime, 'scheduled', $service_id, $_SESSION['user_id']]);
        $message = "Wartungseintrag erfolgreich hinzugefügt!";
    } else {
        $message = "Bitte alle Felder ausfüllen.";
    }
}

// -----------------------------
// Incident hinzufügen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_incident'])) {
    $incidentDescription = clean_input($_POST['incident_description'] ?? '');
    $incidentDate = clean_input($_POST['incident_date'] ?? '');
    $incidentTime = clean_input($_POST['incident_time'] ?? '');
    $service_id = clean_input($_POST['service_id'] ?? '');
    
    if ($incidentDescription && $incidentDate && $incidentTime && $service_id) {
        $datetime = $incidentDate . ' ' . $incidentTime;
        $stmt = $pdo->prepare("INSERT INTO incidents (description, date, status, service_id, user_id) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$incidentDescription, $datetime, 'reported', $service_id, $_SESSION['user_id']]);
        $message = "Vorfall erfolgreich hinzugefügt!";
    } else {
        $message = "Bitte alle Felder ausfüllen.";
    }
}

// -----------------------------
// Maintenance History bearbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_maintenance'])) {
    $id = $_POST['id'] ?? '';
    $description = clean_input($_POST['description'] ?? '');
    $date = clean_input($_POST['date'] ?? '');
    $time = clean_input($_POST['time'] ?? '');
    $status = clean_input($_POST['status'] ?? '');
    $service_id = clean_input($_POST['service_id'] ?? '');
    
    if ($id && $description && $date && $time && $service_id) {
        $datetime = $date . ' ' . $time;
        $stmt = $pdo->prepare("UPDATE maintenance_history SET description = ?, date = ?, status = ?, service_id = ? WHERE id = ? AND user_id = ?");
        $stmt->execute([$description, $datetime, $status, $service_id, $id, $_SESSION['user_id']]);
        $message = "Wartungseintrag erfolgreich bearbeitet!";
    } else {
        $message = "Bitte alle Felder ausfüllen.";
    }
}

// -----------------------------
// Incident bearbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_incident'])) {
    $id = $_POST['id'] ?? '';
    $description = clean_input($_POST['description'] ?? '');
    $date = clean_input($_POST['date'] ?? '');
    $time = clean_input($_POST['time'] ?? '');
    $status = clean_input($_POST['status'] ?? '');
    $service_id = clean_input($_POST['service_id'] ?? '');
    
    if ($id && $description && $date && $time && $service_id) {
        $datetime = $date . ' ' . $time;
        $stmt = $pdo->prepare("UPDATE incidents SET description = ?, date = ?, status = ?, service_id = ? WHERE id = ? AND user_id = ?");
        $stmt->execute([$description, $datetime, $status, $service_id, $id, $_SESSION['user_id']]);
        $message = "Vorfall erfolgreich bearbeitet!";
    } else {
        $message = "Bitte alle Felder ausfüllen.";
    }
}

// -----------------------------
// Service löschen (nur, wenn der Service dem Nutzer gehört)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['delete_service'])) {
    $id = $_GET['delete_service'] ?? '';
    if ($id) {
        $stmt = $pdo->prepare("DELETE FROM config WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $_SESSION['user_id']]);
        $message = "Service erfolgreich gelöscht!";
    }
}

// -----------------------------
// Maintenance History löschen
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['delete_maintenance'])) {
    $id = $_GET['delete_maintenance'] ?? '';
    if ($id) {
        $stmt = $pdo->prepare("DELETE FROM maintenance_history WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $_SESSION['user_id']]);
        $message = "Wartungseintrag erfolgreich gelöscht!";
    }
}

// -----------------------------
// Incident löschen
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['delete_incident'])) {
    $id = $_GET['delete_incident'] ?? '';
    if ($id) {
        $stmt = $pdo->prepare("DELETE FROM incidents WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $_SESSION['user_id']]);
        $message = "Vorfall erfolgreich gelöscht!";
    }
}

// -----------------------------
// Statuspage erstellen – Mehrere Sensoren hinzufügen und UUID generieren
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_statuspage'])) {
    $page_title = clean_input($_POST['page_title'] ?? '');
    $custom_css = clean_input($_POST['custom_css'] ?? '');
    // sensor_ids als Array (Multiple-Select)
    $sensor_ids = isset($_POST['sensor_ids']) ? $_POST['sensor_ids'] : [];
    
    if ($page_title) {
        // Sensor-IDs als JSON speichern
        $sensor_ids_json = json_encode($sensor_ids);
        // UUID generieren (Hier als Beispiel mit uniqid – ggf. durch eine robustere Lösung ersetzen)
        $uuid = uniqid('sp_', true);
        // In der Tabelle status_pages wird nun in der Spalte "uuid" der eindeutige UUID-Wert gespeichert
        $stmt = $pdo->prepare("INSERT INTO status_pages (user_id, sensor_ids, page_title, custom_css, uuid) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$_SESSION['user_id'], $sensor_ids_json, $page_title, $custom_css, $uuid]);
        $newStatusPageId = $pdo->lastInsertId();
        // Zugriff über die UUID anzeigen
        $message = "Statuspage erfolgreich erstellt! Zugriff: <a href='index2.php?status_page_uuid=" . urlencode($uuid) . "' target='_blank'>Statuspage anzeigen</a>";
    } else {
        $message = "Bitte einen Titel für die Statuspage angeben.";
    }
}

// -----------------------------
// Statuspage bearbeiten – Inline bearbeiten direkt auf der Seite
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_statuspage'])) {
    $page_id = intval($_POST['page_id'] ?? 0);
    $page_title = clean_input($_POST['page_title'] ?? '');
    $custom_css = clean_input($_POST['custom_css'] ?? '');
    $sensor_ids = isset($_POST['sensor_ids']) ? $_POST['sensor_ids'] : [];
    
    if ($page_id > 0 && $page_title) {
        $sensor_ids_json = json_encode($sensor_ids);
        $stmt = $pdo->prepare("UPDATE status_pages SET page_title = ?, custom_css = ?, sensor_ids = ? WHERE id = ? AND user_id = ?");
        $stmt->execute([$page_title, $custom_css, $sensor_ids_json, $page_id, $_SESSION['user_id']]);
        if ($stmt->rowCount() > 0) {
            $message = "Statuspage erfolgreich aktualisiert.";
        } else {
            $message = "Keine Änderungen vorgenommen oder Aktualisierung fehlgeschlagen.";
        }
    } else {
        $message = "Bitte einen gültigen Titel angeben.";
    }
}

// -----------------------------
// Statuspage löschen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_statuspage'])) {
    $page_id = intval($_POST['page_id'] ?? 0);
    if ($page_id > 0) {
        $stmt = $pdo->prepare("DELETE FROM status_pages WHERE id = ? AND user_id = ?");
        $stmt->execute([$page_id, $_SESSION['user_id']]);
        if ($stmt->rowCount() > 0) {
            $message = "Statuspage erfolgreich gelöscht.";
        } else {
            $message = "Statuspage konnte nicht gelöscht werden.";
        }
    }
}

// -----------------------------
// Daten abrufen: Nur Einträge des eingeloggten Nutzers
$stmt = $pdo->prepare("SELECT * FROM config WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$services = $stmt->fetchAll(PDO::FETCH_ASSOC);

$maintenanceHistoryStmt = $pdo->prepare("
    SELECT m.*, c.name AS service_name 
    FROM maintenance_history m
    LEFT JOIN config c ON m.service_id = c.id
    WHERE m.user_id = ?
    ORDER BY m.date DESC LIMIT 5
");
$maintenanceHistoryStmt->execute([$_SESSION['user_id']]);
$maintenanceHistory = $maintenanceHistoryStmt->fetchAll(PDO::FETCH_ASSOC);

$recentIncidentsStmt = $pdo->prepare("
    SELECT i.*, c.name AS service_name 
    FROM incidents i
    LEFT JOIN config c ON i.service_id = c.id
    WHERE i.user_id = ?
    ORDER BY i.date DESC LIMIT 5
");
$recentIncidentsStmt->execute([$_SESSION['user_id']]);
$recentIncidents = $recentIncidentsStmt->fetchAll(PDO::FETCH_ASSOC);

$statusPagesStmt = $pdo->prepare("SELECT * FROM status_pages WHERE user_id = ? ORDER BY created_at DESC");
$statusPagesStmt->execute([$_SESSION['user_id']]);
$statusPages = $statusPagesStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard – ProStatus</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f5f5f5;
            margin: 0;
            padding: 20px;
        }
        header {
            background: #1d2d44;
            color: #fff;
            padding: 20px;
            text-align: center;
            position: relative;
        }
        header h1 {
            margin: 0;
            font-size: 32px;
        }
        header a.logout {
            position: absolute;
            right: 20px;
            top: 20px;
            color: #fff;
        }
        .container {
            max-width: 1000px;
            margin: 20px auto;
            background: #fff;
            padding: 20px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
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
        textarea,
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
        .sort-options {
            text-align: center;
            margin-bottom: 20px;
        }
        .sort-options select {
            padding: 5px 10px;
            font-size: 16px;
        }
    </style>
</head>
<body>
    <header>
        <h1>Dashboard – ProStatus</h1>
        <p>Willkommen, <?php echo htmlspecialchars($_SESSION['user_name']); ?></p>
        <a href="logout.php" class="logout">Logout</a>
    </header>
    <div class="container">
        <?php if($message): ?>
            <div class="message success"><?php echo $message; ?></div>
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
                <input type="text" id="sensor_config" name="sensor_config" placeholder="z. B. erlaubte HTTP-Codes, Portnummer etc." required>
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
                                        <option value="reported" <?php echo $incident['status'] == 'reported' ? 'selected' : ''; ?>>Gemeldet</option>
                                        <option value="in progress" <?php echo $incident['status'] == 'in progress' ? 'selected' : ''; ?>>In Bearbeitung</option>
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
        
        <!-- Neue Sektion: Statuspage erstellen mit mehreren Sensoren -->
        <div class="section">
            <h2>Statuspage erstellen</h2>
            <form method="POST">
                <div class="form-group">
                    <label for="page_title">Seiten-Titel</label>
                    <input type="text" id="page_title" name="page_title" required placeholder="Titel der Statuspage">
                </div>
                <div class="form-group">
                    <label for="sensor_ids">Sensoren auswählen (Mehrfachauswahl möglich)</label>
                    <select id="sensor_ids" name="sensor_ids[]" multiple>
                        <?php foreach($services as $service): ?>
                            <option value="<?php echo $service['id']; ?>"><?php echo htmlspecialchars($service['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="custom_css">Benutzerdefiniertes CSS (optional)</label>
                    <textarea id="custom_css" name="custom_css" placeholder="Hier kannst du eigenes CSS einfügen..."></textarea>
                </div>
                <button type="submit" name="add_statuspage">Statuspage erstellen</button>
            </form>
        </div>

<!-- Bestehende Statuspages anzeigen -->
<div class="section">
    <h2>Deine Statuspages</h2>
    <?php if (count($statusPages) > 0): ?>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Titel</th>
                <th>Erstellt am</th>
                <th>Aktion</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($statusPages as $page): ?>
            <tr>
                <td><?php echo htmlspecialchars($page['id']); ?></td>
                <td><?php echo htmlspecialchars($page['page_title']); ?></td>
                <td><?php echo htmlspecialchars($page['created_at']); ?></td>
                <td>
                    <!-- Statuspage anzeigen -->
                    <a href="index2.php?status_page_uuid=<?php echo urlencode($page['uuid']); ?>" target="_blank">
                        <button class="action-btn">Anzeigen</button>
                    </a>
                    <!-- Inline Edit Button -->
                    <button class="action-btn" onclick="toggleEdit(<?php echo $page['id']; ?>)">Editieren</button>
                    <!-- Formular zum Löschen -->
                    <form method="POST" style="display:inline-block;" onsubmit="return confirm('Möchtest du diese Statuspage wirklich löschen?');">
                        <input type="hidden" name="page_id" value="<?php echo htmlspecialchars($page['id']); ?>">
                        <button type="submit" name="delete_statuspage" class="action-btn delete-btn">Löschen</button>
                    </form>
                </td>
            </tr>
            <!-- Inline Editing Row (initial ausgeblendet) -->
            <tr id="editRow_<?php echo $page['id']; ?>" style="display:none;">
                <td colspan="4">
                    <form method="POST">
                        <input type="hidden" name="page_id" value="<?php echo htmlspecialchars($page['id']); ?>">
                        <div class="form-group">
                            <label for="page_title_<?php echo $page['id']; ?>">Seiten-Titel</label>
                            <input type="text" id="page_title_<?php echo $page['id']; ?>" name="page_title" required value="<?php echo htmlspecialchars($page['page_title']); ?>">
                        </div>
                        <div class="form-group">
                            <label for="sensor_ids_<?php echo $page['id']; ?>">Sensoren auswählen (Mehrfachauswahl möglich)</label>
                            <select id="sensor_ids_<?php echo $page['id']; ?>" name="sensor_ids[]" multiple>
                                <?php 
                                $selectedSensors = json_decode($page['sensor_ids'], true) ?? [];
                                foreach($services as $service): ?>
                                    <option value="<?php echo $service['id']; ?>" <?php echo in_array($service['id'], $selectedSensors) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($service['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="custom_css_<?php echo $page['id']; ?>">Benutzerdefiniertes CSS (optional)</label>
                            <textarea id="custom_css_<?php echo $page['id']; ?>" name="custom_css" placeholder="Hier kannst du eigenes CSS einfügen..."><?php echo htmlspecialchars($page['custom_css'] ?? ''); ?></textarea>
                        </div>
                        <button type="submit" name="edit_statuspage">Speichern</button>
                        <button type="button" onclick="toggleEdit(<?php echo $page['id']; ?>)">Abbrechen</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?>
        <p>Du hast noch keine Statuspage erstellt.</p>
    <?php endif; ?>
</div>

    </div>
    <script>
  window.toggleEdit = function(id) {
      var editRow = document.getElementById('editRow_' + id);
      // Umschalten zwischen Anzeigen und Verbergen der Bearbeitungszeile
      if (editRow.style.display === 'none' || editRow.style.display === '') {
          editRow.style.display = 'table-row';
      } else {
          editRow.style.display = 'none';
      }
  };
</script>
    <script>
        // Übergabe der Filter-Parameter: sensor_ids (als CSV) und service_id (Fallback)
        const sensorIds = "<?php echo $sensorIdsParam; ?>";
        const filterServiceId = "<?php echo $filterServiceId ? $filterServiceId : ''; ?>";
        
        function getSortOrder() {
            return document.getElementById('sort-order').value;
        }
        
        let initialLoad = true;
        function fetchStatus() {
            const loadingElement = document.getElementById('loading');
            if (initialLoad) {
                loadingElement.style.display = 'block';
            }
            let url = 'status.php?status_page_uuid=<?php echo $status_page_uuid; ?>';
            if (sensorIds) {
                url += '&sensor_ids=' + encodeURIComponent(sensorIds);
            } else if (filterServiceId) {
                url += '&service_id=' + filterServiceId;
            }
            url += '&sort=' + getSortOrder();
            
            fetch(url)
                .then(response => response.json())
                .then(data => {
                    const container = document.getElementById('status-cards');
                    container.innerHTML = '';
                    data.sensors.forEach(sensor => {
                        const card = document.createElement('div');
                        card.className = 'card card-status';
                        
                        const header = document.createElement('div');
                        header.className = 'card-header';
                        
                        const title = document.createElement('h2');
                        title.textContent = sensor.name;
                        
                        const statusSpan = document.createElement('span');
                        statusSpan.className = 'status ' + (sensor.status === 'up' ? 'up' : 'down');
                        statusSpan.textContent = sensor.status.toUpperCase();
                        
                        header.appendChild(title);
                        header.appendChild(statusSpan);
                        card.appendChild(header);
                        
                        const uptimeText = document.createElement('p');
                        uptimeText.className = 'uptime';
                        uptimeText.textContent = 'Uptime (30 Tage): ' + parseFloat(sensor.uptime).toFixed(2) + '%';
                        card.appendChild(uptimeText);
                        
                        if (sensor.daily && sensor.daily.length > 0) {
                            const dailyContainer = document.createElement('div');
                            dailyContainer.className = 'daily-strips';
                            
                            sensor.daily.forEach(day => {
                                const dailyStrip = document.createElement('div');
                                dailyStrip.className = 'daily-strip';
                                dailyStrip.setAttribute('title', day.date + ' - ' + day.uptime + '% uptime');
                                
                                let bgColor = '#27ae60';
                                if (day.uptime < 97) {
                                    bgColor = '#e74c3c';
                                } else if (day.uptime < 99) {
                                    bgColor = '#f39c12';
                                }
                                dailyStrip.style.backgroundColor = bgColor;
                                
                                dailyContainer.appendChild(dailyStrip);
                            });
                            card.appendChild(dailyContainer);
                        }
                        container.appendChild(card);
                    });
                    
                    const now = new Date();
                    document.getElementById('last-check').textContent = 'Letzte Aktualisierung: ' + now.toLocaleTimeString();
                    if (initialLoad) {
                        loadingElement.style.display = 'none';
                        initialLoad = false;
                    }
                })
                .catch(error => {
                    console.error('Fehler beim Laden der Sensoren:', error);
                    if (initialLoad) {
                        loadingElement.style.display = 'none';
                        initialLoad = false;
                    }
                });
        }
        
        function fetchMaintenanceHistory() {
            let url = 'maintenance_history.php?status_page_uuid=<?php echo $status_page_uuid; ?>';
            if (filterServiceId) {
                url += '&service_id=' + filterServiceId;
            }
            fetch(url)
                .then(response => response.json())
                .then(data => {
                    const tableBody = document.getElementById('maintenance-history').querySelector('tbody');
                    tableBody.innerHTML = '';
                    data.forEach(event => {
                        const row = tableBody.insertRow();
                        row.insertCell(0).textContent = event.date;
                        row.insertCell(1).textContent = event.service_name;
                        row.insertCell(2).textContent = event.description;
                        row.insertCell(3).textContent = event.status;
                    });
                })
                .catch(error => console.error('Fehler beim Laden der Wartungshistorie:', error));
        }

        function fetchRecentIncidents() {
            let url = 'recent_incidents.php?status_page_uuid=<?php echo $status_page_uuid; ?>';
            if (filterServiceId) {
                url += '&service_id=' + filterServiceId;
            }
            fetch(url)
                .then(response => response.json())
                .then(data => {
                    const tableBody = document.getElementById('recent-incidents').querySelector('tbody');
                    tableBody.innerHTML = '';
                    data.forEach(incident => {
                        const row = tableBody.insertRow();
                        row.insertCell(0).textContent = incident.date;
                        row.insertCell(1).textContent = incident.service_name;
                        row.insertCell(2).textContent = incident.description;
                        row.insertCell(3).textContent = incident.status;
                    });
                })
                .catch(error => console.error('Fehler beim Laden der Vorfälle:', error));
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            fetchStatus();
            fetchMaintenanceHistory();
            fetchRecentIncidents();
            setInterval(fetchStatus, 30000);
        });
        
        document.addEventListener('DOMContentLoaded', function() {
            const uptimePopup = document.getElementById('uptime-popup');
            document.body.addEventListener('mouseover', function(event) {
                if (event.target.classList.contains('daily-strip')) {
                    const rect = event.target.getBoundingClientRect();
                    uptimePopup.textContent = event.target.getAttribute('title');
                    uptimePopup.style.display = 'block';
                    uptimePopup.style.top = `${rect.top + window.scrollY - 30}px`;
                    uptimePopup.style.left = `${rect.left + window.scrollX + 10}px`;
                }
            });
            document.body.addEventListener('mouseout', function(event) {
                if (event.target.classList.contains('daily-strip')) {
                    uptimePopup.style.display = 'none';
                }
            });
        });
    </script>
    
    <div id="uptime-popup" class="uptime-popup"></div>
</body>
</html>
