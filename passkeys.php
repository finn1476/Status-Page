<?php
require_once 'db.php';
session_start();

// Prüfe ob Benutzer eingeloggt ist
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Hole alle Passkeys des Benutzers
$stmt = $pdo->prepare("
    SELECT 
        id,
        credential_id,
        device_name,
        created_at,
        last_used_at,
        counter
    FROM passkeys 
    WHERE user_id = ? 
    ORDER BY created_at DESC
");
$stmt->execute([$_SESSION['user_id']]);
$passkeys = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Passkey-Verwaltung - Status Page System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #0d6efd;
            --secondary-color: #6c757d;
            --success-color: #198754;
            --danger-color: #dc3545;
            --light-bg: #f8f9fa;
            --dark-bg: #212529;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--light-bg);
            min-height: 100vh;
        }

        .wrapper {
            display: flex;
            width: 100%;
            align-items: stretch;
        }

        #sidebar {
            min-width: 250px;
            max-width: 250px;
            background: var(--dark-bg);
            color: #fff;
            transition: all 0.3s;
            position: fixed;
            height: 100vh;
            z-index: 1000;
        }

        #sidebar.active {
            margin-left: -250px;
        }

        #sidebar .sidebar-header {
            padding: 20px;
            background: rgba(0, 0, 0, 0.1);
        }

        #sidebar ul.components {
            padding: 20px 0;
        }

        #sidebar ul li a {
            padding: 15px 20px;
            font-size: 1.1em;
            display: block;
            color: #fff;
            text-decoration: none;
            transition: all 0.3s;
        }

        #sidebar ul li a:hover,
        #sidebar ul li.active > a {
            background: rgba(255, 255, 255, 0.1);
        }

        #sidebar ul li a i {
            margin-right: 10px;
            width: 20px;
            text-align: center;
        }

        #content {
            width: 100%;
            padding: 20px;
            min-height: 100vh;
            transition: all 0.3s;
            margin-left: 250px;
        }

        .navbar {
            padding: 15px 10px;
            background: #fff;
            border: none;
            border-radius: 0;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }

        .card-body {
            padding: 1.5rem;
        }

        .table {
            margin-bottom: 0;
        }

        .table th {
            border-top: none;
            background-color: var(--light-bg);
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.85rem;
            letter-spacing: 0.5px;
        }

        .table td {
            vertical-align: middle;
        }

        .btn {
            padding: 0.5rem 1rem;
            font-weight: 500;
            border-radius: 5px;
            transition: all 0.3s;
        }

        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }

        .btn-primary:hover {
            background-color: #0b5ed7;
            border-color: #0b5ed7;
            transform: translateY(-1px);
        }

        .btn-danger {
            background-color: var(--danger-color);
            border-color: var(--danger-color);
        }

        .btn-danger:hover {
            background-color: #bb2d3b;
            border-color: #bb2d3b;
            transform: translateY(-1px);
        }

        .modal-content {
            border: none;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }

        .modal-header {
            background-color: var(--light-bg);
            border-bottom: 1px solid #dee2e6;
            border-radius: 10px 10px 0 0;
        }

        .modal-body {
            padding: 1.5rem;
        }

        .passkey-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .passkey-icon {
            width: 40px;
            height: 40px;
            background-color: var(--light-bg);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary-color);
        }

        .passkey-details {
            flex: 1;
        }

        .passkey-name {
            font-weight: 600;
            margin-bottom: 2px;
        }

        .passkey-meta {
            font-size: 0.85rem;
            color: var(--secondary-color);
        }

        .badge {
            padding: 0.5em 0.75em;
            font-weight: 500;
        }

        .badge-success {
            background-color: var(--success-color);
        }

        .badge-secondary {
            background-color: var(--secondary-color);
        }

        @media (max-width: 768px) {
            #sidebar {
                margin-left: -250px;
            }
            #sidebar.active {
                margin-left: 0;
            }
            #content {
                margin-left: 0;
            }
            #content.active {
                margin-left: 250px;
            }
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <!-- Sidebar -->
        <nav id="sidebar">
            <div class="sidebar-header">
                <h3>Status Page</h3>
            </div>

            <ul class="list-unstyled components">
                <li class="nav-item">
                    <a class="nav-link" href="dashboard2.php">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li class="nav-item active">
                    <a class="nav-link" href="passkeys.php">
                        <i class="fas fa-key"></i>
                        <span>Passkeys</span>
                    </a>
                </li>
                <?php if (isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1): ?>
                <li class="nav-item">
                    <a class="nav-link" href="admin.php">
                        <i class="fas fa-cog"></i>
                        <span>Admin</span>
                    </a>
                </li>
                <?php endif; ?>
            </ul>
        </nav>

        <!-- Page Content -->
        <div id="content">
            <nav class="navbar navbar-expand-lg navbar-light">
                <div class="container-fluid">
                    <button type="button" id="sidebarCollapse" class="btn btn-primary">
                        <i class="fas fa-align-left"></i>
                    </button>
                    <div class="ms-auto">
                        <a href="logout.php" class="btn btn-outline-danger">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </div>
                </div>
            </nav>

            <div class="container-fluid">
                <div class="row mb-4">
                    <div class="col">
                        <h2 class="mb-2">Passkey-Verwaltung</h2>
                        <p class="text-muted">Verwalten Sie Ihre Passkeys für die sichere Anmeldung.</p>
                    </div>
                    <div class="col-auto">
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addPasskeyModal">
                            <i class="fas fa-plus"></i> Neuen Passkey hinzufügen
                        </button>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Gerätename</th>
                                        <th>Erstellt am</th>
                                        <th>Zuletzt verwendet</th>
                                        <th>Verwendungen</th>
                                        <th>Aktionen</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($passkeys as $passkey): ?>
                                    <tr>
                                        <td>
                                            <div class="passkey-info">
                                                <div class="passkey-icon">
                                                    <i class="fas fa-key"></i>
                                                </div>
                                                <div class="passkey-details">
                                                    <div class="passkey-name">
                                                        <?php echo htmlspecialchars($passkey['device_name'] ?? 'Unnamed Passkey'); ?>
                                                    </div>
                                                    <div class="passkey-meta">
                                                        ID: <?php echo substr($passkey['credential_id'], 0, 8) . '...'; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary">
                                                <i class="far fa-calendar-alt me-1"></i>
                                                <?php echo date('d.m.Y H:i', strtotime($passkey['created_at'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($passkey['last_used_at']): ?>
                                                <span class="badge bg-success">
                                                    <i class="far fa-clock me-1"></i>
                                                    <?php echo date('d.m.Y H:i', strtotime($passkey['last_used_at'])); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Nie</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-primary">
                                                <i class="fas fa-check-circle me-1"></i>
                                                <?php echo $passkey['counter']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <button class="btn btn-sm btn-danger delete-passkey" data-id="<?php echo $passkey['id']; ?>">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Passkey Modal -->
    <div class="modal fade" id="addPasskeyModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Neuen Passkey hinzufügen</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-4">
                        <div class="passkey-icon mx-auto mb-3" style="width: 60px; height: 60px;">
                            <i class="fas fa-key fa-2x"></i>
                        </div>
                        <h5>Passkey registrieren</h5>
                        <p class="text-muted">Klicken Sie auf den Button unten, um einen neuen Passkey zu registrieren.</p>
                    </div>
                    <button type="button" class="btn btn-primary w-100" id="registerPasskey">
                        <i class="fas fa-key"></i> Passkey registrieren
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        $(document).ready(function() {
            // Sidebar Toggle
            $('#sidebarCollapse').on('click', function() {
                $('#sidebar').toggleClass('active');
                $('#content').toggleClass('active');
            });

            // Passkey Registration
            $('#registerPasskey').on('click', async function() {
                try {
                    const response = await fetch('register_passkey.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        }
                    });
                    
                    const data = await response.json();
                    
                    if (!data.success) {
                        throw new Error(data.error || 'Fehler beim Registrieren des Passkeys');
                    }
                    
                    // Create credential
                    const credential = await navigator.credentials.create({
                        publicKey: data.options
                    });
                    
                    // Send credential to server
                    const registerResponse = await fetch('register_passkey.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            credential: {
                                id: credential.id,
                                rawId: Array.from(new Uint8Array(credential.rawId)),
                                response: {
                                    attestationObject: Array.from(new Uint8Array(credential.response.attestationObject)),
                                    clientDataJSON: Array.from(new Uint8Array(credential.response.clientDataJSON))
                                },
                                type: credential.type
                            }
                        })
                    });
                    
                    const registerData = await registerResponse.json();
                    
                    if (!registerData.success) {
                        throw new Error(registerData.error || 'Fehler beim Speichern des Passkeys');
                    }
                    
                    // Reload page to show new passkey
                    location.reload();
                    
                } catch (error) {
                    alert('Fehler: ' + error.message);
                }
            });

            // Delete Passkey
            $('.delete-passkey').on('click', async function() {
                if (!confirm('Möchten Sie diesen Passkey wirklich löschen?')) {
                    return;
                }
                
                const id = $(this).data('id');
                
                try {
                    const response = await fetch('delete_passkey.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({ id: id })
                    });
                    
                    const data = await response.json();
                    
                    if (!data.success) {
                        throw new Error(data.error || 'Fehler beim Löschen des Passkeys');
                    }
                    
                    // Reload page to update list
                    location.reload();
                    
                } catch (error) {
                    alert('Fehler: ' + error.message);
                }
            });
        });
    </script>
</body>
</html> 