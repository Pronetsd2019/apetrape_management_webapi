-- Peach checkouts mapping table (also auto-created by ensurePeachCheckoutsTable)
CREATE TABLE IF NOT EXISTS peach_checkouts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    checkout_id VARCHAR(64) NOT NULL,
    merchant_transaction_id VARCHAR(16) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    currency VARCHAR(3) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'created',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uk_peach_checkout_id (checkout_id),
    UNIQUE KEY uk_peach_merchant_tx (merchant_transaction_id),
    KEY idx_peach_order_id (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed Peach pay method if missing
INSERT INTO pay_method (name, status, create_At)
SELECT 'Peach', 1, NOW()
WHERE NOT EXISTS (SELECT 1 FROM pay_method WHERE LOWER(name) = 'peach');
