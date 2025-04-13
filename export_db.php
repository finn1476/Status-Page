<?php
// export_db.php - Skript zum Exportieren der Datenbankstruktur für Installations-/Update-Zwecke

// Sitzung starten
session_start();

// Prüfen, ob Benutzer angemeldet und Admin ist
if (!isset($_SESSION['user_id']) || !isset($_SESSION['is_admin']) || $_SESSION['is_admin'] != 1) {
    header('HTTP/1.1 403 Forbidden');
    echo "Zugriff verweigert. Nur für Administratoren.";
    exit;
}

// Konfigurationsparameter
$dbHost = 'localhost';
$dbName = 'monitoring';
$dbUser = 'root';
$dbPass = '';

// Ausgabe-Datei
$outputFile = 'database_structure.sql';

// HTML-Header für die Ausgabe
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Datenbank-Export</title>
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
    </style>
</head>
<body>
    <div class="container">
        <h1>Datenbank-Export</h1>
        
        <div class="progress mb-4">
            <div class="progress-bar progress-bar-striped progress-bar-animated" style="width: 0%" id="progress"></div>
        </div>
        <div class="log" id="log">Starte Export...</div>
        
        <div class="mt-4 text-center" id="downloadContainer" style="display: none;">
            <a href="<?php echo $outputFile; ?>" class="btn btn-primary" download>SQL-Datei herunterladen</a>
            <a href="dashboard2.php" class="btn btn-secondary ms-2">Zurück zum Dashboard</a>
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
        
        function showDownload() {
            document.getElementById('downloadContainer').style.display = 'block';
        }
    </script>
<?php
// Starten des Exports
ob_implicit_flush(true);
ob_end_flush();

// Fortschritt aktualisieren
echo "<script>updateProgress(5); appendLog('Überprüfe Datenbankverbindung...');</script>";
flush();

// Datenbankverbindung herstellen
try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "<script>appendLog('Datenbankverbindung erfolgreich hergestellt.', 'success');</script>";
    flush();
    
    // Liste aller Tabellen abrufen
    echo "<script>updateProgress(10); appendLog('Rufe Tabellenliste ab...');</script>";
    flush();
    
    $tables = [];
    $result = $pdo->query("SHOW TABLES");
    while ($row = $result->fetch(PDO::FETCH_NUM)) {
        $tables[] = $row[0];
    }
    
    echo "<script>appendLog('Gefundene Tabellen: " . count($tables) . "', 'success');</script>";
    flush();
    
    // SQL-Ausgabedatei öffnen
    echo "<script>updateProgress(15); appendLog('Öffne Ausgabedatei...');</script>";
    flush();
    
    $handle = fopen($outputFile, 'w');
    if (!$handle) {
        throw new Exception("Konnte Ausgabedatei nicht öffnen: $outputFile");
    }
    
    // SQL-Header schreiben
    fwrite($handle, "-- Status Page System - Datenbank-Export\n");
    fwrite($handle, "-- Generiert am: " . date('Y-m-d H:i:s') . "\n");
    fwrite($handle, "-- Server Version: " . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . "\n\n");
    
    fwrite($handle, "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n");
    fwrite($handle, "SET time_zone = \"+00:00\";\n\n");
    
    echo "<script>appendLog('Ausgabedatei bereit.', 'success');</script>";
    echo "<script>updateProgress(20);</script>";
    flush();
    
    // Tabellendefinitionen exportieren
    $totalTables = count($tables);
    $currentTable = 0;
    
    foreach ($tables as $table) {
        $currentTable++;
        $progress = 20 + (70 * ($currentTable / $totalTables));
        
        echo "<script>updateProgress(" . round($progress) . "); appendLog('Exportiere Struktur für: $table...');</script>";
        flush();
        
        // Tabellenerstellungs-Statement abrufen
        $stmt = $pdo->query("SHOW CREATE TABLE `$table`");
        $row = $stmt->fetch(PDO::FETCH_NUM);
        $createTable = $row[1] . ";\n\n";
        
        // Bereinigen - Auto_increment entfernen
        $createTable = preg_replace('/AUTO_INCREMENT=\d+/', '', $createTable);
        
        fwrite($handle, "-- --------------------------------------------------------\n");
        fwrite($handle, "-- Tabellenstruktur für Tabelle `$table`\n");
        fwrite($handle, "-- --------------------------------------------------------\n\n");
        
        fwrite($handle, "DROP TABLE IF EXISTS `$table`;\n");
        fwrite($handle, $createTable);
        
        echo "<script>appendLog('Struktur für $table exportiert.', 'success');</script>";
        flush();
    }
    
    // Datei schließen
    fclose($handle);
    
    echo "<script>updateProgress(100); appendLog('Export abgeschlossen!', 'success');</script>";
    echo "<script>appendLog('Die SQL-Datei wurde unter $outputFile gespeichert.', 'success');</script>";
    echo "<script>showDownload();</script>";
    flush();
    
} catch (Exception $e) {
    echo "<script>appendLog('Fehler: " . addslashes($e->getMessage()) . "', 'error');</script>";
    flush();
}
?>
</body>
</html> 