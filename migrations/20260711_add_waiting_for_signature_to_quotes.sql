ALTER TABLE quotes
  ADD COLUMN waiting_for_signature TINYINT(1) NOT NULL DEFAULT 0 AFTER payment_status;
