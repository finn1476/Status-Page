<?php
/**
 * API Domain Fix
 * 
 * Diese Datei behebt Probleme mit der Domainweiterleitung bei API-Anfragen.
 * Sie stellt sicher, dass API-Endpunkte auch bei benutzerdefinierten Domains 
 * korrekt funktionieren.
 */

// Überprüfe, ob der Request von localhost oder einer internen IP kommt
function isInternalRequest() {
    $clientIP = $_SERVER['REMOTE_ADDR'] ?? '';
    
    // Prüfe auf localhost oder lokale IP-Adressen
    if ($clientIP == '127.0.0.1' || $clientIP == '::1' || 
        substr($clientIP, 0, 8) == '192.168.' || 
        substr($clientIP, 0, 4) == '10.' || 
        substr($clientIP, 0, 7) == '172.16.') {
        return true;
    }
    
    return false;
}

// Setze die Base-URL für API-Anfragen
function getBaseUrl() {
    $scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path = dirname($_SERVER['SCRIPT_NAME']);
    $path = rtrim($path, '/') . '/';
    
    return $scheme . '://' . $host . $path;
}

// Wenn dies ein API-Endpunkt ist, setze die entsprechenden Header
if (!isInternalRequest()) {
    // CORS-Header für Cross-Domain-Anfragen setzen
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    
    // Wenn es eine OPTIONS-Anfrage ist (Preflight), beende hier
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        exit(0);
    }
} 