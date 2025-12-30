# User Notification Preferences Database Schema

## New Table: user_notification_preferences

```sql
CREATE TABLE user_notification_preferences (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    push_notifications TINYINT(1) DEFAULT 1,
    email_notifications TINYINT(1) DEFAULT 0,
    sms_notifications TINYINT(1) DEFAULT 0,
    promotions TINYINT(1) DEFAULT 1,
    security TINYINT(1) DEFAULT 1,
    general TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Table Description:
- Stores notification preferences for mobile app users
- One-to-one relationship with users table (UNIQUE constraint on user_id)
- All boolean fields use TINYINT(1) (0 = false, 1 = true)
- Automatically deleted when user is deleted (CASCADE)
- Indexed for fast lookups by user_id

### Field Descriptions:
- `id` INT AUTO_INCREMENT PRIMARY KEY - Unique identifier
- `user_id` INT NOT NULL UNIQUE - Reference to users table (one preference record per user)
- `push_notifications` TINYINT(1) DEFAULT 1 - Enable push notifications (default: true)
- `email_notifications` TINYINT(1) DEFAULT 0 - Enable email notifications (default: false)
- `sms_notifications` TINYINT(1) DEFAULT 0 - Enable SMS notifications (default: false)
- `promotions` TINYINT(1) DEFAULT 1 - Enable promotion notifications (default: true)
- `security` TINYINT(1) DEFAULT 1 - Enable security notifications (default: true)
- `general` TINYINT(1) DEFAULT 1 - Enable general notifications (default: true)
- `created_at` TIMESTAMP - When the preferences were created
- `updated_at` TIMESTAMP - When the preferences were last updated

### Default Values:
When a user is created, default preferences should be:
- push_notifications: true
- email_notifications: false
- sms_notifications: false
- promotions: true
- security: true
- general: true

