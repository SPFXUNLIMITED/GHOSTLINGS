-- Link catalog machines to inventory stock records
-- Created: 2026-09-07

ALTER TABLE machines
  ADD COLUMN inventory_item_id INT UNSIGNED NULL DEFAULT NULL
    COMMENT 'FK to inventory_items.id; links a catalog machine to a stock record'
  AFTER price;

ALTER TABLE machines
  ADD CONSTRAINT fk_machines_inventory_item
    FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id)
    ON DELETE SET NULL;
