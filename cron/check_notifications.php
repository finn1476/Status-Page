<?php
require_once '/var/www/html/db.php';
require_once '/var/www/html/email_notifications.php';

// Debug-Logging-Funktion
function debug_log($message, $type = 'INFO') {
    echo date('Y-m-d H:i:s') . " [$type] $message\n";
}

try {
    debug_log("Starting notification check process");
    
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    debug_log("Database connection established");
    
    // Check and update database structure if needed
    try {
        // Check if columns exist
        $stmt = $pdo->query("SHOW COLUMNS FROM email_notifications LIKE 'sensor_id'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("ALTER TABLE `email_notifications` ADD COLUMN `sensor_id` int(11) DEFAULT NULL");
            debug_log("Added sensor_id column");
        }
        
        $stmt = $pdo->query("SHOW COLUMNS FROM email_notifications LIKE 'notification_type'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("ALTER TABLE `email_notifications` ADD COLUMN `notification_type` varchar(50) DEFAULT NULL");
            debug_log("Added notification_type column");
        }
        
        // Check if foreign key exists
        $stmt = $pdo->query("
            SELECT CONSTRAINT_NAME 
            FROM information_schema.TABLE_CONSTRAINTS 
            WHERE TABLE_NAME = 'email_notifications' 
            AND CONSTRAINT_NAME = 'fk_email_notifications_sensor'
        ");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("
                ALTER TABLE `email_notifications` 
                ADD CONSTRAINT `fk_email_notifications_sensor` 
                FOREIGN KEY (`sensor_id`) REFERENCES `config` (`id`) ON DELETE CASCADE
            ");
            debug_log("Added foreign key constraint");
        }
        
        debug_log("Database structure check completed");
    } catch (PDOException $e) {
        debug_log("Warning: Could not update database structure: " . $e->getMessage(), 'WARNING');
    }
    
    // Set HTTP_HOST for email notifications
    $_SERVER['HTTP_HOST'] = 'status.anonfile.de';
    
    // Initialize email notifications
    $emailNotifier = new EmailNotifications($pdo);
    debug_log("Email notifier initialized");
    
    // Get all sensors with notification settings
    $stmt = $pdo->prepare("
        SELECT 
            c.id as sensor_id,
            c.name,
            c.url,
            c.ssl_expiry_date,
            ns.enable_downtime_notifications,
            ns.enable_ssl_notifications,
            ns.ssl_warning_days,
            sp.id as status_page_id
        FROM 
            config c
        JOIN 
            notification_settings ns ON c.id = ns.sensor_id
        JOIN 
            status_pages sp ON sp.user_id = c.user_id
        WHERE 
            ns.enable_downtime_notifications = 1 
            OR ns.enable_ssl_notifications = 1
    ");
    $stmt->execute();
    $sensors = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    debug_log("Found " . count($sensors) . " sensors with notification settings");
    
    if (count($sensors) === 0) {
        debug_log("No sensors found with notification settings enabled");
    }
    
    foreach ($sensors as $sensor) {
        debug_log("Processing sensor: " . $sensor['name'] . " (ID: " . $sensor['sensor_id'] . ")");
        debug_log("Sensor URL: " . $sensor['url']);
        debug_log("Downtime notifications: " . ($sensor['enable_downtime_notifications'] ? 'enabled' : 'disabled'));
        debug_log("SSL notifications: " . ($sensor['enable_ssl_notifications'] ? 'enabled' : 'disabled'));
        
        // Check for sensor downtime
        if ($sensor['enable_downtime_notifications']) {
            debug_log("Checking downtime status for sensor: " . $sensor['name']);
            
            $stmt = $pdo->prepare("
                SELECT status, check_time 
                FROM uptime_checks 
                WHERE service_url = ? 
                ORDER BY check_time DESC 
                LIMIT 4
            ");
            $stmt->execute([$sensor['url']]);
            $checks = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (count($checks) >= 4) {
                debug_log("Last four checks for " . $sensor['name'] . ": " . 
                         "Status1: " . $checks[0]['status'] . " at " . $checks[0]['check_time'] . ", " .
                         "Status2: " . $checks[1]['status'] . " at " . $checks[1]['check_time'] . ", " .
                         "Status3: " . $checks[2]['status'] . " at " . $checks[2]['check_time'] . ", " .
                         "Status4: " . $checks[3]['status'] . " at " . $checks[3]['check_time']);
                
                // Check if the service is down (last four checks were down)
                if ($checks[0]['status'] == '0' && $checks[1]['status'] == '0' && 
                    $checks[2]['status'] == '0' && $checks[3]['status'] == '0') {
                    debug_log("Sensor " . $sensor['name'] . " is down, checking last notification");
                    
                    // Check if we've sent a notification in the last hour
                    $stmt = $pdo->prepare("
                        SELECT sent_at 
                        FROM email_notifications 
                        WHERE status_page_id = ? 
                        AND notification_type = 'downtime'
                        AND sensor_id = ?
                        ORDER BY sent_at DESC 
                        LIMIT 1
                    ");
                    $stmt->execute([$sensor['status_page_id'], $sensor['sensor_id']]);
                    $lastNotification = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    $shouldNotify = true;
                    if ($lastNotification) {
                        $lastNotificationTime = strtotime($lastNotification['sent_at']);
                        $oneHourAgo = strtotime('-1 hour');
                        $shouldNotify = $lastNotificationTime < $oneHourAgo;
                        
                        debug_log("Last notification was at " . date('Y-m-d H:i:s', $lastNotificationTime) . 
                                ", should notify: " . ($shouldNotify ? 'yes' : 'no'));
                    }
                    
                    if ($shouldNotify) {
                        debug_log("Sending downtime notification for sensor: " . $sensor['name']);
                        $emailNotifier->sendSensorDowntimeNotification($sensor['sensor_id'], $sensor['status_page_id']);
                        
                        // Record the notification
                        $stmt = $pdo->prepare("
                            INSERT INTO email_notifications 
                                (status_page_id, notification_type, sensor_id) 
                            VALUES 
                                (?, 'downtime', ?)
                        ");
                        $stmt->execute([$sensor['status_page_id'], $sensor['sensor_id']]);
                        debug_log("Downtime notification recorded in database");
                    }
                }
                // Check if the service is back up (last four checks were up)
                else if ($checks[0]['status'] == '1' && $checks[1]['status'] == '1' && 
                         $checks[2]['status'] == '1' && $checks[3]['status'] == '1') {
                    // First check if there was a downtime notification in the last hour
                    $stmt = $pdo->prepare("
                        SELECT sent_at 
                        FROM email_notifications 
                        WHERE status_page_id = ? 
                        AND notification_type = 'downtime'
                        AND sensor_id = ?
                        AND sent_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
                        ORDER BY sent_at DESC 
                        LIMIT 1
                    ");
                    $stmt->execute([$sensor['status_page_id'], $sensor['sensor_id']]);
                    $lastDowntimeNotification = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($lastDowntimeNotification) {
                        debug_log("Sensor " . $sensor['name'] . " is back up after recent downtime, checking last recovery notification");
                        
                        // Check if we've sent a recovery notification since the last downtime
                        $stmt = $pdo->prepare("
                            SELECT sent_at 
                            FROM email_notifications 
                            WHERE status_page_id = ? 
                            AND notification_type = 'recovery'
                            AND sensor_id = ?
                            AND sent_at > ?
                            ORDER BY sent_at DESC 
                            LIMIT 1
                        ");
                        $stmt->execute([$sensor['status_page_id'], $sensor['sensor_id'], $lastDowntimeNotification['sent_at']]);
                        $lastRecoveryNotification = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if (!$lastRecoveryNotification) {
                            debug_log("Sending recovery notification for sensor: " . $sensor['name']);
                            $emailNotifier->sendSensorRecoveryNotification($sensor['sensor_id'], $sensor['status_page_id']);
                            
                            // Record the notification
                            $stmt = $pdo->prepare("
                                INSERT INTO email_notifications 
                                    (status_page_id, notification_type, sensor_id) 
                                VALUES 
                                    (?, 'recovery', ?)
                            ");
                            $stmt->execute([$sensor['status_page_id'], $sensor['sensor_id']]);
                            debug_log("Recovery notification recorded in database");
                        } else {
                            debug_log("Recovery notification already sent at " . $lastRecoveryNotification['sent_at']);
                        }
                    } else {
                        debug_log("Sensor " . $sensor['name'] . " is up but no recent downtime notification found");
                    }
                }
            }
        }
        
        // Check for SSL certificate expiration
        if ($sensor['enable_ssl_notifications'] && $sensor['ssl_expiry_date']) {
            debug_log("Checking SSL certificate for sensor: " . $sensor['name']);
            
            $expiryDate = new DateTime($sensor['ssl_expiry_date']);
            $now = new DateTime();
            $daysUntilExpiry = $now->diff($expiryDate)->days;
            
            debug_log("SSL certificate expires in " . $daysUntilExpiry . " days");
            
            // Get the last SSL expiry date from the database
            $stmt = $pdo->prepare("
                SELECT ssl_expiry_date 
                FROM config 
                WHERE id = ? 
                AND ssl_expiry_date != ?
            ");
            $stmt->execute([$sensor['sensor_id'], $sensor['ssl_expiry_date']]);
            $oldExpiryDate = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Check if the certificate was renewed (new expiry date is later than the old one)
            if ($oldExpiryDate) {
                $oldDate = new DateTime($oldExpiryDate['ssl_expiry_date']);
                if ($expiryDate > $oldDate) {
                    debug_log("SSL certificate was renewed, sending notification");
                    
                    // Check if we've sent a renewal notification in the last 24 hours
                    $stmt = $pdo->prepare("
                        SELECT sent_at 
                        FROM email_notifications 
                        WHERE sensor_id = ? 
                        AND notification_type = 'ssl_renewal'
                        ORDER BY sent_at DESC 
                        LIMIT 1
                    ");
                    $stmt->execute([$sensor['sensor_id']]);
                    $lastNotification = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    $shouldNotify = true;
                    if ($lastNotification) {
                        $lastNotificationTime = strtotime($lastNotification['sent_at']);
                        $oneDayAgo = strtotime('-1 day');
                        $shouldNotify = $lastNotificationTime < $oneDayAgo;
                        
                        debug_log("Last SSL renewal notification was at " . date('Y-m-d H:i:s', $lastNotificationTime) . 
                                ", should notify: " . ($shouldNotify ? 'yes' : 'no'));
                    }
                    
                    if ($shouldNotify) {
                        debug_log("Sending SSL renewal notification for sensor: " . $sensor['name']);
                        $emailNotifier->sendSSLCertificateRenewalNotification($sensor['sensor_id'], $sensor['status_page_id']);
                        
                        // Record the notification
                        $stmt = $pdo->prepare("
                            INSERT INTO email_notifications 
                                (sensor_id, notification_type, status_page_id) 
                            VALUES 
                                (?, 'ssl_renewal', ?)
                        ");
                        $stmt->execute([$sensor['sensor_id'], $sensor['status_page_id']]);
                        debug_log("SSL renewal notification recorded in database");
                    }
                }
            }
            
            if ($daysUntilExpiry <= $sensor['ssl_warning_days']) {
                debug_log("SSL certificate is expiring soon, checking last notification");
                
                // Check if we've sent a notification in the last 24 hours
                $stmt = $pdo->prepare("
                    SELECT sent_at 
                    FROM email_notifications 
                    WHERE sensor_id = ? 
                    AND notification_type = 'ssl'
                    ORDER BY sent_at DESC 
                    LIMIT 1
                ");
                $stmt->execute([$sensor['sensor_id']]);
                $lastNotification = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $shouldNotify = true;
                if ($lastNotification) {
                    $lastNotificationTime = strtotime($lastNotification['sent_at']);
                    $oneDayAgo = strtotime('-1 day');
                    $shouldNotify = $lastNotificationTime < $oneDayAgo;
                    
                    debug_log("Last SSL notification was at " . date('Y-m-d H:i:s', $lastNotificationTime) . 
                            ", should notify: " . ($shouldNotify ? 'yes' : 'no'));
                }
                
                if ($shouldNotify) {
                    debug_log("Sending SSL warning notification for sensor: " . $sensor['name']);
                    $emailNotifier->sendSSLCertificateWarning($sensor['sensor_id'], $sensor['status_page_id']);
                    
                    // Record the notification
                    $stmt = $pdo->prepare("
                        INSERT INTO email_notifications 
                            (sensor_id, notification_type, status_page_id) 
                        VALUES 
                            (?, 'ssl', ?)
                    ");
                    $stmt->execute([$sensor['sensor_id'], $sensor['status_page_id']]);
                    debug_log("SSL warning notification recorded in database");
                }
            }
        }
    }
    
    debug_log("Notification check process completed successfully");
    
} catch (PDOException $e) {
    debug_log("Database error: " . $e->getMessage(), 'ERROR');
} catch (Exception $e) {
    debug_log("Error in notification check: " . $e->getMessage(), 'ERROR');
} 