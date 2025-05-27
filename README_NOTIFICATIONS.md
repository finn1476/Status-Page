# Email Notification System Setup

This document explains how to set up and use the email notification system for sensor downtime and SSL certificate expiration warnings.

## Database Setup

1. Create the notification settings table:
```bash
mysql -u your_username -p your_database < sql/notification_settings.sql
```

2. Update the email notifications table:
```bash
mysql -u your_username -p your_database < sql/update_email_notifications.sql
```

## Cron Job Setup

To enable automatic checking of sensor status and SSL certificates, set up a cron job to run the check_notifications.php script every 5 minutes:

```bash
# Edit crontab
crontab -e

# Add the following line (adjust the path as needed)
*/5 * * * * php /var/www/html/cron/check_notifications.php
```

## Using the Notification System

1. Log in to your dashboard
2. Navigate to the "Notification Settings" section
3. For each sensor, you can:
   - Enable/disable downtime notifications
   - Enable/disable SSL certificate expiration warnings
   - Set the number of days before SSL expiration to receive warnings (default: 30 days)

## Notification Types

### Sensor Downtime Notifications
- Sent when a sensor is detected as down
- Notifications are sent once per hour to avoid spam
- Includes sensor name, URL, and type
- Links to the status page for more details

### SSL Certificate Warnings
- Sent when an SSL certificate is approaching expiration
- Warnings are sent once per day
- Includes:
  - Service name
  - URL
  - SSL certificate expiration date
  - Days until expiration
  - Link to the status page

## Troubleshooting

If notifications are not being sent:

1. Check the PHP error log for any errors
2. Verify that the cron job is running:
```bash
grep CRON /var/log/syslog
```

3. Ensure email configuration is correct in email_config.php
4. Verify that subscribers have verified their email addresses

## Security Notes

- All notifications are sent only to verified email subscribers
- CSRF protection is implemented for all form submissions
- Database queries use prepared statements to prevent SQL injection
- Input is sanitized before being stored in the database 