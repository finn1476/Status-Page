<?php
// Test-Skript für die Domain-Weiterleitung
require_once 'db.php';

// Aktiviere Fehlerausgabe für Debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Aktuelle Domain ermitteln mit Berücksichtigung des Reverse Proxys
function get_actual_domain() {
    // Mögliche Header, die von Reverse Proxies gesetzt werden
    $headers = [
        'HTTP_X_FORWARDED_HOST',   // Standard für viele Proxies
        'HTTP_X_FORWARDED_SERVER', // Manchmal verwendet
        'HTTP_FORWARDED',          // Neuer HTTP/2 Standard
        'HTTP_X_HOST',             // Manchmal von Sophos verwendet
        'HTTP_HOST'                // Fallback auf den normalen Host-Header
    ];
    
    foreach ($headers as $header) {
        if (isset($_SERVER[$header]) && !empty($_SERVER[$header])) {
            // Manchmal enthält der Header mehrere Werte, kommagetrennt
            $hosts = explode(',', $_SERVER[$header]);
            $host = trim($hosts[0]);
            
            // Manchmal enthält der Host auch den Port
            if (strpos($host, ':') !== false) {
                $host = explode(':', $host)[0];
            }
            
            return strtolower($host);
        }
    }
    
    // Fallback: IP-Adresse des Servers
    return isset($_SERVER['SERVER_ADDR']) ? $_SERVER['SERVER_ADDR'] : 'localhost';
}

// Erfasse die tatsächliche Domain
$current_domain = get_actual_domain();

