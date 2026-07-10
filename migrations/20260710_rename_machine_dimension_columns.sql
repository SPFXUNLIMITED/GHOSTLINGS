-- Rename legacy machine dimension columns to explicit prefixed names
-- Created: 2026-07-10

ALTER TABLE machines
  CHANGE `width`     `machine_length`    DECIMAL(10,4) NULL,
  CHANGE `height`    `machine_width`     DECIMAL(10,4) NULL,
  CHANGE `width_mm`  `machine_length_mm` DECIMAL(10,2) NULL,
  CHANGE `height_mm` `machine_width_mm`  DECIMAL(10,2) NULL,
  CHANGE `weight_kg` `machine_weight_kg` DECIMAL(10,2) NULL;
