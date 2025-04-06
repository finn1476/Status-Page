<?php
session_start();

// Setze Admin-Status direkt
$_SESSION['is_admin'] = true;

echo "<h1>Admin-Status gesetzt</h1>";
echo "<p>Der Admin-Status wurde in der Session gesetzt.</p>";
echo "<p>Aktuelle Session-Daten:</p>";
echo "<pre>";
print_r($_SESSION);
echo "</pre>";

echo "<p><a href='admin.php'>Zur Admin-Seite</a></p>";
?> 