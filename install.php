<?php
// install.php – Erzeugt die Datenbank "monitoring" und legt alle Tabellen an

$dbHost = 'localhost';
$dbUser = 'root';
$dbPass = '';

try {
    // Verbindung ohne Datenbank, um ggf. die DB anzulegen
    $pdo = new PDO("mysql:host=$dbHost;charset=utf8mb4", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Datenbank erstellen, falls nicht vorhanden
    $pdo->exec("CREATE DATABASE IF NOT EXISTS monitoring CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;");
    echo "Datenbank 'monitoring' erstellt oder bereits vorhanden.<br>";

    // Auf die Datenbank wechseln
    $pdo->exec("USE monitoring;");

    // Tabellen anlegen
    $sql = "
    -- Tabelle: users
    CREATE TABLE IF NOT EXISTS users (
      id int(11) NOT NULL AUTO_INCREMENT,
      name varchar(255) NOT NULL,
      email varchar(255) NOT NULL,
      password varchar(255) NOT NULL,
      token varchar(32) NOT NULL,
      verified tinyint(1) NOT NULL DEFAULT 0,
      created_at datetime DEFAULT current_timestamp(),
      PRIMARY KEY (id),
      UNIQUE KEY email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

    -- Tabelle: config
    CREATE TABLE IF NOT EXISTS config (
      id int(11) NOT NULL AUTO_INCREMENT,
      user_id int(11) NOT NULL,
      name varchar(255) NOT NULL,
      url varchar(255) NOT NULL,
      sensor_type varchar(50) NOT NULL DEFAULT 'http',
      sensor_config text DEFAULT NULL,
      PRIMARY KEY (id),
      KEY user_id (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

    -- Tabelle: incidents
    CREATE TABLE IF NOT EXISTS incidents (
      id int(11) NOT NULL AUTO_INCREMENT,
      user_id int(11) NOT NULL,
      service_id int(11) DEFAULT NULL,
      date datetime NOT NULL,
      description text NOT NULL,
      status enum('resolved','in progress','reported') DEFAULT 'reported',
      created_at timestamp NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (id),
      KEY user_id (user_id),
      KEY fk_incidents_service (service_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

    -- Tabelle: maintenance_history
    CREATE TABLE IF NOT EXISTS maintenance_history (
      id int(11) NOT NULL AUTO_INCREMENT,
      user_id int(11) NOT NULL,
      service_id int(11) NOT NULL,
      date datetime NOT NULL,
      description text NOT NULL,
      status enum('scheduled','completed','cancelled') DEFAULT 'scheduled',
      created_at timestamp NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (id),
      KEY user_id (user_id),
      KEY fk_maintenance_service (service_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

    -- Tabelle: status_pages
    CREATE TABLE IF NOT EXISTS status_pages (
      id int(11) NOT NULL AUTO_INCREMENT,
      user_id int(11) NOT NULL,
      service_id int(11) DEFAULT NULL,
      page_title varchar(255) NOT NULL,
      custom_css text DEFAULT NULL,
      uuid varchar(255) NOT NULL,
      sensor_ids text DEFAULT NULL,
      created_at datetime DEFAULT current_timestamp(),
      PRIMARY KEY (id),
      KEY user_id (user_id),
      KEY service_id (service_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

    -- Tabelle: uptime_checks
    CREATE TABLE IF NOT EXISTS uptime_checks (
      id int(11) NOT NULL AUTO_INCREMENT,
      user_id int(11) NOT NULL,
      service_name varchar(255) NOT NULL,
      service_url varchar(255) NOT NULL,
      check_time datetime NOT NULL DEFAULT current_timestamp(),
      status tinyint(1) NOT NULL,
      PRIMARY KEY (id),
      KEY user_id (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

    -- Constraints (Fremdschlüssel)
    ALTER TABLE config
      ADD CONSTRAINT fk_config_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE;

    ALTER TABLE incidents
      ADD CONSTRAINT fk_incidents_service FOREIGN KEY (service_id) REFERENCES config (id) ON DELETE CASCADE ON UPDATE CASCADE,
      ADD CONSTRAINT fk_incidents_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE;

    ALTER TABLE maintenance_history
      ADD CONSTRAINT fk_maintenance_service FOREIGN KEY (service_id) REFERENCES config (id) ON DELETE CASCADE ON UPDATE CASCADE,
      ADD CONSTRAINT fk_maintenance_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE;

    ALTER TABLE status_pages
      ADD CONSTRAINT fk_statuspages_service FOREIGN KEY (service_id) REFERENCES config (id) ON DELETE SET NULL ON UPDATE CASCADE,
      ADD CONSTRAINT fk_statuspages_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE;

    ALTER TABLE uptime_checks
      ADD CONSTRAINT fk_uptime_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE;
    ";

    $pdo->exec($sql);
    echo "Tabellen wurden erfolgreich angelegt.";

} catch(PDOException $e) {
    die("Installation fehlgeschlagen: " . $e->getMessage());
}
?>
