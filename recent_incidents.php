<?php
// Include API domain fix
require_once 'api_domain_fix.php';

// Load DB Config
require_once 'db.php';

// Error handling
function handleError($message, $status = 500) {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(['error' => $message]);
    exit;
}

// Get status page UUID from request
$status_page_uuid = isset($_GET['status_page_uuid']) ? $_GET['status_page_uuid'] : null;
$service_id = isset($_GET['service_id']) ? intval($_GET['service_id']) : null;

if (!$status_page_uuid) {
    handleError('Status page UUID is required', 400);
}

try {
    // Get status page information
    $stmt = $pdo->prepare("SELECT * FROM status_pages WHERE uuid = ?");
    $stmt->execute([$status_page_uuid]);
    $statusPage = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$statusPage) {
        handleError('Status page not found', 404);
    }

    // Get incidents for this status page
    $query = "
        SELECT 
            i.*, 
            c.name as service_name 
        FROM 
            incidents i 
        LEFT JOIN 
            config c ON i.service_id = c.id 
        WHERE 
            i.status_page_id = ?
    ";
    
    $params = [$statusPage['id']];
    
    // Filter by service if specified
    if ($service_id) {
        $query .= " AND i.service_id = ?";
        $params[] = $service_id;
    }
    
    // Order by date descending, limiting to recent incidents
    $query .= " ORDER BY i.date DESC LIMIT 10";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $incidents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get updates for each incident
    foreach ($incidents as &$incident) {
        $stmtUpdates = $pdo->prepare("
            SELECT 
                iu.*, 
                u.name as username 
            FROM 
                incident_updates iu
            JOIN 
                users u ON iu.created_by = u.id
            WHERE 
                iu.incident_id = ?
            ORDER BY 
                iu.update_time DESC
        ");
        $stmtUpdates->execute([$incident['id']]);
        $incident['updates'] = $stmtUpdates->fetchAll(PDO::FETCH_ASSOC);
    }

    header('Content-Type: application/json');
    echo json_encode($incidents);
    
} catch (PDOException $e) {
    error_log("Database error in recent_incidents.php: " . $e->getMessage());
    handleError('Database error: ' . $e->getMessage());
}
?>
