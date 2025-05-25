<?php
require_once 'db.php';
require_once 'email_config.php';

class EmailNotifications {
    private $pdo;
    private $emailConfig;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->emailConfig = new EmailConfig($pdo);
    }

    public function sendIncidentNotification($incident_id, $status_page_id) {
        // Get incident details
        $stmt = $this->pdo->prepare("
            SELECT i.*, sp.page_title, sp.uuid, s.name as service_name
            FROM incidents i
            JOIN config s ON i.service_id = s.id
            JOIN status_pages sp ON sp.id = ?
            WHERE i.id = ?
        ");
        $stmt->execute([$status_page_id, $incident_id]);
        $incident = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$incident) {
            return false;
        }

        // Get subscribers
        $stmt = $this->pdo->prepare("
            SELECT email
            FROM email_subscribers
            WHERE status_page_id = ? AND status = 'verified'
        ");
        $stmt->execute([$status_page_id]);
        $subscribers = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($subscribers)) {
            return false;
        }

        // Prepare email content
        $subject = "Status Update: " . $incident['page_title'];
        $status = ucfirst($incident['status']);
        $message = "
            <h2>Status Update: {$incident['page_title']}</h2>
            <p><strong>Service:</strong> {$incident['service_name']}</p>
            <p><strong>Status:</strong> {$status}</p>
            <p><strong>Description:</strong> {$incident['description']}</p>
            <p><strong>Date:</strong> " . date('Y-m-d H:i:s', strtotime($incident['date'])) . "</p>
            <p>View the full status page: <a href='" . $this->getStatusPageUrl($incident['uuid']) . "'>Click here</a></p>
        ";

        // Send emails
        $success = true;
        foreach ($subscribers as $email) {
            if (!$this->emailConfig->sendEmail($email, $subject, $message)) {
                $success = false;
                // Log the error but continue with other subscribers
                error_log("Failed to send incident notification to {$email}: " . $this->emailConfig->getLastError());
            }
        }

        // Record notification
        if ($success) {
            $stmt = $this->pdo->prepare("
                INSERT INTO email_notifications (status_page_id, incident_id)
                VALUES (?, ?)
            ");
            $stmt->execute([$status_page_id, $incident_id]);
        }

        return $success;
    }
    
    public function sendMaintenanceNotification($maintenance_id, $status_page_id) {
        // Get maintenance details
        $stmt = $this->pdo->prepare("
            SELECT mh.*, sp.page_title, sp.uuid, s.name as service_name
            FROM maintenance_history mh
            JOIN config s ON mh.service_id = s.id
            JOIN status_pages sp ON sp.id = ?
            WHERE mh.id = ?
        ");
        $stmt->execute([$status_page_id, $maintenance_id]);
        $maintenance = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$maintenance) {
            return false;
        }

        // Get subscribers
        $stmt = $this->pdo->prepare("
            SELECT email
            FROM email_subscribers
            WHERE status_page_id = ? AND status = 'verified'
        ");
        $stmt->execute([$status_page_id]);
        $subscribers = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($subscribers)) {
            return false;
        }

        // Prepare email content
        $subject = "Scheduled Maintenance: " . $maintenance['page_title'];
        $status = ucfirst($maintenance['status']);
        $startDate = date('Y-m-d H:i:s', strtotime($maintenance['start_date']));
        $endDate = date('Y-m-d H:i:s', strtotime($maintenance['end_date']));
        
        $message = "
            <h2>Scheduled Maintenance: {$maintenance['page_title']}</h2>
            <p><strong>Service:</strong> {$maintenance['service_name']}</p>
            <p><strong>Status:</strong> {$status}</p>
            <p><strong>Description:</strong> {$maintenance['description']}</p>
            <p><strong>Start Time:</strong> {$startDate}</p>
            <p><strong>End Time:</strong> {$endDate}</p>
            <p>View the full status page: <a href='" . $this->getStatusPageUrl($maintenance['uuid']) . "'>Click here</a></p>
        ";

        // Send emails
        $success = true;
        foreach ($subscribers as $email) {
            if (!$this->emailConfig->sendEmail($email, $subject, $message)) {
                $success = false;
                // Log the error but continue with other subscribers
                error_log("Failed to send maintenance notification to {$email}: " . $this->emailConfig->getLastError());
            }
        }

        // Record notification (reusing the email_notifications table)
        if ($success) {
            $stmt = $this->pdo->prepare("
                INSERT INTO email_notifications (status_page_id, incident_id, maintenance_id)
                VALUES (?, NULL, ?)
            ");
            $stmt->execute([$status_page_id, $maintenance_id]);
        }

        return $success;
    }

    public function sendVerificationEmail($email, $status_page_id, $verification_token) {
        // Get status page details
        $stmt = $this->pdo->prepare("
            SELECT page_title, uuid
            FROM status_pages
            WHERE id = ?
        ");
        $stmt->execute([$status_page_id]);
        $page = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$page) {
            return false;
        }

        $subject = "Verify your subscription to " . $page['page_title'];
        $message = "
            <h2>Verify your subscription to {$page['page_title']}</h2>
            <p>Thank you for subscribing to status updates. Please click the link below to verify your email address:</p>
            <p><a href='" . $this->getVerificationUrl($verification_token) . "'>Verify Email</a></p>
            <p>If you didn't request this subscription, you can safely ignore this email.</p>
        ";

        return $this->emailConfig->sendEmail($email, $subject, $message);
    }

    public function sendSensorDowntimeNotification($sensor_id, $status_page_id) {
        // Get sensor details
        $stmt = $this->pdo->prepare("
            SELECT c.*, sp.page_title, sp.uuid
            FROM config c
            JOIN status_pages sp ON sp.id = ?
            WHERE c.id = ?
        ");
        $stmt->execute([$status_page_id, $sensor_id]);
        $sensor = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$sensor) {
            return false;
        }

        // Get subscribers
        $stmt = $this->pdo->prepare("
            SELECT email
            FROM email_subscribers
            WHERE status_page_id = ? AND status = 'verified'
        ");
        $stmt->execute([$status_page_id]);
        $subscribers = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($subscribers)) {
            return false;
        }

        // Prepare email content
        $subject = "Sensor Down Alert: " . $sensor['name'];
        $message = "
            <h2>Sensor Down Alert: {$sensor['name']}</h2>
            <p><strong>Service:</strong> {$sensor['name']}</p>
            <p><strong>URL:</strong> {$sensor['url']}</p>
            <p><strong>Type:</strong> {$sensor['sensor_type']}</p>
            <p><strong>Time:</strong> " . date('Y-m-d H:i:s') . "</p>
            <p>View the full status page: <a href='" . $this->getStatusPageUrl($sensor['uuid']) . "'>Click here</a></p>
        ";

        // Send emails
        $success = true;
        foreach ($subscribers as $email) {
            if (!$this->emailConfig->sendEmail($email, $subject, $message)) {
                $success = false;
                error_log("Failed to send sensor downtime notification to {$email}: " . $this->emailConfig->getLastError());
            }
        }

        return $success;
    }

    public function sendSSLCertificateWarning($sensor_id, $status_page_id) {
        // Get sensor details
        $stmt = $this->pdo->prepare("
            SELECT c.*, sp.page_title, sp.uuid
            FROM config c
            JOIN status_pages sp ON sp.id = ?
            WHERE c.id = ?
        ");
        $stmt->execute([$status_page_id, $sensor_id]);
        $sensor = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$sensor || !$sensor['ssl_expiry_date']) {
            return false;
        }

        // Get subscribers
        $stmt = $this->pdo->prepare("
            SELECT email
            FROM email_subscribers
            WHERE status_page_id = ? AND status = 'verified'
        ");
        $stmt->execute([$status_page_id]);
        $subscribers = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($subscribers)) {
            return false;
        }

        // Calculate days until expiration
        $expiryDate = new DateTime($sensor['ssl_expiry_date']);
        $now = new DateTime();
        $daysUntilExpiry = $now->diff($expiryDate)->days;

        // Prepare email content
        $subject = "SSL Certificate Expiration Warning: " . $sensor['name'];
        $message = "
            <h2>SSL Certificate Expiration Warning</h2>
            <p><strong>Service:</strong> {$sensor['name']}</p>
            <p><strong>URL:</strong> {$sensor['url']}</p>
            <p><strong>SSL Certificate Expires:</strong> {$sensor['ssl_expiry_date']}</p>
            <p><strong>Days Until Expiration:</strong> {$daysUntilExpiry}</p>
            <p>Please renew your SSL certificate before it expires to maintain secure connections.</p>
            <p>View the full status page: <a href='" . $this->getStatusPageUrl($sensor['uuid']) . "'>Click here</a></p>
        ";

        // Send emails
        $success = true;
        foreach ($subscribers as $email) {
            if (!$this->emailConfig->sendEmail($email, $subject, $message)) {
                $success = false;
                error_log("Failed to send SSL certificate warning to {$email}: " . $this->emailConfig->getLastError());
            }
        }

        return $success;
    }

    private function getStatusPageUrl($uuid) {
        return (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]/status_page.php?status_page_uuid=" . $uuid;
    }

    private function getVerificationUrl($token) {
        return (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]/verify_subscription.php?token=" . $token;
    }
} 