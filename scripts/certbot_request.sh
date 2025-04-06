#!/bin/bash
# Certbot-Skript für die automatische SSL-Zertifikatsgenerierung

if [ $# -lt 2 ]; then
    echo "Usage: $0 <domain> <email>"
    exit 1
fi

DOMAIN=$1
EMAIL=$2
BASE_URL="https://$(hostname -f)"
CALLBACK_URL="$BASE_URL/certbot_callback.php"
CALLBACK_TOKEN=$(cat /var/www/html/.certbot_token)

# Log-Datei vorbereiten
LOGFILE="/var/www/html/logs/certbot_${DOMAIN// /_}.log"
echo "Starting certificate request for $DOMAIN at $(date)" > "$LOGFILE"

# Certbot ausführen
certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos --email "$EMAIL" >> "$LOGFILE" 2>&1
RESULT=$?

# Callback aufrufen mit dem Ergebnis
if [ $RESULT -eq 0 ]; then
    STATUS="success"
    echo "Certificate request successful. Calling callback..." >> "$LOGFILE"
else
    STATUS="failed"
    echo "Certificate request failed. Calling callback..." >> "$LOGFILE"
fi

# Callback mit Basic Auth
curl -s -X POST \
     -H "Authorization: Basic $(echo -n "certbot:$CALLBACK_TOKEN" | base64)" \
     "$CALLBACK_URL?domain=$DOMAIN&status=$STATUS" \
     >> "$LOGFILE" 2>&1

echo "Certification process completed at $(date)" >> "$LOGFILE"
echo "Final status: $STATUS" >> "$LOGFILE" 