#!/bin/bash
# Certbot-Skript für die automatische SSL-Zertifikatsgenerierung

if [ $# -lt 2 ]; then
    echo "Usage: $0 <domain> <email>"
    exit 1
fi

DOMAIN=$1
EMAIL=$2

# Use the actual server hostname instead of hostname -f, which may not work correctly
SERVER_HOSTNAME=$(hostname)
BASE_URL="http://${SERVER_HOSTNAME}"
CALLBACK_URL="${BASE_URL}/certbot_callback.php"
CALLBACK_TOKEN=$(cat /var/www/html/.certbot_token)

# Log-Datei vorbereiten
LOGFILE="/var/www/html/logs/certbot_${DOMAIN// /_}.log"
echo "Starting certificate request for $DOMAIN at $(date)" > "$LOGFILE"

# Create custom directories for certbot if they don't exist
CERTBOT_CONFIG_DIR="/var/www/html/certbot/config"
CERTBOT_WORK_DIR="/var/www/html/certbot/work"
CERTBOT_LOGS_DIR="/var/www/html/certbot/logs"

mkdir -p "$CERTBOT_CONFIG_DIR" "$CERTBOT_WORK_DIR" "$CERTBOT_LOGS_DIR"

# Define webroot path
WEBROOT_PATH="/var/www/html"

# Run certbot with webroot plugin instead of nginx
certbot certonly --webroot \
    -w "$WEBROOT_PATH" \
    -d "$DOMAIN" \
    --non-interactive \
    --agree-tos \
    --email "$EMAIL" \
    --config-dir "$CERTBOT_CONFIG_DIR" \
    --work-dir "$CERTBOT_WORK_DIR" \
    --logs-dir "$CERTBOT_LOGS_DIR" \
    >> "$LOGFILE" 2>&1
RESULT=$?

# Callback aufrufen mit dem Ergebnis
if [ $RESULT -eq 0 ]; then
    STATUS="success"
    echo "Certificate request successful. Calling callback..." >> "$LOGFILE"
    
    # Output certificate location for debugging
    echo "Certificate location: $CERTBOT_CONFIG_DIR/live/$DOMAIN/" >> "$LOGFILE"
    
    # Create symlinks for Apache/Nginx if needed
    # This section can be customized based on your web server setup
    echo "Certificate details:" >> "$LOGFILE"
    ls -la "$CERTBOT_CONFIG_DIR/live/$DOMAIN/" >> "$LOGFILE" 2>&1
else
    STATUS="failed"
    echo "Certificate request failed. Calling callback..." >> "$LOGFILE"
fi

# Improved callback with better debugging
echo "Calling callback URL: $CALLBACK_URL" >> "$LOGFILE"
echo "Domain: $DOMAIN, Status: $STATUS" >> "$LOGFILE"

# Use direct URL for callback instead of trying to use HTTPS which might not be set up yet
CALLBACK_RESULT=$(curl -s -X POST \
     -H "Authorization: Basic $(echo -n "certbot:$CALLBACK_TOKEN" | base64)" \
     --data-urlencode "domain=$DOMAIN" \
     --data-urlencode "status=$STATUS" \
     "http://localhost/certbot_callback.php" 2>&1)

echo "Callback response: $CALLBACK_RESULT" >> "$LOGFILE"

# Try direct file access as a fallback method
echo "Trying direct file access as fallback" >> "$LOGFILE"
PHP_COMMAND="<?php
\$_GET['domain'] = '$DOMAIN';
\$_GET['status'] = '$STATUS';
\$_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode('certbot:$CALLBACK_TOKEN');
require_once '/var/www/html/certbot_callback.php';
?>"

echo "$PHP_COMMAND" | php >> "$LOGFILE" 2>&1

echo "Certification process completed at $(date)" >> "$LOGFILE"
echo "Final status: $STATUS" >> "$LOGFILE" 