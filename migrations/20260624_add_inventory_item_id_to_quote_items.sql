-- Add inventory_item_id to quote_items for thumbnail support in quote email
-- Created: 2026-06-24

ALTER TABLE quote_items
  ADD COLUMN inventory_item_id INT UNSIGNED NULL DEFAULT NULL
    COMMENT 'FK to inventory_items.id; used to resolve thumbnail in email preview'
  AFTER is_taxable;
