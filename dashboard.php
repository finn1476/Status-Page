<?php
// index2.php – Öffentliche Statuspage, basierend auf UUID und mehreren Sensoren
require 'db.php';

// Statuspage-UUID aus GET lesen
$status_page_uuid = isset($_GET['status_page_uuid']) ? $_GET['status_page_uuid'] : '';
if (!$status_page_uuid) {
    // Fehlerseite, wenn keine UUID übergeben wurde
    ?>
    <!DOCTYPE html>
    <html lang="de">
    <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
      <title>Statuspage nicht gefunden</title>
      <link href="https://fonts.googleapis.com/css?family=Roboto:400,500,700&display=swap" rel="stylesheet">
      <style>
        body {
          font-family: 'Roboto', sans-serif;
          background: #f3f4f6;
          margin: 0;
          padding: 0;
          display: flex;
          justify-content: center;
          align-items: center;
          height: 100vh;
        }
        .error-container {
          background: #fff;
          padding: 30px;
          border-radius: 10px;
          box-shadow: 0 4px 12px rgba(0,0,0,0.08);
          text-align: center;
        }
        .error-container h1 {
          color: #1d2d44;
          font-size: 32px;
          margin-bottom: 20px;
        }
        .error-container p {
          font-size: 18px;
          color: #333;
        }
      </style>
    </head>
    <body>
      <div class="error-container">
        <h1>Statuspage nicht gefunden</h1>
        <p>Es wurde keine Statuspage-UUID übergeben.</p>
      </div>
    </body>
    </html>
    <?php
    exit;
}

// Statuspage-Datensatz anhand der UUID abrufen
$stmt = $pdo->prepare("SELECT * FROM status_pages WHERE uuid = ?");
$stmt->execute([$status_page_uuid]);
$status_page = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$status_page) {
    // Fehlerseite, wenn keine Statuspage gefunden wurde
    ?>
    <!DOCTYPE html>
    <html lang="de">
    <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
      <title>Statuspage nicht gefunden</title>
      <link href="https://fonts.googleapis.com/css?family=Roboto:400,500,700&display=swap" rel="stylesheet">
      <style>
        body {
          font-family: 'Roboto', sans-serif;
          background: #f3f4f6;
          margin: 0;
          padding: 0;
          display: flex;
          justify-content: center;
          align-items: center;
          height: 100vh;
        }
        .error-container {
          background: #fff;
          padding: 30px;
          border-radius: 10px;
          box-shadow: 0 4px 12px rgba(0,0,0,0.08);
          text-align: center;
        }
        .error-container h1 {
          color: #1d2d44;
          font-size: 32px;
          margin-bottom: 20px;
        }
        .error-container p {
          font-size: 18px;
          color: #333;
        }
      </style>
    </head>
    <body>
      <div class="error-container">
        <h1>Statuspage nicht gefunden</h1>
        <p>Die angeforderte Statuspage konnte nicht gefunden werden.</p>
      </div>
    </body>
    </html>
    <?php
    exit;
}

// Titel und benutzerdefiniertes CSS der Statuspage
$pageTitle = htmlspecialchars($status_page['page_title']);
$customCSS = !empty($status_page['custom_css']) ? $status_page['custom_css'] : "";

// Mehrere Sensoren: Versuche, aus der Spalte sensor_ids (JSON) ein Array zu erhalten
$sensorIds = [];
if (!empty($status_page['sensor_ids'])) {
    $sensorIds = json_decode($status_page['sensor_ids'], true);
}
// Fallback: Falls keine sensor_ids hinterlegt sind, nutze ggf. den vorhandenen service_id
$filterServiceId = empty($sensorIds) && !empty($status_page['service_id']) ? $status_page['service_id'] : "";
// In JavaScript übergeben wir sensorIds als Komma-separierte Liste
$sensorIdsParam = implode(',', $sensorIds);

// Hole die user_id aus dem Datensatz (basierend auf der per GET gelieferten UUID)
$userId = $status_page['user_id'];
?>

