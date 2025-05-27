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

    // Get notification settings
    $stmt = $pdo->prepare("
        SELECT 
            c.id as sensor_id,
            c.name as sensor_name,
            ns.enable_downtime_notifications,
            ns.enable_ssl_notifications,
            ns.ssl_warning_days
        FROM 
            config c
        LEFT JOIN 
            notification_settings ns ON c.id = ns.sensor_id
        WHERE 
            c.user_id = ?
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $notificationSettings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Handle notification settings update
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_notifications'])) {
        if (check_csrf_token()) {
            try {
                foreach ($_POST['settings'] as $sensor_id => $settings) {
                    $enable_downtime = isset($settings['enable_downtime']) ? 1 : 0;
                    $enable_ssl = isset($settings['enable_ssl']) ? 1 : 0;
                    $ssl_warning_days = clean_input($settings['ssl_warning_days'] ?? '30');

                    // Validate sensor ownership
                    $stmt = $pdo->prepare("SELECT id FROM config WHERE id = ? AND user_id = ?");
                    $stmt->execute([$sensor_id, $_SESSION['user_id']]);
                    if ($stmt->fetch()) {
                        // Update or insert notification settings
                        $stmt = $pdo->prepare("
                            INSERT INTO notification_settings 
                                (sensor_id, enable_downtime_notifications, enable_ssl_notifications, ssl_warning_days)
                            VALUES 
                                (?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE
                                enable_downtime_notifications = VALUES(enable_downtime_notifications),
                                enable_ssl_notifications = VALUES(enable_ssl_notifications),
                                ssl_warning_days = VALUES(ssl_warning_days)
                        ");
                        $stmt->execute([$sensor_id, $enable_downtime, $enable_ssl, $ssl_warning_days]);
                    }
                }
                $message = "Notification settings updated successfully!";
            } catch (PDOException $e) {
                $error = "Database error: " . $e->getMessage();
                error_log("Error updating notification settings: " . $e->getMessage());
            }
        }
    }

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

            if (isset($_POST['add_notification_email'])) {
                if (check_csrf_token()) {
                    $email = clean_input($_POST['notification_email']);
                    
                    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        try {
                            // Check if email already exists for this user
                            $stmt = $pdo->prepare("SELECT id FROM email_notification_recipients WHERE email = ? AND user_id = ?");
                            $stmt->execute([$email, $_SESSION['user_id']]);
                            
                            if ($stmt->rowCount() == 0) {
                                // Generate verification token
                                $verification_token = bin2hex(random_bytes(32));
                                
                                // Add new recipient
                                $stmt = $pdo->prepare("
                                    INSERT INTO email_notification_recipients 
                                        (user_id, email, verification_token, verification_sent) 
                                    VALUES 
                                        (?, ?, ?, 1)
                                ");
                                $stmt->execute([$_SESSION['user_id'], $email, $verification_token]);
                                
                                // Send verification email
                                require_once 'email_notifications.php';
                                $emailNotifier = new EmailNotifications($pdo);
                                $emailNotifier->sendVerificationEmail($email, $_SESSION['user_id'], $verification_token);
                                
                                $message = "Recipient added. A verification email has been sent.";
                            } else {
                                $error = "This email address is already registered.";
                            }
                        } catch (PDOException $e) {
                            $error = "Database error: " . $e->getMessage();
                            error_log("Error adding notification recipient: " . $e->getMessage());
                        }
                    } else {
                        $error = "Invalid email address.";
                    }
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

            if (isset($_GET['resend_verification'])) {
                $recipient_id = (int)$_GET['resend_verification'];
                
                try {
                    // Verify ownership and check verification status
                    $stmt = $pdo->prepare("
                        SELECT email, verification_token, user_id, verified, verification_sent 
                        FROM email_notification_recipients 
                        WHERE id = ? AND user_id = ?
                    ");
                    $stmt->execute([$recipient_id, $_SESSION['user_id']]);
                    $recipient = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($recipient) {
                        // Check if already verified
                        if ($recipient['verified']) {
                            $error = "This email address is already verified.";
                        } else {
                            // Check if verification was sent in the last 5 minutes
                            $stmt = $pdo->prepare("
                                SELECT COUNT(*) as recent_sends 
                                FROM email_notification_recipients 
                                WHERE id = ? AND verification_sent = 1 
                                AND updated_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                            ");
                            $stmt->execute([$recipient_id]);
                            $recentSends = $stmt->fetch(PDO::FETCH_ASSOC)['recent_sends'];
                            
                            if ($recentSends > 0) {
                                // Calculate remaining time
                                $stmt = $pdo->prepare("
                                    SELECT TIMESTAMPDIFF(SECOND, updated_at, DATE_ADD(NOW(), INTERVAL 5 MINUTE)) as remaining_seconds
                                    FROM email_notification_recipients 
                                    WHERE id = ? AND verification_sent = 1
                                ");
                                $stmt->execute([$recipient_id]);
                                $remaining = $stmt->fetch(PDO::FETCH_ASSOC)['remaining_seconds'];
                                
                                $minutes = floor($remaining / 60);
                                $seconds = $remaining % 60;
                                
                                $error = "Please wait before requesting another verification email. Time remaining: " . 
                                        sprintf("%02d:%02d", $minutes, $seconds);
                            } else {
                                // Generate new verification token
                                $new_token = bin2hex(random_bytes(32));
                                
                                // Update the verification token
                                $stmt = $pdo->prepare("
                                    UPDATE email_notification_recipients 
                                    SET verification_token = ?,
                                        verification_sent = 1,
                                        updated_at = NOW()
                                    WHERE id = ? AND user_id = ?
                                ");
                                $stmt->execute([$new_token, $recipient_id, $_SESSION['user_id']]);
                                
                                // Resend verification email
                                require_once 'email_notifications.php';
                                $emailNotifier = new EmailNotifications($pdo);
                                $result = $emailNotifier->sendVerificationEmail($recipient['email'], $recipient['user_id'], $new_token);
                                
                                if ($result) {
                                    $message = "Verification email has been resent.";
                                } else {
                                    $error = "Failed to send verification email. Please try again later.";
                                    error_log("Failed to send verification email to: " . $recipient['email']);
                                }
                            }
                        }
                    } else {
                        $error = "Recipient not found or you don't have permission to resend verification.";
                    }
                } catch (PDOException $e) {
                    $error = "Database error occurred. Please try again later.";
                    error_log("Error resending verification: " . $e->getMessage());
                } catch (Exception $e) {
                    $error = "An unexpected error occurred. Please try again later.";
                    error_log("Unexpected error in resend verification: " . $e->getMessage());
                }
            }

            if (isset($_GET['delete_recipient']) && isset($_GET['confirm']) && $_GET['confirm'] === 'true') {
                $recipient_id = (int)$_GET['delete_recipient'];
                
                try {
                    // Verify ownership and delete
                    $stmt = $pdo->prepare("
                        DELETE FROM email_notification_recipients 
                        WHERE id = ? AND user_id = ?
                    ");
                    $stmt->execute([$recipient_id, $_SESSION['user_id']]);
                    
                    if ($stmt->rowCount() > 0) {
                        $message = "Recipient removed successfully.";
                    }
                } catch (PDOException $e) {
                    $error = "Database error: " . $e->getMessage();
                    error_log("Error deleting recipient: " . $e->getMessage());
                }
            }
        }
    }

    // Account löschen
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_account'])) {
        // CSRF-Token überprüfen
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            $error = "Sicherheitsüberprüfung fehlgeschlagen. Bitte versuchen Sie es erneut.";
        } else {
            // Bestätigungspasswort überprüfen
            $confirmation_password = $_POST['confirmation_password'] ?? '';
            
            // Benutzerpasswort aus der Datenbank abrufen
            $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user && password_verify($confirmation_password, $user['password'])) {
                // Beginne eine Transaktion, um alle Benutzerdaten sicher zu löschen
                $pdo->beginTransaction();
                
                try {
                    // 1. Lösche alle E-Mail-Abonnenten für die Status-Pages des Benutzers
                    $stmt = $pdo->prepare("
                        DELETE es FROM email_subscribers es
                        JOIN status_pages sp ON es.status_page_id = sp.id
                        WHERE sp.user_id = ?
                    ");
                    $stmt->execute([$_SESSION['user_id']]);
                    
                    // 2. Lösche alle Custom Domains des Benutzers
                    $stmt = $pdo->prepare("DELETE FROM custom_domains WHERE user_id = ?");
                    $stmt->execute([$_SESSION['user_id']]);
                    
                    // 3. Lösche alle Status-Pages des Benutzers
                    $stmt = $pdo->prepare("DELETE FROM status_pages WHERE user_id = ?");
                    $stmt->execute([$_SESSION['user_id']]);
                    
                    // 4. Lösche alle Incidents des Benutzers
                    $stmt = $pdo->prepare("DELETE FROM incidents WHERE user_id = ?");
                    $stmt->execute([$_SESSION['user_id']]);
                    
                    // 5. Lösche alle Wartungseinträge des Benutzers
                    $stmt = $pdo->prepare("DELETE FROM maintenance_history WHERE user_id = ?");
                    $stmt->execute([$_SESSION['user_id']]);
                    
                    // 6. Lösche alle Uptime-Checks des Benutzers
                    $stmt = $pdo->prepare("DELETE FROM uptime_checks WHERE user_id = ?");
                    $stmt->execute([$_SESSION['user_id']]);
                    
                    // 7. Lösche alle Services/Sensoren des Benutzers
                    $stmt = $pdo->prepare("DELETE FROM config WHERE user_id = ?");
                    $stmt->execute([$_SESSION['user_id']]);
                    
                    // 8. Lösche das Benutzerabonnement
                    $stmt = $pdo->prepare("DELETE FROM user_subscriptions WHERE user_id = ?");
                    $stmt->execute([$_SESSION['user_id']]);
                    
                    // 9. Lösche den Benutzer selbst
                    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                    $stmt->execute([$_SESSION['user_id']]);
                    
                    // Transaktion bestätigen
                    $pdo->commit();
                    
                    // Benutzer ausloggen und zur Startseite weiterleiten
                    session_unset();
                    session_destroy();
                    header('Location: index.php?account_deleted=1');
                    exit;
                    
                } catch (PDOException $e) {
                    // Bei Fehler Transaktion zurückrollen
                    $pdo->rollBack();
                    $error = "Fehler beim Löschen des Accounts: " . $e->getMessage();
                    error_log("Account deletion error: " . $e->getMessage());
                }
            } else {
                $error = "Das eingegebene Passwort ist nicht korrekt.";
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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #0d6efd;
            --secondary-color: #6c757d;
            --success-color: #198754;
            --danger-color: #dc3545;
            --light-bg: #f8f9fa;
            --dark-bg: #212529;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--light-bg);
            min-height: 100vh;
        }

        .wrapper {
            display: flex;
            width: 100%;
            align-items: stretch;
        }

        #sidebar {
            min-width: 250px;
            max-width: 250px;
            background: var(--dark-bg);
            color: #fff;
            transition: all 0.3s;
            position: fixed;
            height: 100vh;
            z-index: 1000;
        }

        #sidebar.active {
            margin-left: -250px;
        }

        #sidebar .sidebar-header {
            padding: 20px;
            background: rgba(0, 0, 0, 0.1);
        }

        #sidebar ul.components {
            padding: 20px 0;
        }

        #sidebar ul li a {
            padding: 15px 20px;
            font-size: 1.1em;
            display: block;
            color: #fff;
            text-decoration: none;
            transition: all 0.3s;
        }

        #sidebar ul li a:hover,
        #sidebar ul li.active > a {
            background: rgba(255, 255, 255, 0.1);
        }

        #sidebar ul li a i {
            margin-right: 10px;
            width: 20px;
            text-align: center;
        }

        #content {
            width: 100%;
            padding: 20px;
            min-height: 100vh;
            transition: all 0.3s;
            margin-left: 250px;
        }

        .navbar {
            padding: 15px 10px;
            background: #fff;
            border: none;
            border-radius: 0;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }

        .card-body {
            padding: 1.5rem;
        }

        .table {
            margin-bottom: 0;
        }

        .table th {
            border-top: none;
            background-color: var(--light-bg);
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.85rem;
            letter-spacing: 0.5px;
        }

        .table td {
            vertical-align: middle;
        }

        .btn {
            padding: 0.5rem 1rem;
            font-weight: 500;
            border-radius: 5px;
            transition: all 0.3s;
        }

        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }

        .btn-primary:hover {
            background-color: #0b5ed7;
            border-color: #0b5ed7;
            transform: translateY(-1px);
        }

        .btn-danger {
            background-color: var(--danger-color);
            border-color: var(--danger-color);
        }

        .btn-danger:hover {
            background-color: #bb2d3b;
            border-color: #bb2d3b;
            transform: translateY(-1px);
        }

        .modal-content {
            border: none;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }

        .modal-header {
            background-color: var(--light-bg);
            border-bottom: 1px solid #dee2e6;
            border-radius: 10px 10px 0 0;
        }

        .modal-body {
            padding: 1.5rem;
        }

        .badge {
            padding: 0.5em 0.75em;
            font-weight: 500;
        }

        .badge-success {
            background-color: var(--success-color);
        }

        .badge-secondary {
            background-color: var(--secondary-color);
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

        .notification-settings-table {
            background: white;
            border-radius: 0.5rem;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            overflow: hidden;
        }

        .notification-settings-table .form-check {
            padding-left: 2.25rem;
            margin: 0;
            position: relative;
        }

        .notification-settings-table .form-check-input {
            width: 1.25rem;
            height: 1.25rem;
            margin-left: -2.25rem;
            margin-top: 0;
            cursor: pointer;
            border: 1.5px solid #ced4da;
            border-radius: 0.25rem;
            transition: all 0.15s ease-in-out;
            position: relative;
            background-color: #fff;
        }

        .notification-settings-table .form-check-input:checked {
            background-color: #0d6efd;
            border-color: #0d6efd;
        }

        .notification-settings-table .form-check-input:checked::after {
            content: '';
            position: absolute;
            left: 0.4rem;
            top: 0.2rem;
            width: 0.35rem;
            height: 0.6rem;
            border: solid white;
            border-width: 0 2px 2px 0;
            transform: rotate(45deg);
        }

        .notification-settings-table .form-check-input:hover {
            border-color: #0d6efd;
        }

        .notification-settings-table .form-check-input:focus {
            box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.15);
            border-color: #0d6efd;
        }

        .notification-settings-table .form-check-label {
            font-size: 0.875rem;
            color: #495057;
            padding-top: 0.1rem;
            user-select: none;
        }

        .notification-settings-table .input-group {
            width: auto;
            min-width: 110px;
        }

        .notification-settings-table .form-control {
            width: 70px;
            padding: 0.375rem 0.5rem;
            font-size: 0.875rem;
            border-radius: 0.25rem 0 0 0.25rem;
            border: 1.5px solid #ced4da;
            transition: all 0.15s ease-in-out;
            text-align: center;
            background-color: #fff;
        }

        .notification-settings-table .form-control:focus {
            border-color: #0d6efd;
            box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.15);
        }

        .notification-settings-table .input-group-text {
            background-color: #f8f9fa;
            border: 1.5px solid #ced4da;
            border-left: none;
            border-radius: 0 0.25rem 0.25rem 0;
            color: #6c757d;
            font-size: 0.875rem;
            padding: 0.375rem 0.5rem;
        }

        .notification-settings-table .btn-update {
            padding: 0.375rem 0.75rem;
            font-size: 0.875rem;
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            border-radius: 0.25rem;
            transition: all 0.15s ease-in-out;
            background-color: #0d6efd;
            border: none;
        }

        .notification-settings-table .btn-update:hover {
            background-color: #0b5ed7;
            transform: translateY(-1px);
        }

        .notification-settings-table .btn-update i {
            font-size: 0.875rem;
        }

        .notification-settings-table td {
            vertical-align: middle;
            padding: 1rem;
            border-bottom: 1px solid #dee2e6;
        }

        .notification-settings-table th {
            background-color: #f8f9fa;
            font-weight: 600;
            padding: 1rem;
            border-bottom: 2px solid #dee2e6;
            color: #495057;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .notification-settings-table tr:last-child td {
            border-bottom: none;
        }

        .notification-settings-table tr {
            transition: background-color 0.15s ease-in-out;
        }

        .notification-settings-table tr:hover {
            background-color: #f8f9fa;
        }

        .notification-settings-table .sensor-name {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-weight: 500;
            color: #212529;
            font-size: 0.875rem;
        }

        .notification-settings-table .sensor-name i {
            font-size: 1rem;
            color: #0d6efd;
        }

        .notification-settings-card {
            background: white;
            border-radius: 0.5rem;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            margin-bottom: 1.5rem;
        }

        .notification-settings-card .card-header {
            background-color: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
            padding: 1rem;
        }

        .notification-settings-card .card-header h5 {
            margin: 0;
            color: #212529;
            font-size: 1rem;
            font-weight: 600;
        }

        .notification-settings-card .card-body {
            padding: 1.25rem;
        }

        @media (max-width: 768px) {
            #sidebar {
                margin-left: -250px;
            }
            #sidebar.active {
                margin-left: 0;
            }
            #content {
                margin-left: 0;
            }
            #content.active {
                margin-left: 250px;
            }
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <!-- Sidebar -->
        <nav id="sidebar">
            <div class="sidebar-header">
                <h3>Status Page</h3>
            </div>

            <ul class="list-unstyled components">
                <li class="nav-item active">
                    <a class="nav-link" href="dashboard2.php">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="passkeys.php">
                        <i class="fas fa-key"></i>
                        <span>Passkeys</span>
                    </a>
                </li>
                <?php if (isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1): ?>
                <li class="nav-item">
                    <a class="nav-link" href="admin.php">
                        <i class="fas fa-cog"></i>
                        <span>Admin</span>
                    </a>
                </li>
                <?php endif; ?>
            </ul>
        </nav>

        <!-- Page Content -->
        <div id="content">
            <nav class="navbar navbar-expand-lg navbar-light">
                <div class="container-fluid">
                    <button type="button" id="sidebarCollapse" class="btn btn-primary">
                        <i class="fas fa-align-left"></i>
                    </button>
                    <div class="ms-auto">
                        <a href="logout.php" class="btn btn-outline-danger">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </div>
                </div>
            </nav>

            <div class="container-fluid">
                <?php if($message): ?>
                    <div class="alert alert-success"><?php echo $message; ?></div>
                <?php endif; ?>

                <?php if($error): ?>
                    <div class="alert alert-danger"><?php echo $error; ?></div>
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
                        <a class="nav-link active" href="#overview">Overview</a>
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
                                                <i class="fas fa-plus-circle"></i> Create Status Page
                                            </button>
                                        </div>
                                        <div class="col-md-3">
                                            <button type="button" class="btn btn-success w-100 mb-2 <?php echo $usage['sensors_count'] >= $userTier['max_sensors'] ? 'btn-disabled' : ''; ?>" 
                                                    data-bs-toggle="modal" data-bs-target="#addSensorModal"
                                                    <?php echo $usage['sensors_count'] >= $userTier['max_sensors'] ? 'disabled' : ''; ?>>
                                                <i class="fas fa-plus-circle"></i> Add Sensor
                                            </button>
                                        </div>
                                        <div class="col-md-3">
                                            <button type="button" class="btn btn-warning w-100 mb-2" data-bs-toggle="modal" data-bs-target="#addMaintenanceModal">
                                                <i class="fas fa-tools"></i> Schedule Maintenance
                                            </button>
                                        </div>
                                        <div class="col-md-3">
                                            <button type="button" class="btn btn-danger w-100 mb-2" data-bs-toggle="modal" data-bs-target="#addIncidentModal">
                                                <i class="fas fa-exclamation-triangle"></i> Report Incident
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
                            <button type="button" class="btn btn-success mb-3" data-bs-toggle="modal" data-bs-target="#addSensorModal">
                                <i class="bi bi-plus-circle"></i> Add Sensor
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
                            
                            <hr class="my-4">
                            
                            <h5 class="text-danger">Account löschen</h5>
                            <p class="text-muted">Diese Aktion kann nicht rückgängig gemacht werden. Alle Ihre Daten, Status-Pages und Sensoren werden unwiderruflich gelöscht.</p>
                            
                            <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#deleteAccountModal">
                                Account löschen
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Notification Settings Section -->
                <div class="notification-settings-card">
                    <div class="card-header">
                        <h5>Notification Settings</h5>
                    </div>
                    <div class="card-body">
                        <!-- Notification Recipients -->
                        <div class="mb-4">
                            <h6 class="border-bottom pb-2">Notification Recipients</h6>
                            <form method="POST" action="" class="mb-3">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <div class="row align-items-end">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="notification_email">Email Address:</label>
                                            <input type="email" name="notification_email" id="notification_email" class="form-control" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <button type="submit" name="add_notification_email" class="btn btn-primary">Add Recipient</button>
                                    </div>
                                </div>
                            </form>

                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Email Address</th>
                                            <th>Status</th>
                                            <th>Added On</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        // Get notification recipients
                                        $stmt = $pdo->prepare("
                                            SELECT en.*, 
                                                   CASE 
                                                       WHEN en.verified = 1 THEN 'Verified'
                                                       WHEN en.verification_sent = 1 THEN 'Pending Verification'
                                                       ELSE 'Not Verified'
                                                   END as status_text
                                            FROM email_notification_recipients en
                                            WHERE en.user_id = ?
                                            ORDER BY en.created_at DESC
                                        ");
                                        $stmt->execute([$_SESSION['user_id']]);
                                        $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);

                                        foreach ($recipients as $recipient) {
                                            $statusClass = $recipient['verified'] ? 'success' : 
                                                ($recipient['verification_sent'] ? 'warning' : 'danger');
                                            echo '<tr>';
                                            echo '<td>' . htmlspecialchars($recipient['email']) . '</td>';
                                            echo '<td><span class="badge bg-' . $statusClass . '">' . $recipient['status_text'] . '</span></td>';
                                            echo '<td>' . date('Y-m-d H:i', strtotime($recipient['created_at'])) . '</td>';
                                            echo '<td>';
                                            if (!$recipient['verified']) {
                                                // Check if we need to show countdown
                                                $stmt = $pdo->prepare("
                                                    SELECT TIMESTAMPDIFF(SECOND, NOW(), DATE_ADD(updated_at, INTERVAL 5 MINUTE)) as remaining_seconds
                                                    FROM email_notification_recipients 
                                                    WHERE id = ? AND verification_sent = 1 
                                                    AND updated_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                                                ");
                                                $stmt->execute([$recipient['id']]);
                                                $remaining = $stmt->fetch(PDO::FETCH_ASSOC)['remaining_seconds'];
                                                
                                                if ($remaining > 0) {
                                                    $endTime = time() + $remaining;
                                                    echo '<span class="verification-countdown" data-end-time="' . $endTime . '">';
                                                    echo '<i class="bi bi-clock"></i>';
                                                    echo 'Please wait: ' . floor($remaining / 60) . ':' . str_pad($remaining % 60, 2, '0', STR_PAD_LEFT);
                                                    echo '</span>';
                                                    echo '<a href="?resend_verification=' . $recipient['id'] . '" class="btn btn-sm btn-info me-1 resend-verification-btn" style="display:none;">';
                                                    echo '<i class="bi bi-envelope"></i> Resend Verification</a>';
                                                } else {
                                                    echo '<a href="?resend_verification=' . $recipient['id'] . '" class="btn btn-sm btn-info me-1 resend-verification-btn">';
                                                    echo '<i class="bi bi-envelope"></i> Resend Verification</a>';
                                                }
                                            }
                                            echo '<a href="?delete_recipient=' . $recipient['id'] . '&confirm=true" class="btn btn-sm btn-danger" onclick="return confirm(\'Are you sure you want to remove this recipient?\');">';
                                            echo '<i class="bi bi-trash"></i> Remove</a>';
                                            echo '</td>';
                                            echo '</tr>';
                                        }
                                        ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Sensor Notification Settings -->
                        <h6 class="border-bottom pb-2">Sensor Notification Settings</h6>
                        <form method="POST" action="">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <div class="table-responsive">
                                <table class="table notification-settings-table">
                                    <thead>
                                        <tr>
                                            <th>Sensor</th>
                                            <th>Downtime Notifications</th>
                                            <th>SSL Certificate Warnings</th>
                                            <th>SSL Warning Days</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($notificationSettings as $setting): ?>
                                        <tr>
                                            <td>
                                                <div class="sensor-name">
                                                    <i class="bi bi-hdd-network"></i>
                                                    <?php echo htmlspecialchars($setting['sensor_name']); ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="form-check">
                                                    <input type="checkbox" class="form-check-input" 
                                                           name="settings[<?php echo $setting['sensor_id']; ?>][enable_downtime]" 
                                                           id="downtime_<?php echo $setting['sensor_id']; ?>"
                                                           <?php echo $setting['enable_downtime_notifications'] ? 'checked' : ''; ?>>
                                                    <label class="form-check-label" for="downtime_<?php echo $setting['sensor_id']; ?>">
                                                        Enable
                                                    </label>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="form-check">
                                                    <input type="checkbox" class="form-check-input" 
                                                           name="settings[<?php echo $setting['sensor_id']; ?>][enable_ssl]" 
                                                           id="ssl_<?php echo $setting['sensor_id']; ?>"
                                                           <?php echo $setting['enable_ssl_notifications'] ? 'checked' : ''; ?>>
                                                    <label class="form-check-label" for="ssl_<?php echo $setting['sensor_id']; ?>">
                                                        Enable
                                                    </label>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="input-group">
                                                    <input type="number" class="form-control" 
                                                           name="settings[<?php echo $setting['sensor_id']; ?>][ssl_warning_days]" 
                                                           value="<?php echo htmlspecialchars($setting['ssl_warning_days'] ?? '30'); ?>" 
                                                           min="1" max="90">
                                                    <span class="input-group-text">days</span>
                                                </div>
                                            </td>
                                            <td>
                                                <button type="submit" name="update_notifications" class="btn btn-update">
                                                    <i class="bi bi-save"></i>
                                                    Update
                                                </button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </form>
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        $(document).ready(function() {
            // Sidebar Toggle
            $('#sidebarCollapse').on('click', function() {
                $('#sidebar').toggleClass('active');
                $('#content').toggleClass('active');
            });

            // Rest of your existing JavaScript code
            // ... existing code ...
        });
    </script>
</body>
</html>