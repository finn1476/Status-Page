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
                    $maintenance_id = $pdo->lastInsertId();
                    $message = "Wartungseintrag erfolgreich hinzugefügt!";
                    
                    // Sende E-Mail-Benachrichtigungen
                    if ($maintenance_id) {
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
                                $emailNotifier->sendMaintenanceNotification($maintenance_id, $status_page_id);
                            }
                            
                            $message .= " E-Mail-Benachrichtigungen wurden gesendet.";
                        }
                    }
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
        
        // Finde die Standard-Status-Page des Benutzers oder die erste verfügbare
        $stmt = $pdo->prepare("SELECT id FROM status_pages WHERE user_id = ? ORDER BY id ASC LIMIT 1");
        $stmt->execute([$_SESSION['user_id']]);
        $status_page = $stmt->fetch(PDO::FETCH_ASSOC);
        $status_page_id = $status_page ? $status_page['id'] : 0;
        
        if ($status_page_id) {
            // Verwende die Beschreibung auch als Titel
            $title = $incidentDescription;
            
            // Füge den Incident mit status_page_id und title hinzu
            $stmt = $pdo->prepare("INSERT INTO incidents (title, description, date, status, service_id, user_id, status_page_id, impact) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$title, $incidentDescription, $datetime, 'reported', $service_id, $_SESSION['user_id'], $status_page_id, 'minor']);
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
            $error = "Keine Status Page gefunden. Bitte erstellen Sie zuerst eine Status Page.";
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

            if (isset($_POST['add_domain'])) {
                $domain = clean_input($_POST['domain'] ?? '');
                $status_page_id = clean_input($_POST['domain_status_page_id'] ?? '');
                
                if ($domain && $status_page_id) {
                    $stmt = $pdo->prepare("INSERT INTO custom_domains (domain, status_page_id, user_id) VALUES (?, ?, ?)");
                    $stmt->execute([$domain, $status_page_id, $_SESSION['user_id']]);
                    $message = "Domain erfolgreich hinzugefügt!";
                } else {
                    $message = "Bitte alle Felder ausfüllen.";
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
                isset($_GET['delete_incident']) || isset($_GET['delete_status_page']) || isset($_GET['delete_domain'])) && 
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

            if (isset($_GET['delete_domain']) && isset($_GET['confirm']) && $_GET['confirm'] === 'true') {
                $id = $_GET['delete_domain'] ?? '';
                if ($id) {
                    $stmt = $pdo->prepare("DELETE FROM custom_domains WHERE id = ? AND user_id = ?");
                    $stmt->execute([$id, $user_id_for_action]);
                    if ($stmt->rowCount() > 0) {
                        $message = "Domain erfolgreich gelöscht!";
                    } else {
                        $error = "Domain nicht gefunden oder keine Berechtigung!";
                    }
                }
            }

            if (isset($_GET['verify_domain'])) {
                $domain_id = (int)$_GET['verify_domain'];
                
                // Prüfe, ob die Domain dem Benutzer gehört
                $stmt = $pdo->prepare("
                    SELECT cd.* FROM custom_domains cd
                    WHERE cd.id = ? AND cd.user_id = ?
                ");
                $stmt->execute([$domain_id, $_SESSION['user_id']]);
                $domain = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($domain) {
                    // Führe die CNAME-Überprüfung durch
                    $domain_name = $domain['domain'];
                    $expected_target = $_SERVER['HTTP_HOST'];
                    
                    // DNS-Lookup durchführen
                    $dns_records = dns_get_record($domain_name, DNS_CNAME);
                    $verified = false;
                    
                    foreach ($dns_records as $record) {
                        if (isset($record['target']) && ($record['target'] === $expected_target || $record['target'] === $expected_target . '.')) {
                            $verified = true;
                            break;
                        }
                    }
                    
                    if ($verified) {
                        // Domain als verifiziert markieren
                        $stmt = $pdo->prepare("UPDATE custom_domains SET verified = 1 WHERE id = ?");
                        $stmt->execute([$domain_id]);
                        $message = "Domain erfolgreich verifiziert!";

                        // Certbot-Script im Hintergrund ausführen
                        $domain_name = escapeshellarg($domain_name);
                        $email = escapeshellarg($_SESSION['user_email']);
                        $cmd = "/var/www/html/scripts/certbot_request.sh $domain_name $email > /dev/null 2>&1 &";
                        exec($cmd);
                        
                        $message .= " Ein SSL-Zertifikat wird im Hintergrund angefordert. Dies kann einige Minuten dauern.";
                    } else {
                        $error = "Die Domain konnte nicht verifiziert werden. Bitte stellen Sie sicher, dass der CNAME-Eintrag korrekt eingerichtet ist und auf {$expected_target} zeigt.";
                    }
                } else {
                    $error = "Domain nicht gefunden oder keine Berechtigung!";
                }
            }

            if (isset($_GET['request_ssl'])) {
                $domain_id = (int)$_GET['request_ssl'];
                
                // Prüfe, ob die Domain dem Benutzer gehört und verifiziert ist
                $stmt = $pdo->prepare("
                    SELECT cd.* FROM custom_domains cd
                    WHERE cd.id = ? AND cd.user_id = ? AND cd.verified = 1
                ");
                $stmt->execute([$domain_id, $_SESSION['user_id']]);
                $domain = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($domain) {
                    // SSL-Status auf "pending" setzen
                    $stmt = $pdo->prepare("UPDATE custom_domains SET ssl_status = 'pending' WHERE id = ?");
                    $stmt->execute([$domain_id]);
                    
                    // Certbot-Script im Hintergrund ausführen
                    $domain_name = escapeshellarg($domain['domain']);
                    $email = escapeshellarg($_SESSION['user_email']);
                    $cmd = "/var/www/html/scripts/certbot_request.sh $domain_name $email > /dev/null 2>&1 &";
                    exec($cmd);
                    
                    $message = "SSL-Zertifikatsanforderung für die Domain {$domain['domain']} wurde gestartet. Dies kann einige Minuten dauern.";
                } else {
                    $error = "Domain nicht gefunden, nicht verifiziert oder keine Berechtigung!";
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

        <!-- Main Content Tabs -->
        <ul class="nav nav-pills nav-fill mb-4">
            <li class="nav-item">
                <a class="nav-link" href="#overview">Overview</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="#sensors_tab">Sensors</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="#incidents_tab">Incidents</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="#maintenance_tab">Maintenance</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="#status_pages_tab">Status Pages</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="#domains_tab">Domains</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="#settings_tab">Settings</a>
            </li>
        </ul>

        <!-- Content Sections -->
        <!-- Overview Section -->
        <div id="overview" class="mb-5">
            <h2 class="border-bottom pb-2 mb-4">Overview</h2>
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
        </div>

        <!-- Sensors Section -->
        <div id="sensors_tab" class="mb-5">
            <h2 class="border-bottom pb-2 mb-4">Sensors</h2>
            <div class="card shadow-sm mb-4">
                <div class="card-header">
                    <h5 class="m-0">Sensors</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>URL</th>
                                    <th>Sensor Type</th>
                                    <th>Status</th>
                                    <th>Last Check</th>
                                    <th>Actions</th>
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
            </div>
        </div>

        <!-- Incidents Section -->
        <div id="incidents_tab" class="mb-5">
            <h2 class="border-bottom pb-2 mb-4">Incidents</h2>
            <div class="card shadow-sm mb-4">
                <div class="card-header">
                    <h5 class="m-0">Incidents</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Service</th>
                                    <th>Description</th>
                                    <th>Status</th>
                                    <th>Actions</th>
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
            </div>
        </div>

        <!-- Maintenance Section -->
        <div id="maintenance_tab" class="mb-5">
            <h2 class="border-bottom pb-2 mb-4">Maintenance</h2>
            <div class="card shadow-sm mb-4">
                <div class="card-header">
                    <h5 class="m-0">Maintenance</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Service</th>
                                    <th>Description</th>
                                    <th>Status</th>
                                    <th>Actions</th>
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
            </div>
        </div>

        <!-- Status Pages Section -->
        <div id="status_pages_tab" class="mb-5">
            <h2 class="border-bottom pb-2 mb-4">Status Pages</h2>
            <div class="card shadow-sm mb-4">
                <div class="card-header">
                    <h5 class="m-0">Status Pages</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Title</th>
                                    <th>Sensors</th>
                                    <th>Subscribers</th>
                                    <th>Actions</th>
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
        </div>

        <!-- Custom Domains Section -->
        <div id="domains_tab" class="mb-5">
            <h2 class="border-bottom pb-2 mb-4">Custom Domains</h2>
            <div class="card shadow-sm">
                <div class="card-header">
                    <h5 class="m-0">Custom Domains</h5>
                </div>
                <div class="card-body">
                    <!-- Domain hinzufügen -->
                    <form method="POST" class="mb-4">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <div class="row align-items-end">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="domain">Domain:</label>
                                    <input type="text" name="domain" id="domain" class="form-control" placeholder="e.g. status.mydomain.com" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="domain_status_page_id">Status Page:</label>
                                    <select name="domain_status_page_id" id="domain_status_page_id" class="form-control" required>
                                        <?php 
                                        // Status Pages des Benutzers abrufen
                                        $stmt = $pdo->prepare("SELECT id, page_title FROM status_pages WHERE user_id = ?");
                                        $stmt->execute([$_SESSION['user_id']]);
                                        $user_status_pages = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                        
                                        if (empty($user_status_pages)) {
                                            echo '<option value="" disabled>No Status Pages available</option>';
                                        } else {
                                            foreach ($user_status_pages as $page) {
                                                echo '<option value="' . $page['id'] . '">' . htmlspecialchars($page['page_title']) . '</option>';
                                            }
                                        }
                                        ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <button type="submit" name="add_domain" class="btn btn-success w-100">Add Domain</button>
                            </div>
                        </div>
                    </form>

                    <h6 class="mb-3 mt-4 border-bottom pb-2">Instructions for setting up your own domain:</h6>
                    <ol class="mb-4">
                        <li>Add your domain above (e.g. status.mydomain.com)</li>
                        <li>Create a CNAME record in your DNS that points to <strong><?php echo $_SERVER['HTTP_HOST']; ?></strong></li>
                        <li>Wait for DNS changes to take effect (may take up to 24 hours)</li>
                        <li>Click "Verify" to check if the CNAME is set up correctly</li>
                        <li>After successful verification, your domain is ready to use!</li>
                    </ol>

                    <!-- Bestehende Domains anzeigen -->
                    <h6 class="border-bottom pb-2 mb-4">Your Domains</h6>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Domain</th>
                                    <th>Status Page</th>
                                    <th>Domain Status</th>
                                    <th>SSL Status</th>
                                    <th>Added on</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                // Benutzerdefinierte Domains abrufen
                                $stmt = $pdo->prepare("
                                    SELECT cd.*, sp.page_title 
                                    FROM custom_domains cd
                                    JOIN status_pages sp ON cd.status_page_id = sp.id
                                    WHERE cd.user_id = ?
                                    ORDER BY cd.created_at DESC
                                ");
                                $stmt->execute([$_SESSION['user_id']]);
                                $domains = $stmt->fetchAll(PDO::FETCH_ASSOC);

                                if (empty($domains)) {
                                    echo '<tr><td colspan="6" class="text-center">No custom domains available</td></tr>';
                                } else {
                                    foreach ($domains as $domain) {
                                        $status = $domain['verified'] ? 
                                            '<span class="badge bg-success">Verified</span>' : 
                                            '<span class="badge bg-warning text-dark">Not verified</span>';
                                        
                                        // SSL-Status bestimmen
                                        $ssl_status = '';
                                        if (!$domain['verified']) {
                                            $ssl_status = '<span class="badge bg-secondary">Not available</span>';
                                        } else {
                                            switch ($domain['ssl_status']) {
                                                case 'active':
                                                    $ssl_status = '<span class="badge bg-success">Active</span>';
                                                    break;
                                                case 'failed':
                                                    $ssl_status = '<span class="badge bg-danger">Failed</span>';
                                                    break;
                                                default: // 'pending'
                                                    $ssl_status = '<span class="badge bg-info">Pending</span>';
                                            }
                                        }
                                        
                                        echo '<tr>';
                                        echo '<td>' . htmlspecialchars($domain['domain']) . '</td>';
                                        echo '<td>' . htmlspecialchars($domain['page_title']) . '</td>';
                                        echo '<td>' . $status . '</td>';
                                        echo '<td>' . $ssl_status . '</td>';
                                        echo '<td>' . date('Y-m-d H:i', strtotime($domain['created_at'])) . '</td>';
                                        echo '<td>';
                                        
                                        if (!$domain['verified']) {
                                            echo '<a href="?verify_domain=' . $domain['id'] . '" class="btn btn-sm btn-primary me-1">Verify</a>';
                                        } else if ($domain['ssl_status'] !== 'active') {
                                            echo '<a href="?request_ssl=' . $domain['id'] . '" class="btn btn-sm btn-success me-1">Request SSL</a>';
                                        }
                                        
                                        echo '<a href="?delete_domain=' . $domain['id'] . '" class="btn btn-sm btn-danger" onclick="return confirm(\'Are you sure you want to delete this domain?\');">Delete</a>';
                                        echo '</td>';
                                        echo '</tr>';
                                    }
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Settings Section -->
        <div id="settings_tab" class="mb-5">
            <h2 class="border-bottom pb-2 mb-4">Settings</h2>
            <div class="card shadow-sm">
                <div class="card-header">
                    <h5 class="m-0">Settings</h5>
                </div>
                <div class="card-body">
                    <form method="POST" class="mb-4">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <div class="form-group mb-3">
                            <label for="email">Email Address:</label>
                            <input type="email" name="email" id="email" class="form-control" value="<?php echo htmlspecialchars($_SESSION['user_email']); ?>" required>
                        </div>
                        <div class="form-group mb-3">
                            <label for="password">New Password (leave empty to keep current):</label>
                            <input type="password" name="password" id="password" class="form-control">
                        </div>
                        <div class="form-group mb-3">
                            <label for="password_confirm">Confirm New Password:</label>
                            <input type="password" name="password_confirm" id="password_confirm" class="form-control">
                        </div>
                        <button type="submit" name="update_settings" class="btn btn-primary">Save Settings</button>
                    </form>
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
                            <label for="page_title">Page Title</label>
                            <input type="text" id="page_title" name="page_title" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="sensor_ids">Select Sensors (Multiple Selection)</label>
                            <select id="sensor_ids" name="sensor_ids[]" class="form-control" multiple>
                                <?php foreach($sensors as $sensor): ?>
                                    <option value="<?php echo $sensor['id']; ?>"><?php echo htmlspecialchars($sensor['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="custom_css">Custom CSS (optional)</label>
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
                            <label for="sensor_type">Sensor Type</label>
                            <select id="sensor_type" name="sensor_type" class="form-control" required>
                                <option value="">-- Please select sensor type --</option>
                                <option value="http">HTTP</option>
                                <option value="ping">Ping</option>
                                <option value="port">Port Check</option>
                                <option value="dns">DNS Check</option>
                                <option value="smtp">SMTP Check</option>
                                <option value="custom">Custom</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="sensor_config">Sensor Configuration</label>
                            <input type="text" id="sensor_config" name="sensor_config" class="form-control" placeholder="e.g. allowed HTTP codes, port number, etc." required>
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
                            <label for="description">Description</label>
                            <input type="text" id="description" name="description" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="start_date">Start Date</label>
                            <input type="date" id="start_date" name="start_date" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="start_time">Start Time</label>
                            <input type="time" id="start_time" name="start_time" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="end_date">End Date</label>
                            <input type="date" id="end_date" name="end_date" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="end_time">End Time</label>
                            <input type="time" id="end_time" name="end_time" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="service_id">Service</label>
                            <select id="service_id" name="service_id" class="form-control" required>
                                <option value="">-- Please select service --</option>
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
                            <label for="incident_description">Description</label>
                            <input type="text" id="incident_description" name="incident_description" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="incident_date">Date</label>
                            <input type="date" id="incident_date" name="incident_date" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="incident_time">Time</label>
                            <input type="time" id="incident_time" name="incident_time" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="service_id">Service</label>
                            <select id="service_id" name="service_id" class="form-control" required>
                                <option value="">-- Please select service --</option>
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.7.0/chart.min.js"></script>
    
    <script>
        // Navigation scroll functionality
        document.addEventListener('DOMContentLoaded', function() {
            // Handle smooth scrolling for navigation links
            const navLinks = document.querySelectorAll('.nav-pills .nav-link');
            
            // Set the first link as active initially
            if (navLinks.length > 0) {
                navLinks[0].classList.add('active');
            }
            
            navLinks.forEach(link => {
                link.addEventListener('click', function(e) {
                    // Prevent default anchor behavior
                    e.preventDefault();
                    
                    // Remove active class from all links
                    navLinks.forEach(l => l.classList.remove('active'));
                    
                    // Add active class to clicked link
                    this.classList.add('active');
                    
                    // Get the target section
                    const targetId = this.getAttribute('href');
                    const targetSection = document.querySelector(targetId);
                    
                    if (targetSection) {
                        // Scroll to the section with smooth behavior
                        window.scrollTo({
                            top: targetSection.offsetTop - 70, // Offset for navbar height
                            behavior: 'smooth'
                        });
                        
                        // Update URL hash without triggering scroll
                        history.pushState(null, null, targetId);
                    }
                });
            });
            
            // Check for hash in URL on page load
            if (window.location.hash) {
                const hash = window.location.hash;
                const targetSection = document.querySelector(hash);
                
                if (targetSection) {
                    // Activate corresponding nav link
                    const correspondingLink = document.querySelector(`.nav-link[href="${hash}"]`);
                    if (correspondingLink) {
                        navLinks.forEach(l => l.classList.remove('active'));
                        correspondingLink.classList.add('active');
                        
                        // Scroll to section after a small delay to ensure page is fully loaded
                        setTimeout(() => {
                            window.scrollTo({
                                top: targetSection.offsetTop - 70,
                                behavior: 'smooth'
                            });
                        }, 100);
                    }
                }
            }
            
            // Update active nav item on scroll
            window.addEventListener('scroll', function() {
                let current = '';
                const sections = document.querySelectorAll('div[id]');
                
                sections.forEach(section => {
                    const sectionTop = section.offsetTop;
                    
                    if (window.pageYOffset >= sectionTop - 100) {
                        current = section.getAttribute('id');
                    }
                });
                
                navLinks.forEach(link => {
                    link.classList.remove('active');
                    if (link.getAttribute('href') === '#' + current) {
                        link.classList.add('active');
                    }
                });
            });
        });
    </script>
</body>
</html>
