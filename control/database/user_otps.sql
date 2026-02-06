-- OTP storage for mobile registration verification
-- Purpose: store hashed OTP codes with expiry for email/SMS delivery

CREATE TABLE IF NOT EXISTS user_otps (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  channel ENUM('email','sms') NOT NULL,
  destination VARCHAR(255) NOT NULL,
  purpose VARCHAR(50) NOT NULL DEFAULT 'register',
  code_hash VARCHAR(255) NOT NULL,
  expires_at DATETIME NOT NULL,
  consumed_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user_id (user_id),
  INDEX idx_destination (destination),
  INDEX idx_expires_at (expires_at),
  CONSTRAINT fk_user_otps_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