// HTML-Header
echo '<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Domain-Weiterleitungs-Test</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 1200px; margin: 0 auto; padding: 20px; }
        .section { margin-bottom: 30px; border: 1px solid #ccc; padding: 15px; border-radius: 5px; }
        h2 { color: #333; }
        .success { color: green; }
        .error { color: red; }
        .info { color: blue; }
        .code { font-family: monospace; background: #f5f5f5; padding: 10px; border-radius: 3px; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
    </style>
</head>
<body>
    <h1>Domain-Weiterleitungs-Test</h1>';

// Verbindungsüberprüfung
echo '<div class="section">
    <h2>1. Umgebungsinformationen</h2>';
echo '<p><strong>Ermittelte Domain:</strong> ' . htmlspecialchars($current_domain) . '</p>';

// Zeigen Sie alle HTTP-Header an, die für die Domain-Erkennung relevant sein könnten
echo '<h3>HTTP-Header Analyse:</h3>';
echo '<table>';
echo '<tr><th>Header</th><th>Wert</th></tr>';

$proxy_headers = [
    'HTTP_X_FORWARDED_HOST',
    'HTTP_X_FORWARDED_SERVER',
    'HTTP_FORWARDED',
    'HTTP_X_HOST',
    'HTTP_HOST',
    'SERVER_NAME',
    'SERVER_ADDR'
];

foreach ($proxy_headers as $header) {
    echo '<tr>';
    echo '<td>' . htmlspecialchars($header) . '</td>';
    echo '<td>' . htmlspecialchars(isset($_SERVER[$header]) ? $_SERVER[$header] : 'nicht gesetzt') . '</td>';
    echo '</tr>';
}

echo '</table>';

// Alle Header anzeigen
echo '<h3>Alle HTTP-Header:</h3>';
echo '<table>';
echo '<tr><th>Header</th><th>Wert</th></tr>';

$all_headers = getallheaders();
foreach ($all_headers as $name => $value) {
    echo '<tr>';
    echo '<td>' . htmlspecialchars($name) . '</td>';
    echo '<td>' . htmlspecialchars($value) . '</td>';
    echo '</tr>';
}

echo '</table>';

echo '<p><strong>Request URI:</strong> ' . htmlspecialchars($_SERVER['REQUEST_URI']) . '</p>';
echo '<p><strong>Server IP:</strong> ' . htmlspecialchars($_SERVER['SERVER_ADDR']) . '</p>';
echo '<p><strong>Remote IP:</strong> ' . htmlspecialchars($_SERVER['REMOTE_ADDR']) . '</p>';
echo '<p><strong>PHP Version:</strong> ' . phpversion() . '</p>';
echo '</div>';

// Datenbankverbindung überprüfen
echo '<div class="section">
    <h2>2. Datenbankverbindungstest</h2>';
try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo '<p class="success">Datenbankverbindung erfolgreich hergestellt!</p>';
    echo '<p><strong>Host:</strong> ' . $dbHost . '</p>';
    echo '<p><strong>Datenbank:</strong> ' . $dbName . '</p>';
} catch (PDOException $e) {
    echo '<p class="error">Datenbankverbindung fehlgeschlagen: ' . $e->getMessage() . '</p>';
    die('</div></body></html>');
}
echo '</div>';

// Überprüfen, ob die Tabelle existiert
echo '<div class="section">
    <h2>3. Tabellen-Überprüfung</h2>';
try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'custom_domains'");
    if ($stmt->rowCount() > 0) {
        echo '<p class="success">Die Tabelle "custom_domains" existiert!</p>';
        
        // Tabellenstruktur anzeigen
        $stmt = $pdo->query("DESCRIBE custom_domains");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo '<h3>Tabellenstruktur:</h3>';
        echo '<table>';
        echo '<tr><th>Feld</th><th>Typ</th><th>Null</th><th>Schlüssel</th><th>Standard</th><th>Extra</th></tr>';
        foreach ($columns as $column) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars($column['Field']) . '</td>';
            echo '<td>' . htmlspecialchars($column['Type']) . '</td>';
            echo '<td>' . htmlspecialchars($column['Null']) . '</td>';
            echo '<td>' . htmlspecialchars($column['Key']) . '</td>';
            echo '<td>' . (isset($column['Default']) ? htmlspecialchars($column['Default']) : 'NULL') . '</td>';
            echo '<td>' . htmlspecialchars($column['Extra']) . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    } else {
        echo '<p class="error">Die Tabelle "custom_domains" existiert nicht!</p>';
    }
    
    $stmt = $pdo->query("SHOW TABLES LIKE 'status_pages'");
    if ($stmt->rowCount() > 0) {
        echo '<p class="success">Die Tabelle "status_pages" existiert!</p>';
    } else {
        echo '<p class="error">Die Tabelle "status_pages" existiert nicht!</p>';
    }
} catch (PDOException $e) {
    echo '<p class="error">Fehler bei der Tabellenüberprüfung: ' . $e->getMessage() . '</p>';
}
echo '</div>';

// Überprüfen, ob es Einträge in der Tabelle gibt
echo '<div class="section">
    <h2>4. Datenüberprüfung</h2>';
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM custom_domains");
    $count = $stmt->fetchColumn();
    echo '<p>Anzahl der Datensätze in der Tabelle "custom_domains": <strong>' . $count . '</strong></p>';
    
    if ($count > 0) {
        // Aktuelle Domains anzeigen
        $stmt = $pdo->query("SELECT * FROM custom_domains LIMIT 10");
        $domains = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo '<h3>Aktuelle Domains:</h3>';
        echo '<table>';
        echo '<tr><th>ID</th><th>Domain</th><th>Status Page ID</th><th>User ID</th><th>Verified</th><th>SSL Status</th><th>Created At</th></tr>';
        foreach ($domains as $domain) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars($domain['id']) . '</td>';
            echo '<td>' . htmlspecialchars($domain['domain']) . '</td>';
            echo '<td>' . htmlspecialchars($domain['status_page_id']) . '</td>';
            echo '<td>' . htmlspecialchars($domain['user_id']) . '</td>';
            echo '<td>' . htmlspecialchars($domain['verified']) . '</td>';
            echo '<td>' . htmlspecialchars($domain['ssl_status']) . '</td>';
            echo '<td>' . htmlspecialchars($domain['created_at']) . '</td>';
            echo '</tr>';
        }
        echo '</table>';
        
        // Status Pages anzeigen
        $stmt = $pdo->query("SELECT id, uuid, page_title FROM status_pages WHERE id IN (SELECT DISTINCT status_page_id FROM custom_domains) LIMIT 10");
        $pages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo '<h3>Zugehörige Status Pages:</h3>';
        if (count($pages) > 0) {
            echo '<table>';
            echo '<tr><th>ID</th><th>UUID</th><th>Page Title</th></tr>';
            foreach ($pages as $page) {
                echo '<tr>';
                echo '<td>' . htmlspecialchars($page['id']) . '</td>';
                echo '<td>' . htmlspecialchars($page['uuid']) . '</td>';
                echo '<td>' . htmlspecialchars($page['page_title']) . '</td>';
                echo '</tr>';
            }
            echo '</table>';
        } else {
            echo '<p class="error">Keine zugehörigen Status Pages gefunden!</p>';
        }
    }
} catch (PDOException $e) {
    echo '<p class="error">Fehler bei der Datenüberprüfung: ' . $e->getMessage() . '</p>';
}
echo '</div>';

