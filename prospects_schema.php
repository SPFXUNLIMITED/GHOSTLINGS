<?php

function prospectsColumnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
          AND COLUMN_NAME = :column_name
    ");
    $stmt->execute([
        ':table_name' => $table,
        ':column_name' => $column,
    ]);

    return (int) $stmt->fetchColumn() > 0;
}

function prospectsEnsureSchema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS prospects (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            company VARCHAR(255) NOT NULL DEFAULT '',
            contact_name VARCHAR(255) NOT NULL DEFAULT '',
            phone VARCHAR(100) NULL,
            email VARCHAR(255) NULL,
            website VARCHAR(255) NULL,
            last_called_at DATETIME NULL,
            last_emailed_at DATETIME NULL,
            status ENUM(
                'new',
                'attempting_contact',
                'contacted',
                'qualified',
                'not_interested',
                'converted',
                'archived'
            ) NOT NULL DEFAULT 'new',
            notes TEXT NULL,
            raw_text_dump LONGTEXT NULL,
            parse_preview_json LONGTEXT NULL,
            parse_confidence DECIMAL(5,2) NULL,
            parse_provider VARCHAR(100) NULL,
            parse_errors TEXT NULL,
            converted_customer_id INT UNSIGNED NULL,
            converted_at DATETIME NULL,
            is_archived TINYINT(1) NOT NULL DEFAULT 0,
            created_by INT UNSIGNED NULL,
            updated_by INT UNSIGNED NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_prospects_status (status),
            INDEX idx_prospects_company (company),
            INDEX idx_prospects_email (email),
            INDEX idx_prospects_phone (phone),
            INDEX idx_prospects_last_called_at (last_called_at),
            INDEX idx_prospects_archived (is_archived)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    if (!prospectsColumnExists($pdo, 'prospects', 'converted_customer_id')) {
        $pdo->exec("ALTER TABLE prospects ADD COLUMN converted_customer_id INT UNSIGNED NULL AFTER parse_errors");
    }
    if (!prospectsColumnExists($pdo, 'prospects', 'converted_at')) {
        $pdo->exec("ALTER TABLE prospects ADD COLUMN converted_at DATETIME NULL AFTER converted_customer_id");
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS prospect_interactions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            prospect_id BIGINT UNSIGNED NOT NULL,
            interaction_type ENUM('call', 'email', 'note', 'status_change', 'conversion') NOT NULL,
            outcome VARCHAR(255) NULL,
            interaction_notes TEXT NULL,
            interacted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            admin_id INT UNSIGNED NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_prospect_interactions_prospect (prospect_id),
            INDEX idx_prospect_interactions_type (interaction_type),
            INDEX idx_prospect_interactions_at (interacted_at),
            CONSTRAINT fk_prospect_interactions_prospect
                FOREIGN KEY (prospect_id) REFERENCES prospects(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS prospect_notification_templates (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            subject VARCHAR(255) NOT NULL DEFAULT '',
            body TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS prospect_conversion_map (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            prospect_id BIGINT UNSIGNED NOT NULL,
            customer_id INT UNSIGNED NOT NULL,
            converted_by INT UNSIGNED NULL,
            converted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_prospect_conversion (prospect_id),
            INDEX idx_conversion_customer (customer_id),
            CONSTRAINT fk_conversion_map_prospect
                FOREIGN KEY (prospect_id) REFERENCES prospects(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}
