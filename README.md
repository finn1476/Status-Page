# Status Page System
## Demo

- https://status.anonfile.de

Ein modernes, selbst-gehostetes System für Statusseiten, Monitoring und Benachrichtigungen.

## Features

- **Statusseiten**: Erstelle öffentliche Statusseiten für deine Dienste
- **Monitoring**: Überwache deine Dienste und Website-Verfügbarkeit
- **Benachrichtigungen**: Automatische E-Mail-Benachrichtigungen bei Ausfällen
- **Benutzerverwaltung**: Multi-Benutzer-System mit verschiedenen Rollen
- **Admin-Dashboard**: Umfassendes Dashboard zur Verwaltung aller Aspekte
- **Abonnement-System**: Verschiedene Nutzungsstufen für Benutzer

## Installation

### Voraussetzungen

- PHP 7.4 oder höher
- MySQL 5.7/MariaDB 10.2 oder höher
- Webserver (Apache/Nginx)
- Die PHP-Erweiterungen: pdo, pdo_mysql, json, openssl, mbstring

### Schnellstart

1. Klone das Repository in dein Webserver-Verzeichnis:
   ```
   git clone https://github.com/username/status-page-system.git /var/www/html
   ```

2. Navigiere zur Installations-URL und folge den Anweisungen:
   ```
   http://deine-domain.de/install.php
   ```

3. Nach der Installation kannst du dich mit dem Standard-Admin-Konto anmelden:
   - E-Mail: admin@example.com
   - Passwort: admin123 (bitte sofort ändern!)

4. Richte im Admin-Bereich die E-Mail-Einstellungen ein:
   ```
   http://deine-domain.de/admin.php
   ```

### Manuell

1. Klone das Repository
2. Erstelle eine MySQL-Datenbank
3. Passe die `db.php` mit deinen Datenbankdaten an
4. Importiere die Datenbankstruktur aus `database.sql`
5. Setze entsprechende Schreibrechte für die Verzeichnisse `logs` und `uploads`

## Nutzung

### Administrator

Als Administrator hast du Zugriff auf:
- Benutzerverwaltung
- E-Mail-Konfiguration
- System-Einstellungen
- Gesamtüberblick über alle Statusseiten
- Systemdiagnose

### Benutzer

Als Benutzer kannst du:
- Eigene Statusseiten erstellen
- Dienste zum Monitoring hinzufügen
- Vorfälle und Wartungsarbeiten eintragen
- E-Mail-Benachrichtigungen konfigurieren

## Systemdiagnose

Zur Diagnose deiner Installation steht der Healthcheck zur Verfügung:
```
http://deine-domain.de/healthcheck.php
```

## Sicherheit

- Alle Benutzerpasswörter werden sicher mit password_hash() gespeichert
- E-Mail-Bestätigungen verhindern Missbrauch
- CSRF-Schutz für alle Formulare
- Eingabevalidierung zur Vermeidung von Injektionen

## Entwicklung

### Verzeichnisstruktur

- `/` - Hauptdateien und Kern des Systems
- `/logs` - Log-Dateien (muss schreibbar sein)
- `/uploads` - Benutzer-Uploads (muss schreibbar sein)

## Lizenz

Dieses Projekt steht unter der MIT-Lizenz - siehe die [LICENSE](LICENSE) Datei für Details.

## Support

Bei Fragen oder Problemen öffne ein [GitHub Issue](https://github.com/username/status-page-system/issues).