// Manuelle Domain-Suche
echo '<div class="section">
    <h2>5. Manuelle Domain-Suche und Weiterleitungstest</h2>
    <form method="get" action="">
        <label for="domain">Domain zum Testen:</label>
        <input type="text" id="domain" name="domain" value="' . htmlspecialchars($current_domain) . '" style="width: 300px;">
        <button type="submit">Testen</button>
    </form>';

if (isset($_GET['domain'])) {
    $test_domain = trim(strtolower($_GET['domain']));
    echo '<h3>Testergebnis für: ' . htmlspecialchars($test_domain) . '</h3>';
    
    try {
        // Hauptdomains definieren
        $main_domains = ['status.anonfile.de', 'localhost', 'localhost:80', '127.0.0.1'];
        
        if (in_array($test_domain, $main_domains)) {
            echo '<p class="info">Die angegebene Domain ist eine Hauptdomain und würde nicht weitergeleitet werden.</p>';
        } else if (filter_var($test_domain, FILTER_VALIDATE_IP)) {
            echo '<p class="info">Die angegebene Domain ist eine IP-Adresse und würde nicht weitergeleitet werden.</p>';
        } else {
            // In der Datenbank nach der Domain suchen
            $stmt = $pdo->prepare("SELECT * FROM custom_domains WHERE domain = ? AND verified = 1");
            $stmt->execute([$test_domain]);
            $domain = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($domain) {
                echo '<p class="success">Die Domain wurde in der Datenbank gefunden!</p>';
                echo '<pre class="code">' . print_r($domain, true) . '</pre>';
                
                // UUID der Status-Page abrufen
                $stmt = $pdo->prepare("SELECT id, uuid, page_title FROM status_pages WHERE id = ?");
                $stmt->execute([$domain['status_page_id']]);
                $status_page = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($status_page) {
                    echo '<p class="success">Die zugehörige Status-Page wurde gefunden!</p>';
                    echo '<pre class="code">' . print_r($status_page, true) . '</pre>';
                    
                    $redirect_url = "status_page.php?status_page_uuid=" . $status_page['uuid'];
                    echo '<p class="success">Die Weiterleitung würde funktionieren zu: <a href="' . htmlspecialchars($redirect_url) . '">' . htmlspecialchars($redirect_url) . '</a></p>';
                } else {
                    echo '<p class="error">Die zugehörige Status-Page mit ID ' . htmlspecialchars($domain['status_page_id']) . ' wurde nicht gefunden!</p>';
                }
            } else {
                echo '<p class="error">Die Domain wurde in der Datenbank nicht gefunden!</p>';
                
                // Erweiterte Suche für Wildcards
                $domain_parts = explode('.', $test_domain);
                if (count($domain_parts) > 2) {
                    array_shift($domain_parts);
                    $wildcard_domain = '*.' . implode('.', $domain_parts);
                    
                    echo '<p class="info">Teste Wildcard-Domain: ' . htmlspecialchars($wildcard_domain) . '</p>';
                    
                    $stmt = $pdo->prepare("SELECT * FROM custom_domains WHERE domain = ? AND verified = 1");
                    $stmt->execute([$wildcard_domain]);
                    $domain = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($domain) {
                        echo '<p class="success">Die Wildcard-Domain wurde in der Datenbank gefunden!</p>';
                        echo '<pre class="code">' . print_r($domain, true) . '</pre>';
                        
                        // UUID der Status-Page abrufen
                        $stmt = $pdo->prepare("SELECT id, uuid, page_title FROM status_pages WHERE id = ?");
                        $stmt->execute([$domain['status_page_id']]);
                        $status_page = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($status_page) {
                            echo '<p class="success">Die zugehörige Status-Page wurde gefunden!</p>';
                            echo '<pre class="code">' . print_r($status_page, true) . '</pre>';
                            
                            $redirect_url = "status_page.php?status_page_uuid=" . $status_page['uuid'];
                            echo '<p class="success">Die Weiterleitung würde funktionieren zu: <a href="' . htmlspecialchars($redirect_url) . '">' . htmlspecialchars($redirect_url) . '</a></p>';
                        } else {
                            echo '<p class="error">Die zugehörige Status-Page mit ID ' . htmlspecialchars($domain['status_page_id']) . ' wurde nicht gefunden!</p>';
                        }
                    } else {
                        echo '<p class="error">Keine Wildcard-Domain gefunden!</p>';
                    }
                }
            }
        }
    } catch (PDOException $e) {
        echo '<p class="error">Fehler beim Domain-Test: ' . $e->getMessage() . '</p>';
    }
}
echo '</div>';

