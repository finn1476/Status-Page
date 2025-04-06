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
  <title><?php echo $pageTitle; ?></title>
  <!-- Google Fonts -->
  <link href="https://fonts.googleapis.com/css?family=Roboto:400,500,700&display=swap" rel="stylesheet">
  <style>
    /* Grundlegende Styles */
    body {
      font-family: 'Roboto', sans-serif;
      background: #f3f4f6;
      margin: 0;
      padding: 0;
    }
    .header {
      background: #1d2d44;
      color: #fff;
      padding: 30px;
      text-align: center;
      box-shadow: 0 3px 8px rgba(0, 0, 0, 0.1);
      font-size: 30px;
    }
    .container {
      max-width: 1200px;
      margin: 40px auto;
      padding: 0 20px;
    }
    
    /* Sortierungsoptionen */
    .sort-options {
      text-align: center;
      margin-bottom: 20px;
    }
    .sort-options select {
      padding: 5px 10px;
      font-size: 16px;
    }
    
    /* Karten-Layout */
    .cards-wrapper {
      display: flex;
      flex-direction: column;
      gap: 20px;
      justify-content: flex-start;
    }
    
    .card {
      background: #fff;
      border-radius: 10px;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
      overflow: hidden;
      transition: transform 0.3s ease, box-shadow 0.3s ease;
    }
    
    .card:hover {
      transform: translateY(-5px);
      box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
    }
    
    .card-header {
      background: #1d2d44;
      color: #fff;
      padding: 15px 20px;
      font-size: 22px;
      font-weight: 500;
    }
    
    .card-content {
      padding: 25px;
    }
    
    .card-status {
      display: flex;
      flex-direction: column;
      padding: 25px;
    }
    
    .card-status .card-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 0;
      margin-bottom: 15px;
      background: transparent;
      color: inherit;
    }
    
    .card-status h2 {
      margin: 0;
      font-size: 22px;
      font-weight: 500;
    }
    
    .status {
      font-size: 18px;
      font-weight: 500;
      padding: 5px 10px;
      border-radius: 20px;
      text-transform: uppercase;
    }
    
    .status.up {
      background-color: #27ae60;
      color: white;
    }
    
    .status.down {
      background-color: #e74c3c;
      color: white;
    }
    
    .status.maintenance {
      background-color: #f39c12;
      color: white;
    }
    
    .uptime {
      margin: 15px 0;
      font-size: 16px;
      color: #7f8c8d;
      font-weight: 500;
    }
    
    .daily-strips {
      display: flex;
      gap: 3px;
      margin-top: 15px;
    }
    
    .daily-strip {
      width: 10px;
      height: 40px;
      border-radius: 3px;
      cursor: pointer;
      transition: background-color 0.2s ease;
    }
    
    .last-check {
      font-size: 14px;
      color: #7f8c8d;
      text-align: right;
      margin-top: 20px;
    }
    
    .uptime-popup {
      display: none;
      position: absolute;
      background: rgba(0, 0, 0, 0.8);
      color: #fff;
      padding: 8px 12px;
      border-radius: 5px;
      font-size: 14px;
      white-space: nowrap;
      z-index: 10;
    }
    
    .transparent-card {
      background: rgba(255, 255, 255, 0.7);
      backdrop-filter: blur(10px);
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
      border-radius: 10px;
      overflow: hidden;
      margin-top: 20px;
    }
    
    .transparent-card .card-header {
      background: rgba(29, 45, 68, 0.8);
      color: #fff;
      padding: 15px 20px;
      font-size: 22px;
      font-weight: 500;
    }
    
    .transparent-card .card-content {
      background: transparent;
      padding: 25px;
    }
    
    .table {
      width: 100%;
      border-collapse: collapse;
      margin: 0;
    }
    
    .table thead th {
      background: rgba(29, 45, 68, 0.8);
      color: #fff;
      font-weight: 500;
      font-size: 16px;
      padding: 12px 15px;
      text-align: left;
    }
    
    .table tbody td {
      padding: 12px 15px;
      font-size: 14px;
      color: #333;
      background: rgba(255, 255, 255, 0.5);
      border-bottom: 1px solid rgba(0, 0, 0, 0.1);
    }
    
    .table tbody tr:last-child td {
      border-bottom: none;
    }
    
    .table td.status {
      font-weight: 500;
      color: #7f8c8d;
    }
    
    .table td.status.resolved {
      color: #27ae60;
    }
    
    .table td.status.in-progress {
      color: #f39c12;
    }
    
    .table td.status.reported {
      color: #e74c3c;
    }
    
    .table td.status.scheduled {
      color: #2980b9;
    }
    
    @media (max-width: 768px) {
      .header {
        font-size: 24px;
        padding: 20px;
      }
      .container {
        padding: 0 15px;
      }
      .cards-wrapper {
        gap: 15px;
      }
      .card-content, .card-status {
        padding: 20px;
      }
      .card-header {
        font-size: 20px;
        padding: 15px 20px;
      }
      .status {
        font-size: 16px;
        padding: 4px 8px;
      }
      .uptime {
        font-size: 14px;
      }
      .daily-strips {
        flex-direction: column;
        align-items: center;
      }
      .daily-strip {
        height: 30px;
        margin: 5px 0;
      }
      .last-check {
        font-size: 12px;
        text-align: center;
      }
      .table thead th, .table tbody td {
        padding: 8px;
        font-size: 14px;
      }
      .table thead th {
        font-size: 16px;
      }
    }
    
    @media (max-width: 480px) {
      .header {
        font-size: 20px;
        padding: 15px;
      }
      .cards-wrapper {
        gap: 10px;
      }
      .card-content, .card-status {
        padding: 15px;
      }
      .card-header {
        font-size: 18px;
        padding: 10px 15px;
      }
      .status {
        font-size: 14px;
        padding: 3px 6px;
      }
      .uptime {
        font-size: 12px;
      }
      .daily-strips {
        flex-direction: row;
        justify-content: center;
        gap: 5px;
      }
      .daily-strip {
        height: 25px;
        width: 8px;
      }
      .last-check {
        font-size: 10px;
        text-align: center;
      }
      .table thead th, .table tbody td {
        padding: 6px;
        font-size: 12px;
      }
      .table thead th {
        font-size: 14px;
      }
    }
    
    .loading-container {
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(255, 255, 255, 0.8);
      z-index: 1000;
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
      margin: 0 auto;
    }
    
    .loading-content p {
      margin-top: 10px;
      text-align: center;
      width: 100%;
    }
    
    /* Benutzerdefiniertes CSS aus der Statuspage (falls gesetzt) */
    <?php echo $customCSS; ?>
  </style>