<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <!-- Google Fonts -->
  <link href="https://fonts.googleapis.com/css?family=Roboto:400,500,700&display=swap" rel="stylesheet">
  <style>
    /* Global Styles */
    body {
      font-family: 'Roboto', sans-serif;
      background: #f3f4f6;
      margin: 0;
      padding: 0;
      overflow: hidden;
    }
    .header {
      background: #1d2d44;
      color: #fff;
      padding: 20px;
      text-align: center;
      font-size: 28px;
      box-shadow: 0 3px 8px rgba(0, 0, 0, 0.1);
    }
    /* Dashboard-Layout: Zwei Spalten (links: Service Status, rechts: History & Incidents) */
    .dashboard-container {
      display: grid;
      grid-template-columns: 2fr 1fr;
      gap: 20px;
      padding: 20px;
      height: calc(100vh - 60px); /* Höhe minus Header */
    }
    /* Linke Spalte: Service Status – automatisch scrollende Liste */
    .status-section {
      background: rgba(255, 255, 255, 0.8);
      border-radius: 10px;
      padding: 20px;
      overflow: hidden;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
      display: flex;
      flex-direction: column;
      height: 90%;
    }
    .status-section .section-header {
      font-size: 24px;
      margin-bottom: 10px;
      font-weight: 500;
      color: #1d2d44;
    }
    /* Scroll-Container für die automatisch scrollende Liste */
    .scroll-container {
      position: relative;
      overflow: hidden;
    }
    /* Die Liste – im normalen Dokumentenfluss */
    .list {
      width: 100%;
    }
    /* Einzelne Listenelemente */
    .list-item {
      padding: 10px;
      margin-bottom: 5px;
      background: #fff;
      border-radius: 5px;
      box-shadow: 0 2px 4px rgba(0,0,0,0.1);
      display: flex;
      justify-content: space-between;
      align-items: center;
      font-size: 16px;
    }
    .list-item:last-child {
      margin-bottom: 0;
    }
    /* Letzte Aktualisierung */
    .last-check {
      font-size: 12px;
      color: #7f8c8d;
      text-align: right;
      padding: 5px 10px;
    }
    /* Rechte Spalte: Maintenance History & Recent Incidents */
    .info-section {
      display: flex;
      flex-direction: column;
      gap: 20px;
      overflow-y: auto;
    }
    /* Transparente Karten (Glassmorphism) */
    .transparent-card {
      background: rgba(255, 255, 255, 0.7);
      backdrop-filter: blur(8px);
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
      border-radius: 10px;
      overflow: hidden;
      margin-bottom: 20px;
    }
    .transparent-card .card-header {
      background: rgba(29, 45, 68, 0.8);
      color: #fff;
      padding: 10px 15px;
      font-size: 20px;
      font-weight: 500;
    }
    .transparent-card .card-content {
      background: transparent;
      padding: 10px 15px;
    }
    /* Tabelle */
    .table {
      width: 100%;
      border-collapse: collapse;
    }
    .table thead th {
      background: rgba(29, 45, 68, 0.8);
      color: #fff;
      font-weight: 500;
      font-size: 14px;
      padding: 8px 10px;
      text-align: left;
    }
    .table tbody td {
      padding: 8px 10px;
      font-size: 13px;
      color: #333;
      background: rgba(255, 255, 255, 0.5);
      border-bottom: 1px solid rgba(0, 0, 0, 0.1);
    }
    .table tbody tr:last-child td {
      border-bottom: none;
    }
    /* Status-Labels */
    .status {
      font-size: 14px;
      font-weight: 500;
      padding: 4px 8px;
      border-radius: 12px;
      text-transform: uppercase;
    }
    .status.up {
      background-color: #27ae60;
      color: #fff;
    }
    .status.down {
      background-color: #e74c3c;
      color: #fff;
    }
    .uptime {
      font-size: 14px;
      color: #7f8c8d;
      margin: 8px 0;
    }
    .daily-strips {
      display: flex;
      gap: 3px;
      margin-top: 8px;
    }
    .daily-strip {
      width: 8px;
      height: 30px;
      border-radius: 3px;
      cursor: pointer;
      transition: background-color 0.2s ease;
    }
    /* Lade-Overlay */
    .loading-container {
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(255, 255, 255, 0.8);
      z-index: 1000;
    }
    .loading-content {
      position: absolute;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
      text-align: center;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
    }
    .spinner {
      border: 8px solid #f3f3f3;
      border-top: 8px solid #3498db;
      border-radius: 50%;
      width: 60px;
      height: 60px;
      animation: spin 1s linear infinite;
      margin-bottom: 10px;
    }
    @keyframes spin {
      0% { transform: rotate(0deg); }
      100% { transform: rotate(360deg); }
    }
    .loading-content p {
      margin-top: 10px;
      text-align: center;
    }
    /* Uptime Popup */
    .uptime-popup {
      display: none;
      position: absolute;
      background: rgba(0, 0, 0, 0.8);
      color: #fff;
      padding: 8px 12px;
      border-radius: 5px;
      font-size: 12px;
      white-space: nowrap;
      z-index: 10;
    }
    .status-summary {
      display: flex;
      justify-content: space-between;
      padding: 10px;
      background: #fff;
      border-radius: 5px;
      box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
      margin-bottom: 10px;
    }
    .summary-item {
      font-size: 16px;
      font-weight: bold;
    }
    .summary-label {
      margin-right: 5px;
      color: #1d2d44;
    }
    .summary-value {
      color: #27ae60;
    }
    .summary-value.down {
      color: #e74c3c;
    }
    /* Globaler Status (unten rechts) mit verbesserter Optik */
    .global-status {
      position: fixed;
      bottom: 20px;
      right: 20px;
      background: linear-gradient(135deg, #1d2d44, #2c3e50);
      color: #fff;
      padding: 12px 20px;
      border-radius: 10px;
      font-size: 16px;
      font-weight: bold;
      box-shadow: 0 4px 10px rgba(0, 0, 0, 0.2);
      z-index: 1000;
      transition: transform 0.3s ease;
    }
    .global-status:hover {
      transform: scale(1.05);
    }
    .card {
  background: #fff;
  border-radius: 5px;
  box-shadow: 0 2px 4px rgba(0,0,0,0.1);
  margin: 10px;
  padding: 15px;
  text-align: left;
}

/* Layout für den Header der Karte */
.card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

/* Sensor-Name */
.card-header h2 {
  font-size: 18px;
  margin: 0;
}

/* Status-Label */
.status {
  font-size: 14px;
  font-weight: bold;
  padding: 5px 10px;
  border-radius: 3px;
  text-transform: uppercase;
}

/* Farben für Up und Down */
.status.up {
  background-color: #27ae60;
  color: #fff;
}

.status.down {
  background-color: #e74c3c;
  color: #fff;
}
  </style>
</head>
<body>
  <!-- Lade-Overlay (wird nur beim initialen Laden angezeigt) -->
  <div id="loading" class="loading-container" style="display: none;">
    <div class="loading-content">
      <div class="spinner"></div>
      <p>Loading Statusdata...</p>
    </div>
  </div>

  <div class="header">
  <?php echo $pageTitle; ?>
  </div>
  <div class="dashboard-container">
    <!-- Linke Spalte: Service Status (automatisch scrollende Liste) -->
    <div class="status-section">
      <div class="section-header">Service Status</div>
      <div class="status-summary">
        <div class="summary-item">
          <span class="summary-label">🟢 Up:</span>
          <span id="up-count" class="summary-value">0</span>
        </div>
        <div class="summary-item">
          <span class="summary-label">🔴 Down:</span>
          <span id="down-count" class="summary-value">0</span>
        </div>
      </div>
      <div id="scroll-container" class="scroll-container">
        <div id="list" class="list">
        <div id="status-cards"></div>

          <!-- Dynamische Listenelemente werden hier eingefügt -->
        </div>
      </div>
      <div class="last-check" id="last-check"></div>
    </div>
    
    <!-- Rechte Spalte: Maintenance History & Recent Incidents -->
    <div class="info-section">
      <div class="card transparent-card">
        <div class="card-header">Maintenance History</div>
        <div class="card-content">
          <table class="table" id="maintenance-history">
            <thead>
              <tr>
                <th>Datum</th>
                <th>Service</th>
                <th>Beschreibung</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <!-- Wartungshistorie wird hier geladen -->
            </tbody>
          </table>
        </div>
      </div>
      <div class="card transparent-card">
        <div class="card-header">Recent Incidents</div>
        <div class="card-content">
          <table class="table" id="recent-incidents">
            <thead>
              <tr>
                <th>Datum</th>
                <th>Service</th>
                <th>Beschreibung</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <!-- Vorfälle werden hier geladen -->
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- Globaler Status (unten rechts) -->
  <div id="global-status" class="global-status"></div>

  <!-- Uptime-Popup -->
  <div id="uptime-popup" class="uptime-popup"></div>

  <script>
    // Flag für initialen Ladevorgang
    let initialLoad = true;
    
    // Funktionen: updateSummary, fetchMaintenanceHistory, fetchRecentIncidents
    function updateSummary(data) {
      // Verwende data.sensors, da unser JSON diesen Schlüssel enthält
      let upCount = data.sensors.filter(sensor => sensor.status === 'up').length;
      let downCount = data.sensors.filter(sensor => sensor.status === 'down').length;
      document.getElementById('up-count').textContent = upCount;
      document.getElementById('down-count').textContent = downCount;
      document.getElementById('down-count').classList.toggle('down', downCount > 0);
    }
    function fetchMaintenanceHistory() {
  fetch('maintenance_history.php?status_page_uuid=<?php echo $status_page_uuid; ?>')
    .then(response => response.json())
    .then(data => {
      const tableBody = document.getElementById('maintenance-history').querySelector('tbody');
      tableBody.innerHTML = '';
      data.forEach(event => {
        const row = tableBody.insertRow();
        row.insertCell(0).textContent = event.date;
        row.insertCell(1).textContent = event.service_name;
        row.insertCell(2).textContent = event.description;
        row.insertCell(3).textContent = event.status;
      });
    })
    .catch(error => console.error('Fehler beim Laden der Wartungshistorie:', error));
}

function fetchRecentIncidents() {
  fetch('recent_incidents.php?status_page_uuid=<?php echo $status_page_uuid; ?>')
    .then(response => response.json())
    .then(data => {
      const tableBody = document.getElementById('recent-incidents').querySelector('tbody');
      tableBody.innerHTML = '';
      data.forEach(incident => {
        const row = tableBody.insertRow();
        row.insertCell(0).textContent = incident.date;
        row.insertCell(1).textContent = incident.service_name;
        row.insertCell(2).textContent = incident.description;
        row.insertCell(3).textContent = incident.status;
      });
    })
    .catch(error => console.error('Fehler beim Laden der Vorfälle:', error));
}

    
    // Beispielvariablen – bitte ggf. anpassen
    let sensorIds = "<?php echo $sensorIdsParam; ?>"; // als String (z. B. "1,2,3")
    let userId = "<?php echo $userId; ?>";
    // Beispiel-Funktion; passe sie an deine Sortierung an:
    function getSortOrder() {
      return "asc";
    }
    
    function fetchStatus() {
      const loadingElement = document.getElementById('loading');
      if (initialLoad) {
        loadingElement.style.display = 'block';
      }
      
      // Erstelle das POST-Daten-Objekt
      const postData = {
        status_page_uuid: <?php echo json_encode($status_page_uuid); ?>,
        sensor_ids: sensorIds,
        sort: getSortOrder(),
        userId: userId
      };

      fetch('status.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify(postData)
      })
      .then(response => response.json())
      .then(data => {
        // Container für Statuskarten (falls vorhanden)
        const container = document.getElementById('status-cards');
        if (container) {
          container.innerHTML = '';
        }
        
        // Überprüfe, ob das Array "sensors" existiert
        if (!data.sensors) {
          console.error('Der erwartete "sensors"-Schlüssel fehlt.');
          return;
        }
        data.sensors.forEach(sensor => {
  const card = document.createElement('div');
  card.className = 'card card-status';

  const header = document.createElement('div');
  header.className = 'card-header';

  const title = document.createElement('h2');
  title.textContent = sensor.name;

  const statusSpan = document.createElement('span');
  statusSpan.className = 'status ' + (sensor.status === 'up' ? 'up' : 'down');
  statusSpan.textContent = sensor.status.toUpperCase();

  header.appendChild(title);
  header.appendChild(statusSpan);
  card.appendChild(header);

  const container = document.getElementById('status-cards');
  if (container) {
    container.appendChild(card);
  }
});
        // Aktualisiere Zusammenfassung, rufe weitere Daten ab und berechne globale Uptime
        updateSummary(data);
        fetchMaintenanceHistory();
        fetchRecentIncidents();

        let totalUptime = 0;
        data.sensors.forEach(sensor => {
          totalUptime += parseFloat(sensor.uptime);
        });
        let globalUptime = totalUptime / data.sensors.length;
        const globalStatusEl = document.getElementById('global-status');
        const downCount = data.sensors.filter(sensor => sensor.status === 'down').length;
        if (downCount === 0) {
          globalStatusEl.textContent = `✅ All Systems Operational - Global Uptime: ${globalUptime.toFixed(2)}%`;
        } else {
          globalStatusEl.textContent = `❌ System Issues Detected - Global Uptime: ${globalUptime.toFixed(2)}%`;
        }

        // Für nahtloses Scrolling: Kopiere den Inhalt der Liste und hänge ihn unterhalb des Originals an
        const scrollContainer = document.getElementById('scroll-container');
        const listEl = document.getElementById('list');
        const existingClone = document.getElementById('list-clone');
        if (existingClone) {
          existingClone.remove();
        }
        let clone = listEl.cloneNode(true);
        clone.id = 'list-clone';
        scrollContainer.appendChild(clone);

        const now = new Date();
        document.getElementById('last-check').textContent = 'Letzte Aktualisierung: ' + now.toLocaleTimeString();

        if (initialLoad) {
          loadingElement.style.display = 'none';
          initialLoad = false;
        }
      })
      .catch(error => {
        console.error('Fehler beim Laden des Status:', error);
        if (initialLoad) {
          loadingElement.style.display = 'none';
          initialLoad = false;
        }
      });
    }

    // Funktion für das automatische Scrollen der Liste
    function autoScroll() {
      const container = document.getElementById('scroll-container');
      container.scrollTop += 1;
      const listHeight = document.getElementById('list').offsetHeight;
      if (container.scrollTop >= listHeight) {
        container.scrollTop = 0;
      }
    }

    document.addEventListener('DOMContentLoaded', function() {
      fetchStatus();
      setInterval(fetchStatus, 30000);
      setInterval(autoScroll, 30);
    });

    // Uptime-Popup bei Mouseover (falls benötigt)
    document.addEventListener('DOMContentLoaded', function() {
      const uptimePopup = document.getElementById('uptime-popup');
      document.body.addEventListener('mouseover', function(event) {
        if (event.target.classList.contains('daily-strip')) {
          const rect = event.target.getBoundingClientRect();
          uptimePopup.textContent = event.target.getAttribute('title');
          uptimePopup.style.display = 'block';
          uptimePopup.style.top = `${rect.top + window.scrollY - 30}px`;
          uptimePopup.style.left = `${rect.left + window.scrollX + 10}px`;
        }
      });
      document.body.addEventListener('mouseout', function(event) {
        if (event.target.classList.contains('daily-strip')) {
          uptimePopup.style.display = 'none';
        }
      });
    });
  </script>
</body>
</html>
