<?php
require_once 'db.php';

$message = '';
$message_type = 'error';

try {
    if (isset($_GET['token'])) {
        $token = $_GET['token'];
        
        // Check if token exists and is not verified
        $stmt = $pdo->prepare("
            SELECT id, email, user_id 
            FROM email_notification_recipients 
            WHERE verification_token = ? 
            AND verified = 0
        ");
        $stmt->execute([$token]);
        $recipient = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($recipient) {
            // Update recipient status to verified
            $stmt = $pdo->prepare("
                UPDATE email_notification_recipients 
                SET verified = 1,
                    verification_token = NULL,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$recipient['id']]);
            
            $message_type = 'success';
            $message = "Your email has been verified successfully! You will now receive notifications for your status pages.";
        } else {
            $message_type = 'error';
            $message = "Invalid or expired verification link. Please try adding your email again.";
        }
    } else {
        $message_type = 'error';
        $message = "No verification token provided.";
    }
} catch (PDOException $e) {
    $message_type = 'error';
    $message = "An error occurred. Please try again later.";
    error_log("Error in verify_notification.php: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Email Notification</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
            padding: 20px;
        }
        .verification-container {
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
    </style>
</head>
<body>
    <div class="verification-container">
        <h2 class="text-center mb-4">Email Verification</h2>
        <?php if ($message): ?>
            <div class="notification notification-<?php echo $message_type; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>
        <div class="text-center">
            <a href="dashboard2.php" class="btn btn-primary">Return to Dashboard</a>
        </div>
    </div>
</body>
</html> 