</head>
<body>
  <!-- Lade-Overlay -->
  <div id="loading" class="loading-container" style="display: none;">
    <div class="loading-content">
      <div class="spinner"></div>
      <p>Loading Statusdata...</p>
    </div>
  </div>

  <div class="header">
    <h1><?php echo $pageTitle; ?></h1>
  </div>
  <div class="container">
    <!-- Sortierungsoptionen -->
    <div class="sort-options">
      Sortierung:
      <select id="sort-order" onchange="fetchStatus()">
        <option value="name">Name</option>
        <option value="status">Status</option>
        <option value="uptime">Uptime</option>
      </select>
    </div>
    
    <!-- Sensor-Status-Karten -->
    <div class="cards-wrapper" id="status-cards"></div>
    
    <!-- Transparent Cards: Maintenance History -->
    <div class="card transparent-card">
      <div class="card-header">
        Maintenance History
      </div>
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

    <!-- Transparent Cards: Recent Incidents -->
    <div class="card transparent-card">
      <div class="card-header">
        Recent Incidents
      </div>
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

    <div class="last-check" id="last-check"></div>
  </div>

  <script>
    // Übergabe der Filter-Parameter: sensor_ids (als CSV) und service_id (Fallback)
    const sensorIds = "<?php echo $sensorIdsParam; ?>";
    const filterServiceId = "<?php echo $filterServiceId ? $filterServiceId : ''; ?>";
    // userId aus der DB (aus dem Statuspage-Datensatz)
    const userId = "<?php echo $userId; ?>";
    
    function getSortOrder() {
      return document.getElementById('sort-order').value;
    }
    
    let initialLoad = true;

    function fetchStatus() {
      const loadingElement = document.getElementById('loading');
      if (initialLoad) {
        loadingElement.style.display = 'block';
      }
      
      // Erstelle das POST-Daten-Objekt
      const postData = {
        status_page_uuid: "<?php echo $status_page_uuid; ?>",
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
        const container = document.getElementById('status-cards');
        container.innerHTML = '';

        // Wir erwarten ein Array "sensors"
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

          const uptimeText = document.createElement('p');
          uptimeText.className = 'uptime';
          uptimeText.textContent = 'Uptime (30 Tage): ' + parseFloat(sensor.uptime).toFixed(2) + '%';
          card.appendChild(uptimeText);

          if (sensor.daily && sensor.daily.length > 0) {
            const dailyContainer = document.createElement('div');
            dailyContainer.className = 'daily-strips';

            sensor.daily.forEach(day => {
              const dailyStrip = document.createElement('div');
              dailyStrip.className = 'daily-strip';
              dailyStrip.setAttribute('title', day.date + ' - ' + day.uptime + '% uptime');

              let bgColor = '#27ae60';
              if (day.uptime < 97) {
                bgColor = '#e74c3c';
              } else if (day.uptime < 99) {
                bgColor = '#f39c12';
              }
              dailyStrip.style.backgroundColor = bgColor;

              dailyContainer.appendChild(dailyStrip);
            });
            card.appendChild(dailyContainer);
          }
          container.appendChild(card);
        });

        const now = new Date();
        document.getElementById('last-check').textContent = 'Letzte Aktualisierung: ' + now.toLocaleTimeString();
        if (initialLoad) {
          loadingElement.style.display = 'none';
          initialLoad = false;
        }
      })
      .catch(error => {
        console.error('Fehler beim Laden der Sensoren:', error);
        if (initialLoad) {
          loadingElement.style.display = 'none';
          initialLoad = false;
        }
      });
    }

    function fetchMaintenanceHistory() {
      let url = 'maintenance_history.php?status_page_uuid=<?php echo $status_page_uuid; ?>';
      if (filterServiceId) {
        url += '&service_id=' + filterServiceId;
      }
      fetch(url)
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
      let url = 'recent_incidents.php?status_page_uuid=<?php echo $status_page_uuid; ?>';
      if (filterServiceId) {
        url += '&service_id=' + filterServiceId;
      }
      fetch(url)
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

    document.addEventListener('DOMContentLoaded', function() {
      fetchStatus();
      fetchMaintenanceHistory();
      fetchRecentIncidents();
      setInterval(fetchStatus, 30000);
    });

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

  <div id="uptime-popup" class="uptime-popup"></div>
</body>
</html>
