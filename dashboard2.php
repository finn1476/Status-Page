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
        SELECT 
            c.id, 
            c.name, 
            c.url, 
            c.sensor_type, 
            c.ssl_expiry_date,
            (SELECT status FROM uptime_checks WHERE service_url = c.url ORDER BY check_time DESC LIMIT 1) as last_status,
            (SELECT check_time FROM uptime_checks WHERE service_url = c.url ORDER BY check_time DESC LIMIT 1) as last_check,
            (SELECT response_time FROM uptime_checks WHERE service_url = c.url ORDER BY check_time DESC LIMIT 1) as last_response_time,
            (SELECT AVG(response_time) FROM uptime_checks WHERE service_url = c.url AND check_time >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) as avg_response_time
        FROM 
            config c
        WHERE 
            c.user_id = ?
        ORDER BY 
            c.name ASC
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
                    $message = "Service successfully added!";
    } else {
                    $message = "Please fill in all fields.";
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
                    $message = "Maintenance entry successfully added!";
                    
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
                            
                            $message .= " Email notifications have been sent.";
                        }
                    }
    } else {
                    $message = "Please fill in all fields.";
    }
}

            if (isset($_POST['add_incident'])) {
    $incidentDescription = clean_input($_POST['incident_description'] ?? '');
    $incidentDate = clean_input($_POST['incident_date'] ?? '');
    $incidentTime = clean_input($_POST['incident_time'] ?? '');
    $service_id = clean_input($_POST['service_id'] ?? '');
    $impact = clean_input($_POST['impact'] ?? 'minor');
    
    // Sicherstellen, dass Impact ein gültiger Wert ist
    $validImpacts = ['minor', 'major', 'critical'];
    if (!in_array($impact, $validImpacts)) {
        $impact = 'minor'; // Standardwert als Fallback
    }
    
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
                        $stmt->execute([$title, $incidentDescription, $datetime, 'reported', $service_id, $_SESSION['user_id'], $status_page_id, $impact]);
                        $incident_id = $pdo->lastInsertId();
                        $message = "Incident successfully added!";
                        
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
                                
                                $message .= " Email notifications have been sent.";
                            }
                        }
    } else {
                        $error = "No status page found. Please create a status page first.";
                    }
                } else {
                    $message = "Please fill in all fields.";
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
                    $message = "Status page successfully created!";
    } else {
                    $message = "Please provide a title for the status page.";
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
                    
                    $message = "Service successfully deleted!";
                }
            }

            if (isset($_POST['add_domain'])) {
                $domain = clean_input($_POST['domain'] ?? '');
                $status_page_id = clean_input($_POST['domain_status_page_id'] ?? '');
                
                if ($domain && $status_page_id) {
                    $stmt = $pdo->prepare("INSERT INTO custom_domains (domain, status_page_id, user_id) VALUES (?, ?, ?)");
                    $stmt->execute([$domain, $status_page_id, $_SESSION['user_id']]);
                    $message = "Domain successfully added!";
    } else {
                    $message = "Please fill in all fields.";
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
                            $message = "Service successfully deleted!";
                        } else {
                            $error = "Service not found or insufficient permissions!";
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
                        $message = "Maintenance entry successfully deleted!";
                    } else {
                        $error = "Maintenance entry not found or insufficient permissions!";
                    }
                }
            }

            if (isset($_GET['delete_incident']) && isset($_GET['confirm']) && $_GET['confirm'] === 'true') {
    $id = $_GET['delete_incident'] ?? '';
    if ($id) {
        $stmt = $pdo->prepare("DELETE FROM incidents WHERE id = ? AND user_id = ?");
                    $stmt->execute([$id, $user_id_for_action]);
                    if ($stmt->rowCount() > 0) {
                        $message = "Incident successfully deleted!";
                    } else {
                        $error = "Incident not found or insufficient permissions!";
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
                        $message = "Status Page successfully deleted!";
    } else {
                        $error = "Status Page not found or insufficient permissions!";
                    }
                }
            }

            if (isset($_GET['delete_domain']) && isset($_GET['confirm']) && $_GET['confirm'] === 'true') {
                $id = $_GET['delete_domain'] ?? '';
                if ($id) {
                    $stmt = $pdo->prepare("DELETE FROM custom_domains WHERE id = ? AND user_id = ?");
                    $stmt->execute([$id, $user_id_for_action]);
        if ($stmt->rowCount() > 0) {
                        $message = "Domain successfully deleted!";
        } else {
                        $error = "Domain not found or insufficient permissions!";
                    }
                }
            }

            if (isset($_GET['verify_domain'])) {
                $domain_id = (int)$_GET['verify_domain'];
                
                // Prüfe, ob die letzte Überprüfung weniger als 5 Minuten her ist
                $last_check = isset($_SESSION['last_domain_check_' . $domain_id]) ? $_SESSION['last_domain_check_' . $domain_id] : 0;
                $current_time = time();
                
                if ($current_time - $last_check < 300 && $last_check > 0) {
                    $remaining = 300 - ($current_time - $last_check);
                    $error = "Please wait at least 5 minutes between verification attempts. Remaining time: " . floor($remaining / 60) . " minutes and " . ($remaining % 60) . " seconds.";
    } else {
                    // Aktualisiere den Zeitstempel der letzten Überprüfung
                    $_SESSION['last_domain_check_' . $domain_id] = $current_time;
                    
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
                            if (isset($record['target']) && (
                                $record['target'] === $expected_target || 
                                $record['target'] === $expected_target . '.'
                            )) {
                                $verified = true;
                                break;
                            }
                        }
                        
                        if ($verified) {
                            // Domain als verifiziert markieren
                            $stmt = $pdo->prepare("UPDATE custom_domains SET verified = 1 WHERE id = ?");
                            $stmt->execute([$domain_id]);
                            $message = "Domain successfully verified!";

                            // Check if SSL certificate is already available in the certbot directory
                            $cert_path = "/var/www/html/certbot/config/live/{$domain_name}/fullchain.pem";
                            $key_path = "/var/www/html/certbot/config/live/{$domain_name}/privkey.pem";
                            
                            if (file_exists($cert_path) && file_exists($key_path)) {
                                // If the certificate already exists, mark SSL as active
                                $stmt = $pdo->prepare("UPDATE custom_domains SET ssl_status = 'active' WHERE id = ?");
                                $stmt->execute([$domain_id]);
                                $message .= " A valid SSL certificate was found and activated.";
        } else {
                                // Request new certificate
                                // Certbot-Script im Hintergrund ausführen
                                $domain_name = escapeshellarg($domain_name);
                                $email = escapeshellarg($_SESSION['user_email']);
                                $cmd = "/var/www/html/scripts/certbot_request.sh $domain_name $email > /dev/null 2>&1 &";
                                exec($cmd);
                                
                                // Update SSL status to pending
                                $stmt = $pdo->prepare("UPDATE custom_domains SET ssl_status = 'pending' WHERE id = ?");
                                $stmt->execute([$domain_id]);
                                
                                $message .= " An SSL certificate is being requested in the background. This may take a few minutes.";
                            }
                        } else {
                            // Füge Details zu den gefundenen CNAME-Einträgen hinzu
                            $found_records = '';
                            if (empty($dns_records)) {
                                $found_records = "No CNAME records found.";
                            } else {
                                $found_records = "Found CNAME records: ";
                                foreach ($dns_records as $index => $record) {
                                    if (isset($record['target'])) {
                                        $found_records .= $record['target'];
                                        if ($index < count($dns_records) - 1) {
                                            $found_records .= ", ";
                                        }
                                    }
                                }
                            }
                            
                            $error = "The domain could not be verified. Please ensure that the CNAME record is correctly configured and points to {$expected_target}. {$found_records}<br><br>
                            <strong>Note:</strong> DNS changes can take up to 24 hours to fully propagate. Please try again later.";
                        }
                    } else {
                        $error = "Domain not found or insufficient permissions!";
                    }
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
                    // Check if SSL certificate is already available
                    $domain_name = $domain['domain'];
                    $cert_path = "/var/www/html/certbot/config/live/{$domain_name}/fullchain.pem";
                    $key_path = "/var/www/html/certbot/config/live/{$domain_name}/privkey.pem";
                    
                    if (file_exists($cert_path) && file_exists($key_path)) {
                        // If the certificate already exists, just mark it as active
                        $stmt = $pdo->prepare("UPDATE custom_domains SET ssl_status = 'active' WHERE id = ?");
                        $stmt->execute([$domain_id]);
                        $message = "SSL certificate for domain {$domain['domain']} is already available and has been activated.";
                    } else {
                        // SSL-Status auf "pending" setzen
                        $stmt = $pdo->prepare("UPDATE custom_domains SET ssl_status = 'pending' WHERE id = ?");
                        $stmt->execute([$domain_id]);
                        
                        // Certbot-Script im Hintergrund ausführen
                        $domain_name = escapeshellarg($domain['domain']);
                        $email = escapeshellarg($_SESSION['user_email']);
                        $cmd = "/var/www/html/scripts/certbot_request.sh $domain_name $email > /dev/null 2>&1 &";
                        exec($cmd);
                        
                        $message = "SSL certificate request for domain {$domain['domain']} has been initiated. This may take a few minutes.";
                        
                        // Create a JavaScript function to check the SSL status periodically
                        echo '<script>
                            // Check SSL status every 10 seconds
                            function checkSSLStatus() {
                                fetch("check_ssl_status.php?domain_id=' . $domain_id . '")
                                    .then(response => response.json())
                                    .then(data => {
                                        if (data.status === "active") {
                                            // Refresh the page to show the updated status
                                            window.location.reload();
                                        } else if (data.status === "pending") {
                                            // Check again in 10 seconds
                                            setTimeout(checkSSLStatus, 10000);
                                        }
                                    });
                            }
                            
                            // Start checking after 5 seconds
                            setTimeout(checkSSLStatus, 5000);
                        </script>';
                    }
                } else {
                    $error = "Domain not found, not verified, or insufficient permissions!";
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
        .response-time-sparkline {
            height: 30px;
            width: 100%;
            margin-top: 5px;
        }
        
        .response-time-sparkline-container {
            display: flex;
            align-items: center;
            margin-top: 8px;
            width: 100%;
        }
        
        .response-time-sparkline-container canvas {
            flex: 1;
            height: 30px;
            min-width: 80px;
        }
        
        .show-chart-btn {
            padding: 3px 8px;
            font-size: 12px;
            width: auto;
            max-width: none;
        }
        
        .show-chart-btn:hover {
            background-color: #0d6efd;
            color: white;
        }
        
        .expand-chart-btn {
            padding: 0;
            margin-left: 5px;
            font-size: 14px;
            color: #6c757d;
        }
        
        .expand-chart-btn:hover {
            color: #0d6efd;
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
            <div class="card mb-4">
                <div class="card-header">
                    <h6 class="m-0 font-weight-bold text-primary">Sensors</h6>
                </div>
                <div class="card-body">
                    <?php if (count($sensors) < $userTier['max_sensors']): ?>
                    <button type="button" class="btn btn-success mb-3" data-bs-toggle="modal" data-bs-target="#addServiceModal">
                        <i class="fas fa-plus"></i> Add Sensor
                    </button>
                    <?php else: ?>
                    <div class="alert alert-warning mb-3">
                        You have reached your sensor limit (<?php echo $userTier['max_sensors']; ?>). Upgrade your plan to add more sensors.
                    </div>
                    <?php endif; ?>
                    
                    <div class="table-responsive">
                        <table class="table table-striped">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>URL</th>
                                    <th>Sensor</th>
                                    <th>Status</th>
                                    <th>Reaktionszeit</th>
                                    <th>SSL Ablauf</th>
                    <th>Aktionen</th>
                </tr>
            </thead>
            <tbody>
                                <?php
                                foreach ($sensors as $sensor) {
                                    // Status-Klasse bestimmen
                                    $statusClass = 'secondary';
                                    if ($sensor['last_status'] === 'up') $statusClass = 'success';
                                    elseif ($sensor['last_status'] === 'down') $statusClass = 'danger';
                                    
                                    // Reaktionszeit formatieren
                                    $responseTime = $sensor['last_response_time'] ? round($sensor['last_response_time'] * 1000, 2) . ' ms' : 'N/A';
                                    
                                    // SSL-Ablaufdatum prüfen und formatieren
                                    $sslWarning = '';
                                    $sslClass = '';
                                    $sslDisplay = 'N/A';
                                    
                                    if ($sensor['ssl_expiry_date']) {
                                        $now = new DateTime();
                                        $expiryDate = new DateTime($sensor['ssl_expiry_date']);
                                        $sslDisplay = $expiryDate->format('d.m.Y');
                                        
                                        $diff = $now->diff($expiryDate);
                                        $daysRemaining = $diff->days;
                                        
                                        if ($expiryDate < $now) {
                                            $sslClass = 'text-danger';
                                            $sslWarning = ' <i class="fas fa-exclamation-triangle" title="SSL-Zertifikat abgelaufen!"></i>';
                                        } elseif ($daysRemaining <= 30) {
                                            $sslClass = 'text-warning';
                                            $sslWarning = ' <i class="fas fa-exclamation-circle" title="SSL-Zertifikat läuft in ' . $daysRemaining . ' Tagen ab!"></i>';
                                        } else {
                                            $sslClass = 'text-success';
                                        }
                                    }
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($sensor['name']); ?></td>
                                    <td><a href="<?php echo htmlspecialchars($sensor['url']); ?>" target="_blank"><?php echo htmlspecialchars($sensor['url']); ?></a></td>
                                    <td><?php echo htmlspecialchars($sensor['sensor_type']); ?></td>
                                    <td><span class="badge bg-<?php echo $statusClass; ?>"><?php echo htmlspecialchars($sensor['last_status']); ?></span></td>
                                    <td>
                                        <?php echo $responseTime; ?>
                                        <div class="d-flex gap-2 mt-1">

                                            <button class="btn btn-sm btn-outline-primary" onclick="loadResponseTimeChart(<?php echo $sensor['id']; ?>, '<?php echo htmlspecialchars($sensor['name']); ?>')" title="Detaillierte Grafik anzeigen">
                                                <i class="bi bi-arrows-fullscreen"></i> Detail-Popup
                                            </button>
                                        </div>
                                        <div class="response-time-sparkline-container" id="chart-container-<?php echo $sensor['id']; ?>" style="display:none;">
                                            <canvas class="response-time-sparkline" id="sparkline-<?php echo $sensor['id']; ?>"></canvas>
                                        </div>
                                    </td>
                                    <td class="<?php echo $sslClass; ?>"><?php echo $sslDisplay . $sslWarning; ?></td>
                                    <td>
                                        <div class="btn-group">
                                            <a href="edit_sensor.php?id=<?php echo $sensor['id']; ?>" class="btn btn-sm btn-primary"><i class="fas fa-edit"></i></a>
                                            <button type="button" class="btn btn-sm btn-danger delete-sensor" data-sensor-id="<?php echo $sensor['id']; ?>"><i class="fas fa-trash"></i></button>
                                        </div>
                        </td>
                    </tr>
                                <?php } ?>
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
                                            echo '<button type="button" class="btn btn-sm btn-info me-1" data-bs-toggle="modal" data-bs-target="#cnameHelpModal" data-domain="' . htmlspecialchars($domain['domain']) . '">Configure CNAME</button>';
                                        } else if ($domain['ssl_status'] !== 'active') {
                                            echo '<a href="?request_ssl=' . $domain['id'] . '" class="btn btn-sm btn-success me-1">Request SSL</a>';
                                        }
                                        
                                        echo '<a href="?delete_domain=' . $domain['id'] . '&confirm=true" class="btn btn-sm btn-danger" onclick="return confirm(\'Are you sure you want to delete this domain?\');">Delete</a>';
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
                            <label for="impact">Impact Severity</label>
                            <select id="impact" name="impact" class="form-control" required>
                                <option value="minor">Minor - Low Impact</option>
                                <option value="major">Major - Significant Impact</option>
                                <option value="critical">Critical - Service Outage</option>
                            </select>
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

    <!-- CNAME Help Modal -->
    <div class="modal fade" id="cnameHelpModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">CNAME Configuration Help</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>To verify your domain, you need to add a CNAME record in your DNS settings.</p>
                    
                    <div class="alert alert-info">
                        <strong>Domain:</strong> <span id="domainName"></span><br>
                        <strong>CNAME Record:</strong> Set to <code><?php echo $_SERVER['HTTP_HOST']; ?></code>
                    </div>
                    
                    <h6>General Steps:</h6>
                    <ol>
                        <li>Log in to your domain registrar or DNS provider</li>
                        <li>Go to the DNS management section</li>
                        <li>Add a new CNAME record for your domain</li>
                        <li>Set the target/value to <code><?php echo $_SERVER['HTTP_HOST']; ?></code></li>
                        <li>Save changes</li>
                        <li>Wait for DNS propagation (can take up to 24 hours)</li>
                    </ol>
                    
                    <h6>Example for Common Providers:</h6>
                    <div class="accordion" id="dnsProviders">
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#godaddy">
                                    GoDaddy
                                </button>
                            </h2>
                            <div id="godaddy" class="accordion-collapse collapse" data-bs-parent="#dnsProviders">
                                <div class="accordion-body">
                                    <ol>
                                        <li>Go to "My Products" > Select your domain</li>
                                        <li>Click "DNS" or "Manage DNS"</li>
                                        <li>Under "Records", find the "Add" button</li>
                                        <li>Select "CNAME" as the record type</li>
                                        <li>For "Host", enter @ or your subdomain</li>
                                        <li>For "Points to", enter <code><?php echo $_SERVER['HTTP_HOST']; ?></code></li>
                                        <li>Set TTL to 1 Hour</li>
                                        <li>Click "Save"</li>
                                    </ol>
                                </div>
                            </div>
                        </div>
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#cloudflare">
                                    Cloudflare
                                </button>
                            </h2>
                            <div id="cloudflare" class="accordion-collapse collapse" data-bs-parent="#dnsProviders">
                                <div class="accordion-body">
                                    <ol>
                                        <li>Log in to Cloudflare</li>
                                        <li>Select your domain</li>
                                        <li>Go to the "DNS" tab</li>
                                        <li>Click "Add Record"</li>
                                        <li>Select "CNAME" as the record type</li>
                                        <li>For "Name", enter @ or your subdomain</li>
                                        <li>For "Target", enter <code><?php echo $_SERVER['HTTP_HOST']; ?></code></li>
                                        <li>Set "Proxy status" to "DNS only" (gray cloud)</li>
                                        <li>Click "Save"</li>
                                    </ol>
                                </div>
                            </div>
                        </div>
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#porkbun">
                                    Porkbun
                                </button>
                            </h2>
                            <div id="porkbun" class="accordion-collapse collapse" data-bs-parent="#dnsProviders">
                                <div class="accordion-body">
                                    <ol>
                                        <li>Log in to your Porkbun account</li>
                                        <li>Go to "Domain Management" and select your domain</li>
                                        <li>Click on "DNS Records"</li>
                                        <li>Under "Add a DNS Record", select "CNAME" from the dropdown</li>
                                        <li>For "Name", enter the subdomain portion (e.g., "status" for status.yourdomain.com) or leave empty for root domain</li>
                                        <li>For "Content", enter <code><?php echo $_SERVER['HTTP_HOST']; ?></code></li>
                                        <li>Set "TTL" to 300 or 3600 seconds</li>
                                        <li>Click "Add Record"</li>
                                    </ol>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Response Time Chart Modal -->
    <div class="modal fade" id="responseTimeChartModal" tabindex="-1" aria-labelledby="responseTimeChartModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="responseTimeChartModalLabel">Response Time History</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="d-flex justify-content-between mb-3">
                        <div class="btn-group" role="group">
                            <button type="button" class="btn btn-outline-primary active" onclick="updateChartPeriod(7)">7 Days</button>
                            <button type="button" class="btn btn-outline-primary" onclick="updateChartPeriod(30)">30 Days</button>
                            <button type="button" class="btn btn-outline-primary" onclick="updateChartPeriod(90)">90 Days</button>
                        </div>
                        <div class="sensor-stats">
                            <div class="badge bg-success me-1">Uptime: <span id="uptime-stat">-</span></div>
                            <div class="badge bg-info">Avg. Response: <span id="avg-response-stat">-</span></div>
                        </div>
                    </div>
                    <div class="chart-container" style="position: relative; height:400px;">
                        <canvas id="responseTimeChart"></canvas>
                    </div>
                    <div class="row mt-4">
                        <div class="col-md-6">
                            <h6>Recent Checks</h6>
                            <div class="table-responsive" style="max-height:200px; overflow-y:auto;">
                                <table class="table table-sm table-hover" id="recent-checks-table">
                                    <thead>
                                        <tr>
                                            <th>Time</th>
                                            <th>Status</th>
                                            <th>Response</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <!-- Filled via JavaScript -->
        </tbody>
    </table>
</div>
    </div>
                        <div class="col-md-6">
                            <h6>Statistics</h6>
                            <div class="row">
                                <div class="col-6">
                                    <div class="card mb-3">
                                        <div class="card-body p-2 text-center">
                                            <div class="small text-muted">Fastest Response</div>
                                            <div class="h5" id="fastest-response">-</div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="card mb-3">
                                        <div class="card-body p-2 text-center">
                                            <div class="small text-muted">Slowest Response</div>
                                            <div class="h5" id="slowest-response">-</div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="card mb-3">
                                        <div class="card-body p-2 text-center">
                                            <div class="small text-muted">Checks</div>
                                            <div class="h5" id="total-checks">-</div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="card mb-3">
                                        <div class="card-body p-2 text-center">
                                            <div class="small text-muted">Outages</div>
                                            <div class="h5" id="total-outages">-</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns@2.0.0/dist/chartjs-adapter-date-fns.bundle.min.js"></script>
    
    <script>
        // Global variables for the chart
        let responseTimeChart = null;
        let currentSensorId = null;
        let currentChartDays = 7;
        let sparklineCharts = {};
        
        // Globale Variable für aktives Modal
        let responseTimeChartModal = null;
        
        // Function to load and display the detailed chart in modal
        function loadResponseTimeChart(sensorId, sensorName) {
            currentSensorId = sensorId;
            
            // Update modal title
            document.getElementById('responseTimeChartModalLabel').textContent = `Response Time History: ${sensorName}`;
            
            // Zerstöre altes Chart falls es existiert
            if (responseTimeChart !== null && typeof responseTimeChart !== 'undefined') {
                responseTimeChart.destroy();
                responseTimeChart = null;
            }
            
            // Show the modal
            const modalEl = document.getElementById('responseTimeChartModal');
            
            // Event-Listener hinzufügen für das Modal-Schließen
            modalEl.addEventListener('hidden.bs.modal', function() {
                // Chart zerstören, wenn Modal geschlossen wird
                if (responseTimeChart !== null && typeof responseTimeChart !== 'undefined') {
                    responseTimeChart.destroy();
                    responseTimeChart = null;
                }
            }, { once: true }); // Nur einmal ausführen
            
            responseTimeChartModal = new bootstrap.Modal(modalEl);
            responseTimeChartModal.show();
            
            // Fetch data and render chart
            fetchResponseTimeData(sensorId, currentChartDays);
        }
        
        // Function to update the chart period
        function updateChartPeriod(days) {
            // Update active button state
            document.querySelectorAll('#responseTimeChartModal .btn-group .btn').forEach(btn => {
                btn.classList.remove('active');
            });
            event.target.classList.add('active');
            
            // Update days and reload data
            currentChartDays = days;
            fetchResponseTimeData(currentSensorId, days);
        }
        
        // Function to fetch response time data from the server
        function fetchResponseTimeData(sensorId, days) {
            fetch(`get_response_times.php?sensor_id=${sensorId}&days=${days}`, {
                method: 'GET',
                credentials: 'same-origin', // Cookies senden für Authentifizierung
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => {
                if (!response.ok) {
                    if (response.status === 401) {
                        throw new Error('Authentifizierungsfehler. Bitte aktualisieren Sie die Seite.');
                    }
                    throw new Error('Serverfehler: ' + response.status);
                }
                return response.json();
            })
                .then(data => {
                if (data.error) {
                    throw new Error(data.error);
                }
                renderResponseTimeChart(data);
                updateResponseTimeStats(data);
                updateRecentChecksTable(data);
            })
            .catch(error => {
                console.error('Error fetching response time data:', error);
                alert('Failed to load response time data: ' + error.message);
            });
        }
        
        // Function to render response time chart
        function renderResponseTimeChart(data) {
            try {
                const ctx = document.getElementById('responseTimeChart');
                if (!ctx) {
                    console.error('Canvas element responseTimeChart not found!');
                    return;
                }
                
                // Zuerst das alte Chart sauber zerstören, falls es existiert
                if (responseTimeChart !== null && typeof responseTimeChart !== 'undefined') {
                    responseTimeChart.destroy();
                    responseTimeChart = null;
                }
                
                // Neue Chart-Context abrufen
                const context = ctx.getContext('2d');
                
                // Prepare data for chart
                const labels = data.map(item => new Date(item.check_time));
                const responseTimes = data.map(item => item.response_time * 1000); // Convert to ms
                const statuses = data.map(item => item.status);
                
                // Create datasets with point colors based on status
                const pointColors = data.map(item => item.status === 'up' ? '#198754' : '#dc3545');
                
                // Create the chart
                responseTimeChart = new Chart(context, {
                    type: 'line',
                    data: {
                        labels: labels,
                        datasets: [
                            {
                                label: 'Response Time (ms)',
                                data: responseTimes,
                                backgroundColor: 'rgba(54, 162, 235, 0.2)',
                                borderColor: 'rgba(54, 162, 235, 1)',
                                borderWidth: 2,
                                tension: 0.2,
                                pointRadius: 3,
                                pointBackgroundColor: pointColors
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: {
                            mode: 'index',
                            intersect: false
                        },
                        plugins: {
                            tooltip: {
                                callbacks: {
                                    title: function(tooltipItems) {
                                        const date = new Date(tooltipItems[0].label);
                                        return date.toLocaleString();
                                    },
                                    label: function(context) {
                                        const index = context.dataIndex;
                                        const status = statuses[index];
                                        return [
                                            `Response Time: ${context.raw.toFixed(2)} ms`,
                                            `Status: ${status === 'up' ? 'Online' : 'Down'}`
                                        ];
                                    }
                                }
                            }
                        },
                        scales: {
                            x: {
                                type: 'time',
                                time: {
                                    unit: 'day',
                                    displayFormats: {
                                        day: 'MMM d'
                                    },
                                    tooltipFormat: 'MMM d, HH:mm'
                                },
                                title: {
                                    display: true,
                                    text: 'Date/Time'
                                }
                            },
                            y: {
                                beginAtZero: true,
                                title: {
                                    display: true,
                                    text: 'Response Time (ms)'
                                }
                            }
                        }
                    }
                });
            } catch (error) {
                console.error('Error rendering response time chart:', error);
                alert('Error rendering chart: ' + error.message);
                
                // Versuche, das Chart vollständig zurückzusetzen
                responseTimeChart = null;
            }
        }
        
        // Function to update response time statistics
        function updateResponseTimeStats(data) {
            if (!data || data.length === 0) {
                document.getElementById('uptime-stat').textContent = 'N/A';
                document.getElementById('avg-response-stat').textContent = 'N/A';
                document.getElementById('fastest-response').textContent = 'N/A';
                document.getElementById('slowest-response').textContent = 'N/A';
                document.getElementById('total-checks').textContent = '0';
                document.getElementById('total-outages').textContent = '0';
                return;
            }
            
            // Calculate statistics
            const responseTimes = data.map(item => item.response_time * 1000);
            const upChecks = data.filter(item => item.status === 'up').length;
            const totalChecks = data.length;
            const uptimePercentage = (upChecks / totalChecks * 100).toFixed(2);
            const avgResponseTime = (responseTimes.reduce((a, b) => a + b, 0) / totalChecks).toFixed(2);
            const fastestResponse = Math.min(...responseTimes).toFixed(2);
            const slowestResponse = Math.max(...responseTimes).toFixed(2);
            const outages = totalChecks - upChecks;
            
            // Update DOM elements
            document.getElementById('uptime-stat').textContent = `${uptimePercentage}%`;
            document.getElementById('avg-response-stat').textContent = `${avgResponseTime} ms`;
            document.getElementById('fastest-response').textContent = `${fastestResponse} ms`;
            document.getElementById('slowest-response').textContent = `${slowestResponse} ms`;
            document.getElementById('total-checks').textContent = totalChecks;
            document.getElementById('total-outages').textContent = outages;
        }
        
        // Function to update recent checks table
        function updateRecentChecksTable(data) {
            const tableBody = document.getElementById('recent-checks-table').querySelector('tbody');
            tableBody.innerHTML = '';
            
            // Take only the last 10 checks, in reverse chronological order
            const recentChecks = [...data].reverse().slice(0, 10);
            
            recentChecks.forEach(check => {
                const row = document.createElement('tr');
                
                // Format date
                const checkTime = new Date(check.check_time);
                const formattedTime = checkTime.toLocaleString();
                
                // Format status
                const statusClass = check.status === 'up' ? 'success' : 'danger';
                const statusText = check.status === 'up' ? 'Online' : 'Down';
                
                // Format response time
                const responseTime = (check.response_time * 1000).toFixed(2);
                
                // Create cells
                row.innerHTML = `
                    <td>${formattedTime}</td>
                    <td><span class="badge bg-${statusClass}">${statusText}</span></td>
                    <td>${responseTime} ms</td>
                `;
                
                tableBody.appendChild(row);
            });
        }

        // Activate tooltips
        document.addEventListener('DOMContentLoaded', function() {
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
            var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl)
            });
            
            // Event-Listener für Sensor löschen Buttons
            document.querySelectorAll('.delete-sensor').forEach(button => {
                button.addEventListener('click', function() {
                    const sensorId = this.getAttribute('data-sensor-id');
                    if (confirm('Sind Sie sicher, dass Sie diesen Sensor löschen möchten?')) {
                        // Erstelle ein Formular und reiche es ein
                        const form = document.createElement('form');
                        form.method = 'POST';
                        form.style.display = 'none';
                        
                        // CSRF-Token hinzufügen
                        const csrfToken = document.createElement('input');
                        csrfToken.type = 'hidden';
                        csrfToken.name = 'csrf_token';
                        csrfToken.value = '<?php echo $_SESSION['csrf_token']; ?>';
                        form.appendChild(csrfToken);
                        
                        // Sensor-ID hinzufügen
                        const sensorIdInput = document.createElement('input');
                        sensorIdInput.type = 'hidden';
                        sensorIdInput.name = 'service_id';
                        sensorIdInput.value = sensorId;
                        form.appendChild(sensorIdInput);
                        
                        // Aktion hinzufügen
                        const actionInput = document.createElement('input');
                        actionInput.type = 'hidden';
                        actionInput.name = 'delete_service';
                        actionInput.value = '1';
                        form.appendChild(actionInput);
                        
                        document.body.appendChild(form);
                        form.submit();
                    }
                });
            });
        });
        
        // Function to load sparkline data for all sensors (nur wenn explizit aufgerufen)
        function loadAllSparklines() {
            const sensorElements = document.querySelectorAll('.response-time-sparkline');
            sensorElements.forEach(element => {
                const sensorId = element.id.split('-')[1];
                loadSparklineData(sensorId);
            });
        }
        
        // Function to load response time data for sparklines
        function loadSparklineData(sensorId) {
            fetch(`get_response_times.php?sensor_id=${sensorId}&days=7`, {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Serverfehler: ' + response.status);
                }
                return response.json();
            })
                .then(data => {
                if (data.error) {
                    throw new Error(data.error);
                }
                renderSparkline(sensorId, data);
            })
            .catch(error => {
                console.error('Error loading sparkline data:', error);
                // Diskreter Fehler - keine Alert-Box anzeigen
            });
        }
        
        // Function to render a sparkline chart
        function renderSparkline(sensorId, data) {
            try {
                const canvas = document.getElementById(`sparkline-${sensorId}`);
                if (!canvas) {
                    console.error(`Canvas element sparkline-${sensorId} not found!`);
                    return;
                }
                
                // Destroy existing chart if it exists
                if (sparklineCharts[sensorId] !== null && typeof sparklineCharts[sensorId] !== 'undefined') {
                    sparklineCharts[sensorId].destroy();
                    sparklineCharts[sensorId] = null;
                }
                
                // Get context
                const ctx = canvas.getContext('2d');
                
                // Prepare data for chart
                const responseTimes = data.map(item => item.response_time * 1000); // Convert to ms
                const dates = data.map(item => new Date(item.check_time).toLocaleDateString());
                const statuses = data.map(item => item.status);
                
                // Calculate gradient colors based on status
                const gradient = ctx.createLinearGradient(0, 0, 0, 30);
                gradient.addColorStop(0, 'rgba(13, 110, 253, 0.2)');
                gradient.addColorStop(1, 'rgba(13, 110, 253, 0.05)');
                
                // Create chart
                sparklineCharts[sensorId] = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: dates,
                        datasets: [{
                            data: responseTimes,
                            borderColor: '#0d6efd',
                            backgroundColor: gradient,
                            borderWidth: 1.5,
                            pointRadius: 1,
                            pointHoverRadius: 5,
                            pointBackgroundColor: (context) => {
                                const status = statuses[context.dataIndex];
                                return status === 'up' ? '#198754' : '#dc3545';
                            },
                            tension: 0.4,
                            fill: true
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: false
                            },
                            tooltip: {
                                callbacks: {
                                    title: (context) => {
                                        const index = context[0].dataIndex;
                                        return new Date(data[index].check_time).toLocaleString();
                                    },
                                    label: (context) => {
                                        const index = context.dataIndex;
                                        const status = statuses[index];
                                        const statusText = status === 'up' ? 'Online' : 'Down';
                                        return [
                                            `Response: ${context.parsed.y.toFixed(2)} ms`,
                                            `Status: ${statusText}`
                                        ];
                                    }
                                }
                            }
                        },
                        interaction: {
                            intersect: false,
                            mode: 'index'
                        },
                        scales: {
                            x: {
                                display: false
                            },
                            y: {
                                display: false,
                                beginAtZero: true
                            }
                        }
                    }
                });
            } catch (error) {
                console.error(`Error rendering sparkline chart for sensor ${sensorId}:`, error);
                // Keine Alert-Box für Sparklines, um User nicht zu stören
                
                // Chart zurücksetzen
                sparklineCharts[sensorId] = null;
            }
        }
        
        // Function to toggle response time chart visibility
        function toggleResponseTimeChart(button, sensorId, sensorName) {
            const container = document.getElementById(`chart-container-${sensorId}`);
            
            // Toggle visibility
            if (container.style.display === 'none') {
                // Show chart
                container.style.display = 'flex';
                button.innerHTML = '<i class="bi bi-graph-up"></i> Verberge Grafik';
                
                // Load data if not loaded yet
                if (!sparklineCharts[sensorId]) {
                    loadSparklineData(sensorId);
                }
            } else {
                // Hide chart
                container.style.display = 'none';
                button.innerHTML = '<i class="bi bi-graph-up"></i> Zeige Grafik';
            }
        }
    </script>
</body>
</html>
