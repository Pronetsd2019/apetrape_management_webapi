# Support Database Schema

## New Table: support_contacts

```sql
CREATE TABLE support_contacts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    name VARCHAR(255) NULL,
    cell VARCHAR(50) NULL,
    email VARCHAR(255) NULL,
    category VARCHAR(100) NOT NULL,
    message TEXT NOT NULL,
    status ENUM('pending', 'in_progress', 'resolved', 'closed') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user_id (user_id),
    INDEX idx_status (status),
    INDEX idx_category (category),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Table Description:
- Stores contact/support messages from mobile app users
- `user_id` is NULL for unauthenticated users, populated for logged-in users
- `name` and `cell` are required for unauthenticated users, optional for authenticated users (can be extracted from user record)
- `email` is optional and can be extracted from user record if logged in
- `category` stores the support category (e.g., "Billing", "Technical", "General", etc.)
- `message` stores the user's message
- `status` tracks the resolution status of the support request
- Indexed for efficient queries by user, status, category, and date

### Field Descriptions:
- `id` INT AUTO_INCREMENT PRIMARY KEY - Unique identifier
- `user_id` INT NULL - Reference to users table (NULL for unauthenticated users)
- `name` VARCHAR(255) NULL - User's name (required for unauthenticated, optional for authenticated)
- `cell` VARCHAR(50) NULL - User's cell phone (required for unauthenticated, optional for authenticated)
- `email` VARCHAR(255) NULL - User's email (extracted from user record if logged in)
- `category` VARCHAR(100) NOT NULL - Support category
- `message` TEXT NOT NULL - User's message
- `status` ENUM('pending', 'in_progress', 'resolved', 'closed') DEFAULT 'pending' - Request status
- `created_at` TIMESTAMP - When the message was created
- `updated_at` TIMESTAMP - When the message was last updated

---

## New Table: support_feedback

```sql
CREATE TABLE support_feedback (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    name VARCHAR(255) NULL,
    cell VARCHAR(50) NULL,
    email VARCHAR(255) NULL,
    rating INT NOT NULL CHECK (rating >= 1 AND rating <= 5),
    type VARCHAR(100) NULL,
    message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user_id (user_id),
    INDEX idx_rating (rating),
    INDEX idx_type (type),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Table Description:
- Stores feedback submissions from mobile app users
- `user_id` is NULL for unauthenticated users, populated for logged-in users
- `name` and `cell` are required for unauthenticated users, optional for authenticated users (can be extracted from user record)
- `email` is optional and can be extracted from user record if logged in
- `rating` is required and must be between 1 and 5
- `type` is optional and can indicate what the feedback is about (e.g., "App", "Service", "Feature", etc.)
- `message` is optional and allows users to provide detailed feedback comments
- Indexed for efficient queries by user, rating, type, and date

### Field Descriptions:
- `id` INT AUTO_INCREMENT PRIMARY KEY - Unique identifier
- `user_id` INT NULL - Reference to users table (NULL for unauthenticated users)
- `name` VARCHAR(255) NULL - User's name (required for unauthenticated, optional for authenticated)
- `cell` VARCHAR(50) NULL - User's cell phone (required for unauthenticated, optional for authenticated)
- `email` VARCHAR(255) NULL - User's email (extracted from user record if logged in)
- `rating` INT NOT NULL - Rating value (1-5 scale)
- `type` VARCHAR(100) NULL - Type/category of feedback (optional)
- `message` TEXT NULL - Detailed feedback message (optional)
- `created_at` TIMESTAMP - When the feedback was created
- `updated_at` TIMESTAMP - When the feedback was last updated

