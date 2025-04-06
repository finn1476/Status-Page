<?php
session_start();
require_once 'db.php';

// Überprüfe, ob der Benutzer ein Admin ist
if (!isset($_SESSION['user_id']) || !isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    // Wenn nicht eingeloggt oder kein Admin, zur Login-Seite umleiten
    header('Location: login.php');
    exit();
}

// Initialize CSRF token if it doesn't exist
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Variable für Statusmeldungen
$message = '';
$error = '';

// Hilfsfunktion zum Bereinigen von Eingaben
function clean_input($data) {
    return trim(htmlspecialchars(strip_tags($data), ENT_QUOTES, 'UTF-8'));
}

// CSRF-Token-Überprüfung
function check_csrf_token() {
    if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token'])) {
        return false;
    }
    
    if ($_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        return false;
    }
    
    return true;
}

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Get system statistics
    $stats = [
        'total_users' => $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(),
        'total_status_pages' => $pdo->query("SELECT COUNT(*) FROM status_pages")->fetchColumn(),
        'total_incidents' => $pdo->query("SELECT COUNT(*) FROM incidents")->fetchColumn(),
        'active_subscriptions' => $pdo->query("SELECT COUNT(*) FROM user_subscriptions WHERE status = 'active'")->fetchColumn(),
        'total_sensors' => $pdo->query("SELECT COUNT(*) FROM config")->fetchColumn(),
        'total_email_subscribers' => $pdo->query("SELECT COUNT(*) FROM email_subscribers")->fetchColumn()
    ];

    // Get status pages with usage statistics
    $stmt = $pdo->prepare("
        SELECT 
            sp.*,
            u.email as owner_email,
            u.id as user_id,
            (SELECT COUNT(*) FROM config WHERE user_id = sp.user_id) as sensor_count,
            (SELECT COUNT(*) FROM email_subscribers WHERE status_page_id = sp.id) as subscriber_count,
            ut.name as tier_name,
            ut.max_sensors,
            ut.max_status_pages,
            ut.max_email_subscribers
        FROM status_pages sp
        LEFT JOIN users u ON sp.user_id = u.id
        LEFT JOIN user_subscriptions us ON u.id = us.user_id
        LEFT JOIN user_tiers ut ON us.tier_id = ut.id
        ORDER BY sp.created_at DESC
    ");
    $stmt->execute();
    $status_pages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get user details for the modal
    $stmt = $pdo->prepare("
        SELECT 
            u.*,
            (SELECT COUNT(*) FROM status_pages WHERE user_id = u.id) as status_page_count,
            (SELECT COUNT(*) FROM config WHERE user_id = u.id) as sensor_count,
            (SELECT COUNT(*) FROM email_subscribers es JOIN status_pages sp ON es.status_page_id = sp.id WHERE sp.user_id = u.id) as subscriber_count,
            (SELECT COUNT(*) FROM incidents WHERE user_id = u.id) as incident_count,
            ut.name as tier_name,
            ut.max_sensors,
            ut.max_status_pages,
            ut.max_email_subscribers
        FROM users u
        LEFT JOIN user_subscriptions us ON u.id = us.user_id
        LEFT JOIN user_tiers ut ON us.tier_id = ut.id
        WHERE u.id = ?
    ");

    // Get user usage statistics
    $stmt = $pdo->prepare("
        SELECT 
            u.*,
            (SELECT COUNT(*) FROM status_pages WHERE user_id = u.id) as status_page_count,
            (SELECT COUNT(*) FROM config WHERE user_id = u.id) as sensor_count,
            (SELECT COUNT(*) FROM email_subscribers es JOIN status_pages sp ON es.status_page_id = sp.id WHERE sp.user_id = u.id) as subscriber_count,
            (SELECT COUNT(*) FROM incidents WHERE user_id = u.id) as incident_count,
            ut.name as tier_name,
            ut.max_sensors,
            ut.max_status_pages,
            ut.max_email_subscribers
        FROM users u
        LEFT JOIN user_subscriptions us ON u.id = us.user_id
        LEFT JOIN user_tiers ut ON us.tier_id = ut.id
        ORDER BY u.created_at DESC
    ");
    $stmt->execute();
    $user_usage = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Handle form submissions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!check_csrf_token()) {
            $error = "Security verification failed. Please try again.";
        } else {
            // User Management
            if (isset($_POST['add_user'])) {
                $email = clean_input($_POST['email']);
                $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
                $role = clean_input($_POST['role']);
                $status = clean_input($_POST['status']);
                
                // Füge Benutzer hinzu
                $stmt = $pdo->prepare("INSERT INTO users (email, password, role, status, created_at) VALUES (?, ?, ?, ?, NOW())");
                if ($stmt->execute([$email, $password, $role, $status])) {
                    $userId = $pdo->lastInsertId();
                    $message = "User added successfully.";
                    
                    // Füge Abonnement hinzu, wenn ein Tarif ausgewählt wurde
                    if (!empty($_POST['tier_id'])) {
                        $tier_id = (int)$_POST['tier_id'];
                        $start_date = clean_input($_POST['start_date']);
                        $end_date = clean_input($_POST['end_date']);
                        
                        $stmt = $pdo->prepare("INSERT INTO user_subscriptions (user_id, tier_id, start_date, end_date, status) VALUES (?, ?, ?, ?, 'active')");
                        $stmt->execute([$userId, $tier_id, $start_date, $end_date]);
                    }
                } else {
                    $error = "Failed to add user. Email might already be in use.";
                }
            }
            
            if (isset($_POST['update_user'])) {
                $user_id = (int)$_POST['user_id'];
                $email = clean_input($_POST['email']);
                $role = clean_input($_POST['role']);
                $status = clean_input($_POST['status']);
                
                // Aktualisiere Benutzerinformationen
                if (!empty($_POST['password'])) {
                    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("UPDATE users SET email = ?, password = ?, role = ?, status = ? WHERE id = ?");
                    $stmt->execute([$email, $password, $role, $status, $user_id]);
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET email = ?, role = ?, status = ? WHERE id = ?");
                    $stmt->execute([$email, $role, $status, $user_id]);
                }
                
                // Aktualisiere oder erstelle Abonnement
                if (!empty($_POST['tier_id'])) {
                    $tier_id = (int)$_POST['tier_id'];
                    $start_date = clean_input($_POST['start_date']);
                    $end_date = clean_input($_POST['end_date']);
                    
                    // Prüfe, ob ein Abonnement existiert
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM user_subscriptions WHERE user_id = ?");
                    $stmt->execute([$user_id]);
                    $hasSubscription = $stmt->fetchColumn() > 0;
                    
                    if ($hasSubscription) {
                        $stmt = $pdo->prepare("UPDATE user_subscriptions SET tier_id = ?, start_date = ?, end_date = ?, status = 'active' WHERE user_id = ?");
                        $stmt->execute([$tier_id, $start_date, $end_date, $user_id]);
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO user_subscriptions (user_id, tier_id, start_date, end_date, status) VALUES (?, ?, ?, ?, 'active')");
                        $stmt->execute([$user_id, $tier_id, $start_date, $end_date]);
                    }
                } else {
                    // Lösche Abonnement, wenn kein Tarif ausgewählt wurde
                    $stmt = $pdo->prepare("DELETE FROM user_subscriptions WHERE user_id = ?");
                    $stmt->execute([$user_id]);
                }
                
                $message = "User updated successfully.";
            }
            
            if (isset($_POST['delete_user'])) {
                $user_id = (int)$_POST['user_id'];
                
                // Beginne Transaktion
                $pdo->beginTransaction();
                
                try {
                    // Lösche abhängige Daten
                    $stmt = $pdo->prepare("DELETE FROM user_subscriptions WHERE user_id = ?");
                    $stmt->execute([$user_id]);
                    
                    // Lösche Benutzer
                    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                    $stmt->execute([$user_id]);
                    
                    $pdo->commit();
                    $message = "User and associated data deleted successfully.";
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $error = "Failed to delete user: " . $e->getMessage();
                }
            }
            
            // Subscription Management
            if (isset($_POST['update_subscription'])) {
                $user_id = (int)$_POST['user_id'];
                $tier_id = (int)$_POST['tier_id'];
                $start_date = clean_input($_POST['start_date']);
                $end_date = clean_input($_POST['end_date']);
                $status = clean_input($_POST['status']);
                
                $stmt = $pdo->prepare("UPDATE user_subscriptions SET tier_id = ?, start_date = ?, end_date = ?, status = ? WHERE user_id = ?");
                $stmt->execute([$tier_id, $start_date, $end_date, $status, $user_id]);
                $message = "Subscription updated successfully.";
            }
            
            if (isset($_POST['delete_subscription'])) {
                $user_id = (int)$_POST['user_id'];
                $stmt = $pdo->prepare("DELETE FROM user_subscriptions WHERE user_id = ?");
                $stmt->execute([$user_id]);
                $message = "Subscription deleted successfully.";
            }
            
            // Tier Management
            if (isset($_POST['add_tier'])) {
                $name = clean_input($_POST['name']);
                $price = (float)$_POST['price'];
                $max_sensors = (int)$_POST['max_sensors'];
                $max_status_pages = (int)$_POST['max_status_pages'];
                $max_email_subscribers = (int)$_POST['max_email_subscribers'];
                
                $stmt = $pdo->prepare("INSERT INTO user_tiers (name, price, max_sensors, max_status_pages, max_email_subscribers) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$name, $price, $max_sensors, $max_status_pages, $max_email_subscribers]);
                $message = "Tier added successfully.";
            }
            
            if (isset($_POST['update_tier'])) {
                $tier_id = (int)$_POST['tier_id'];
                $name = clean_input($_POST['name']);
                $price = (float)$_POST['price'];
                $max_sensors = (int)$_POST['max_sensors'];
                $max_status_pages = (int)$_POST['max_status_pages'];
                $max_email_subscribers = (int)$_POST['max_email_subscribers'];
                
                $stmt = $pdo->prepare("UPDATE user_tiers SET name = ?, price = ?, max_sensors = ?, max_status_pages = ?, max_email_subscribers = ? WHERE id = ?");
                $stmt->execute([$name, $price, $max_sensors, $max_status_pages, $max_email_subscribers, $tier_id]);
                $message = "Tier updated successfully.";
            }
            
            if (isset($_POST['delete_tier'])) {
                $tier_id = (int)$_POST['tier_id'];
                $stmt = $pdo->prepare("DELETE FROM user_tiers WHERE id = ?");
                $stmt->execute([$tier_id]);
                $message = "Tier deleted successfully.";
            }
            
            // System Settings
            if (isset($_POST['update_settings'])) {
                $site_name = clean_input($_POST['site_name']);
                $site_description = clean_input($_POST['site_description']);
                $contact_email = clean_input($_POST['contact_email']);
                $maintenance_mode = isset($_POST['maintenance_mode']) ? 1 : 0;
                
                $stmt = $pdo->prepare("UPDATE system_settings SET value = ? WHERE setting_key = ?");
                $stmt->execute([$site_name, 'site_name']);
                $stmt->execute([$site_description, 'site_description']);
                $stmt->execute([$contact_email, 'contact_email']);
                $stmt->execute([$maintenance_mode, 'maintenance_mode']);
                
                $message = "System settings updated successfully.";
            }
        }
    }

    // Get all users with their subscription information
    $stmt = $pdo->prepare("
        SELECT 
            u.*,
            us.tier_id,
            us.start_date,
            us.end_date,
            us.status as subscription_status,
            ut.name as tier_name,
            ut.price as tier_price
        FROM users u
        LEFT JOIN user_subscriptions us ON u.id = us.user_id
        LEFT JOIN user_tiers ut ON us.tier_id = ut.id
        ORDER BY u.id DESC
    ");
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get all tiers
    $stmt = $pdo->prepare("SELECT * FROM user_tiers ORDER BY price ASC");
    $stmt->execute();
    $tiers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get system settings
    $stmt = $pdo->prepare("SELECT * FROM system_settings");
    $stmt->execute();
    $settings = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_key']] = $row['value'];
    }

} catch (PDOException $e) {
    die('Database error: ' . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .admin-section {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .admin-section h2 {
            color: #333;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #eee;
        }
        
        .table th {
            background-color: #f8f9fa;
        }
        
        .status-badge {
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 0.85em;
        }
        
        .status-active {
            background-color: #d4edda;
            color: #155724;
        }
        
        .status-inactive {
            background-color: #f8d7da;
            color: #721c24;
        }
        
        .status-pending {
            background-color: #fff3cd;
            color: #856404;
        }
        
        .action-buttons .btn {
            margin-right: 5px;
        }

        .stat-card {
            background: #fff;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            transition: transform 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-card i {
            font-size: 2em;
            margin-bottom: 10px;
        }

        .usage-bar {
            height: 20px;
            background-color: #e9ecef;
            border-radius: 10px;
            overflow: hidden;
            margin-top: 5px;
        }

        .usage-bar-fill {
            height: 100%;
            background-color: #28a745;
            transition: width 0.3s ease;
        }

        .usage-bar-warning {
            background-color: #ffc107;
        }

        .usage-bar-danger {
            background-color: #dc3545;
        }
    </style>
</head>
<body class="bg-light">
    <div class="container py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="mb-0">Admin Panel</h1>
            <div>
                <span class="me-3">Eingeloggt als: <?php echo htmlspecialchars($_SESSION['user_email'] ?? 'Admin'); ?></span>
                <a href="logout.php" class="btn btn-outline-danger btn-sm">
                    <i class="bi bi-box-arrow-right"></i> Logout
                </a>
            </div>
        </div>
        
        <?php if($message): ?>
            <div class="alert alert-success"><?php echo $message; ?></div>
        <?php endif; ?>
        
        <?php if($error): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>

        <!-- Admin Navigation -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">System Settings</h5>
                        <p class="card-text">Configure system-wide settings and preferences.</p>
                        <a href="admin_settings.php" class="btn btn-primary">
                            <i class="bi bi-gear"></i> Manage Settings
                        </a>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">Email Configuration</h5>
                        <p class="card-text">Configure email server settings for notifications.</p>
                        <a href="admin_email_config.php" class="btn btn-primary">
                            <i class="bi bi-envelope"></i> Email Settings
                        </a>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">User Management</h5>
                        <p class="card-text">Manage user accounts and permissions.</p>
                        <a href="#user-management" class="btn btn-primary">
                            <i class="bi bi-people"></i> Manage Users
                        </a>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">Subscription Tiers</h5>
                        <p class="card-text">Manage subscription tiers and pricing.</p>
                        <a href="admin_tiers.php" class="btn btn-primary">
                            <i class="bi bi-tags"></i> Manage Tiers
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- System Statistics -->
        <div class="admin-section">
            <h2><i class="bi bi-graph-up"></i> System Statistics</h2>
            <div class="row">
                <div class="col-md-4">
                    <div class="stat-card text-center">
                        <i class="bi bi-people text-primary"></i>
                        <h3><?php echo $stats['total_users']; ?></h3>
                        <p class="text-muted">Total Users</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-card text-center">
                        <i class="bi bi-file-earmark-text text-success"></i>
                        <h3><?php echo $stats['total_status_pages']; ?></h3>
                        <p class="text-muted">Status Pages</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-card text-center">
                        <i class="bi bi-exclamation-triangle text-warning"></i>
                        <h3><?php echo $stats['total_incidents']; ?></h3>
                        <p class="text-muted">Total Incidents</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-card text-center">
                        <i class="bi bi-credit-card text-info"></i>
                        <h3><?php echo $stats['active_subscriptions']; ?></h3>
                        <p class="text-muted">Active Subscriptions</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-card text-center">
                        <i class="bi bi-hdd-network text-secondary"></i>
                        <h3><?php echo $stats['total_sensors']; ?></h3>
                        <p class="text-muted">Total Sensors</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-card text-center">
                        <i class="bi bi-envelope text-danger"></i>
                        <h3><?php echo $stats['total_email_subscribers']; ?></h3>
                        <p class="text-muted">Email Subscribers</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Status Page Usage -->
        <div class="admin-section">
            <h2><i class="bi bi-file-earmark-text"></i> Status Page Usage</h2>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Owner</th>
                            <th>Tier</th>
                            <th>Sensors</th>
                            <th>Subscribers</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($status_pages as $page): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($page['title']); ?></td>
                            <td>
                                <a href="#" data-bs-toggle="modal" data-bs-target="#userDetailsModal<?php echo $page['user_id']; ?>">
                                    <?php echo htmlspecialchars($page['owner_email']); ?>
                                </a>
                            </td>
                            <td><?php echo htmlspecialchars($page['tier_name'] ?? 'Free'); ?></td>
                            <td>
                                <?php echo $page['sensor_count']; ?> / <?php echo $page['max_sensors'] ?? 10; ?>
                                <div class="usage-bar">
                                    <div class="usage-bar-fill <?php echo $page['sensor_count'] > ($page['max_sensors'] ?? 10) * 0.9 ? 'usage-bar-danger' : ($page['sensor_count'] > ($page['max_sensors'] ?? 10) * 0.7 ? 'usage-bar-warning' : ''); ?>" 
                                         style="width: <?php echo min(($page['sensor_count'] / ($page['max_sensors'] ?? 10)) * 100, 100); ?>%"></div>
                                </div>
                            </td>
                            <td>
                                <?php echo $page['subscriber_count']; ?> / <?php echo $page['max_email_subscribers'] ?? 10; ?>
                                <div class="usage-bar">
                                    <div class="usage-bar-fill <?php echo $page['subscriber_count'] > ($page['max_email_subscribers'] ?? 10) * 0.9 ? 'usage-bar-danger' : ($page['subscriber_count'] > ($page['max_email_subscribers'] ?? 10) * 0.7 ? 'usage-bar-warning' : ''); ?>" 
                                         style="width: <?php echo min(($page['subscriber_count'] / ($page['max_email_subscribers'] ?? 10)) * 100, 100); ?>%"></div>
                                </div>
                            </td>
                            <td><?php echo date('Y-m-d', strtotime($page['created_at'])); ?></td>
                            <td class="action-buttons">
                                <a href="status_page.php?uuid=<?php echo $page['uuid']; ?>" class="btn btn-sm btn-info" target="_blank">
                                    <i class="bi bi-eye"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- User Details Modals -->
        <?php foreach($user_usage as $user): ?>
        <div class="modal fade" id="userDetailsModal<?php echo $user['id']; ?>" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">User Details: <?php echo htmlspecialchars($user['email']); ?></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <h6>Subscription Information</h6>
                                <p><strong>Tier:</strong> <?php echo htmlspecialchars($user['tier_name'] ?? 'Free'); ?></p>
                                <p><strong>Status:</strong> <span class="status-badge status-<?php echo $user['status']; ?>"><?php echo ucfirst($user['status']); ?></span></p>
                            </div>
                            <div class="col-md-6">
                                <h6>Usage Statistics</h6>
                                <p><strong>Status Pages:</strong> <?php echo $user['status_page_count']; ?> / <?php echo $user['max_status_pages'] ?? 1; ?></p>
                                <p><strong>Sensors:</strong> <?php echo $user['sensor_count']; ?> / <?php echo $user['max_sensors'] ?? 10; ?></p>
                                <p><strong>Subscribers:</strong> <?php echo $user['subscriber_count']; ?> / <?php echo $user['max_email_subscribers'] ?? 10; ?></p>
                                <p><strong>Incidents:</strong> <?php echo $user['incident_count']; ?></p>
                            </div>
                        </div>

                        <h6>Status Pages</h6>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Title</th>
                                        <th>Sensors</th>
                                        <th>Subscribers</th>
                                        <th>Created</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $stmt = $pdo->prepare("
                                        SELECT sp.*, 
                                            (SELECT COUNT(*) FROM config WHERE user_id = ?) as sensor_count,
                                            (SELECT COUNT(*) FROM email_subscribers WHERE status_page_id = sp.id) as subscriber_count
                                        FROM status_pages sp 
                                        WHERE sp.user_id = ? 
                                        ORDER BY sp.created_at DESC
                                    ");
                                    $stmt->execute([$user['id'], $user['id']]);
                                    $user_status_pages = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                    foreach($user_status_pages as $page):
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($page['title']); ?></td>
                                        <td><?php echo $page['sensor_count']; ?></td>
                                        <td><?php echo $page['subscriber_count']; ?></td>
                                        <td><?php echo date('Y-m-d', strtotime($page['created_at'])); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <h6>Recent Incidents</h6>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Title</th>
                                        <th>Status</th>
                                        <th>Created</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $stmt = $pdo->prepare("
                                        SELECT * FROM incidents 
                                        WHERE user_id = ? 
                                        ORDER BY created_at DESC 
                                        LIMIT 5
                                    ");
                                    $stmt->execute([$user['id']]);
                                    $incidents = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                    foreach($incidents as $incident):
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($incident['title']); ?></td>
                                        <td>
                                            <span class="status-badge status-<?php echo $incident['status']; ?>">
                                                <?php echo ucfirst($incident['status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo date('Y-m-d H:i', strtotime($incident['created_at'])); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <h6>Active Sensors</h6>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Service Name</th>
                                        <th>Service URL</th>
                                        <th>Sensor Type</th>
                                        <th>Last Check</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $stmt = $pdo->prepare("
                                        SELECT c.*, 
                                            (SELECT status FROM uptime_checks WHERE service_url = c.url ORDER BY check_time DESC LIMIT 1) as last_status,
                                            (SELECT check_time FROM uptime_checks WHERE service_url = c.url ORDER BY check_time DESC LIMIT 1) as last_check
                                        FROM config c
                                        WHERE c.user_id = ? 
                                        ORDER BY c.name
                                    ");
                                    $stmt->execute([$user['id']]);
                                    $sensors = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                    foreach($sensors as $sensor):
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($sensor['name']); ?></td>
                                        <td><?php echo htmlspecialchars($sensor['url']); ?></td>
                                        <td><?php echo htmlspecialchars($sensor['sensor_type']); ?></td>
                                        <td><?php echo $sensor['last_check'] ? date('Y-m-d H:i', strtotime($sensor['last_check'])) : 'Never'; ?></td>
                                        <td>
                                            <span class="status-badge status-<?php echo $sensor['last_status'] ? 'up' : 'down'; ?>">
                                                <?php echo $sensor['last_status'] ? 'Up' : 'Down'; ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#editUserModal<?php echo $user['id']; ?>">
                            <i class="bi bi-pencil"></i> Edit User
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- User Usage Stats -->
        <div class="admin-section" id="user-management">
            <h2><i class="bi bi-people"></i> User Management</h2>
            
            <!-- Add User Button -->
            <div class="mb-4">
                <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addUserModal">
                    <i class="bi bi-person-plus"></i> Add New User
                </button>
            </div>
            
            <!-- Users Table -->
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Subscription</th>
                            <th>Usage</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($users as $user): ?>
                        <tr>
                            <td><?php echo $user['id']; ?></td>
                            <td><?php echo htmlspecialchars($user['email']); ?></td>
                            <td>
                                <span class="badge <?php echo $user['role'] === 'admin' ? 'bg-danger' : 'bg-primary'; ?>">
                                    <?php echo htmlspecialchars(ucfirst($user['role'])); ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge <?php echo $user['status'] === 'active' ? 'bg-success' : ($user['status'] === 'pending' ? 'bg-warning' : 'bg-secondary'); ?>">
                                    <?php echo htmlspecialchars(ucfirst($user['status'])); ?>
                                </span>
                            </td>
                            <td>
                                <?php if(isset($user['tier_name'])): ?>
                                    <span class="badge bg-info"><?php echo htmlspecialchars($user['tier_name']); ?></span>
                                    <small class="text-muted">
                                        (<?php echo date('Y-m-d', strtotime($user['start_date'])); ?> - 
                                        <?php echo date('Y-m-d', strtotime($user['end_date'])); ?>)
                                    </small>
                                <?php else: ?>
                                    <span class="badge bg-secondary">No subscription</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php 
                                $statusPageCount = isset($user_usage[$user['id']]) ? $user_usage[$user['id']]['status_page_count'] : 0;
                                $maxStatusPages = isset($user_usage[$user['id']]) ? ($user_usage[$user['id']]['max_status_pages'] ?? 1) : 1;
                                $sensorCount = isset($user_usage[$user['id']]) ? $user_usage[$user['id']]['sensor_count'] : 0;
                                $maxSensors = isset($user_usage[$user['id']]) ? ($user_usage[$user['id']]['max_sensors'] ?? 1) : 1;
                                ?>
                                <small>Pages: <?php echo $statusPageCount; ?>/<?php echo $maxStatusPages; ?></small><br>
                                <small>Sensors: <?php echo $sensorCount; ?>/<?php echo $maxSensors; ?></small>
                            </td>
                            <td><?php echo date('Y-m-d', strtotime($user['created_at'])); ?></td>
                            <td>
                                <div class="btn-group">
                                    <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#editUserModal<?php echo $user['id']; ?>">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#deleteUserModal<?php echo $user['id']; ?>">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#userDetailsModal<?php echo $user['id']; ?>">
                                        <i class="bi bi-info-circle"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Add User Modal -->
        <div class="modal fade" id="addUserModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Add New User</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <form method="POST" action="">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            
                            <div class="mb-3">
                                <label for="add_email" class="form-label">Email</label>
                                <input type="email" class="form-control" id="add_email" name="email" required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="add_password" class="form-label">Password</label>
                                <input type="password" class="form-control" id="add_password" name="password" required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="add_role" class="form-label">Role</label>
                                <select class="form-control" id="add_role" name="role" required>
                                    <option value="user">User</option>
                                    <option value="admin">Admin</option>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label for="add_status" class="form-label">Status</label>
                                <select class="form-control" id="add_status" name="status" required>
                                    <option value="active">Active</option>
                                    <option value="pending">Pending</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label for="add_tier" class="form-label">Subscription Tier</label>
                                <select class="form-control" id="add_tier" name="tier_id">
                                    <option value="">No Subscription</option>
                                    <?php foreach($tiers as $tier): ?>
                                        <option value="<?php echo $tier['id']; ?>"><?php echo htmlspecialchars($tier['name']); ?> (<?php echo number_format($tier['price'], 2); ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label for="add_start_date" class="form-label">Subscription Start Date</label>
                                <input type="date" class="form-control" id="add_start_date" name="start_date" value="<?php echo date('Y-m-d'); ?>">
                            </div>
                            
                            <div class="mb-3">
                                <label for="add_end_date" class="form-label">Subscription End Date</label>
                                <input type="date" class="form-control" id="add_end_date" name="end_date" value="<?php echo date('Y-m-d', strtotime('+1 year')); ?>">
                            </div>
                            
                            <button type="submit" name="add_user" class="btn btn-success">Add User</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Edit User Modals -->
        <?php foreach($users as $user): ?>
        <div class="modal fade" id="editUserModal<?php echo $user['id']; ?>" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit User: <?php echo htmlspecialchars($user['email']); ?></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <form method="POST" action="">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                            
                            <div class="mb-3">
                                <label for="edit_email<?php echo $user['id']; ?>" class="form-label">Email</label>
                                <input type="email" class="form-control" id="edit_email<?php echo $user['id']; ?>" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="edit_password<?php echo $user['id']; ?>" class="form-label">New Password (leave blank to keep current)</label>
                                <input type="password" class="form-control" id="edit_password<?php echo $user['id']; ?>" name="password">
                            </div>
                            
                            <div class="mb-3">
                                <label for="edit_role<?php echo $user['id']; ?>" class="form-label">Role</label>
                                <select class="form-control" id="edit_role<?php echo $user['id']; ?>" name="role" required>
                                    <option value="user" <?php echo $user['role'] === 'user' ? 'selected' : ''; ?>>User</option>
                                    <option value="admin" <?php echo $user['role'] === 'admin' ? 'selected' : ''; ?>>Admin</option>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label for="edit_status<?php echo $user['id']; ?>" class="form-label">Status</label>
                                <select class="form-control" id="edit_status<?php echo $user['id']; ?>" name="status" required>
                                    <option value="active" <?php echo $user['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="pending" <?php echo $user['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="inactive" <?php echo $user['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label for="edit_tier<?php echo $user['id']; ?>" class="form-label">Subscription Tier</label>
                                <select class="form-control" id="edit_tier<?php echo $user['id']; ?>" name="tier_id">
                                    <option value="">No Subscription</option>
                                    <?php foreach($tiers as $tier): ?>
                                        <option value="<?php echo $tier['id']; ?>" <?php echo isset($user['tier_id']) && $user['tier_id'] == $tier['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($tier['name']); ?> (<?php echo number_format($tier['price'], 2); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label for="edit_start_date<?php echo $user['id']; ?>" class="form-label">Subscription Start Date</label>
                                <input type="date" class="form-control" id="edit_start_date<?php echo $user['id']; ?>" name="start_date" 
                                      value="<?php echo isset($user['start_date']) ? date('Y-m-d', strtotime($user['start_date'])) : date('Y-m-d'); ?>">
                            </div>
                            
                            <div class="mb-3">
                                <label for="edit_end_date<?php echo $user['id']; ?>" class="form-label">Subscription End Date</label>
                                <input type="date" class="form-control" id="edit_end_date<?php echo $user['id']; ?>" name="end_date" 
                                      value="<?php echo isset($user['end_date']) ? date('Y-m-d', strtotime($user['end_date'])) : date('Y-m-d', strtotime('+1 year')); ?>">
                            </div>
                            
                            <button type="submit" name="update_user" class="btn btn-primary">Update User</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- Delete User Modals -->
        <?php foreach($users as $user): ?>
        <div class="modal fade" id="deleteUserModal<?php echo $user['id']; ?>" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Delete User</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p>Are you sure you want to delete the user <strong><?php echo htmlspecialchars($user['email']); ?></strong>?</p>
                        <p class="text-danger">This action cannot be undone and will remove all data associated with this user!</p>
                        
                        <form method="POST" action="">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                            
                            <button type="submit" name="delete_user" class="btn btn-danger">Delete User</button>
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- Subscription Management -->
        <div class="admin-section">
            <h2><i class="bi bi-credit-card"></i> Subscription Management</h2>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Tier</th>
                            <th>Start Date</th>
                            <th>End Date</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($users as $user): ?>
                            <?php if($user['tier_id']): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($user['email']); ?></td>
                                <td><?php echo htmlspecialchars($user['tier_name']); ?></td>
                                <td><?php echo $user['start_date']; ?></td>
                                <td><?php echo $user['end_date']; ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo $user['subscription_status']; ?>">
                                        <?php echo ucfirst($user['subscription_status']); ?>
                                    </span>
                                </td>
                                <td class="action-buttons">
                                    <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#editSubscriptionModal<?php echo $user['id']; ?>">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#deleteSubscriptionModal<?php echo $user['id']; ?>">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </td>
                            </tr>
                            
                            <!-- Edit Subscription Modal -->
                            <div class="modal fade" id="editSubscriptionModal<?php echo $user['id']; ?>" tabindex="-1">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Edit Subscription</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <form method="POST">
                                            <div class="modal-body">
                                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                <div class="mb-3">
                                                    <label class="form-label">Tier</label>
                                                    <select class="form-select" name="tier_id" required>
                                                        <?php foreach($tiers as $tier): ?>
                                                            <option value="<?php echo $tier['id']; ?>" <?php echo $user['tier_id'] == $tier['id'] ? 'selected' : ''; ?>>
                                                                <?php echo htmlspecialchars($tier['name']); ?> - $<?php echo $tier['price']; ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label">Start Date</label>
                                                    <input type="date" class="form-control" name="start_date" value="<?php echo $user['start_date']; ?>" required>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label">End Date</label>
                                                    <input type="date" class="form-control" name="end_date" value="<?php echo $user['end_date']; ?>" required>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label">Status</label>
                                                    <select class="form-select" name="status" required>
                                                        <option value="active" <?php echo $user['subscription_status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                                                        <option value="inactive" <?php echo $user['subscription_status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                                        <option value="pending" <?php echo $user['subscription_status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                <button type="submit" name="update_subscription" class="btn btn-primary">Update Subscription</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Delete Subscription Modal -->
                            <div class="modal fade" id="deleteSubscriptionModal<?php echo $user['id']; ?>" tabindex="-1">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Delete Subscription</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <p>Are you sure you want to delete this subscription? This action cannot be undone.</p>
                                        </div>
                                        <div class="modal-footer">
                                            <form method="POST">
                                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                <button type="submit" name="delete_subscription" class="btn btn-danger">Delete Subscription</button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Tier Management -->
        <div class="admin-section">
            <h2><i class="bi bi-layers"></i> Tier Management</h2>
            <button type="button" class="btn btn-primary mb-3" data-bs-toggle="modal" data-bs-target="#addTierModal">
                <i class="bi bi-plus-circle"></i> Add New Tier
            </button>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Price</th>
                            <th>Max Sensors</th>
                            <th>Max Status Pages</th>
                            <th>Max Email Subscribers</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($tiers as $tier): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($tier['name']); ?></td>
                            <td>$<?php echo number_format($tier['price'], 2); ?></td>
                            <td><?php echo $tier['max_sensors']; ?></td>
                            <td><?php echo $tier['max_status_pages']; ?></td>
                            <td><?php echo $tier['max_email_subscribers']; ?></td>
                            <td class="action-buttons">
                                <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#editTierModal<?php echo $tier['id']; ?>">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#deleteTierModal<?php echo $tier['id']; ?>">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                        </tr>
                        
                        <!-- Edit Tier Modal -->
                        <div class="modal fade" id="editTierModal<?php echo $tier['id']; ?>" tabindex="-1">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title">Edit Tier</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <form method="POST">
                                        <div class="modal-body">
                                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                            <input type="hidden" name="tier_id" value="<?php echo $tier['id']; ?>">
                                            <div class="mb-3">
                                                <label class="form-label">Name</label>
                                                <input type="text" class="form-control" name="name" value="<?php echo htmlspecialchars($tier['name']); ?>" required>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Price</label>
                                                <input type="number" step="0.01" class="form-control" name="price" value="<?php echo $tier['price']; ?>" required>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Max Sensors</label>
                                                <input type="number" class="form-control" name="max_sensors" value="<?php echo $tier['max_sensors']; ?>" required>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Max Status Pages</label>
                                                <input type="number" class="form-control" name="max_status_pages" value="<?php echo $tier['max_status_pages']; ?>" required>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Max Email Subscribers</label>
                                                <input type="number" class="form-control" name="max_email_subscribers" value="<?php echo $tier['max_email_subscribers']; ?>" required>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" name="update_tier" class="btn btn-primary">Update Tier</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Delete Tier Modal -->
                        <div class="modal fade" id="deleteTierModal<?php echo $tier['id']; ?>" tabindex="-1">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title">Delete Tier</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <p>Are you sure you want to delete this tier? This action cannot be undone.</p>
                                    </div>
                                    <div class="modal-footer">
                                        <form method="POST">
                                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                            <input type="hidden" name="tier_id" value="<?php echo $tier['id']; ?>">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" name="delete_tier" class="btn btn-danger">Delete Tier</button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Add Tier Modal -->
        <div class="modal fade" id="addTierModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Add New Tier</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form method="POST">
                        <div class="modal-body">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <div class="mb-3">
                                <label class="form-label">Name</label>
                                <input type="text" class="form-control" name="name" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Price</label>
                                <input type="number" step="0.01" class="form-control" name="price" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Max Sensors</label>
                                <input type="number" class="form-control" name="max_sensors" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Max Status Pages</label>
                                <input type="number" class="form-control" name="max_status_pages" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Max Email Subscribers</label>
                                <input type="number" class="form-control" name="max_email_subscribers" required>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" name="add_tier" class="btn btn-primary">Add Tier</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- System Settings -->
        <div class="admin-section">
            <h2><i class="bi bi-gear"></i> System Settings</h2>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Site Name</label>
                            <input type="text" class="form-control" name="site_name" value="<?php echo htmlspecialchars($settings['site_name'] ?? ''); ?>" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Contact Email</label>
                            <input type="email" class="form-control" name="contact_email" value="<?php echo htmlspecialchars($settings['contact_email'] ?? ''); ?>" required>
                        </div>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Site Description</label>
                    <textarea class="form-control" name="site_description" rows="3"><?php echo htmlspecialchars($settings['site_description'] ?? ''); ?></textarea>
                </div>
                <div class="mb-3">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" name="maintenance_mode" id="maintenance_mode" <?php echo ($settings['maintenance_mode'] ?? '0') == '1' ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="maintenance_mode">Maintenance Mode</label>
                    </div>
                </div>
                <button type="submit" name="update_settings" class="btn btn-primary">Update Settings</button>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 