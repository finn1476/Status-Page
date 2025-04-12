<?php
// status_page.php – Öffentliche Statuspage, basierend auf UUID und mehreren Sensoren
require 'db.php';
require_once 'email_config.php';

try {
    // Initialize PDO connection
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Initialize email configuration
    $emailConfig = new EmailConfig($pdo);

    // Statuspage-UUID aus GET lesen
    $status_page_uuid = isset($_GET['status_page_uuid']) ? $_GET['status_page_uuid'] : '';
    if (!$status_page_uuid) {
        die('Statuspage UUID fehlt');
    }

    // Statuspage-Daten abrufen
    $stmt = $pdo->prepare("
        SELECT sp.*, u.email as user_email 
        FROM status_pages sp 
        LEFT JOIN users u ON sp.user_id = u.id 
        WHERE sp.uuid = ?
    ");
    $stmt->execute([$status_page_uuid]);
    $status_page = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$status_page) {
        die('Statuspage nicht gefunden');
    }

    // Email Subscription verarbeiten
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['subscribe'])) {
        $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
        $message_type = 'error';
        $message = '';

        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            // Check if email is already registered
            $stmt = $pdo->prepare("SELECT id FROM email_subscribers WHERE email = ? AND status_page_id = ?");
            $stmt->execute([$email, $status_page['id']]);
            
            if ($stmt->rowCount() == 0) {
                // Check subscriber limit
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) as subscriber_count, ut.max_email_subscribers 
                    FROM email_subscribers es
                    JOIN status_pages sp ON es.status_page_id = sp.id
                    LEFT JOIN user_subscriptions us ON sp.user_id = us.user_id
                    LEFT JOIN user_tiers ut ON us.tier_id = ut.id
                    WHERE sp.id = ?
                ");
                $stmt->execute([$status_page['id']]);
                $subscriber_info = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $max_subscribers = $subscriber_info['max_email_subscribers'] ?? 10; // Default to 10 for free tier
                
                if ($subscriber_info['subscriber_count'] < $max_subscribers) {
                    // Generate verification token (32 bytes = 64 hex characters)
                    $verification_token = bin2hex(random_bytes(32));
                    $expires_at = date('Y-m-d H:i:s', strtotime('+24 hours'));
                    
                    // Insert subscriber with verification token
                    $stmt = $pdo->prepare("
                        INSERT INTO email_subscribers (email, status_page_id, verification_token, expires_at, status) 
                        VALUES (?, ?, ?, ?, 'pending')
                    ");
                    $stmt->execute([$email, $status_page['id'], $verification_token, $expires_at]);
                    
                    // Send verification email
                    $verification_link = "https://" . $_SERVER['HTTP_HOST'] . "/verify_subscription.php?token=" . $verification_token;
                    $to = $email;
                    $subject = "Verify your subscription for " . htmlspecialchars($status_page['page_title']);
                    $message = "
                        <html>
                        <head>
                            <title>Verify your subscription</title>
                        </head>
                        <body>
                            <h2>Verify your subscription</h2>
                            <p>Thank you for subscribing to updates for " . htmlspecialchars($status_page['page_title']) . ".</p>
                            <p>Please click the link below to verify your email address:</p>
                            <p><a href='{$verification_link}'>{$verification_link}</a></p>
                            <p>This link will expire in 24 hours.</p>
                            <p>If you didn't request this subscription, you can safely ignore this email.</p>
                            <br>
                            <p>Best regards,<br>" . htmlspecialchars($status_page['page_title']) . " Team</p>
                        </body>
                        </html>
                    ";
                    
                    try {
                        if ($emailConfig->sendEmail($to, $subject, $message)) {
                            $message_type = 'success';
                            $message = "Please check your email to verify your subscription.";
                        } else {
                            // Get the detailed error message
                            $error_details = $emailConfig->getLastError();
                            
                            // Delete the failed subscription
                            $stmt = $pdo->prepare("DELETE FROM email_subscribers WHERE email = ? AND status_page_id = ? AND verification_token = ?");
                            $stmt->execute([$email, $status_page['id'], $verification_token]);
                            
                            $message_type = 'error';
                            $message = "Failed to send verification email. Error details:<br><pre>" . htmlspecialchars($error_details) . "</pre>";
                        }
                    } catch (Exception $e) {
                        // Delete the failed subscription
                        $stmt = $pdo->prepare("DELETE FROM email_subscribers WHERE email = ? AND status_page_id = ? AND verification_token = ?");
                        $stmt->execute([$email, $status_page['id'], $verification_token]);
                        
                        $message_type = 'error';
                        $message = "Error sending verification email: " . $e->getMessage();
                    }
                } else {
                    $message_type = 'warning';
                    $message = "Maximum number of subscribers reached for this status page.";
                }
            } else {
                $message_type = 'info';
                $message = "This email is already registered for updates.";
            }
        } else {
            $message_type = 'error';
            $message = "Please enter a valid email address.";
        }
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
} catch (Exception $e) {
    die('Database connection failed: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - Status</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #0d6efd;
            --secondary-color: #0dcaf0;
            --success-color: #198754;
            --warning-color: #ffc107;
            --danger-color: #dc3545;
            --text-color: #333;
            --light-text: #6c757d;
            --background-color: #f8f9fa;
            --card-background: #ffffff;
            --border-radius: 10px;
            --box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--background-color);
            color: var(--text-color);
        }

        .hero-section {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
            padding: 80px 0;
            margin-bottom: 50px;
            position: relative;
            overflow: hidden;
        }

        .hero-section .row {
            position: relative;
            z-index: 2;
        }

        .hero-section .col-lg-8 {
            padding-right: 40px;
        }

        .hero-section .col-lg-4 {
            padding-left: 40px;
        }

        .hero-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(45deg, rgba(255,255,255,0.1) 25%, transparent 25%),
                        linear-gradient(-45deg, rgba(255,255,255,0.1) 25%, transparent 25%),
                        linear-gradient(45deg, transparent 75%, rgba(255,255,255,0.1) 75%),
                        linear-gradient(-45deg, transparent 75%, rgba(255,255,255,0.1) 75%);
            background-size: 20px 20px;
            opacity: 0.1;
        }

        .hero-title {
            font-size: 3.5rem;
            font-weight: bold;
            margin-bottom: 20px;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.2);
        }

        .hero-subtitle {
            font-size: 1.5rem;
            margin-bottom: 30px;
            opacity: 0.9;
        }

        .subscription-form {
            background: rgba(255, 255, 255, 0.95);
            padding: 30px;
            border-radius: var(--border-radius);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(10px);
            transform: none;
            margin: 0;
            position: relative;
        }

        .subscription-form h2 {
            margin: 0 0 20px 0;
            color: var(--primary-color);
            font-size: 24px;
            font-weight: 700;
        }

        .subscription-form .form-group {
            margin-bottom: 20px;
        }

        .subscription-form .form-group label {
            color: var(--text-color);
            font-weight: 500;
            margin-bottom: 8px;
        }

        .subscription-form .form-group input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 16px;
            transition: all 0.3s ease;
            background: rgba(255, 255, 255, 0.9);
        }

        .subscription-form .form-group input:focus {
            border-color: var(--primary-color);
            outline: none;
            box-shadow: 0 0 0 3px rgba(13, 110, 253, 0.1);
        }

        .subscription-form .btn-custom {
            width: 100%;
            padding: 12px 30px;
            font-size: 1.1rem;
            border-radius: 30px;
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            border: none;
            color: white;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .subscription-form .btn-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .success-message {
            background: var(--success-color);
            color: #fff;
            padding: 12px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: 500;
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from {
                transform: translateY(-10px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .sort-options {
            text-align: center;
            margin-bottom: 30px;
            background: var(--card-background);
            padding: 20px;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
        }

        .sort-options select {
            padding: 10px 20px;
            font-size: 16px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            background: var(--card-background);
            cursor: pointer;
            transition: border-color 0.3s ease;
        }

        .sort-options select:focus {
            border-color: var(--primary-color);
            outline: none;
        }

        .cards-wrapper {
            display: flex;
            flex-direction: column;
            gap: 25px;
            margin-bottom: 40px;
        }

        .card {
            background: var(--card-background);
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            overflow: hidden;
            transition: transform 0.3s ease;
        }

        .card:hover {
            transform: translateY(-5px);
        }

        .card-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: #fff;
            padding: 20px;
            font-size: 22px;
            font-weight: 500;
            display: flex;
            justify-content: space-between;
            align-items: center;
            
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
            margin-bottom: 20px;
            background: transparent;
            color: inherit;
        }

        .card-status h2 {
            margin: 0;
            font-size: 22px;
            font-weight: 500;
            color: var(--primary-color);
        }

        .status {
            font-size: 16px;
            font-weight: 500;
            padding: 8px 16px;
            border-radius: 20px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status.up {
            background-color: var(--success-color);
            color: white;
        }

        .status.down {
            background-color: var(--danger-color);
            color: white;
        }

        .status.maintenance {
            background-color: var(--warning-color);
            color: white;
        }

        .uptime {
            margin: 15px 0;
            font-size: 16px;
            color: var(--light-text);
            font-weight: 500;
        }

        .daily-strips {
            display: flex;
            gap: 3px;
            margin-top: 15px;
            height: 40px;
            align-items: flex-end;
            background: rgba(0, 0, 0, 0.05);
            padding: 5px 5px 5px 5px;
            border-radius: 5px;
            flex-direction: row-reverse;
            position: relative;
            margin-bottom: 25px;
            margin-top: 30px;
        }

        .daily-strip {
            flex: 1;
            min-width: 4px;
            border-radius: 2px;
            transition: transform 0.2s ease;
            position: relative;
            max-height: 30px;
        }

        .daily-strip:hover {
            transform: scaleY(1.05);
            box-shadow: 0 0 5px rgba(0, 0, 0, 0.2);
            z-index: 10;
        }

        .strip-tooltip {
            position: absolute;
            top: -60px;
            left: 50%;
            transform: translateX(-50%);
            background: #000;
            color: #fff;
            padding: 6px 10px;
            border-radius: 4px;
            font-size: 12px;
            white-space: nowrap;
            z-index: 9999;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.2s ease, visibility 0.2s ease;
            pointer-events: none;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
        }

        .daily-strip:hover .strip-tooltip {
            opacity: 1;
            visibility: visible;
        }

        .last-check {
            font-size: 14px;
            color: var(--light-text);
            text-align: right;
            margin-top: 20px;
        }

        .uptime-popup {
            display: none;
            position: fixed;
            background: rgba(0, 0, 0, 0.8);
            color: #fff;
            padding: 8px 12px;
            border-radius: 5px;
            font-size: 14px;
            white-space: nowrap;
            z-index: 1090000;
            pointer-events: none;
        }

        .transparent-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border-radius: var(--border-radius);
            overflow: hidden;
            margin-top: 20px;
            transition: transform 0.3s ease;
        }

        .transparent-card:hover {
            transform: translateY(-5px);
        }

        .transparent-card .card-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: #fff;
            padding: 20px;
            font-size: 22px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .transparent-card .card-header i {
            font-size: 24px;
        }

        .transparent-card .card-content {
            background: transparent;
            padding: 25px;
        }

        .table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            margin: 0;
        }

        .table thead th {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: #fff;
            font-weight: 500;
            font-size: 16px;
            padding: 15px 20px;
            text-align: left;
            border: none;
        }

        .table tbody td {
            padding: 15px 20px;
            font-size: 14px;
            color: var(--text-color);
            background: rgba(255, 255, 255, 0.5);
            border-bottom: 1px solid rgba(0, 0, 0, 0.1);
        }

        .table tbody tr:last-child td {
            border-bottom: none;
        }

        .table td.status {
            font-weight: 500;
        }

        .table td.status.resolved {
            color: var(--success-color);
        }

        .table td.status.in-progress {
            color: var(--warning-color);
        }

        .table td.status.reported {
            color: var(--danger-color);
        }

        .table td.status.scheduled {
            color: var(--primary-color);
        }

        .maintenance-time {
            font-size: 14px;
            color: var(--text-color);
            margin-top: 5px;
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .maintenance-time span {
            color: var(--warning-color);
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .maintenance-time span i {
            font-size: 16px;
        }

        .date-separator {
            color: var(--light-text);
            font-size: 12px;
            margin-top: 2px;
        }

        .loading-container {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.95);
            z-index: 1000;
            display: flex;
            justify-content: center;
            align-items: center;
            backdrop-filter: blur(5px);
        }

        .spinner {
            border: 8px solid #f3f3f3;
            border-top: 8px solid var(--primary-color);
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
            text-align: center;
        }

        .loading-content p {
            margin-top: 10px;
            color: var(--primary-color);
            font-size: 16px;
            font-weight: 500;
        }

        @media (max-width: 991px) {
            .hero-section .col-lg-8 {
                padding-right: 15px;
                margin-bottom: 30px;
            }

            .hero-section .col-lg-4 {
                padding-left: 15px;
            }

            .subscription-form {
                margin-top: 20px;
            }
        }

        @media (max-width: 768px) {
            .hero-section {
                padding: 60px 0;
            }

            .hero-title {
                font-size: 2.5rem;
            }

            .hero-subtitle {
                font-size: 1.2rem;
            }

            .subscription-form {
                padding: 20px;
            }
        }

        /* Notification styling */
        .notification {
            padding: 10px;
            margin: 10px 0;
            border-radius: 5px;
            animation: fadeIn 0.5s;
        }
        
        .notification-success {
            background-color: rgba(25, 135, 84, 0.1);
            border-left: 4px solid #198754;
            color: #0f5132;
        }
        
        .notification-warning {
            background-color: rgba(255, 193, 7, 0.1);
            border-left: 4px solid #ffc107;
            color: #664d03;
        }
        
        .notification-error {
            background-color: rgba(220, 53, 69, 0.1);
            border-left: 4px solid #dc3545;
            color: #842029;
        }
        
        .notification-info {
            background-color: rgba(13, 110, 253, 0.1);
            border-left: 4px solid #0d6efd;
            color: #084298;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        <?php echo $customCSS; ?>

        .uptime-popup:hover {
            display: none;
        }
        
        /* Incident Styles */
        #incidents-section {
            background-color: #fff;
            padding: 20px;
            border-radius: 5px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        .incident-item {
            border: 1px solid #eee;
            border-radius: 5px;
            padding: 15px;
            background-color: #fff;
        }
        .incident-header {
            margin-bottom: 10px;
        }
        .incident-title {
            font-size: 18px;
            font-weight: 600;
        }
        .incident-meta {
            font-size: 14px;
        }
        .incident-description {
            font-size: 14px;
            color: #555;
        }
        .incident-updates {
            border-top: 1px solid #eee;
            padding-top: 15px;
            margin-top: 15px;
        }
        .updates-title {
            font-size: 15px;
            font-weight: 600;
            color: #666;
        }
        .update-item {
            border-radius: 3px;
            padding: 8px 12px;
            background-color: #f9f9f9;
            margin-bottom: 10px;
        }
        .update-meta {
            font-size: 13px;
        }
        .update-message {
            font-size: 14px;
            margin-top: 5px;
        }
        .border-success { border-color: #198754 !important; }
        .border-primary { border-color: #0d6efd !important; }
        .border-warning { border-color: #ffc107 !important; }
        .border-info { border-color: #0dcaf0 !important; }
        .border-secondary { border-color: #6c757d !important; }
        .border-danger { border-color: #dc3545 !important; }
    </style>
</head>
<body>
    <div id="loading" class="loading-container" style="display: none;">
        <div class="loading-content">
            <div class="spinner"></div>
            <p>Loading Statusdata...</p>
        </div>
    </div>

    <section class="hero-section">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-8 text-start">
                    <h1 class="hero-title"><?php echo $pageTitle; ?></h1>
                    <p class="hero-subtitle">Monitoring and Status of all Services</p>
                </div>
                <div class="col-lg-4">
                    <div class="subscription-form">
                        <h2>Get Status Updates</h2>
                        <?php if (isset($message)): ?>
                            <div class="notification notification-<?php echo $message_type; ?>">
                                <?php echo $message; ?>
                            </div>
                        <?php endif; ?>
                        <form method="POST">
                            <div class="form-group">
                                <label for="email">Email Address</label>
                                <input type="email" id="email" name="email" required placeholder="your@email.com">
                            </div>
                            <button type="submit" name="subscribe" class="btn btn-custom">Subscribe</button>
                        </form>
                        <div class="mt-3 text-center">
                            <a href="manage_subscription.php?status_page_uuid=<?php echo $status_page_uuid; ?>" class="text-decoration-none">
                                <small>Manage your subscription</small>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <div class="container">
        <div class="sort-options">
            Sort by:
            <select id="sort-order" onchange="fetchStatus()">
                <option value="name">Name</option>
                <option value="status">Status</option>
                <option value="uptime">Uptime</option>
            </select>
        </div>

        <div class="cards-wrapper" id="status-cards"></div>
        
        <div class="card transparent-card">
            <div class="card-header">
                <i class="bi bi-tools"></i> Maintenance History
            </div>
            <div class="card-content">
                <table class="table" id="maintenance-history">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Service</th>
                            <th>Description</th>
                            <th>Time Period</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Maintenance history will be loaded here -->
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card transparent-card">
<!-- Incidents Section -->
<section id="incidents-section" class="container mt-5 mb-5">
        <h3>Recent Incidents</h3>
        <div class="mt-4">
            <?php
            // Incidents direkt aus der Datenbank holen
            $incidentsQuery = "
                SELECT i.*, c.name as service_name 
                FROM incidents i 
                LEFT JOIN config c ON i.service_id = c.id 
                WHERE i.status_page_id = ?
                ORDER BY i.date DESC LIMIT 10
            ";
            $incidentsStmt = $pdo->prepare($incidentsQuery);
            $incidentsStmt->execute([$status_page['id']]);
            $incidents = $incidentsStmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (count($incidents) > 0) {
                foreach ($incidents as $incident) {
                    // Updates für diesen Incident laden
                    $updatesQuery = "
                        SELECT iu.*, u.name as username 
                        FROM incident_updates iu
                        JOIN users u ON iu.created_by = u.id
                        WHERE iu.incident_id = ?
                        ORDER BY iu.update_time DESC
                    ";
                    $updatesStmt = $pdo->prepare($updatesQuery);
                    $updatesStmt->execute([$incident['id']]);
                    $updates = $updatesStmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    // Status-Class bestimmen
                    $statusClass = 'secondary';
                    if ($incident['status'] === 'resolved') $statusClass = 'success';
                    else if ($incident['status'] === 'in progress') $statusClass = 'primary';
                    else if ($incident['status'] === 'investigating') $statusClass = 'warning';
                    else if ($incident['status'] === 'identified') $statusClass = 'info';
                    else if ($incident['status'] === 'monitoring') $statusClass = 'secondary';
                    
                    // Impact-Class bestimmen
                    $impactClass = 'info';
                    if ($incident['impact'] === 'critical') $impactClass = 'danger';
                    else if ($incident['impact'] === 'major') $impactClass = 'warning';
                    
                    // Datum formatieren
                    $date = new DateTime($incident['date']);
                    $formattedDate = $date->format('d.m.Y H:i');
                    ?>
                    <div class="incident-item mb-4">
                        <div class="incident-header d-flex justify-content-between align-items-start">
                            <div>
                                <h5 class="incident-title mb-1"><?php echo htmlspecialchars($incident['title'] ?: 'Unnamed Incident'); ?></h5>
                                <div class="incident-meta text-muted mb-2">
                                    <small><?php echo $formattedDate; ?> - <span class="badge bg-<?php echo $impactClass; ?>"><?php echo htmlspecialchars($incident['impact']); ?></span></small>
                                </div>
                            </div>
                            <span class="badge bg-<?php echo $statusClass; ?>"><?php echo htmlspecialchars($incident['status']); ?></span>
                        </div>
                        <div class="incident-description mb-3"><?php echo nl2br(htmlspecialchars($incident['description'])); ?></div>
                        
                        <!-- Incident Updates -->
                        <div class="incident-updates">
                            <h6 class="updates-title" onclick="toggleUpdates(this)" style="cursor: pointer;">
                                <i class="fas fa-chevron-down me-1"></i> Updates anzeigen
                            </h6>
                            <div class="updates-content" style="display: none;">
                                <?php if (count($updates) > 0): ?>
                                    <?php foreach ($updates as $update): 
                                        // Update Status-Class bestimmen
                                        $updateStatusClass = 'secondary';
                                        if ($update['status'] === 'resolved') $updateStatusClass = 'success';
                                        else if ($update['status'] === 'in progress') $updateStatusClass = 'primary';
                                        else if ($update['status'] === 'investigating') $updateStatusClass = 'warning';
                                        else if ($update['status'] === 'identified') $updateStatusClass = 'info';
                                        else if ($update['status'] === 'monitoring') $updateStatusClass = 'secondary';
                                        
                                        // Update Zeit formatieren
                                        $updateTime = new DateTime($update['update_time']);
                                        $formattedUpdateTime = $updateTime->format('d.m.Y H:i');
                                    ?>
                                    <div class="update-item mb-3 ps-3 border-start border-<?php echo $updateStatusClass; ?>">
                                        <div class="update-meta d-flex justify-content-between align-items-center mb-1">
                                            <span class="update-time text-muted"><small><?php echo $formattedUpdateTime; ?></small></span>
                                            <span class="badge bg-<?php echo $updateStatusClass; ?>"><?php echo htmlspecialchars($update['status']); ?></span>
                                        </div>
                                        <div class="update-message"><?php echo nl2br(htmlspecialchars($update['message'])); ?></div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <p class="text-muted">Keine Updates vorhanden.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php
                }
            } else {
                echo '<p class="text-muted">Keine Incidents vorhanden.</p>';
            }
            ?>
        </div>
    </section>

        
    </div>
    <div class="last-check" id="last-check"></div>
    

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const sensorIds = "<?php echo $sensorIdsParam; ?>";
        const filterServiceId = "<?php echo $filterServiceId ? $filterServiceId : ''; ?>";
        const userId = "<?php echo $userId; ?>";
        
        function getSortOrder() {
            return document.getElementById('sort-order').value;
        }
        
        let initialLoad = true;

        function fetchStatus() {
            const loadingElement = document.getElementById('loading');
            if (initialLoad) {
                loadingElement.style.display = 'flex';
            }
            
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

                if (!data.sensors) {
                    console.error('Expected "sensors" key is missing.');
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
                    uptimeText.textContent = 'Uptime (30 days): ' + parseFloat(sensor.uptime).toFixed(2) + '%';
                    card.appendChild(uptimeText);

                    if (sensor.daily && sensor.daily.length > 0) {
                        const dailyContainer = document.createElement('div');
                        dailyContainer.className = 'daily-strips';

                        // Keine Sortierung hier - wir nutzen die Daten wie sie von status.php kommen
                        // Das älteste Datum sollte Index 0 sein, das neueste sollte den höchsten Index haben
                        sensor.daily.forEach(day => {
                            // Erzeuge den Container für den Balken
                            const dailyStrip = document.createElement('div');
                            dailyStrip.className = 'daily-strip';
                            
                            // Formatiere das Datum
                            const date = new Date(day.date);
                            const formattedDate = date.toLocaleDateString('en-US', { 
                                year: 'numeric', 
                                month: 'short', 
                                day: 'numeric' 
                            });
                            
                            // Definiere Farbe basierend auf Uptime
                            let bgColor = '#198754'; // Grün für hohe Uptime
                            if (day.uptime < 97) {
                                bgColor = '#dc3545'; // Rot für niedrige Uptime
                            } else if (day.uptime < 99) {
                                bgColor = '#ffc107'; // Gelb für mittlere Uptime
                            }
                            
                            // Setze die Höhe und Farbe des Balkens
                            const heightValue = Math.max(5, Math.min(30, (day.uptime / 100) * 30));
                            
                            dailyStrip.style.cssText = `
                                background-color: ${bgColor};
                                height: ${heightValue}px;
                            `;
                            
                            // Erzeuge Tooltip mit besserer Positionierung
                            const tooltip = document.createElement('div');
                            tooltip.className = 'strip-tooltip';
                            tooltip.textContent = `${formattedDate}: ${day.uptime}%`;
                            dailyStrip.appendChild(tooltip);
                            
                            // Füge Balken zum Container hinzu
                            dailyContainer.appendChild(dailyStrip);
                        });
                        
                        card.appendChild(dailyContainer);
                    }
                    container.appendChild(card);
                });

                const now = new Date();
                document.getElementById('last-check').textContent = 'Last update: ' + now.toLocaleTimeString();
                if (initialLoad) {
                    loadingElement.style.display = 'none';
                    initialLoad = false;
                }
            })
            .catch(error => {
                console.error('Error loading sensors:', error);
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
                        const startDate = new Date(event.start_date);
                        const endDate = new Date(event.end_date);
                        
                        row.insertCell(0).textContent = startDate.toLocaleDateString('en-US', {
                            year: 'numeric',
                            month: 'short',
                            day: 'numeric'
                        });
                        row.insertCell(1).textContent = event.service_name;
                        row.insertCell(2).textContent = event.description;
                        
                        const timeCell = row.insertCell(3);
                        const timeDiv = document.createElement('div');
                        timeDiv.className = 'maintenance-time';
                        
                        const startDateStr = startDate.toLocaleDateString('en-US', {
                            year: 'numeric',
                            month: 'short',
                            day: 'numeric'
                        });
                        const endDateStr = endDate.toLocaleDateString('en-US', {
                            year: 'numeric',
                            month: 'short',
                            day: 'numeric'
                        });
                        
                        if (startDateStr === endDateStr) {
                            timeDiv.innerHTML = `
                                <span><i class="bi bi-clock"></i>${startDate.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' })} - ${endDate.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' })}</span>
                            `;
                        } else {
                            timeDiv.innerHTML = `
                                <span><i class="bi bi-calendar"></i>${startDateStr} ${startDate.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' })}</span>
                                <span class="date-separator">to</span>
                                <span><i class="bi bi-calendar"></i>${endDateStr} ${endDate.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' })}</span>
                            `;
                        }
                        
                        timeCell.appendChild(timeDiv);
                        
                        const statusCell = row.insertCell(4);
                        const statusBadge = document.createElement('span');
                        statusBadge.className = 'status ' + event.status.toLowerCase();
                        statusBadge.textContent = event.status;
                        statusCell.appendChild(statusBadge);
                    });
                })
                .catch(error => console.error('Error loading maintenance history:', error));
        }

        // Toggle Updates
        function toggleUpdates(element) {
            const updatesContent = element.nextElementSibling;
            const isHidden = updatesContent.style.display === 'none';
            
            updatesContent.style.display = isHidden ? 'block' : 'none';
            element.innerHTML = isHidden 
                ? '<i class="fas fa-chevron-up me-1"></i> Updates ausblenden' 
                : '<i class="fas fa-chevron-down me-1"></i> Updates anzeigen';
        }

        document.addEventListener('DOMContentLoaded', function() {
            fetchStatus();
            fetchMaintenanceHistory();
            setInterval(fetchStatus, 30000);
        });
    </script>

    <div id="uptime-popup" class="uptime-popup"></div>
</body>
</html> 