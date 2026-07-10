-- Add crate height and crate weight columns to machines table
-- Created: 2026-07-10

ALTER TABLE machines
  ADD COLUMN crate_height     DECIMAL(10,4) NULL AFTER crate_width_mm,
  ADD COLUMN crate_height_mm  DECIMAL(10,2) NULL AFTER crate_height,
  ADD COLUMN crate_weight_kg  DECIMAL(10,2) NULL AFTER crate_height_mm;
