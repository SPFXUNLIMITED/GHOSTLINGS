ALTER TABLE freight_forwarders
  ADD COLUMN does_consolidation TINYINT(1) NOT NULL DEFAULT 0
  AFTER shipping_modes;
