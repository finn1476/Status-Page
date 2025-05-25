<?php
// install.php - Datenbankinstallation und -aktualisierung für das Status-Page-System

// Konfigurationsparameter
$dbHost = 'localhost';
$dbName = 'monitoring';
$dbUser = 'root';
$dbPass = '';
$createDatabase = true;

// Installationsmodus prüfen: "install" (Standardeinstellung) oder "update"
$mode = isset($_GET['mode']) && $_GET['mode'] === 'update' ? 'update' : 'install';

// Überprüfen, ob das Installationsskript läuft oder bereits abgeschlossen ist
$installLockFile = __DIR__ . '/install.lock';
if (file_exists($installLockFile) && $mode === 'install') {
    die('Installation wurde bereits durchgeführt. Bitte löschen Sie die Datei "install.lock", wenn Sie die Installation erneut durchführen möchten, oder verwenden Sie "?mode=update" um nur die Datenbankstruktur zu aktualisieren.');
}

// HTML-Header für die Ausgabe
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Status-Page-System - <?php echo $mode === 'update' ? 'Aktualisierung' : 'Installation'; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
            padding: 30px;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background-color: #fff;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        h1 {
            margin-bottom: 30px;
            color: #0d6efd;
        }
        .log {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-top: 20px;
            max-height: 400px;
            overflow-y: auto;
            font-family: monospace;
            white-space: pre-wrap;
        }
        .success {
            color: #198754;
        }
        .error {
            color: #dc3545;
        }
        .warning {
            color: #ffc107;
        }
        .mode-toggle {
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Status-Page-System - <?php echo $mode === 'update' ? 'Aktualisierung' : 'Installation'; ?></h1>
        
        <div class="mode-toggle">
            <a href="?mode=install" class="btn btn-<?php echo $mode === 'install' ? 'primary' : 'outline-primary'; ?> me-2">Installation</a>
            <a href="?mode=update" class="btn btn-<?php echo $mode === 'update' ? 'primary' : 'outline-primary'; ?>">Aktualisierung</a>
        </div>
        
        <div class="progress mb-4">
            <div class="progress-bar progress-bar-striped progress-bar-animated" style="width: 0%" id="progress"></div>
        </div>
        <div class="log" id="log">Starte <?php echo $mode === 'update' ? 'Aktualisierung' : 'Installation'; ?>...</div>
        
        <div class="mt-4 text-center" id="finishContainer" style="display: none;">
            <a href="index.php" class="btn btn-primary">Zur Startseite</a>
        </div>
    </div>
    
    <script>
        function updateProgress(percent) {
            document.getElementById('progress').style.width = percent + '%';
        }
        
        function appendLog(message, type = '') {
            const log = document.getElementById('log');
            const entry = document.createElement('div');
            entry.textContent = message;
            if (type) entry.className = type;
            log.appendChild(entry);
            log.scrollTop = log.scrollHeight;
        }
        
        function showFinish() {
            document.getElementById('finishContainer').style.display = 'block';
        }
    </script>
<?php
// Starten der Installation oder Aktualisierung
ob_implicit_flush(true);
ob_end_flush();

// Fortschritt aktualisieren
echo "<script>updateProgress(5); appendLog('Überprüfe Datenbankverbindung...');</script>";
flush();

// Datenbankverbindung ohne spezifische Datenbank herstellen
try {
    $pdo = new PDO("mysql:host=$dbHost", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "<script>appendLog('Datenbankverbindung erfolgreich hergestellt.', 'success');</script>";
    flush();
    
    // Datenbank erstellen falls erforderlich
    if ($createDatabase) {
        echo "<script>updateProgress(10); appendLog('Erstelle Datenbank \"$dbName\" falls nicht vorhanden...');</script>";
        flush();
        
        try {
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
            echo "<script>appendLog('Datenbank wurde erfolgreich erstellt oder existiert bereits.', 'success');</script>";
            flush();
        } catch (PDOException $e) {
            echo "<script>appendLog('Fehler beim Erstellen der Datenbank: " . addslashes($e->getMessage()) . "', 'error');</script>";
            die();
        }
    }
    
    // Verbindung zur spezifischen Datenbank herstellen
    echo "<script>updateProgress(15); appendLog('Verbinde mit Datenbank \"$dbName\"...');</script>";
    flush();
    
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "<script>appendLog('Verbindung zur Datenbank hergestellt.', 'success');</script>";
    flush();
    
    // Hilfsfunktion zum Prüfen, ob eine Tabelle existiert
    function tableExists($pdo, $table) {
        try {
            $result = $pdo->query("SELECT 1 FROM $table LIMIT 1");
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
    
    // Hilfsfunktion zum Prüfen, ob eine Spalte in einer Tabelle existiert
    function columnExists($pdo, $table, $column) {
        try {
            $stmt = $pdo->prepare("SELECT $column FROM $table LIMIT 1");
            $stmt->execute();
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
    
    // Tabellen erstellen/aktualisieren
    if ($mode === 'install') {
        echo "<script>updateProgress(20); appendLog('Erstelle Tabellen...');</script>";
    } else {
        echo "<script>updateProgress(20); appendLog('Überprüfe und aktualisiere Tabellen...');</script>";
    }
    flush();
    
    // Array mit allen Tabellen und deren Erstellungsbefehlen aus database.sql
    $tables = [];
    
    // admin_login_logs
    $tables['admin_login_logs'] = "
    CREATE TABLE IF NOT EXISTS `admin_login_logs` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `user_id` int(11) NOT NULL,
      `ip_address` varchar(45) NOT NULL,
      `user_agent` text DEFAULT NULL,
      `login_time` datetime NOT NULL,
      `login_count` int(11) DEFAULT 1,
      PRIMARY KEY (`id`),
      UNIQUE KEY `user_id` (`user_id`,`ip_address`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ";
    
    // admin_settings
    $tables['admin_settings'] = "
    CREATE TABLE IF NOT EXISTS `admin_settings` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `setting_key` varchar(50) NOT NULL,
      `setting_value` text DEFAULT NULL,
      `created_at` datetime DEFAULT current_timestamp(),
      `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
      PRIMARY KEY (`id`),
      UNIQUE KEY `setting_key` (`setting_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ";
    
    // users
    $tables['users'] = "
    CREATE TABLE IF NOT EXISTS `users` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `name` varchar(255) NOT NULL,
      `email` varchar(255) NOT NULL,
      `password` varchar(255) NOT NULL,
      `token` varchar(32) NOT NULL,
      `verified` tinyint(1) NOT NULL DEFAULT 0,
      `created_at` datetime DEFAULT current_timestamp(),
      `is_admin` tinyint(1) NOT NULL DEFAULT 0,
      `role` enum('user','admin') NOT NULL DEFAULT 'user',
      `status` enum('active','pending','inactive') NOT NULL DEFAULT 'active',
      `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
      PRIMARY KEY (`id`),
      UNIQUE KEY `email` (`email`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ";
    
    // user_tiers
    $tables['user_tiers'] = "
    CREATE TABLE IF NOT EXISTS `user_tiers` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `name` varchar(50) NOT NULL,
      `max_status_pages` int(11) NOT NULL DEFAULT 1,
      `max_sensors` int(11) NOT NULL DEFAULT 5,
      `max_email_subscribers` int(11) NOT NULL DEFAULT 10,
      `price` decimal(10,2) DEFAULT NULL,
      `created_at` datetime DEFAULT current_timestamp(),
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ";
    
    // config
    $tables['config'] = "
    CREATE TABLE IF NOT EXISTS `config` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `user_id` int(11) NOT NULL,
      `name` varchar(255) NOT NULL,
      `url` varchar(255) NOT NULL,
      `sensor_type` varchar(50) NOT NULL DEFAULT 'http',
      `sensor_config` text DEFAULT NULL,
      `ssl_expiry_date` date DEFAULT NULL COMMENT 'SSL certificate expiration date',
      PRIMARY KEY (`id`),
      KEY `user_id` (`user_id`),
      CONSTRAINT `fk_config_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ";
    
    // status_pages
    $tables['status_pages'] = "
    CREATE TABLE IF NOT EXISTS `status_pages` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `user_id` int(11) NOT NULL,
      `name` varchar(255) NOT NULL DEFAULT 'Status Page',
      `slug` varchar(255) DEFAULT NULL,
      `description` text DEFAULT NULL,
      `theme` varchar(50) DEFAULT 'default',
      `service_id` int(11) DEFAULT NULL,
      `page_title` varchar(255) NOT NULL,
      `custom_css` text DEFAULT NULL,
      `uuid` varchar(255) NOT NULL,
      `sensor_ids` text DEFAULT NULL,
      `created_at` datetime DEFAULT current_timestamp(),
      `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
      PRIMARY KEY (`id`),
      KEY `user_id` (`user_id`),
      KEY `service_id` (`service_id`),
      CONSTRAINT `fk_statuspages_service` FOREIGN KEY (`service_id`) REFERENCES `config` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
      CONSTRAINT `fk_statuspages_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ";
    
    // email_subscribers
    $tables['email_subscribers'] = "
    CREATE TABLE IF NOT EXISTS `email_subscribers` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `status_page_id` int(11) NOT NULL,
      `email` varchar(255) NOT NULL,
      `status` enum('pending','verified') NOT NULL DEFAULT 'pending',
      `verified` tinyint(1) NOT NULL DEFAULT 0,
      `verification_token` varchar(64) DEFAULT NULL,
      `expires_at` timestamp NULL DEFAULT NULL,
      `verified_at` timestamp NULL DEFAULT NULL,
      `unsubscribe_token` varchar(64) DEFAULT NULL,
      `unsubscribe_expires_at` timestamp NULL DEFAULT NULL,
      `created_at` datetime DEFAULT current_timestamp(),
      PRIMARY KEY (`id`),
      UNIQUE KEY `email_status_page` (`email`,`status_page_id`),
      KEY `status_page_id` (`status_page_id`),
      KEY `idx_unsubscribe_token` (`unsubscribe_token`),
      CONSTRAINT `fk_subscribers_status_page` FOREIGN KEY (`status_page_id`) REFERENCES `status_pages` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ";
    
    // incidents
    $tables['incidents'] = "
    CREATE TABLE IF NOT EXISTS `incidents` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `user_id` int(11) NOT NULL,
      `status_page_id` int(11) NOT NULL,
      `title` varchar(255) NOT NULL,
      `service_id` int(11) DEFAULT NULL,
      `date` datetime NOT NULL,
      `description` text NOT NULL,
      `impact` enum('minor','major','critical') DEFAULT 'minor',
      `status` enum('resolved','in progress','reported') DEFAULT 'reported',
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
      PRIMARY KEY (`id`),
      KEY `user_id` (`user_id`),
      KEY `fk_incidents_service` (`service_id`),
      CONSTRAINT `fk_incidents_service` FOREIGN KEY (`service_id`) REFERENCES `config` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
      CONSTRAINT `fk_incidents_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ";
    
    // incident_updates
    $tables['incident_updates'] = "
    CREATE TABLE IF NOT EXISTS `incident_updates` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `incident_id` int(11) NOT NULL,
      `update_time` datetime NOT NULL DEFAULT current_timestamp(),
      `message` text NOT NULL,
      `status` enum('investigating','identified','monitoring','resolved','in progress') DEFAULT 'investigating',
      `created_by` int(11) NOT NULL,
      PRIMARY KEY (`id`),
      KEY `incident_id` (`incident_id`),
      KEY `created_by` (`created_by`),
      CONSTRAINT `fk_incident_updates_incident` FOREIGN KEY (`incident_id`) REFERENCES `incidents` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
      CONSTRAINT `fk_incident_updates_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ";
    
    // maintenance_history
    $tables['maintenance_history'] = "
    CREATE TABLE IF NOT EXISTS `maintenance_history` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `user_id` int(11) NOT NULL,
      `service_id` int(11) NOT NULL,
      `start_date` datetime NOT NULL,
      `end_date` datetime NOT NULL,
      `description` text NOT NULL,
      `status` enum('scheduled','completed','cancelled') DEFAULT 'scheduled',
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`id`),
      KEY `user_id` (`user_id`),
      KEY `fk_maintenance_service` (`service_id`),
      CONSTRAINT `fk_maintenance_service` FOREIGN KEY (`service_id`) REFERENCES `config` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
      CONSTRAINT `fk_maintenance_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ";
    
    // sensors
    $tables['sensors'] = "
    CREATE TABLE IF NOT EXISTS `sensors` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `status_page_id` int(11) NOT NULL,
      `name` varchar(255) NOT NULL,
      `type` varchar(50) NOT NULL,
      `url` varchar(255) NOT NULL,
      `check_interval` int(11) NOT NULL DEFAULT 300,
      `timeout` int(11) NOT NULL DEFAULT 30,
      `status` varchar(20) NOT NULL DEFAULT 'unknown',
      `last_check` timestamp NULL DEFAULT NULL,
      `uptime_percentage` decimal(5,2) DEFAULT 100.00,
      `created_at` timestamp NULL DEFAULT current_timestamp(),
      `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
      PRIMARY KEY (`id`),
      KEY `status_page_id` (`status_page_id`),
      CONSTRAINT `sensors_ibfk_1` FOREIGN KEY (`status_page_id`) REFERENCES `status_pages` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ";
    
    // smtp_config
    $tables['smtp_config'] = "
    CREATE TABLE IF NOT EXISTS `smtp_config` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `user_id` int(11) NOT NULL,
      `smtp_host` varchar(255) NOT NULL,
      `smtp_port` int(11) NOT NULL,
      `smtp_user` varchar(255) NOT NULL,
      `smtp_pass` varchar(255) NOT NULL,
      `from_email` varchar(255) NOT NULL,
      `from_name` varchar(255) NOT NULL,
      `created_at` timestamp NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`id`),
      KEY `user_id` (`user_id`),
      CONSTRAINT `smtp_config_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ";
    
    // system_settings
    $tables['system_settings'] = "
    CREATE TABLE IF NOT EXISTS `system_settings` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `setting_key` varchar(50) NOT NULL,
      `setting_value` text DEFAULT NULL,
      `created_at` timestamp NULL DEFAULT current_timestamp(),
      `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
      PRIMARY KEY (`id`),
      UNIQUE KEY `setting_key` (`setting_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ";
    
    // uptime_checks
    $tables['uptime_checks'] = "
    CREATE TABLE IF NOT EXISTS `uptime_checks` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `user_id` int(11) NOT NULL,
      `service_name` varchar(255) NOT NULL,
      `service_url` varchar(255) NOT NULL,
      `check_time` datetime NOT NULL DEFAULT current_timestamp(),
      `status` tinyint(1) NOT NULL,
      `response_time` float DEFAULT NULL,
      PRIMARY KEY (`id`),
      KEY `user_id` (`user_id`),
      CONSTRAINT `fk_uptime_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ";
    
    // user_subscriptions
    $tables['user_subscriptions'] = "
    CREATE TABLE IF NOT EXISTS `user_subscriptions` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `user_id` int(11) NOT NULL,
      `tier_id` int(11) NOT NULL,
      `status` enum('active','cancelled','expired') DEFAULT 'active',
      `start_date` datetime NOT NULL,
      `end_date` datetime DEFAULT NULL,
      `created_at` datetime DEFAULT current_timestamp(),
      PRIMARY KEY (`id`),
      KEY `user_id` (`user_id`),
      KEY `tier_id` (`tier_id`),
      CONSTRAINT `fk_subscriptions_tier` FOREIGN KEY (`tier_id`) REFERENCES `user_tiers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
      CONSTRAINT `fk_subscriptions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ";
    
    // email_notifications
    $tables['email_notifications'] = "
    CREATE TABLE IF NOT EXISTS `email_notifications` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `status_page_id` int(11) NOT NULL,
      `incident_id` int(11) NULL,
      `sent_at` datetime DEFAULT current_timestamp(),
      `maintenance_id` int(11) DEFAULT NULL,
      PRIMARY KEY (`id`),
      KEY `status_page_id` (`status_page_id`),
      KEY `incident_id` (`incident_id`),
      KEY `maintenance_id` (`maintenance_id`),
      CONSTRAINT `fk_notifications_incident` FOREIGN KEY (`incident_id`) REFERENCES `incidents` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
      CONSTRAINT `fk_notifications_status_page` FOREIGN KEY (`status_page_id`) REFERENCES `status_pages` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
      CONSTRAINT `email_notifications_ibfk_1` FOREIGN KEY (`maintenance_id`) REFERENCES `maintenance_history` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ";
    
    // custom_domains
    $tables['custom_domains'] = "
    CREATE TABLE IF NOT EXISTS `custom_domains` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `user_id` int(11) NOT NULL,
      `status_page_id` int(11) NOT NULL,
      `domain` varchar(255) NOT NULL,
      `verified` tinyint(1) DEFAULT 0,
      `ssl_status` ENUM('pending', 'active', 'failed') DEFAULT 'pending',
      `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY (`domain`),
      FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
      FOREIGN KEY (`status_page_id`) REFERENCES `status_pages` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ";
    
    // domain_access_log
    $tables['domain_access_log'] = "
    CREATE TABLE IF NOT EXISTS `domain_access_log` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `domain` varchar(255) NOT NULL,
      `ip` varchar(45) NOT NULL,
      `user_agent` text DEFAULT NULL,
      `access_date` datetime NOT NULL,
      PRIMARY KEY (`id`),
      KEY `domain` (`domain`),
      KEY `access_date` (`access_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ";
    
    // email_notification_recipients
    $tables['email_notification_recipients'] = "
    CREATE TABLE IF NOT EXISTS `email_notification_recipients` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `user_id` int(11) NOT NULL,
        `email` varchar(255) NOT NULL,
        `verification_token` varchar(64) DEFAULT NULL,
        `verified` tinyint(1) NOT NULL DEFAULT 0,
        `verification_sent` tinyint(1) NOT NULL DEFAULT 0,
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `user_email` (`user_id`, `email`),
        CONSTRAINT `fk_email_notification_recipients_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";

    // notification_settings
    $tables['notification_settings'] = "
    CREATE TABLE IF NOT EXISTS `notification_settings` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `sensor_id` int(11) NOT NULL,
        `enable_downtime_notifications` tinyint(1) NOT NULL DEFAULT 1,
        `enable_ssl_notifications` tinyint(1) NOT NULL DEFAULT 1,
        `ssl_warning_days` int(11) NOT NULL DEFAULT 30,
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `sensor_id` (`sensor_id`),
        CONSTRAINT `fk_notification_settings_sensor` FOREIGN KEY (`sensor_id`) REFERENCES `config` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";

    // Update email_notifications table
    $tables['email_notifications_update'] = "
    ALTER TABLE `email_notifications` 
    ADD COLUMN IF NOT EXISTS `sensor_id` int(11) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `notification_type` varchar(50) DEFAULT NULL,
    ADD CONSTRAINT `fk_email_notifications_sensor` FOREIGN KEY (`sensor_id`) REFERENCES `config` (`id`) ON DELETE CASCADE;
    ";
    
    // Spalten-Definitionen für Aktualisierungen
    $columns = [
        'email_notifications' => [
            'maintenance_id' => "ALTER TABLE `email_notifications` ADD COLUMN `maintenance_id` INT NULL AFTER `sent_at`, ADD KEY `maintenance_id` (`maintenance_id`), ADD CONSTRAINT `email_notifications_ibfk_1` FOREIGN KEY (`maintenance_id`) REFERENCES `maintenance_history` (`id`) ON DELETE CASCADE;",
            'incident_id' => "ALTER TABLE `email_notifications` MODIFY COLUMN `incident_id` INT NULL;"
        ],
        'custom_domains' => [
            'ssl_status' => "ALTER TABLE `custom_domains` ADD COLUMN `ssl_status` ENUM('pending', 'active', 'failed') DEFAULT 'pending' AFTER `verified`;"
        ],
        'incidents' => [
            'title' => "ALTER TABLE `incidents` ADD COLUMN `title` VARCHAR(255) NOT NULL AFTER `status_page_id`;",
            'impact' => "ALTER TABLE `incidents` ADD COLUMN `impact` ENUM('minor','major','critical') DEFAULT 'minor' AFTER `description`;"
        ],
        'email_subscribers' => [
            'unsubscribe_token' => "ALTER TABLE `email_subscribers` ADD COLUMN `unsubscribe_token` VARCHAR(64) DEFAULT NULL AFTER `verified_at`;",
            'unsubscribe_expires_at' => "ALTER TABLE `email_subscribers` ADD COLUMN `unsubscribe_expires_at` TIMESTAMP NULL DEFAULT NULL AFTER `unsubscribe_token`;",
            'idx_unsubscribe_token' => "ALTER TABLE `email_subscribers` ADD INDEX `idx_unsubscribe_token` (`unsubscribe_token`);"
        ],
        'config' => [
            'ssl_expiry_date' => "ALTER TABLE `config` ADD COLUMN `ssl_expiry_date` date DEFAULT NULL COMMENT 'SSL certificate expiration date' AFTER `sensor_config`;"
        ]
    ];
    
    // Tabellen erstellen/aktualisieren (Reihenfolge ist wichtig wegen Fremdschlüsselbeziehungen)
    $tableOrder = [
        'users', 
        'user_tiers', 
        'user_subscriptions', 
        'admin_settings', 
        'admin_login_logs', 
        'config', 
        'status_pages', 
        'email_subscribers', 
        'incidents',
        'incident_updates',
        'maintenance_history', 
        'sensors', 
        'smtp_config', 
        'system_settings', 
        'uptime_checks', 
        'email_notifications',
        'custom_domains',
        'domain_access_log',
        'email_notification_recipients',
        'notification_settings'
    ];
    
    // Erstelle alle Tabellen in der korrekten Reihenfolge
    $totalTables = count($tableOrder);
    $currentTable = 0;
    
    foreach ($tableOrder as $tableName) {
        $currentTable++;
        $progress = 20 + (60 * ($currentTable / $totalTables));
        
        if ($mode === 'install') {
            echo "<script>updateProgress(" . round($progress) . "); appendLog('Erstelle Tabelle: $tableName...');</script>";
            flush();
            
            try {
                $pdo->exec($tables[$tableName]);
                echo "<script>appendLog('Tabelle $tableName wurde erfolgreich erstellt.', 'success');</script>";
                flush();
            } catch (PDOException $e) {
                echo "<script>appendLog('Fehler beim Erstellen der Tabelle $tableName: " . addslashes($e->getMessage()) . "', 'error');</script>";
                flush();
            }
        } else {
            // Update-Modus: Prüfen, ob Tabelle existiert, sonst erstellen
            echo "<script>updateProgress(" . round($progress) . "); appendLog('Überprüfe Tabelle: $tableName...');</script>";
            flush();
            
            if (!tableExists($pdo, $tableName)) {
                try {
                    $pdo->exec($tables[$tableName]);
                    echo "<script>appendLog('Tabelle $tableName wurde erstellt (existierte nicht).', 'success');</script>";
                    flush();
                } catch (PDOException $e) {
                    echo "<script>appendLog('Fehler beim Erstellen der Tabelle $tableName: " . addslashes($e->getMessage()) . "', 'error');</script>";
                    flush();
                }
            } else {
                echo "<script>appendLog('Tabelle $tableName existiert bereits.', 'success');</script>";
                
                // Prüfen, ob Spalten aktualisiert werden müssen
                if (isset($columns[$tableName])) {
                    foreach ($columns[$tableName] as $columnName => $alterStatement) {
                        if (!columnExists($pdo, $tableName, $columnName)) {
                            try {
                                $pdo->exec($alterStatement);
                                echo "<script>appendLog('Spalte $columnName zur Tabelle $tableName hinzugefügt.', 'success');</script>";
                                flush();
                            } catch (PDOException $e) {
                                echo "<script>appendLog('Fehler beim Hinzufügen der Spalte $columnName zur Tabelle $tableName: " . addslashes($e->getMessage()) . "', 'warning');</script>";
                                flush();
                            }
                        } else {
                            echo "<script>appendLog('Spalte $columnName in Tabelle $tableName existiert bereits.', 'success');</script>";
                            flush();
                        }
                    }
                }
            }
        }
    }
    
    // Initialen Admin-Benutzer erstellen im Installationsmodus
    if ($mode === 'install') {
        echo "<script>updateProgress(85); appendLog('Erstelle Admin-Benutzer...');</script>";
        flush();
        
        try {
            // Prüfe, ob bereits ein Admin existiert
            $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE is_admin = 1");
            $adminExists = $stmt->fetchColumn() > 0;
            
            if (!$adminExists) {
                $adminEmail = 'admin@example.com';
                $adminPassword = password_hash('admin123', PASSWORD_DEFAULT);
                $adminToken = bin2hex(random_bytes(16));
                
                $stmt = $pdo->prepare("INSERT INTO users (name, email, password, token, verified, is_admin, role, status) VALUES ('Administrator', ?, ?, ?, 1, 1, 'admin', 'active')");
                $stmt->execute([$adminEmail, $adminPassword, $adminToken]);
                
                echo "<script>appendLog('Admin-Benutzer wurde erstellt. E-Mail: $adminEmail, Passwort: admin123', 'success');</script>";
                flush();
            } else {
                echo "<script>appendLog('Admin-Benutzer existiert bereits.', 'warning');</script>";
                flush();
            }
        } catch (PDOException $e) {
            echo "<script>appendLog('Fehler beim Erstellen des Admin-Benutzers: " . addslashes($e->getMessage()) . "', 'error');</script>";
            flush();
        }
        
        // Standard-Tarife erstellen
        echo "<script>updateProgress(90); appendLog('Erstelle Standard-Tarife...');</script>";
        flush();
        
        try {
            // Prüfe, ob bereits Tarife existieren
            $stmt = $pdo->query("SELECT COUNT(*) FROM user_tiers");
            $tiersExist = $stmt->fetchColumn() > 0;
            
            if (!$tiersExist) {
                $tiers = [
                    ['Free', 1, 3, 10, 0.00],
                    ['Basic', 2, 10, 100, 9.99],
                    ['Pro', 5, 50, 1000, 29.99],
                    ['Enterprise', 10, 100, 10000, 99.99]
                ];
                
                $stmt = $pdo->prepare("INSERT INTO user_tiers (name, max_status_pages, max_sensors, max_email_subscribers, price) VALUES (?, ?, ?, ?, ?)");
                
                foreach ($tiers as $tier) {
                    $stmt->execute($tier);
                }
                
                echo "<script>appendLog('Standard-Tarife wurden erstellt.', 'success');</script>";
                flush();
            } else {
                echo "<script>appendLog('Tarife existieren bereits.', 'warning');</script>";
                flush();
            }
        } catch (PDOException $e) {
            echo "<script>appendLog('Fehler beim Erstellen der Standard-Tarife: " . addslashes($e->getMessage()) . "', 'error');</script>";
            flush();
        }
        
        // System-Einstellungen erstellen
        echo "<script>updateProgress(95); appendLog('Erstelle System-Einstellungen...');</script>";
        flush();
        
        try {
            // E-Mail-Einstellungen
            $settings = [
                ['smtp_host', ''],
                ['smtp_port', ''],
                ['smtp_username', ''],
                ['smtp_password', ''],
                ['smtp_encryption', 'tls'],
                ['smtp_from_email', ''],
                ['smtp_from_name', 'Status Page'],
                ['site_title', 'Status Page System'],
                ['site_description', 'Überwachen Sie den Status Ihrer Dienste'],
                ['site_name', 'Status Page System'],
                ['contact_email', 'admin@example.com'],
                ['maintenance_mode', '0'],
                ['allow_registration', '1'],
                ['enable_ssl_monitoring', '1'],
                ['check_interval', '300'],
                ['notify_ssl_expiry', '1'],
                ['ssl_expiry_days', '30']
            ];
            
            $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            
            foreach ($settings as $setting) {
                $stmt->execute($setting);
            }
            
            echo "<script>appendLog('System-Einstellungen wurden erstellt.', 'success');</script>";
            flush();
        } catch (PDOException $e) {
            echo "<script>appendLog('Fehler beim Erstellen der System-Einstellungen: " . addslashes($e->getMessage()) . "', 'error');</script>";
            flush();
        }
    } else {
        // Im Update-Modus können wir die Einstellungen prüfen und aktualisieren falls nötig
        echo "<script>updateProgress(90); appendLog('Überprüfe System-Einstellungen...');</script>";
        flush();
        
        try {
            // Minimale Einstellungen, die vorhanden sein sollten
            $requiredSettings = [
                'site_name' => 'Status Page System',
                'contact_email' => 'admin@example.com',
                'maintenance_mode' => '0'
            ];
            
            foreach ($requiredSettings as $key => $defaultValue) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM system_settings WHERE setting_key = ?");
                $stmt->execute([$key]);
                $settingExists = $stmt->fetchColumn() > 0;
                
                if (!$settingExists) {
                    $insertStmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)");
                    $insertStmt->execute([$key, $defaultValue]);
                    echo "<script>appendLog('Einstellung $key mit Standardwert erstellt.', 'success');</script>";
                    flush();
                }
            }
            
            echo "<script>appendLog('System-Einstellungen wurden überprüft.', 'success');</script>";
            flush();
        } catch (PDOException $e) {
            echo "<script>appendLog('Fehler beim Überprüfen der System-Einstellungen: " . addslashes($e->getMessage()) . "', 'error');</script>";
            flush();
        }
    }
    
    // Installation/Aktualisierung abschließen
    echo "<script>updateProgress(100); appendLog('" . ($mode === 'update' ? 'Aktualisierung' : 'Installation') . " abgeschlossen!', 'success');</script>";
    flush();
    
    // Lock-Datei erstellen, nur im Installationsmodus
    if ($mode === 'install') {
        file_put_contents($installLockFile, date('Y-m-d H:i:s'));
    }
    
    echo "<script>showFinish();</script>";
    flush();
    
} catch (PDOException $e) {
    echo "<script>appendLog('Datenbankverbindungsfehler: " . addslashes($e->getMessage()) . "', 'error');</script>";
    flush();
}
?>
</body>
</html>
