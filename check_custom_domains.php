<?php
// Debug-Skript zur Überprüfung der custom_domains Tabelle
require_once 'db.php';

try {
    // Datenbankverbindung öffnen
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Ausgabe der aktuellen Domains
    echo "<h2>Einträge in der custom_domains Tabelle</h2>";
    
    $stmt = $pdo->query("SELECT * FROM custom_domains");
    $domains = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($domains) > 0) {
        echo "<table border='1' cellpadding='5' cellspacing='0'>";
        echo "<tr><th>ID</th><th>Domain</th><th>Status Page ID</th><th>User ID</th><th>Verified</th><th>SSL Status</th><th>Created At</th></tr>";
        
        foreach ($domains as $domain) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($domain['id']) . "</td>";
            echo "<td>" . htmlspecialchars($domain['domain']) . "</td>";
            echo "<td>" . htmlspecialchars($domain['status_page_id']) . "</td>";
            echo "<td>" . htmlspecialchars($domain['user_id']) . "</td>";
            echo "<td>" . htmlspecialchars($domain['verified']) . "</td>";
            echo "<td>" . htmlspecialchars($domain['ssl_status']) . "</td>";
            echo "<td>" . htmlspecialchars($domain['created_at']) . "</td>";
            echo "</tr>";
        }
        
        echo "</table>";
        
        // Zeige auch die zugehörigen Status-Pages
        echo "<h2>Zugehörige Status-Pages</h2>";
        
        echo "<table border='1' cellpadding='5' cellspacing='0'>";
        echo "<tr><th>Status Page ID</th><th>UUID</th><th>Page Title</th></tr>";
        
        foreach ($domains as $domain) {
            $stmt = $pdo->prepare("SELECT id, uuid, page_title FROM status_pages WHERE id = ?");
            $stmt->execute([$domain['status_page_id']]);
            $status_page = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($status_page) {
                echo "<tr>";
                echo "<td>" . htmlspecialchars($status_page['id']) . "</td>";
                echo "<td>" . htmlspecialchars($status_page['uuid']) . "</td>";
                echo "<td>" . htmlspecialchars($status_page['page_title']) . "</td>";
                echo "</tr>";
            }
        }
        
        echo "</table>";
    } else {
        echo "<p>Keine benutzerdefinierten Domains gefunden.</p>";
    }
    
    // Zeige auch die korrekte Hauptdomain
    echo "<h2>Systemumgebung</h2>";
    echo "<p>Aktuelle Domain: " . htmlspecialchars($_SERVER['HTTP_HOST']) . "</p>";
    echo "<p>Domainüberprüfung wird aktiv für folgende Domains verwendet: status.anonfile.de, localhost, localhost:80, 127.0.0.1</p>";
    
} catch (PDOException $e) {
    die('Datenbankfehler: ' . $e->getMessage());
}
?> 