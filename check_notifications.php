<?php
require_once '../db.php';
require_once '../email_notifications.php';

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Initialize email notifications
    $emailNotifier = new EmailNotifications($pdo);
    
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
    
    foreach ($sensors as $sensor) {
        // Check for sensor downtime
        if ($sensor['enable_downtime_notifications']) {
            $stmt = $pdo->prepare("
                SELECT status, check_time 
                FROM uptime_checks 
                WHERE service_url = ? 
                ORDER BY check_time DESC 
                LIMIT 1
            ");
            $stmt->execute([$sensor['url']]);
            $lastCheck = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($lastCheck && $lastCheck['status'] === 'down') {
                // Check if we haven't sent a notification in the last hour
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) 
                    FROM email_notifications 
                    WHERE sensor_id = ? 
                    AND notification_type = 'downtime' 
                    AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
                ");
                $stmt->execute([$sensor['sensor_id']]);
                $recentNotifications = $stmt->fetchColumn();
                
                if ($recentNotifications == 0) {
                    // Send downtime notification
                    $emailNotifier->sendSensorDowntimeNotification($sensor['sensor_id'], $sensor['status_page_id']);
                    
                    // Record the notification
                    $stmt = $pdo->prepare("
                        INSERT INTO email_notifications 
                            (sensor_id, notification_type, status_page_id) 
                        VALUES 
                            (?, 'downtime', ?)
                    ");
                    $stmt->execute([$sensor['sensor_id'], $sensor['status_page_id']]);
                }
            }
        }
        
        // Check for SSL certificate expiration
        if ($sensor['enable_ssl_notifications'] && $sensor['ssl_expiry_date']) {
            $expiryDate = new DateTime($sensor['ssl_expiry_date']);
            $now = new DateTime();
            $daysUntilExpiry = $now->diff($expiryDate)->days;
            
            if ($daysUntilExpiry <= $sensor['ssl_warning_days']) {
                // Check if we haven't sent a notification in the last day
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) 
                    FROM email_notifications 
                    WHERE sensor_id = ? 
                    AND notification_type = 'ssl' 
                    AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
                ");
                $stmt->execute([$sensor['sensor_id']]);
                $recentNotifications = $stmt->fetchColumn();
                
                if ($recentNotifications == 0) {
                    // Send SSL warning notification
                    $emailNotifier->sendSSLCertificateWarning($sensor['sensor_id'], $sensor['status_page_id']);
                    
                    // Record the notification
                    $stmt = $pdo->prepare("
                        INSERT INTO email_notifications 
                            (sensor_id, notification_type, status_page_id) 
                        VALUES 
                            (?, 'ssl', ?)
                    ");
                    $stmt->execute([$sensor['sensor_id'], $sensor['status_page_id']]);
                }
            }
        }
    }
    
} catch (PDOException $e) {
    error_log("Database error in check_notifications.php: " . $e->getMessage());
} catch (Exception $e) {
    error_log("Error in check_notifications.php: " . $e->getMessage());
} 