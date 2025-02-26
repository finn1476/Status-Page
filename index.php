<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ProStatus – Professionelle Status Pages</title>
  <link href="https://fonts.googleapis.com/css?family=Roboto:400,500,700&display=swap" rel="stylesheet">
  <style>
    /* Global Styles */
    body {
      margin: 0;
      padding: 0;
      font-family: 'Roboto', sans-serif;
      background: #f3f4f6;
      color: #333;
      line-height: 1.6;
    }
    a {
      text-decoration: none;
      color: inherit;
    }
    img {
      max-width: 100%;
      height: auto;
    }
    
    /* Header */
    header {
      background: #1d2d44;
      color: #fff;
      padding: 20px;
      text-align: center;
      position: relative;
    }
    header h1 {
      margin: 0;
      font-size: 32px;
    }
    /* Button-Container im Header */
    .header-buttons {
      position: absolute;
      right: 20px;
      top: 20px;
    }
    .header-buttons button {
      padding: 10px 20px;
      margin-left: 10px;
      border: none;
      background: #27ae60;
      color: #fff;
      border-radius: 5px;
      cursor: pointer;
      transition: background 0.3s ease;
    }
    .header-buttons button:hover {
      background: #219653;
    }
    
    /* Hero Section */
    .hero {
      position: relative;
      background: url('https://via.placeholder.com/1500x600?text=Status+Page+Hero') center center/cover no-repeat;
      color: #fff;
      padding: 120px 20px;
      text-align: center;
    }
    .hero::after {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(0, 0, 0, 0.5);
      z-index: 1;
    }
    .hero-content {
      position: relative;
      z-index: 2;
    }
    .hero h2 {
      font-size: 48px;
      margin-bottom: 20px;
    }
    .hero p {
      font-size: 20px;
      margin-bottom: 30px;
    }
    .cta-button {
      display: inline-block;
      background: #27ae60;
      color: #fff;
      padding: 15px 30px;
      border-radius: 5px;
      font-size: 18px;
      transition: background 0.3s ease;
      border: none;
      cursor: pointer;
    }
    .cta-button:hover {
      background: #219653;
    }
    
    /* Features Section */
    .features {
      padding: 60px 20px;
      max-width: 1200px;
      margin: 0 auto;
      text-align: center;
    }
    .features h2 {
      font-size: 36px;
      margin-bottom: 40px;
    }
    .feature-items {
      display: flex;
      flex-wrap: wrap;
      gap: 30px;
      justify-content: center;
    }
    .feature {
      background: #fff;
      border-radius: 10px;
      padding: 30px;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
      flex: 1 1 300px;
      transition: transform 0.3s ease;
    }
    .feature:hover {
      transform: translateY(-5px);
    }
    .feature img {
      width: 80px;
      margin-bottom: 20px;
    }
    .feature h3 {
      font-size: 24px;
      margin-bottom: 10px;
    }
    .feature p {
      font-size: 16px;
    }
    
    /* Call-to-Action Section */
    .cta-section {
      background: #27ae60;
      color: #fff;
      text-align: center;
      padding: 60px 20px;
    }
    .cta-section h2 {
      font-size: 36px;
      margin-bottom: 20px;
    }
    .cta-section p {
      font-size: 18px;
      margin-bottom: 30px;
    }
    
    /* Footer */
    footer {
      background: #1d2d44;
      color: #fff;
      text-align: center;
      padding: 20px;
    }
    
    /* Modal-Styles für Registrierung und Login */
    .modal {
      display: none; 
      position: fixed;
      z-index: 10000;
      left: 0;
      top: 0;
      width: 100%;
      height: 100%;
      overflow: auto;
      background-color: rgba(0,0,0,0.5);
    }
    .modal-content {
      background-color: #fff;
      margin: 10% auto;
      padding: 20px;
      border-radius: 10px;
      width: 90%;
      max-width: 400px;
      position: relative;
    }
    .modal-content h2 {
      margin-top: 0;
    }
    .modal-content label {
      display: block;
      margin: 10px 0 5px;
    }
    .modal-content input {
      width: 100%;
      padding: 8px;
      box-sizing: border-box;
    }
    .modal-content button {
      margin-top: 15px;
      width: 100%;
      padding: 10px;
      background: #27ae60;
      color: #fff;
      border: none;
      border-radius: 5px;
      cursor: pointer;
    }
    .close, .close-login {
      position: absolute;
      top: 10px;
      right: 15px;
      color: #aaa;
      font-size: 28px;
      font-weight: bold;
      cursor: pointer;
    }
    
    /* Responsive */
    @media (max-width: 768px) {
      .hero h2 {
        font-size: 36px;
      }
      .hero p {
        font-size: 18px;
      }
      .features h2,
      .cta-section h2 {
        font-size: 28px;
      }
    }
  </style>
