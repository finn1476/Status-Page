<?php
session_start();
require_once 'db.php';

$message = '';
$message_type = 'error';

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if (isset($_GET['token'])) {
        $token = $_GET['token'];
        
        // Check if token exists and is not expired
        $stmt = $pdo->prepare("
            SELECT * FROM email_subscribers 
            WHERE verification_token = ? 
            AND expires_at > NOW() 
            AND status = 'pending'
        ");
        $stmt->execute([$token]);
        $subscriber = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($subscriber) {
            // Update subscriber status to verified
            $stmt = $pdo->prepare("
                UPDATE email_subscribers 
                SET status = 'verified', 
                    verification_token = NULL, 
                    expires_at = NULL,
                    verified_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$subscriber['id']]);
            
            $message_type = 'success';
            $message = "Your email has been verified successfully! You will now receive updates for this status page.";
        } else {
            $message_type = 'error';
            $message = "Invalid or expired verification link. Please try subscribing again.";
        }
    } else {
        $message_type = 'error';
        $message = "No verification token provided.";
    }
} catch (PDOException $e) {
    $message_type = 'error';
    $message = "An error occurred. Please try again later.";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Subscription</title>
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
            <a href="javascript:history.back()" class="btn btn-primary">Go Back</a>
        </div>
    </div>
</body>
</html> 