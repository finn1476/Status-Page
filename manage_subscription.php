<?php
session_start();
require_once 'db.php';

$message = '';
$message_type = '';
$status_page_id = null;
$status_page_title = 'Status Page';

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Get status page ID from URL
    if (isset($_GET['status_page_uuid'])) {
        $stmt = $pdo->prepare("SELECT id, page_title FROM status_pages WHERE uuid = ?");
        $stmt->execute([$_GET['status_page_uuid']]);
        $page = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($page) {
            $status_page_id = $page['id'];
            $status_page_title = $page['page_title'];
        } else {
            $message = "Status page not found.";
            $message_type = "error";
        }
    } else {
        // If no specific status page is requested, list all available pages
        $stmt = $pdo->prepare("SELECT id, page_title, uuid FROM status_pages WHERE public = 1");
        $stmt->execute();
        $status_pages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Validate email
        $email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
        if (!$email) {
            $message = "Please enter a valid email address.";
            $message_type = "error";
        } else {
            $action = $_POST['action'] ?? 'subscribe';
            $status_page_id = $_POST['status_page_id'] ?? null;
            
            if (!$status_page_id) {
                $message = "Please select a status page.";
                $message_type = "error";
            } else {
                // Get status page details
                $stmt = $pdo->prepare("SELECT page_title FROM status_pages WHERE id = ?");
                $stmt->execute([$status_page_id]);
                $page = $stmt->fetch(PDO::FETCH_ASSOC);
                $status_page_title = $page['page_title'];
                
                if ($action === 'subscribe') {
                    // Check if already subscribed
                    $stmt = $pdo->prepare("SELECT * FROM email_subscribers WHERE email = ? AND status_page_id = ?");
                    $stmt->execute([$email, $status_page_id]);
                    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($existing) {
                        if ($existing['status'] === 'verified') {
                            $message = "You are already subscribed to updates for this status page.";
                            $message_type = "info";
                        } else {
                            // Resend verification email
                            $token = bin2hex(random_bytes(32));
                            $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));
                            
                            $stmt = $pdo->prepare("
                                UPDATE email_subscribers 
                                SET verification_token = ?, expires_at = ?
                                WHERE id = ?
                            ");
                            $stmt->execute([$token, $expires, $existing['id']]);
                            
                            // Send verification email
                            require_once 'vendor/autoload.php';
                            
                            // Load email settings from the database
                            $emailSettings = [];
                            $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'smtp_%'");
                            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                                $emailSettings[$row['setting_key']] = $row['setting_value'];
                            }
                            
                            // Send verification email with database settings
                            $verification_link = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . 
                                "://$_SERVER[HTTP_HOST]/verify_subscription.php?token=" . $token;
                                
                            $subject = "Verify your subscription to " . $status_page_title;
                            $message_body = "
                                <h2>Verify your subscription to {$status_page_title}</h2>
                                <p>Thank you for subscribing to status updates. Please click the link below to verify your email address:</p>
                                <p><a href='{$verification_link}'>Verify Email</a></p>
                                <p>If you didn't request this subscription, you can safely ignore this email.</p>
                            ";
                            
                            // Send email using PHPMailer
                            $sent = sendEmail($pdo, $email, $subject, $message_body);
                            
                            if ($sent) {
                                $message = "A verification email has been sent to your address. Please check your inbox and verify your subscription.";
                                $message_type = "success";
                            } else {
                                $message = "Failed to send verification email. Please try again later.";
                                $message_type = "error";
                                error_log("Failed to send verification email: " . $sent);
                            }
                        }
                    } else {
                        // Create new subscription
                        $token = bin2hex(random_bytes(32));
                        $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));
                        
                        $stmt = $pdo->prepare("
                            INSERT INTO email_subscribers 
                            (email, status_page_id, status, verification_token, expires_at, created_at) 
                            VALUES (?, ?, 'pending', ?, ?, NOW())
                        ");
                        $stmt->execute([$email, $status_page_id, $token, $expires]);
                        
                        // Send verification email
                        require_once 'vendor/autoload.php';
                        
                        // Send verification email with database settings
                        $verification_link = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . 
                            "://$_SERVER[HTTP_HOST]/verify_subscription.php?token=" . $token;
                            
                        $subject = "Verify your subscription to " . $status_page_title;
                        $message_body = "
                            <h2>Verify your subscription to {$status_page_title}</h2>
                            <p>Thank you for subscribing to status updates. Please click the link below to verify your email address:</p>
                            <p><a href='{$verification_link}'>Verify Email</a></p>
                            <p>If you didn't request this subscription, you can safely ignore this email.</p>
                        ";
                        
                        // Send email using PHPMailer
                        $sent = sendEmail($pdo, $email, $subject, $message_body);
                        
                        if ($sent) {
                            $message = "Thank you for subscribing! A verification email has been sent to your address. Please check your inbox and verify your subscription.";
                            $message_type = "success";
                        } else {
                            $message = "Failed to send verification email. Please try again later.";
                            $message_type = "error";
                            error_log("Failed to send verification email: " . $sent);
                        }
                    }
                } elseif ($action === 'unsubscribe') {
                    // Generate unsubscribe token and send email
                    $token = bin2hex(random_bytes(32));
                    $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));
                    
                    // Check if email exists
                    $stmt = $pdo->prepare("SELECT * FROM email_subscribers WHERE email = ? AND status_page_id = ?");
                    $stmt->execute([$email, $status_page_id]);
                    $subscriber = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($subscriber) {
                        // Store the unsubscribe token
                        $stmt = $pdo->prepare("
                            UPDATE email_subscribers 
                            SET unsubscribe_token = ?, unsubscribe_expires_at = ?
                            WHERE id = ?
                        ");
                        $stmt->execute([$token, $expires, $subscriber['id']]);
                        
                        // Send unsubscribe email
                        $unsubscribe_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . 
                            "://$_SERVER[HTTP_HOST]/unsubscribe.php?token=" . $token;
                        
                        $subject = "Unsubscribe from " . $status_page_title . " Updates";
                        $message_body = "
                            <h2>Unsubscribe from {$status_page_title} Updates</h2>
                            <p>You have requested to unsubscribe from status updates. Please click the link below to confirm:</p>
                            <p><a href='{$unsubscribe_url}'>Unsubscribe</a></p>
                            <p>If you didn't request to unsubscribe, you can safely ignore this email.</p>
                        ";
                        
                        // Send email using PHPMailer
                        $sent = sendEmail($pdo, $email, $subject, $message_body);
                        
                        if ($sent) {
                            $message = "An email with unsubscribe instructions has been sent to your address.";
                            $message_type = "success";
                        } else {
                            $message = "Failed to send unsubscribe email. Please try again later.";
                            $message_type = "error";
                            error_log("Failed to send unsubscribe email: " . $sent);
                        }
                    } else {
                        $message = "This email is not currently subscribed to updates for this status page.";
                        $message_type = "info";
                    }
                }
            }
        }
    }
} catch (PDOException $e) {
    $message = "An error occurred. Please try again later.";
    $message_type = "error";
    error_log("Error in manage_subscription.php: " . $e->getMessage());
}

