<?php
require_once 'db.php';
require_once 'vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class EmailConfig {
    private $pdo;
    private $settings;
    private $lastError = '';

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->loadSettings();
    }

    private function loadSettings() {
        $stmt = $this->pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'smtp_%'");
        $this->settings = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $this->settings[$row['setting_key']] = $row['setting_value'];
        }
    }

    public function updateSettings($settings) {
        try {
            foreach ($settings as $key => $value) {
                $stmt = $this->pdo->prepare("
                    INSERT INTO system_settings (setting_key, setting_value) 
                    VALUES (?, ?) 
                    ON DUPLICATE KEY UPDATE setting_value = ?
                ");
                $stmt->execute([$key, $value, $value]);
            }
            $this->loadSettings();
            return true;
        } catch (PDOException $e) {
            $this->lastError = "Error updating email settings: " . $e->getMessage();
            return false;
        }
    }

    public function getSettings() {
        return $this->settings;
    }

    public function getLastError() {
        return $this->lastError;
    }

    public function sendEmail($to, $subject, $message) {
        $this->lastError = '';
        
        // Check if required SMTP settings are configured
        if (empty($this->settings['smtp_host']) || empty($this->settings['smtp_port']) || 
            empty($this->settings['smtp_username']) || empty($this->settings['smtp_password']) || 
            empty($this->settings['smtp_from_email'])) {
            $this->lastError = "SMTP settings are not properly configured. Please check your email settings.";
            return false;
        }

        try {
            // Create a new PHPMailer instance
            $mail = new PHPMailer(true);

            // Enable debug output for troubleshooting
            $mail->SMTPDebug = 3; // Increased debug level for more details
            $mail->Debugoutput = function($str, $level) {
                $this->lastError .= "Debug: $str\n";
            };

            // Server settings
            $mail->isSMTP();
            $mail->Host = $this->settings['smtp_host'];
            $mail->SMTPAuth = true;
            $mail->Username = $this->settings['smtp_username'];
            $mail->Password = $this->settings['smtp_password'];
            
            // Try different encryption methods
            if ($this->settings['smtp_port'] == '465') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } else {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            }
            
            $mail->Port = $this->settings['smtp_port'];
            $mail->CharSet = 'UTF-8';

            // Set timeouts
            $mail->Timeout = 10;
            $mail->SMTPKeepAlive = false;
            
            // SSL/TLS options
            $mail->SMTPOptions = array(
                'ssl' => array(
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                    'verify_depth' => 0
                )
            );

            // Try different authentication methods
            $mail->AuthType = 'LOGIN'; // Force LOGIN authentication method

            // Recipients
            $mail->setFrom($this->settings['smtp_from_email'], $this->settings['smtp_from_name']);
            $mail->addAddress($to);
            $mail->addReplyTo($this->settings['smtp_from_email'], $this->settings['smtp_from_name']);

            // Content
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $message;

            // Log attempt with more details
            $this->lastError = "Attempting to send email to: {$to} using SMTP host: {$this->settings['smtp_host']}\n";
            $this->lastError .= "SMTP Port: {$this->settings['smtp_port']}\n";
            $this->lastError .= "Encryption: " . $mail->SMTPSecure . "\n";
            $this->lastError .= "Auth Type: " . $mail->AuthType . "\n";
            $this->lastError .= "From: {$this->settings['smtp_from_name']} <{$this->settings['smtp_from_email']}>\n";
            $this->lastError .= "Username: " . substr($this->settings['smtp_username'], 0, 3) . "***\n";

            // Send email with timeout
            set_time_limit(30);
            $mail->send();
            return true;

        } catch (Exception $e) {
            $this->lastError .= "Failed to send email to: {$to}\n";
            $this->lastError .= "Error: " . $mail->ErrorInfo . "\n";
            
            // Add specific error handling for authentication issues
            if (strpos($mail->ErrorInfo, 'Could not authenticate') !== false) {
                $this->lastError .= "Authentication failed. Please check:\n";
                $this->lastError .= "1. SMTP username and password are correct\n";
                $this->lastError .= "2. The SMTP server allows authentication\n";
                $this->lastError .= "3. The port and encryption settings match your SMTP server\n";
                $this->lastError .= "4. The authentication method is supported by the server\n";
                $this->lastError .= "Current settings:\n";
                $this->lastError .= "- Host: {$this->settings['smtp_host']}\n";
                $this->lastError .= "- Port: {$this->settings['smtp_port']}\n";
                $this->lastError .= "- Encryption: " . $mail->SMTPSecure . "\n";
                $this->lastError .= "- Auth Type: " . $mail->AuthType . "\n";
                
                // Try to get more information about the server capabilities
                if (isset($mail->smtp->server_caps)) {
                    $this->lastError .= "Server capabilities:\n";
                    $this->lastError .= print_r($mail->smtp->server_caps, true) . "\n";
                }
            } elseif (strpos($mail->ErrorInfo, 'Connection refused') !== false) {
                $this->lastError .= "Connection was refused. Please check if the SMTP server is running and accessible.\n";
            } elseif (strpos($mail->ErrorInfo, 'Connection timed out') !== false) {
                $this->lastError .= "Connection timed out. Please check if the SMTP server is responding.\n";
            }
            
            return false;
        }
    }
} 