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
