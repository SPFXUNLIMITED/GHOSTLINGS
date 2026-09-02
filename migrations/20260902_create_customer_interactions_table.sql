-- Reusable customer interaction module: mirrors prospect_interactions but for customers.
-- See customer_interaction_module.php (customerInteractionEnsureSchema() applies
-- this automatically at runtime; this migration is kept for reference/manual application).

CREATE TABLE IF NOT EXISTS customer_interactions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    interaction_type ENUM('call', 'email', 'note', 'status_change') NOT NULL,
    outcome VARCHAR(255) NULL,
    interaction_notes TEXT NULL,
    interacted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    admin_id INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_customer_interactions_customer (customer_id),
    INDEX idx_customer_interactions_type (interaction_type),
    INDEX idx_customer_interactions_at (interacted_at),
    CONSTRAINT fk_customer_interactions_customer
        FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE customers ADD COLUMN IF NOT EXISTS notes TEXT NULL;
ALTER TABLE customers ADD COLUMN IF NOT EXISTS last_called_at DATETIME NULL;
ALTER TABLE customers ADD COLUMN IF NOT EXISTS last_emailed_at DATETIME NULL;
