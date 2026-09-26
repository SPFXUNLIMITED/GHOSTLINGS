-- Track the most recently created standalone task so every task list viewer
-- (not just the creator) can be notified about it.
-- Created: 2026-09-26

CREATE TABLE IF NOT EXISTS app_state (
  state_key  VARCHAR(64) NOT NULL,
  state_val  VARCHAR(255) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (state_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO app_state (state_key, state_val)
SELECT 'last_new_task_id', CAST(COALESCE(MAX(id), 0) AS CHAR)
FROM standalone_tasks
ON DUPLICATE KEY UPDATE state_val = state_val;
