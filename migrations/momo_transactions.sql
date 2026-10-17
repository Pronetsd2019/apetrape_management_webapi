-- MTN MoMo Collection transactions (also auto-created by ensureMomoTransactionsTable)
CREATE TABLE IF NOT EXISTS momo_transactions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    user_id INT NOT NULL,
    external_id VARCHAR(64) NOT NULL,
    reference_id VARCHAR(64) NOT NULL,
    cell VARCHAR(16) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    currency VARCHAR(3) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'new',
    reason VARCHAR(255) NULL,
    financial_transaction_id VARCHAR(64) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uk_momo_external_id (external_id),
    UNIQUE KEY uk_momo_reference_id (reference_id),
    KEY idx_momo_order_id (order_id),
    KEY idx_momo_status (status),
    KEY idx_momo_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed MoMo pay method if missing
INSERT INTO pay_method (name, status, create_At)
SELECT 'MoMo', 1, NOW()
WHERE NOT EXISTS (SELECT 1 FROM pay_method WHERE LOWER(name) = 'momo');
