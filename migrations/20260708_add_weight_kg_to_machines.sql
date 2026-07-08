-- Add weight_kg column to machines table
-- Created: 2026-07-08

ALTER TABLE machines
  ADD COLUMN weight_kg DECIMAL(10,2) NULL AFTER height_mm;