/**
 * Helper function to send emails using PHPMailer with settings from the database
 */
function sendEmail($pdo, $to, $subject, $message) {
    try {
        require_once 'vendor/autoload.php';
        
        // Load email settings from the database
        $settings = [];
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'smtp_%'");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        
        // Check if required SMTP settings are configured
        if (empty($settings['smtp_host']) || empty($settings['smtp_port']) || 
            empty($settings['smtp_username']) || empty($settings['smtp_password']) || 
            empty($settings['smtp_from_email'])) {
            error_log("SMTP settings are not properly configured.");
            return false;
        }
        
        // Create a new PHPMailer instance
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        
        // Server settings
        $mail->isSMTP();
        $mail->Host = $settings['smtp_host'];
        $mail->SMTPAuth = true;
        $mail->Username = $settings['smtp_username'];
        $mail->Password = $settings['smtp_password'];
        
        // Set encryption based on port
        if ($settings['smtp_port'] == '465') {
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        }
        
        $mail->Port = $settings['smtp_port'];
        $mail->CharSet = 'UTF-8';
        
        // SSL/TLS options
        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );
        
        // Recipients
        $mail->setFrom($settings['smtp_from_email'], $settings['smtp_from_name'] ?? 'Status Page');
        $mail->addAddress($to);
        $mail->addReplyTo($settings['smtp_from_email'], $settings['smtp_from_name'] ?? 'Status Page');
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $message;
        
        // Send email
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Email sending failed: " . $e->getMessage());
        return false;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Subscription - <?php echo htmlspecialchars($status_page_title); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
            padding: 20px;
        }
        .subscription-container {
            max-width: 600px;
            margin: 50px auto;
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .notification {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
        }
        .notification-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .notification-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .notification-info {
            background-color: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }
        .btn-group {
            width: 100%;
            margin-bottom: 20px;
        }
        .btn-group .btn {
            width: 50%;
        }
    </style>
</head>
<body>
    <div class="subscription-container">
        <h2 class="text-center mb-4">Manage Email Notifications</h2>
        
        <?php if ($message): ?>
            <div class="notification notification-<?php echo $message_type; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>
        
        <form method="post" action="">
            <?php if (!isset($_GET['status_page_uuid']) && isset($status_pages)): ?>
                <div class="mb-3">
                    <label for="status_page_id" class="form-label">Select Status Page</label>
                    <select name="status_page_id" id="status_page_id" class="form-select" required>
                        <option value="">-- Select Status Page --</option>
                        <?php foreach ($status_pages as $page): ?>
                            <option value="<?php echo $page['id']; ?>"><?php echo htmlspecialchars($page['page_title']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php else: ?>
                <input type="hidden" name="status_page_id" value="<?php echo $status_page_id; ?>">
            <?php endif; ?>
            
            <div class="mb-3">
                <label for="email" class="form-label">Email Address</label>
                <input type="email" class="form-control" id="email" name="email" required placeholder="Enter your email address">
            </div>
            
            <div class="btn-group" role="group">
                <input type="radio" class="btn-check" name="action" id="subscribe" value="subscribe" checked>
                <label class="btn btn-outline-primary" for="subscribe">Subscribe</label>
                
                <input type="radio" class="btn-check" name="action" id="unsubscribe" value="unsubscribe">
                <label class="btn btn-outline-danger" for="unsubscribe">Unsubscribe</label>
            </div>
            
            <div class="d-grid">
                <button type="submit" class="btn btn-primary">Submit</button>
            </div>
        </form>
    </div>
</body>
</html> 