</head>
<body>
  <!-- Header -->
  <header>
    <h1>ProStatus</h1>
    <div class="header-buttons">
      <button id="openLogin">Login</button>
      <!-- Verwende eine gemeinsame Klasse statt doppelter IDs -->
      <button class="openRegistration">Registrieren</button>
    </div>
  </header>
  
  <!-- Hero Section -->
  <section class="hero">
    <div class="hero-content">
      <h2>Professionelle Status Pages</h2>
      <p>Transparenz und Vertrauen – zeigen Sie Ihren Kunden den aktuellen Systemstatus in Echtzeit.</p>
      <!-- Auch hier: gemeinsame Klasse -->
      <button class="cta-button openRegistration">Jetzt Registrieren</button>
    </div>
  </section>
  
  <!-- Features Section -->
  <section class="features">
    <h2>Warum ProStatus?</h2>
    <div class="feature-items">
      <div class="feature">
        <img src="https://via.placeholder.com/80?text=Icon" alt="Echtzeit-Status">
        <h3>Echtzeit-Status</h3>
        <p>Erhalten Sie automatische Updates und informieren Sie Ihre Kunden sofort bei Änderungen.</p>
      </div>
      <div class="feature">
        <img src="https://via.placeholder.com/80?text=Icon" alt="Anpassbares Design">
        <h3>Anpassbares Design</h3>
        <p>Passen Sie Ihre Status Page individuell an Ihr Corporate Design an.</p>
      </div>
      <div class="feature">
        <img src="https://via.placeholder.com/80?text=Icon" alt="Einfache Integration">
        <h3>Einfache Integration</h3>
        <p>Nahtlose Integration in Ihre bestehenden Systeme – ohne komplizierte Konfiguration.</p>
      </div>
    </div>
  </section>
  
  <!-- Call-to-Action Section -->
  <section class="cta-section" id="cta">
    <h2>Bereit, Ihre Kunden zu begeistern?</h2>
    <p>Regestrieren Sie sich jezt und erleben Sie, wie ProStatus Ihr Unternehmen unterstützt.</p>
    <!-- Auch hier: gemeinsame Klasse -->
    <a href="#" class="cta-button openRegistration">Regestrieren</a>
  </section>
  
  <!-- Footer -->
  <footer>
    <p>&copy; 2025 ProStatus. Alle Rechte vorbehalten.</p>
  </footer>
  
  <!-- Registrierungs-Modal -->
  <div id="registrationModal" class="modal">
    <div class="modal-content">
      <span class="close">&times;</span>
      <h2>Registrierung</h2>
      <form id="registrationForm" action="register.php" method="POST">
        <label for="name">Name:</label>
        <input type="text" name="name" id="name" required placeholder="Dein Name">
        <label for="email">E-Mail:</label>
        <input type="email" name="email" id="email" required placeholder="Deine E-Mail-Adresse">
        <label for="password">Passwort:</label>
        <input type="password" name="password" id="password" required placeholder="Dein Passwort">
        <button type="submit" class="cta-button">Registrieren</button>
      </form>
    </div>
  </div>
  
  <!-- Login-Modal -->
  <div id="loginModal" class="modal">
    <div class="modal-content">
      <span class="close-login">&times;</span>
      <h2>Login</h2>
      <form id="loginForm" action="login.php" method="POST">
        <label for="login_email">E-Mail:</label>
        <input type="email" name="email" id="login_email" required placeholder="Deine E-Mail-Adresse">
        <label for="login_password">Passwort:</label>
        <input type="password" name="password" id="login_password" required placeholder="Dein Passwort">
        <button type="submit" class="cta-button">Login</button>
      </form>
    </div>
  </div>
  
  <!-- JavaScript für Modal -->
  <script>
    // Registrierung-Modal für alle Elemente mit der Klasse 'openRegistration' öffnen
    document.querySelectorAll('.openRegistration').forEach(function(element) {
      element.addEventListener('click', function(e) {
        e.preventDefault();
        document.getElementById('registrationModal').style.display = 'block';
      });
    });
    
    // Öffne Login-Modal
    document.getElementById('openLogin').addEventListener('click', function() {
      document.getElementById('loginModal').style.display = 'block';
    });
    
    // Schließen der Modale über "X"
    document.querySelector('.modal .close').addEventListener('click', function() {
      document.getElementById('registrationModal').style.display = 'none';
    });
    document.querySelector('.modal .close-login').addEventListener('click', function() {
      document.getElementById('loginModal').style.display = 'none';
    });
    
    // Schließen, wenn außerhalb des Modals geklickt wird
    window.addEventListener('click', function(event) {
      if (event.target === document.getElementById('registrationModal')) {
        document.getElementById('registrationModal').style.display = 'none';
      }
      if (event.target === document.getElementById('loginModal')) {
        document.getElementById('loginModal').style.display = 'none';
      }
    });
  </script>
</body>
</html>
