# Aufräumen vor der Veröffentlichung

Folgende Dateien sollten vor der Veröffentlichung auf GitHub entfernt oder bereinigt werden:

## Zu löschende Debug-Dateien

Diese Dateien wurden für die Entwicklung und Debugging erstellt und sollten nicht in die öffentliche Version gelangen:

```
debug_login.php
debug_admin.php
create_admin.php
set_admin.php
test_admin.php
```

## Zu bereinigende Dateien

Diese Dateien enthalten sensible Daten oder spezifische Konfigurationen, die in der öffentlichen Version nicht enthalten sein sollten:

```
db.php              # Ersetzen durch db.example.php
email_errors.log    # Leeren oder entfernen
.htaccess           # Auf generische Version zurücksetzen
```

## Vor der Freigabe zu erledigende Aufgaben

1. Sensible Daten aus allen Dateien entfernen (Passwörter, API-Schlüssel, etc.)
2. Debug-Code und Kommentare bereinigen
3. Verzeichnisse `logs`, `uploads` und `sessions` leeren
4. Sicherstellen, dass kein Testdatenbestand in der Datenbank verbleibt (in Exportdateien)
5. Versionsnummer in den relevanten Dateien aktualisieren
6. README.md auf Aktualität prüfen

## Zu aktualisierende Pfade

Folgende Pfade in den Dateien sollten überprüft und ggf. angepasst werden:

1. GitHub-Repository-URL in README.md und INSTALL.md
2. Pfade in Cron-Job-Beispielen
3. Domain-Beispiele in der Dokumentation 