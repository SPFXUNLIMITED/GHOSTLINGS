-- Intelligent scheduling core tables
-- Created: 2026-06-19

CREATE TABLE IF NOT EXISTS service_route_days (
  id                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  service_date           DATE NOT NULL,
  technician_id          INT UNSIGNED NULL,
  crew_label             VARCHAR(100) NULL,
  region_label           VARCHAR(150) NULL,
  start_location_label   VARCHAR(255) NULL,
  start_lat              DECIMAL(10,7) NULL,
  start_lng              DECIMAL(10,7) NULL,
  end_location_label     VARCHAR(255) NULL,
  end_lat                DECIMAL(10,7) NULL,
  end_lng                DECIMAL(10,7) NULL,
  max_jobs               SMALLINT UNSIGNED NOT NULL DEFAULT 8,
  planned_jobs           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  total_drive_minutes    INT UNSIGNED NOT NULL DEFAULT 0,
  route_status           ENUM('draft','optimized','published','in_progress','completed','cancelled') NOT NULL DEFAULT 'draft',
  route_locked           TINYINT(1) NOT NULL DEFAULT 0,
  optimization_version   INT UNSIGNED NOT NULL DEFAULT 0,
  notes                  TEXT NULL,
  created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_srd_service_date (service_date),
  KEY idx_srd_status_date (route_status, service_date),
  KEY idx_srd_technician_date (technician_id, service_date),
  CONSTRAINT fk_srd_technician
    FOREIGN KEY (technician_id) REFERENCES users (id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS service_requests (
  id                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  customer_id            INT UNSIGNED NULL,
  quote_id               INT UNSIGNED NULL,
  user_id                INT UNSIGNED NULL,
  laser_entry_id         INT UNSIGNED NULL,
  priority_level         ENUM('standard','vip','emergency') NOT NULL DEFAULT 'standard',
  request_status         ENUM('new','queued','scheduled','dispatched','in_progress','completed','cancelled') NOT NULL DEFAULT 'new',
  contact_name           VARCHAR(255) NULL,
  contact_phone          VARCHAR(100) NULL,
  contact_email          VARCHAR(255) NULL,
  laser_brand            VARCHAR(100) NULL,
  laser_model            VARCHAR(100) NULL,
  laser_watts            VARCHAR(50) NULL,
  laser_age              VARCHAR(50) NULL,
  problem_summary        VARCHAR(255) NULL,
  problem_details        TEXT NULL,
  service_street         VARCHAR(255) NULL,
  service_city           VARCHAR(100) NULL,
  service_state          VARCHAR(100) NULL,
  service_zip            VARCHAR(20) NULL,
  service_country        VARCHAR(100) NOT NULL DEFAULT 'USA',
  latitude               DECIMAL(10,7) NULL,
  longitude              DECIMAL(10,7) NULL,
  geocode_status         ENUM('pending','ok','failed','manual') NOT NULL DEFAULT 'pending',
  geocoded_at            DATETIME NULL,
  requested_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  preferred_date_start   DATE NULL,
  preferred_date_end     DATE NULL,
  promised_service_date  DATE NULL,
  source                 VARCHAR(50) NOT NULL DEFAULT 'online',
  route_day_id           INT UNSIGNED NULL,
  route_stop_sequence    SMALLINT UNSIGNED NULL,
  route_locked           TINYINT(1) NOT NULL DEFAULT 0,
  dispatched_at          DATETIME NULL,
  completed_at           DATETIME NULL,
  created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sr_customer (customer_id),
  KEY idx_sr_quote (quote_id),
  KEY idx_sr_user (user_id),
  KEY idx_sr_laser_entry (laser_entry_id),
  KEY idx_sr_status_priority_requested (request_status, priority_level, requested_at),
  KEY idx_sr_promised_date (promised_service_date),
  KEY idx_sr_route (route_day_id, route_stop_sequence),
  KEY idx_sr_location (service_state, service_city, service_zip),
  KEY idx_sr_geo_status (geocode_status, latitude, longitude),
  CONSTRAINT fk_sr_customer
    FOREIGN KEY (customer_id) REFERENCES customers (id)
    ON DELETE SET NULL,
  CONSTRAINT fk_sr_quote
    FOREIGN KEY (quote_id) REFERENCES quotes (id)
    ON DELETE SET NULL,
  CONSTRAINT fk_sr_user
    FOREIGN KEY (user_id) REFERENCES users (id)
    ON DELETE SET NULL,
  CONSTRAINT fk_sr_laser_entry
    FOREIGN KEY (laser_entry_id) REFERENCES laser_entries (id)
    ON DELETE SET NULL,
  CONSTRAINT fk_sr_route_day
    FOREIGN KEY (route_day_id) REFERENCES service_route_days (id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS service_route_stops (
  id                                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  route_day_id                         INT UNSIGNED NOT NULL,
  service_request_id                   INT UNSIGNED NOT NULL,
  stop_sequence                        SMALLINT UNSIGNED NOT NULL,
  arrival_window_start                 DATETIME NULL,
  arrival_window_end                   DATETIME NULL,
  estimated_drive_minutes_from_prev    SMALLINT UNSIGNED NULL,
  estimated_service_minutes             SMALLINT UNSIGNED NULL,
  is_priority_insertion                TINYINT(1) NOT NULL DEFAULT 0,
  stop_status                          ENUM('planned','dispatched','in_progress','completed','skipped','cancelled') NOT NULL DEFAULT 'planned',
  created_at                           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_srs_route_sequence (route_day_id, stop_sequence),
  UNIQUE KEY uniq_srs_service_request (service_request_id),
  KEY idx_srs_route_day (route_day_id),
  KEY idx_srs_status (stop_status),
  CONSTRAINT fk_srs_route_day
    FOREIGN KEY (route_day_id) REFERENCES service_route_days (id)
    ON DELETE CASCADE,
  CONSTRAINT fk_srs_service_request
    FOREIGN KEY (service_request_id) REFERENCES service_requests (id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS service_request_status_history (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  service_request_id INT UNSIGNED NOT NULL,
  from_status        ENUM('new','queued','scheduled','dispatched','in_progress','completed','cancelled') NULL,
  to_status          ENUM('new','queued','scheduled','dispatched','in_progress','completed','cancelled') NOT NULL,
  changed_by         INT UNSIGNED NULL,
  change_source      VARCHAR(50) NOT NULL DEFAULT 'system',
  notes              TEXT NULL,
  changed_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_srsh_request_changed (service_request_id, changed_at),
  KEY idx_srsh_to_status_changed (to_status, changed_at),
  KEY idx_srsh_changed_by (changed_by),
  CONSTRAINT fk_srsh_request
    FOREIGN KEY (service_request_id) REFERENCES service_requests (id)
    ON DELETE CASCADE,
  CONSTRAINT fk_srsh_changed_by
    FOREIGN KEY (changed_by) REFERENCES users (id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS geo_cache (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  address_hash         CHAR(64) NOT NULL,
  normalized_street    VARCHAR(255) NOT NULL,
  normalized_city      VARCHAR(100) NOT NULL,
  normalized_state     VARCHAR(100) NOT NULL,
  normalized_zip       VARCHAR(20) NOT NULL,
  normalized_country   VARCHAR(100) NOT NULL DEFAULT 'USA',
  latitude             DECIMAL(10,7) NOT NULL,
  longitude            DECIMAL(10,7) NOT NULL,
  precision_level      ENUM('rooftop','range_interpolated','geometric_center','approximate','unknown') NOT NULL DEFAULT 'unknown',
  provider             VARCHAR(50) NULL,
  provider_place_id    VARCHAR(191) NULL,
  last_verified_at     DATETIME NULL,
  created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_geo_cache_hash (address_hash),
  KEY idx_geo_cache_lookup (normalized_state, normalized_city, normalized_zip),
  KEY idx_geo_cache_coords (latitude, longitude),
  KEY idx_geo_cache_verified (last_verified_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
