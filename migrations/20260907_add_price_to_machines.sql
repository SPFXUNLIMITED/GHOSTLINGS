-- Add price column to machines table
-- Created: 2026-09-07

ALTER TABLE machines
  ADD COLUMN price DECIMAL(10,2) NULL AFTER description;
