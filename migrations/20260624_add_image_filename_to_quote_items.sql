-- Add image_filename to quote_items as source of truth for thumbnails
-- Created: 2026-06-24

ALTER TABLE quote_items
  ADD COLUMN image_filename VARCHAR(255) NULL DEFAULT NULL
    COMMENT 'Stored filename of the inventory item image; used for thumbnail in email preview'
  AFTER inventory_item_id;
