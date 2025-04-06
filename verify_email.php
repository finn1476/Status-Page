<?php
// verify_email.php - Verifiziert eine E-Mail für Status-Updates

require 'db.php';

// Token aus GET-Parameter lesen
$token = isset($_GET['token']) ? $_GET['token'] : '';

if (empty($token)) {
    $message = "Verification token is missing.";
    $success = false;
} else {
    // Token in Datenbank suchen
    $stmt = $pdo->prepare("SELECT id, status_page_id, email FROM email_subscribers WHERE verification_token = ? AND verified = 0");
    $stmt->execute([$token]);
    $subscriber = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($subscriber) {
        // Abonnent als verifiziert markieren
        $update = $pdo->prepare("UPDATE email_subscribers SET verified = 1, verification_token = NULL WHERE id = ?");
        $update->execute([$subscriber['id']]);
        
        // Status-Page-Informationen abrufen
        $pageStmt = $pdo->prepare("SELECT page_title, uuid FROM status_pages WHERE id = ?");
        $pageStmt->execute([$subscriber['status_page_id']]);
        $page = $pageStmt->fetch(PDO::FETCH_ASSOC);
        
        $message = "Your email has been successfully verified!";
        $success = true;
        $pageName = $page['page_title'] ?? "Status Page";
        $pageUrl = "status_page.php?status_page_uuid=" . ($page['uuid'] ?? "");
    } else {
        // Token nicht gefunden oder bereits verifiziert
        $message = "Invalid or expired verification token.";
        $success = false;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Verification</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }
        .verification-container {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            padding: 40px;
            max-width: 500px;
            width: 100%;
            text-align: center;
        }
        .icon {
            font-size: 70px;
            margin-bottom: 20px;
        }
        .success {
            color: #28a745;
        }
        .error {
            color: #dc3545;
        }
        h1 {
            font-size: 24px;
            margin-bottom: 20px;
            font-weight: 600;
        }
        p {
            color: #6c757d;
            font-size: 16px;
            line-height: 1.6;
            margin-bottom: 25px;
        }
        .btn-primary {
            background: linear-gradient(135deg, #0d6efd 0%, #0dcaf0 100%);
            border: none;
            padding: 12px 30px;
            border-radius: 30px;
            font-weight: 500;
            transition: all 0.3s ease;
            box-shadow: 0 4px 10px rgba(13, 110, 253, 0.2);
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(13, 110, 253, 0.3);
        }
    </style>
</head>
<body>
    <div class="verification-container">
        <div class="icon <?php echo $success ? 'success' : 'error'; ?>">
            <i class="bi bi-<?php echo $success ? 'check-circle' : 'x-circle'; ?>"></i>
        </div>
        <h1><?php echo $success ? 'Email Verified' : 'Verification Failed'; ?></h1>
        <p><?php echo $message; ?></p>
        
        <?php if ($success && isset($pageUrl)): ?>
            <a href="<?php echo $pageUrl; ?>" class="btn btn-primary">Go to <?php echo htmlspecialchars($pageName); ?></a>
        <?php else: ?>
            <a href="index.php" class="btn btn-primary">Return to Home</a>
        <?php endif; ?>
    </div>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
</body>
</html> 