// Anleitung für Administratoren
echo '<div class="section">
    <h2>6. Tipps zur Fehlerbehebung</h2>
    <ol>
        <li>Stellen Sie sicher, dass die Domain in der Tabelle <code>custom_domains</code> vorhanden ist.</li>
        <li>Überprüfen Sie, ob die Domain als <code>verified = 1</code> markiert ist.</li>
        <li>Überprüfen Sie, ob die Status-Page-ID gültig ist und auf eine existierende Status-Page verweist.</li>
        <li>Wenn es sich um eine Subdomain handelt, prüfen Sie, ob eine entsprechende Wildcard-Domain existiert.</li>
        <li>Überprüfen Sie die Fehlerprotokolle des Webservers auf mögliche Weiterleitungsprobleme.</li>
        <li>Stellen Sie sicher, dass der Webserver korrekt für die Domain konfiguriert ist.</li>
    </ol>
</div>';

// Hinzufügen einer Domain zu Testzwecken
echo '<div class="section">
    <h2>7. Test-Domain hinzufügen</h2>
    <form method="post" action="">
        <input type="hidden" name="action" value="add_test_domain">
        <div style="margin-bottom: 10px;">
            <label for="test_domain">Domain:</label>
            <input type="text" id="test_domain" name="test_domain" required style="width: 300px;">
        </div>
        <div style="margin-bottom: 10px;">
            <label for="test_status_page_id">Status Page ID:</label>
            <input type="number" id="test_status_page_id" name="test_status_page_id" required>
        </div>
        <div style="margin-bottom: 10px;">
            <label for="test_user_id">User ID:</label>
            <input type="number" id="test_user_id" name="test_user_id" required>
        </div>
        <button type="submit">Test-Domain hinzufügen</button>
    </form>';

if (isset($_POST['action']) && $_POST['action'] === 'add_test_domain') {
    $test_domain = trim(strtolower($_POST['test_domain']));
    $test_status_page_id = (int)$_POST['test_status_page_id'];
    $test_user_id = (int)$_POST['test_user_id'];
    
    try {
        // Überprüfen, ob die Status-Page existiert
        $stmt = $pdo->prepare("SELECT id FROM status_pages WHERE id = ?");
        $stmt->execute([$test_status_page_id]);
        if ($stmt->rowCount() === 0) {
            echo '<p class="error">Die angegebene Status-Page-ID existiert nicht!</p>';
        } else {
            // Überprüfen, ob die Domain bereits existiert
            $stmt = $pdo->prepare("SELECT id FROM custom_domains WHERE domain = ?");
            $stmt->execute([$test_domain]);
            if ($stmt->rowCount() > 0) {
                echo '<p class="error">Die Domain existiert bereits in der Datenbank!</p>';
            } else {
                // Domain hinzufügen
                $stmt = $pdo->prepare("INSERT INTO custom_domains (domain, status_page_id, user_id, verified, ssl_status) VALUES (?, ?, ?, 1, 'pending')");
                $stmt->execute([$test_domain, $test_status_page_id, $test_user_id]);
                
                echo '<p class="success">Test-Domain wurde erfolgreich hinzugefügt! ID: ' . $pdo->lastInsertId() . '</p>';
            }
        }
    } catch (PDOException $e) {
        echo '<p class="error">Fehler beim Hinzufügen der Test-Domain: ' . $e->getMessage() . '</p>';
    }
}
echo '</div>';

echo '</body></html>';
?> 