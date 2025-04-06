<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Initialize CSRF token if it doesn't exist
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Variable für Statusmeldungen
$message = '';
$error = '';

// Hilfsfunktion zum Säubern der Eingaben
function clean_input($data) {
    return trim(htmlspecialchars(strip_tags($data), ENT_QUOTES, 'UTF-8'));
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

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Get user's current tier and subscription
    $stmt = $pdo->prepare("
        SELECT ut.*, us.status as subscription_status, us.end_date
        FROM user_subscriptions us
        JOIN user_tiers ut ON us.tier_id = ut.id
        WHERE us.user_id = ? AND us.status = 'active'
        ORDER BY us.end_date DESC
        LIMIT 1
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $userTier = $stmt->fetch(PDO::FETCH_ASSOC);

    // Get user's current usage
    $stmt = $pdo->prepare("
        SELECT 
            (SELECT COUNT(*) FROM status_pages WHERE user_id = ?) as status_pages_count,
            (SELECT COUNT(*) FROM config WHERE user_id = ?) as sensors_count,
            (SELECT COUNT(*) FROM email_subscribers es 
             JOIN status_pages sp ON es.status_page_id = sp.id 
             WHERE sp.user_id = ?) as email_subscribers_count
    ");
    $stmt->execute([$_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id']]);
    $usage = $stmt->fetch(PDO::FETCH_ASSOC);

    // If no tier is set, set default values
    if (!$userTier) {
        $userTier = [
            'name' => 'Free',
            'max_status_pages' => 1,
            'max_sensors' => 5,
            'max_email_subscribers' => 10
        ];
    }

    // Get all available tiers
    $stmt = $pdo->query("SELECT * FROM user_tiers ORDER BY price");
    $availableTiers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get user's status pages
    $stmt = $pdo->prepare("
        SELECT sp.*, 
               COUNT(DISTINCT es.id) as subscriber_count,
               COUNT(DISTINCT c.id) as sensor_count
        FROM status_pages sp
        LEFT JOIN email_subscribers es ON sp.id = es.status_page_id
        LEFT JOIN config c ON sp.user_id = c.user_id
        WHERE sp.user_id = ?
        GROUP BY sp.id
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $statusPages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get user's sensors
    $stmt = $pdo->prepare("
        SELECT c.*, 
               (SELECT status FROM uptime_checks WHERE service_url = c.url ORDER BY check_time DESC LIMIT 1) as last_status,
               (SELECT check_time FROM uptime_checks WHERE service_url = c.url ORDER BY check_time DESC LIMIT 1) as last_check
        FROM config c
        WHERE c.user_id = ?
        ORDER BY c.name
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $sensors = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get recent incidents
    $stmt = $pdo->prepare("
        SELECT i.*, c.name as service_name
        FROM incidents i
        LEFT JOIN config c ON i.service_id = c.id
        WHERE i.user_id = ?
        ORDER BY i.date DESC
        LIMIT 5
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $recentIncidents = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get maintenance history
    $stmt = $pdo->prepare("
        SELECT mh.*, c.name as service_name 
        FROM maintenance_history mh 
        LEFT JOIN config c ON mh.service_id = c.id 
        WHERE mh.user_id = ? 
        ORDER BY mh.start_date DESC 
        LIMIT 5
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $maintenanceHistory = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Handle form submissions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // CSRF-Überprüfung
        if (!check_csrf_token()) {
            $error = "Security verification failed. Please try again.";
        } else {
            if (isset($_POST['add_service'])) {
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

            if (isset($_POST['add_maintenance'])) {
    $description = clean_input($_POST['description'] ?? '');
                $start_date = clean_input($_POST['start_date'] ?? '');
                $start_time = clean_input($_POST['start_time'] ?? '');
                $end_date = clean_input($_POST['end_date'] ?? '');
                $end_time = clean_input($_POST['end_time'] ?? '');
    $service_id = clean_input($_POST['service_id'] ?? '');
    
                if ($description && $start_date && $start_time && $end_date && $end_time && $service_id) {
                    $start_datetime = $start_date . ' ' . $start_time;
                    $end_datetime = $end_date . ' ' . $end_time;
                    
                    $stmt = $pdo->prepare("INSERT INTO maintenance_history (description, start_date, end_date, status, service_id, user_id) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$description, $start_datetime, $end_datetime, 'scheduled', $service_id, $_SESSION['user_id']]);
        $message = "Wartungseintrag erfolgreich hinzugefügt!";
    } else {
        $message = "Bitte alle Felder ausfüllen.";
    }
}

            if (isset($_POST['add_incident'])) {
    $incidentDescription = clean_input($_POST['incident_description'] ?? '');
    $incidentDate = clean_input($_POST['incident_date'] ?? '');
    $incidentTime = clean_input($_POST['incident_time'] ?? '');
    $service_id = clean_input($_POST['service_id'] ?? '');
    
    if ($incidentDescription && $incidentDate && $incidentTime && $service_id) {
        $datetime = $incidentDate . ' ' . $incidentTime;
        $stmt = $pdo->prepare("INSERT INTO incidents (description, date, status, service_id, user_id) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$incidentDescription, $datetime, 'reported', $service_id, $_SESSION['user_id']]);
                    $incident_id = $pdo->lastInsertId();
        $message = "Vorfall erfolgreich hinzugefügt!";
                    
                    // Sende E-Mail-Benachrichtigungen
                    if ($incident_id) {
                        // Finde alle Status Pages, die diesen Service enthalten
                        $stmt = $pdo->prepare("
                            SELECT id 
                            FROM status_pages 
                            WHERE JSON_CONTAINS(sensor_ids, ?) OR service_id = ?
                        ");
                        $stmt->execute([json_encode($service_id), $service_id]);
                        $status_pages = $stmt->fetchAll(PDO::FETCH_COLUMN);
                        
                        if (!empty($status_pages)) {
                            // Lade die E-Mail-Benachrichtigungsklasse
                            require_once 'email_notifications.php';
                            $emailNotifier = new EmailNotifications($pdo);
                            
                            // Sende Benachrichtigungen für jede betroffene Status Page
                            foreach ($status_pages as $status_page_id) {
                                $emailNotifier->sendIncidentNotification($incident_id, $status_page_id);
                            }
                            
                            $message .= " E-Mail-Benachrichtigungen wurden gesendet.";
                        }
                    }
    } else {
        $message = "Bitte alle Felder ausfüllen.";
    }
}

            if (isset($_POST['add_statuspage'])) {
                $page_title = clean_input($_POST['page_title'] ?? '');
                $custom_css = clean_input($_POST['custom_css'] ?? '');
                $sensor_ids = isset($_POST['sensor_ids']) ? $_POST['sensor_ids'] : [];
                
                if ($page_title) {
                    $sensor_ids_json = json_encode($sensor_ids);
                    $uuid = uniqid('sp_', true);
                    $stmt = $pdo->prepare("INSERT INTO status_pages (user_id, sensor_ids, page_title, custom_css, uuid) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$_SESSION['user_id'], $sensor_ids_json, $page_title, $custom_css, $uuid]);
                    $message = "Statuspage erfolgreich erstellt!";
    } else {
                    $message = "Bitte einen Titel für die Statuspage angeben.";
                }
            }

            if (isset($_POST['delete_service'])) {
                $service_id = (int)$_POST['service_id'];
                
                // Get the service name before deleting
                $stmt = $pdo->prepare("SELECT name FROM config WHERE id = ? AND user_id = ?");
                $stmt->execute([$service_id, $_SESSION['user_id']]);
                $service = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($service) {
                    // Delete related uptime_checks entries
                    $stmt = $pdo->prepare("DELETE FROM uptime_checks WHERE service_name = ? AND user_id = ?");
                    $stmt->execute([$service['name'], $_SESSION['user_id']]);
                    
                    // Delete the service from config
                    $stmt = $pdo->prepare("DELETE FROM config WHERE id = ? AND user_id = ?");
                    $stmt->execute([$service_id, $_SESSION['user_id']]);
                    
                    $message = "Service deleted successfully.";
                }
            }
        }
    }

    // Handle GET requests for deletions
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Zusätzliche Sicherheitsprüfung: API-Key-Validierung für DELETE-Aktionen
        $api_key = isset($_GET['api_key']) ? $_GET['api_key'] : '';
        
        // Wenn ein API-Key vorhanden ist, überprüfen wir dessen Gültigkeit
        if (!empty($api_key)) {
            $api_stmt = $pdo->prepare("SELECT user_id FROM api_keys WHERE api_key = ? AND active = 1");
            $api_stmt->execute([$api_key]);
            $api_user = $api_stmt->fetch(PDO::FETCH_ASSOC);
            
            // Wenn der API-Key gültig ist, setzen wir user_id für die Aktionen
            if ($api_user && isset($api_user['user_id'])) {
                $user_id_for_action = $api_user['user_id'];
    } else {
                die('Invalid API Key');
            }
        } else {
            // Für normale Web-Anfragen verwenden wir die Session-User-ID
            $user_id_for_action = $_SESSION['user_id'];
            
            // Überprüfung eines Bestätigungsparameters für DELETE-Aktionen
            if ((isset($_GET['delete_service']) || isset($_GET['delete_maintenance']) || 
                isset($_GET['delete_incident']) || isset($_GET['delete_status_page'])) && 
                (!isset($_GET['confirm']) || $_GET['confirm'] !== 'true')) {
                $error = "Please confirm this deletion by adding '&confirm=true' to the URL.";
            }
        }
        
        // Nur fortfahren, wenn keine Fehler vorliegen
        if (empty($error)) {
            if (isset($_GET['delete_service']) && isset($_GET['confirm']) && $_GET['confirm'] === 'true') {
    $id = $_GET['delete_service'] ?? '';
    if ($id) {
        // Get the service name before deleting
        $stmt = $pdo->prepare("SELECT name FROM config WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $user_id_for_action]);
        $service = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($service) {
            // Delete related uptime_checks entries
            $stmt = $pdo->prepare("DELETE FROM uptime_checks WHERE service_name = ? AND user_id = ?");
            $stmt->execute([$service['name'], $user_id_for_action]);
            
            // Delete the service from config
        $stmt = $pdo->prepare("DELETE FROM config WHERE id = ? AND user_id = ?");
            $stmt->execute([$id, $user_id_for_action]);
            
            if ($stmt->rowCount() > 0) {
        $message = "Service erfolgreich gelöscht!";
            } else {
                $error = "Service nicht gefunden oder keine Berechtigung!";
            }
        }
    }
}

            if (isset($_GET['delete_maintenance']) && isset($_GET['confirm']) && $_GET['confirm'] === 'true') {
    $id = $_GET['delete_maintenance'] ?? '';
    if ($id) {
        $stmt = $pdo->prepare("DELETE FROM maintenance_history WHERE id = ? AND user_id = ?");
                    $stmt->execute([$id, $user_id_for_action]);
                    if ($stmt->rowCount() > 0) {
        $message = "Wartungseintrag erfolgreich gelöscht!";
                    } else {
                        $error = "Wartungseintrag nicht gefunden oder keine Berechtigung!";
                    }
    }
}

            if (isset($_GET['delete_incident']) && isset($_GET['confirm']) && $_GET['confirm'] === 'true') {
    $id = $_GET['delete_incident'] ?? '';
    if ($id) {
        $stmt = $pdo->prepare("DELETE FROM incidents WHERE id = ? AND user_id = ?");
                    $stmt->execute([$id, $user_id_for_action]);
                    if ($stmt->rowCount() > 0) {
        $message = "Vorfall erfolgreich gelöscht!";
    } else {
                        $error = "Vorfall nicht gefunden oder keine Berechtigung!";
                    }
                }
            }

            if (isset($_GET['delete_status_page']) && isset($_GET['confirm']) && $_GET['confirm'] === 'true') {
                $id = $_GET['delete_status_page'] ?? '';
                if ($id) {
                    // Prüfen, ob die Status Page dem Benutzer gehört
                    $stmt = $pdo->prepare("SELECT id FROM status_pages WHERE id = ? AND user_id = ?");
                    $stmt->execute([$id, $user_id_for_action]);
        if ($stmt->rowCount() > 0) {
                        // Lösche zuerst alle Email-Abonnenten dieser Status Page
                        $stmt = $pdo->prepare("DELETE FROM email_subscribers WHERE status_page_id = ?");
                        $stmt->execute([$id]);
                        
                        // Lösche die Status Page
        $stmt = $pdo->prepare("DELETE FROM status_pages WHERE id = ? AND user_id = ?");
                        $stmt->execute([$id, $user_id_for_action]);
                        $message = "Status Page erfolgreich gelöscht!";
        } else {
                        $error = "Status Page nicht gefunden oder keine Berechtigung!";
                    }
                }
            }
        }
    }

} catch (PDOException $e) {
    die('Database error: ' . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Status Page</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f5f5f5;
            margin: 0;
            padding: 0;
        }
        .navbar {
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .navbar-brand {
            font-weight: bold;
            font-size: 1.5rem;
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
        .form-group {
            margin-bottom: 15px;
        }
        .action-btn {
            margin-right: 5px;
        }
        .usage-card {
            background: white;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .usage-title {
            font-size: 0.9rem;
            color: #6c757d;
            margin-bottom: 5px;
        }
        .usage-value {
            font-size: 1.2rem;
            font-weight: bold;
            margin-bottom: 5px;
        }
        .usage-limit {
            font-size: 0.8rem;
            color: #6c757d;
        }
        .progress {
            height: 8px;
            margin-top: 5px;
        }
        .btn-disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container">
            <a class="navbar-brand" href="dashboard2.php">Status Page</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav">
                    <li class="nav-item">
                        <a class="nav-link active" href="dashboard2.php">Dashboard</a>
                    </li>
                    <?php if (isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="admin.php">Admin Panel</a>
                    </li>
                    <?php endif; ?>
                </ul>
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="logout.php">Logout</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <?php if($message): ?>
            <div class="message success"><?php echo $message; ?></div>
        <?php endif; ?>

        <?php if($error): ?>
            <div class="message error"><?php echo $error; ?></div>
        <?php endif; ?>

        <!-- Current Tier and Usage -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Current Plan</h5>
                    </div>
                    <div class="card-body">
                        <h3><?php echo htmlspecialchars($userTier['name']); ?></h3>
                        <?php if (isset($userTier['end_date'])): ?>
                            <p class="text-muted">Valid until: <?php echo date('Y-m-d', strtotime($userTier['end_date'])); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Usage</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4">
                                <div class="usage-card">
                                    <div class="usage-title">Status Pages</div>
                                    <div class="usage-value"><?php echo $usage['status_pages_count']; ?> / <?php echo $userTier['max_status_pages']; ?></div>
                                    <div class="progress">
                                        <div class="progress-bar" role="progressbar" 
                                             style="width: <?php echo min(100, ($usage['status_pages_count'] / $userTier['max_status_pages']) * 100); ?>%">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="usage-card">
                                    <div class="usage-title">Sensors</div>
                                    <div class="usage-value"><?php echo $usage['sensors_count']; ?> / <?php echo $userTier['max_sensors']; ?></div>
                                    <div class="progress">
                                        <div class="progress-bar" role="progressbar" 
                                             style="width: <?php echo min(100, ($usage['sensors_count'] / $userTier['max_sensors']) * 100); ?>%">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="usage-card">
                                    <div class="usage-title">Email Subscribers</div>
                                    <div class="usage-value"><?php echo $usage['email_subscribers_count']; ?> / <?php echo $userTier['max_email_subscribers']; ?></div>
                                    <div class="progress">
                                        <div class="progress-bar" role="progressbar" 
                                             style="width: <?php echo min(100, ($usage['email_subscribers_count'] / $userTier['max_email_subscribers']) * 100); ?>%">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Quick Actions</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3">
                                <button type="button" class="btn btn-primary w-100 mb-2 <?php echo $usage['status_pages_count'] >= $userTier['max_status_pages'] ? 'btn-disabled' : ''; ?>" 
                                        data-bs-toggle="modal" data-bs-target="#addStatusPageModal"
                                        <?php echo $usage['status_pages_count'] >= $userTier['max_status_pages'] ? 'disabled' : ''; ?>>
                                    <i class="bi bi-plus-circle"></i> Create Status Page
                                </button>
                            </div>
                            <div class="col-md-3">
                                <button type="button" class="btn btn-success w-100 mb-2 <?php echo $usage['sensors_count'] >= $userTier['max_sensors'] ? 'btn-disabled' : ''; ?>" 
                                        data-bs-toggle="modal" data-bs-target="#addSensorModal"
                                        <?php echo $usage['sensors_count'] >= $userTier['max_sensors'] ? 'disabled' : ''; ?>>
                                    <i class="bi bi-plus-circle"></i> Add Sensor
                                </button>
                            </div>
                            <div class="col-md-3">
                                <button type="button" class="btn btn-warning w-100 mb-2" data-bs-toggle="modal" data-bs-target="#addMaintenanceModal">
                                    <i class="bi bi-tools"></i> Schedule Maintenance
                                </button>
                            </div>
                            <div class="col-md-3">
                                <button type="button" class="btn btn-danger w-100 mb-2" data-bs-toggle="modal" data-bs-target="#addIncidentModal">
                                    <i class="bi bi-exclamation-triangle"></i> Report Incident
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Add Status Page Modal -->
        <div class="modal fade" id="addStatusPageModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Create Status Page</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <div class="form-group">
                                <label for="page_title">Seiten-Titel</label>
                                <input type="text" id="page_title" name="page_title" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label for="sensor_ids">Sensoren auswählen (Mehrfachauswahl möglich)</label>
                                <select id="sensor_ids" name="sensor_ids[]" class="form-control" multiple>
                                    <?php foreach($sensors as $sensor): ?>
                                        <option value="<?php echo $sensor['id']; ?>"><?php echo htmlspecialchars($sensor['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="custom_css">Benutzerdefiniertes CSS (optional)</label>
                                <textarea id="custom_css" name="custom_css" class="form-control"></textarea>
                            </div>
                            <button type="submit" name="add_statuspage" class="btn btn-primary">Create</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Add Sensor Modal -->
        <div class="modal fade" id="addSensorModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Add Sensor</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <div class="form-group">
                <label for="name">Service Name</label>
                                <input type="text" id="name" name="name" class="form-control" required>
            </div>
            <div class="form-group">
                <label for="url">Service URL</label>
                                <input type="url" id="url" name="url" class="form-control" required>
            </div>
            <div class="form-group">
                <label for="sensor_type">Sensor Typ</label>
                                <select id="sensor_type" name="sensor_type" class="form-control" required>
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
                                <input type="text" id="sensor_config" name="sensor_config" class="form-control" placeholder="z. B. erlaubte HTTP-Codes, Portnummer etc." required>
            </div>
                            <button type="submit" name="add_service" class="btn btn-primary">Add</button>
        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Add Maintenance Modal -->
        <div class="modal fade" id="addMaintenanceModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Schedule Maintenance</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <div class="form-group">
                                <label for="description">Beschreibung</label>
                                <input type="text" id="description" name="description" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label for="start_date">Start Datum</label>
                                <input type="date" id="start_date" name="start_date" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label for="start_time">Start Zeit</label>
                                <input type="time" id="start_time" name="start_time" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label for="end_date">Ende Datum</label>
                                <input type="date" id="end_date" name="end_date" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label for="end_time">Ende Zeit</label>
                                <input type="time" id="end_time" name="end_time" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label for="service_id">Service</label>
                                <select id="service_id" name="service_id" class="form-control" required>
                                    <option value="">-- Bitte Service wählen --</option>
                                    <?php foreach($sensors as $sensor): ?>
                                        <option value="<?php echo $sensor['id']; ?>"><?php echo htmlspecialchars($sensor['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" name="add_maintenance" class="btn btn-primary">Schedule</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Add Incident Modal -->
        <div class="modal fade" id="addIncidentModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Report Incident</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <div class="form-group">
                                <label for="incident_description">Beschreibung</label>
                                <input type="text" id="incident_description" name="incident_description" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label for="incident_date">Datum</label>
                                <input type="date" id="incident_date" name="incident_date" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label for="incident_time">Zeit</label>
                                <input type="time" id="incident_time" name="incident_time" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label for="service_id">Service</label>
                                <select id="service_id" name="service_id" class="form-control" required>
                                    <option value="">-- Bitte Service wählen --</option>
                                    <?php foreach($sensors as $sensor): ?>
                                        <option value="<?php echo $sensor['id']; ?>"><?php echo htmlspecialchars($sensor['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" name="add_incident" class="btn btn-primary">Report</button>
        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Bestehende Services -->
        <div class="section">
        <h2>Bestehende Services</h2>
            <div class="table-responsive">
                <table class="table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>URL</th>
                    <th>Sensor Typ</th>
                            <th>Status</th>
                            <th>Last Check</th>
                    <th>Aktionen</th>
                </tr>
            </thead>
            <tbody>
                        <?php foreach ($sensors as $sensor): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($sensor['name']); ?></td>
                                <td><?php echo htmlspecialchars($sensor['url']); ?></td>
                                <td><?php echo htmlspecialchars($sensor['sensor_type']); ?></td>
                                <td>
                                    <span class="badge bg-<?php echo $sensor['last_status'] ? 'success' : 'danger'; ?>">
                                        <?php echo $sensor['last_status'] ? 'Up' : 'Down'; ?>
                                    </span>
                                </td>
                                <td><?php echo $sensor['last_check'] ? date('Y-m-d H:i:s', strtotime($sensor['last_check'])) : 'Never'; ?></td>
                                <td>
                                    <a href="edit_sensor.php?id=<?php echo $sensor['id']; ?>" class="btn btn-sm btn-primary">Edit</a>
                                    <a href="?delete_service=<?php echo $sensor['id']; ?>&confirm=true" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure?')">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
            </div>
        </div>

        <!-- Maintenance History -->
        <div class="section">
            <h2>Maintenance History</h2>
            <div class="table-responsive">
                <table class="table">
                <thead>
                    <tr>
                        <th>Datum</th>
                        <th>Service</th>
                        <th>Beschreibung</th>
                        <th>Status</th>
                        <th>Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                        <?php foreach ($maintenanceHistory as $maintenance): ?>
                            <tr>
                                <td><?php echo date('Y-m-d H:i:s', strtotime($maintenance['date'])); ?></td>
                                <td><?php echo htmlspecialchars($maintenance['service_name'] ?? 'All Services'); ?></td>
                                <td><?php echo htmlspecialchars($maintenance['description']); ?></td>
                                <td>
                                    <span class="badge bg-<?php 
                                        echo $maintenance['status'] === 'completed' ? 'success' : 
                                            ($maintenance['status'] === 'scheduled' ? 'warning' : 'danger'); 
                                    ?>">
                                        <?php echo ucfirst($maintenance['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="edit_maintenance.php?id=<?php echo $maintenance['id']; ?>" class="btn btn-sm btn-primary">Edit</a>
                                    <a href="?delete_maintenance=<?php echo $maintenance['id']; ?>&confirm=true" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure?')">Delete</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>

        <!-- Recent Incidents -->
        <div class="section">
            <h2>Recent Incidents</h2>
            <div class="table-responsive">
                <table class="table">
                <thead>
                    <tr>
                        <th>Datum</th>
                        <th>Service</th>
                        <th>Beschreibung</th>
                        <th>Status</th>
                        <th>Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                        <?php foreach ($recentIncidents as $incident): ?>
                        <tr>
                                <td><?php echo date('Y-m-d H:i:s', strtotime($incident['date'])); ?></td>
                                <td><?php echo htmlspecialchars($incident['service_name'] ?? 'All Services'); ?></td>
                            <td><?php echo htmlspecialchars($incident['description']); ?></td>
                                <td>
                                    <span class="badge bg-<?php 
                                        echo $incident['status'] === 'resolved' ? 'success' : 
                                            ($incident['status'] === 'in progress' ? 'warning' : 'danger'); 
                                    ?>">
                                        <?php echo ucfirst($incident['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="edit_incident.php?id=<?php echo $incident['id']; ?>" class="btn btn-sm btn-primary">Edit</a>
                                    <a href="?delete_incident=<?php echo $incident['id']; ?>&confirm=true" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure?')">Delete</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        </div>

        <!-- Status Pages -->
<div class="section">
            <h2>Status Pages</h2>
            <div class="table-responsive">
                <table class="table">
        <thead>
            <tr>
                <th>Titel</th>
                            <th>Sensoren</th>
                            <th>Subscribers</th>
                            <th>Aktionen</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($statusPages as $page): ?>
            <tr>
                <td><?php echo htmlspecialchars($page['page_title']); ?></td>
                                <td><?php echo $page['sensor_count']; ?></td>
                                <td><?php echo $page['subscriber_count']; ?></td>
                                <td>
                                    <a href="status_page.php?status_page_uuid=<?php echo $page['uuid']; ?>" class="btn btn-sm btn-info">View</a>
                                    <a href="edit_status_page.php?id=<?php echo $page['id']; ?>" class="btn btn-sm btn-primary">Edit</a>
                                    <a href="?delete_status_page=<?php echo $page['id']; ?>&confirm=true" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure? This will also delete all subscriptions.')">Delete</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
    </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
