# Mobile Authentication Database Schema

## Users Table Modifications

Add the following fields to the existing `users` table:

```sql
ALTER TABLE users 
ADD COLUMN password_hash VARCHAR(255) NULL AFTER cell,
ADD COLUMN provider ENUM('email', 'facebook', 'google', 'instagram') DEFAULT 'email' AFTER password_hash,
ADD COLUMN provider_user_id VARCHAR(255) NULL AFTER provider,
ADD COLUMN avatar VARCHAR(500) NULL AFTER provider_user_id,
ADD COLUMN failed_attempts INT DEFAULT 0 AFTER avatar,
ADD COLUMN locked_until DATETIME NULL AFTER failed_attempts,
ADD COLUMN lockout_stage INT DEFAULT 0 AFTER locked_until;
```

### Field Descriptions:
- `password_hash` VARCHAR(255) NULL - For email/password authentication (hashed using password_hash())
- `provider` ENUM('email', 'facebook', 'google', 'instagram') DEFAULT 'email' - Authentication provider type
- `provider_user_id` VARCHAR(255) NULL - Social provider's user ID (for social login users)
- `avatar` VARCHAR(500) NULL - User avatar URL from social provider or uploaded
- `failed_attempts` INT DEFAULT 0 - Number of consecutive failed login attempts
- `locked_until` DATETIME NULL - Timestamp when account lockout expires (NULL for permanent lockout)
- `lockout_stage` INT DEFAULT 0 - Lockout stage (0 = no lockout, 1 = first lockout, 2 = second lockout, 3 = permanent lockout)

### Lockout Stages:
- **Stage 0**: No lockout - allows login attempts
- **Stage 1**: After 5 failed attempts → locked for 5 minutes
- **Stage 2**: After unlock, 3 failed attempts → locked for 10 minutes
- **Stage 3**: After unlock, 3 failed attempts → permanent lockout (only admin can unlock)

### User Status Values:
- `1` = Active
- `0` = Inactive (admin deactivated)
- `-2` = User self-deactivated

## New Tables: User Deactivation

### Table: user_deactivations

```sql
CREATE TABLE user_deactivations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    other_reason TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Table: user_deactivation_reasons

```sql
CREATE TABLE user_deactivation_reasons (
    id INT AUTO_INCREMENT PRIMARY KEY,
    deactivation_id INT NOT NULL,
    reason VARCHAR(255) NOT NULL,
    FOREIGN KEY (deactivation_id) REFERENCES user_deactivations(id) ON DELETE CASCADE,
    INDEX idx_deactivation_id (deactivation_id),
    INDEX idx_reason (reason)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Table Descriptions:
- `user_deactivations` stores the main deactivation record with optional "Other" reason details
- `user_deactivation_reasons` stores individual reasons selected by the user (supports multiple reasons per deactivation)
- Both tables cascade delete when user is deleted
- Indexed for efficient queries

## New Table: mobile_refresh_tokens

```sql
CREATE TABLE mobile_refresh_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_token (token),
    INDEX idx_user_id (user_id),
    INDEX idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Table Description:
- Stores refresh tokens for mobile app users
- Tokens expire after 7 days
- Automatically deleted when user is deleted (CASCADE)
- Indexed for fast lookups by token, user_id, and expiry

