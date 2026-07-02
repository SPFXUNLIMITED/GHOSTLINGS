-- Machines catalog table
-- Created: 2026-07-02

CREATE TABLE IF NOT EXISTS machines (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name            VARCHAR(255) NOT NULL,
  model           VARCHAR(255) NULL,
  width           DECIMAL(10,4) NULL,        -- total inches
  height          DECIMAL(10,4) NULL,        -- total inches
  width_mm        DECIMAL(10,2) NULL,
  height_mm       DECIMAL(10,2) NULL,
  primary_photo   VARCHAR(255) NULL,
  secondary_photo VARCHAR(255) NULL,
  description     TEXT NULL,
  is_active       TINYINT(1) NOT NULL DEFAULT 1,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_machines_active (is_active),
  KEY idx_machines_size (width_mm, height_mm)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
