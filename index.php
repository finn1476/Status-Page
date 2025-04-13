<?php
// Domain-Weiterleitung
require_once 'db.php';

// Aktiviere Fehlerberichterstattung für Debugging
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);

// Aktuelle Domain ermitteln mit Berücksichtigung des Reverse Proxys
function get_actual_domain() {
    // Mögliche Header, die von Reverse Proxies gesetzt werden
    $headers = [
        'HTTP_X_FORWARDED_HOST',   // Standard für viele Proxies
        'HTTP_X_FORWARDED_SERVER', // Manchmal verwendet
        'HTTP_FORWARDED',          // Neuer HTTP/2 Standard
        'HTTP_X_HOST',             // Manchmal von Sophos verwendet
        'HTTP_HOST'                // Fallback auf den normalen Host-Header
    ];
    
    foreach ($headers as $header) {
        if (isset($_SERVER[$header]) && !empty($_SERVER[$header])) {
            // Manchmal enthält der Header mehrere Werte, kommagetrennt
            $hosts = explode(',', $_SERVER[$header]);
            $host = trim($hosts[0]);
            
            // Manchmal enthält der Host auch den Port
            if (strpos($host, ':') !== false) {
                $host = explode(':', $host)[0];
            }
            
            error_log("Domain aus Header $header: $host");
            return strtolower($host);
        }
    }
    
    // Fallback: IP-Adresse des Servers
    return isset($_SERVER['SERVER_ADDR']) ? $_SERVER['SERVER_ADDR'] : 'localhost';
}

// Erfasse die tatsächliche Domain
$current_domain = get_actual_domain();

// Erfasse zusätzlich alle Header für Debugging
$all_headers = getallheaders();
error_log("Alle HTTP-Header: " . json_encode($all_headers));

// Debugging-Info in das Fehlerprotokoll schreiben
error_log("Ermittelte Domain: " . $current_domain);
error_log("Original HTTP_HOST: " . ($_SERVER['HTTP_HOST'] ?? 'nicht gesetzt'));

// Überprüfen, ob die aktuelle Domain eine IP-Adresse ist
function is_ip_address($domain) {
    return (bool) filter_var($domain, FILTER_VALIDATE_IP);
}

// Liste der Hauptdomains (die nicht weitergeleitet werden sollen)
$main_domains = ['status.anonfile.de', 'localhost', 'localhost:80', '127.0.0.1'];

// Prüfen, ob Weiterleitung erfolgen soll
$should_redirect = !in_array($current_domain, $main_domains);

