<?php
session_start();
require_once 'db.php';

$message = '';
$message_type = 'error';
$page_title = 'Unsubscribe from Updates';

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if (isset($_GET['token'])) {
        $token = $_GET['token'];
        
        // Check if token exists and is not expired
        $stmt = $pdo->prepare("
            SELECT es.*, sp.page_title 
            FROM email_subscribers es
            JOIN status_pages sp ON es.status_page_id = sp.id
            WHERE es.unsubscribe_token = ? 
            AND es.unsubscribe_expires_at > NOW() 
        ");
        $stmt->execute([$token]);
        $subscriber = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($subscriber) {
            // Delete the subscription
            $stmt = $pdo->prepare("DELETE FROM email_subscribers WHERE id = ?");
            $stmt->execute([$subscriber['id']]);
            
            $message_type = 'success';
            $message = "You have successfully unsubscribed from updates for <strong>" . htmlspecialchars($subscriber['page_title']) . "</strong>.";
            $page_title = 'Unsubscribed Successfully';
        } else {
            $message_type = 'error';
            $message = "Invalid or expired unsubscribe link. Please try using the manage subscription page to request a new unsubscribe link.";
        }
    } else {
        $message_type = 'error';
        $message = "No unsubscribe token provided.";
    }
} catch (PDOException $e) {
    $message_type = 'error';
    $message = "An error occurred. Please try again later.";
    error_log("Error in unsubscribe.php: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
            padding: 20px;
        }
        .unsubscribe-container {
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
    <div class="unsubscribe-container">
        <h2 class="text-center mb-4"><?php echo $page_title; ?></h2>
        
        <?php if ($message): ?>
            <div class="notification notification-<?php echo $message_type; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>
        
        <div class="text-center mt-4">
            <a href="manage_subscription.php" class="btn btn-primary">Manage Subscriptions</a>
            <a href="index.php" class="btn btn-secondary ms-2">Home</a>
        </div>
    </div>
</body>
</html> 