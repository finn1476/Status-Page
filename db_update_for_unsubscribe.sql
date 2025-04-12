-- Add new columns for unsubscribe functionality to email_subscribers table
ALTER TABLE email_subscribers 
ADD COLUMN unsubscribe_token VARCHAR(255) NULL,
ADD COLUMN unsubscribe_expires_at DATETIME NULL;

-- Create index for faster queries on unsubscribe token
CREATE INDEX idx_email_subscribers_unsubscribe_token ON email_subscribers (unsubscribe_token); 