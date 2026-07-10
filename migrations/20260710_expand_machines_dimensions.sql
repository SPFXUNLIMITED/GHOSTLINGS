-- Expand machines table: three dimension sections, third photo, visible/catalog toggles
-- Created: 2026-07-10

ALTER TABLE machines
  -- Cutting Area dimensions (Length × Width, stored in inches and mm)
  ADD COLUMN cut_length      DECIMAL(10,4) NULL AFTER weight_kg,
  ADD COLUMN cut_width       DECIMAL(10,4) NULL AFTER cut_length,
  ADD COLUMN cut_length_mm   DECIMAL(10,2) NULL AFTER cut_width,
  ADD COLUMN cut_width_mm    DECIMAL(10,2) NULL AFTER cut_length_mm,
  -- Crate Dimensions (Length × Width, stored in inches and mm)
  ADD COLUMN crate_length    DECIMAL(10,4) NULL AFTER cut_width_mm,
  ADD COLUMN crate_width     DECIMAL(10,4) NULL AFTER crate_length,
  ADD COLUMN crate_length_mm DECIMAL(10,2) NULL AFTER crate_width,
  ADD COLUMN crate_width_mm  DECIMAL(10,2) NULL AFTER crate_length_mm,
  -- Third photo slot
  ADD COLUMN tertiary_photo  VARCHAR(255)  NULL AFTER secondary_photo,
  -- Visibility and catalog membership toggles
  ADD COLUMN is_visible      TINYINT(1)    NOT NULL DEFAULT 1 AFTER is_active,
  ADD COLUMN is_catalog      TINYINT(1)    NOT NULL DEFAULT 1 AFTER is_visible;
