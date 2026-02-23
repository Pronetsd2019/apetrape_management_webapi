-- Order assignments: records when an order is assigned to a driver (admin)
CREATE TABLE IF NOT EXISTS order_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    assigned_to INT NOT NULL,
    assigned_by INT NOT NULL,
    status VARCHAR(50) DEFAULT 'assigned',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_order_id (order_id),
    INDEX idx_assigned_to (assigned_to),
    INDEX idx_status (status),
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);

-- If table already exists without status/updated_at, run:
-- ALTER TABLE order_assignments ADD COLUMN status VARCHAR(50) DEFAULT 'assigned' AFTER assigned_by;
-- ALTER TABLE order_assignments ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;
-- ALTER TABLE order_assignments ADD INDEX idx_status (status);
