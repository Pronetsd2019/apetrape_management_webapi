-- Peach card tokens for one-click payments (also auto-created by ensurePeachSavedCardsTable).
-- Stores Peach registration IDs only — never card numbers.
CREATE TABLE IF NOT EXISTS peach_saved_cards (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    registration_id VARCHAR(64) NOT NULL,
    brand VARCHAR(32) NULL,
    last4 VARCHAR(4) NULL,
    expiry_month VARCHAR(2) NULL,
    expiry_year VARCHAR(4) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uk_peach_registration_id (registration_id),
    KEY idx_peach_saved_cards_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