if ($should_redirect) {
    try {
        // Datenbankverbindung öffnen
        $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $found_domain = false;
        
        if (is_ip_address($current_domain)) {
            // Spezialfall: Bei IP-Adresse die erste verifizierte Domain verwenden
            error_log("IP-Adresse erkannt, suche erste verifizierte Domain");
            $stmt = $pdo->query("SELECT * FROM custom_domains WHERE verified = 1 ORDER BY id LIMIT 1");
            $domain = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($domain) {
                $found_domain = true;
                error_log("Erste verifizierte Domain gefunden: {$domain['domain']}");
            }
        } else {
            // Normale Domain-Suche
            $stmt = $pdo->prepare("SELECT * FROM custom_domains WHERE domain = ? AND verified = 1");
            $stmt->execute([$current_domain]);
            $domain = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Debugging-Info
            error_log("Domainsuche für $current_domain: " . ($domain ? "Gefunden (ID: {$domain['id']})" : "Nicht gefunden"));
            
            if ($domain) {
                $found_domain = true;
            } else {
                // Erweiterte Suche: versuche die Domain als Subdomain zu finden
                // beispielsweise, wenn status.example.com eingegeben wurde, versuche *.example.com zu finden
                $domain_parts = explode('.', $current_domain);
                if (count($domain_parts) > 2) {
                    // Entferne die erste Subdomain und ersetze sie durch Wildcard
                    array_shift($domain_parts);
                    $wildcard_domain = '*.' . implode('.', $domain_parts);
                    
                    $stmt = $pdo->prepare("SELECT * FROM custom_domains WHERE domain = ? AND verified = 1");
                    $stmt->execute([$wildcard_domain]);
                    $domain = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    error_log("Wildcard-Domainsuche für $wildcard_domain: " . ($domain ? "Gefunden" : "Nicht gefunden"));
                    
                    if ($domain) {
                        $found_domain = true;
                    }
                }
            }
        }
        
        // Wenn eine Domain gefunden wurde, zur zugehörigen Status-Seite weiterleiten
        if ($found_domain) {
            // Status-Page-ID auslesen
            $status_page_id = $domain['status_page_id'];
            
            // UUID der Status-Page abrufen
            $stmt = $pdo->prepare("SELECT uuid FROM status_pages WHERE id = ?");
            $stmt->execute([$status_page_id]);
            $status_page = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Debugging-Info
            error_log("Status-Page-Suche für ID $status_page_id: " . ($status_page ? "Gefunden (UUID: {$status_page['uuid']})" : "Nicht gefunden"));
            
            if ($status_page) {
                $redirect_url = "status_page.php?status_page_uuid=" . $status_page['uuid'];
                error_log("Weiterleitung zu: $redirect_url" . (is_ip_address($current_domain) ? " (via IP-Adresse)" : ""));
                
                // Weiterleitung zur Status-Page
                header("Location: $redirect_url");
                exit;
            }
        }
        
        // Keine passende Domain gefunden - protokollieren
        error_log("Keine passende Domain in der Datenbank gefunden für: $current_domain");
        
    } catch (PDOException $e) {
        // Bei Datenbankfehlern Details protokollieren und normale Seite anzeigen
        error_log("Domain-Weiterleitung fehlgeschlagen: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Status Page - Willkommen</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f8f9fa;
            color: #333;
        }
        .hero-section {
            background: linear-gradient(135deg, #0d6efd 0%, #0dcaf0 100%);
            color: white;
            padding: 100px 0;
            margin-bottom: 50px;
        }
        .hero-title {
            font-size: 3.5rem;
            font-weight: bold;
            margin-bottom: 20px;
        }
        .hero-subtitle {
            font-size: 1.5rem;
            margin-bottom: 30px;
            opacity: 0.9;
        }
        .feature-card {
            background: white;
            border-radius: 10px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            transition: transform 0.3s ease;
        }
        .feature-card:hover {
            transform: translateY(-5px);
        }
        .feature-icon {
            font-size: 2.5rem;
            color: #0d6efd;
            margin-bottom: 20px;
        }
        .feature-title {
            font-size: 1.5rem;
            font-weight: bold;
            margin-bottom: 15px;
        }
        .feature-description {
            color: #6c757d;
            line-height: 1.6;
        }
        .cta-section {
            background-color: #e9ecef;
            padding: 50px 0;
            margin-top: 50px;
        }
        .btn-custom {
            padding: 12px 30px;
            font-size: 1.1rem;
            border-radius: 30px;
        }
        .navbar {
            background-color: transparent !important;
            padding: 20px 0;
            transition: background-color 0.3s ease;
        }
        .navbar.scrolled {
            background-color: white !important;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .navbar-brand {
            font-weight: bold;
            font-size: 1.5rem;
        }
        .nav-link {
            color: white !important;
            font-weight: 500;
            margin: 0 10px;
        }
        .navbar.scrolled .nav-link {
            color: #333 !important;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg fixed-top">
        <div class="container">
            <a class="navbar-brand" href="index.php">Status Page</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="#features">Features</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#pricing">Preise</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#" data-bs-toggle="modal" data-bs-target="#loginModal">Login</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#" data-bs-toggle="modal" data-bs-target="#registrationModal">Registrieren</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <section class="hero-section">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-6">
                    <h1 class="hero-title">Überwachen Sie Ihre Services in Echtzeit</h1>
                    <p class="hero-subtitle">Erstellen Sie professionelle Status-Seiten und informieren Sie Ihre Nutzer über Ausfälle und Wartungen.</p>
                    <button class="btn btn-light btn-custom" data-bs-toggle="modal" data-bs-target="#registrationModal">Jetzt kostenlos starten</button>
                </div>
                <div class="col-lg-6">
                    <img src="https://via.placeholder.com/600x400" alt="Status Page Demo" class="img-fluid rounded shadow">
                </div>
            </div>
        </div>
    </section>

    <section id="features" class="py-5">
        <div class="container">
            <h2 class="text-center mb-5">Features</h2>
            <div class="row">
                <div class="col-md-4">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="bi bi-speedometer2"></i>
                        </div>
                        <h3 class="feature-title">Echtzeit-Monitoring</h3>
                        <p class="feature-description">Überwachen Sie Ihre Services in Echtzeit und erhalten Sie sofortige Benachrichtigungen bei Ausfällen.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="bi bi-envelope"></i>
                        </div>
                        <h3 class="feature-title">E-Mail-Benachrichtigungen</h3>
                        <p class="feature-description">Informieren Sie Ihre Nutzer automatisch über Statusänderungen und Wartungen.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="bi bi-palette"></i>
                        </div>
                        <h3 class="feature-title">Anpassbares Design</h3>
                        <p class="feature-description">Gestalten Sie Ihre Status-Seite nach Ihren Wünschen mit benutzerdefiniertem CSS.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section id="pricing" class="py-5 bg-light">
        <div class="container">
            <h2 class="text-center mb-5">Preise</h2>
            <div class="row">
                <div class="col-md-4">
                    <div class="feature-card text-center">
                        <h3 class="feature-title">Free</h3>
                        <div class="feature-icon">
                            <i class="bi bi-gift"></i>
                        </div>
                        <p class="feature-description">
                            <ul class="list-unstyled">
                                <li>1 Status Page</li>
                                <li>5 Sensoren</li>
                                <li>10 E-Mail-Abonnenten</li>
                            </ul>
                        </p>
                        <button class="btn btn-primary btn-custom" data-bs-toggle="modal" data-bs-target="#registrationModal">Kostenlos starten</button>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="feature-card text-center">
                        <h3 class="feature-title">Pro</h3>
                        <div class="feature-icon">
                            <i class="bi bi-star"></i>
                        </div>
                        <p class="feature-description">
                            <ul class="list-unstyled">
                                <li>5 Status Pages</li>
                                <li>20 Sensoren</li>
                                <li>50 E-Mail-Abonnenten</li>
                            </ul>
                        </p>
                        <button class="btn btn-primary btn-custom" data-bs-toggle="modal" data-bs-target="#registrationModal">Pro wählen</button>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="feature-card text-center">
                        <h3 class="feature-title">Business</h3>
                        <div class="feature-icon">
                            <i class="bi bi-building"></i>
                        </div>
                        <p class="feature-description">
                            <ul class="list-unstyled">
                                <li>Unbegrenzte Status Pages</li>
                                <li>Unbegrenzte Sensoren</li>
                                <li>Unbegrenzte E-Mail-Abonnenten</li>
                            </ul>
                        </p>
                        <button class="btn btn-primary btn-custom" data-bs-toggle="modal" data-bs-target="#registrationModal">Business wählen</button>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="cta-section">
        <div class="container text-center">
            <h2 class="mb-4">Bereit zum Start?</h2>
            <p class="mb-4">Erstellen Sie noch heute Ihre professionelle Status-Seite.</p>
            <button class="btn btn-primary btn-custom" data-bs-toggle="modal" data-bs-target="#registrationModal">Jetzt registrieren</button>
        </div>
    </section>

    <!-- Login Modal -->
    <div class="modal fade" id="loginModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Login</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form action="login.php" method="POST">
                        <div class="mb-3">
                            <label for="email" class="form-label">E-Mail</label>
                            <input type="email" class="form-control" id="email" name="email" required>
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">Passwort</label>
                            <input type="password" class="form-control" id="password" name="password" required>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Login</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Registration Modal -->
    <div class="modal fade" id="registrationModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Registrierung</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form action="register.php" method="POST">
                        <div class="mb-3">
                            <label for="name" class="form-label">Name</label>
                            <input type="text" class="form-control" id="name" name="name" required>
                        </div>
                        <div class="mb-3">
                            <label for="reg_email" class="form-label">E-Mail</label>
                            <input type="email" class="form-control" id="reg_email" name="email" required>
                        </div>
                        <div class="mb-3">
                            <label for="reg_password" class="form-label">Passwort</label>
                            <input type="password" class="form-control" id="reg_password" name="password" required>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Registrieren</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        window.addEventListener('scroll', function() {
            if (window.scrollY > 50) {
                document.querySelector('.navbar').classList.add('scrolled');
            } else {
                document.querySelector('.navbar').classList.remove('scrolled');
            }
        });
    </script>
</body>
</html>
