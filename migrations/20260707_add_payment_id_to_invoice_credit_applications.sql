-- Add payment_id column to invoice_credit_applications so each credit
-- application can be traced back to the specific customer_payment that
-- funded it.  The column is nullable so existing rows are unaffected until
-- the link_payments_admin.php backfill tool is run.

ALTER TABLE invoice_credit_applications
  ADD COLUMN payment_id INT UNSIGNED NULL AFTER applied_by,
  ADD KEY idx_ica_payment_id (payment_id),
  ADD CONSTRAINT fk_ica_payment
    FOREIGN KEY (payment_id) REFERENCES customer_payments (id)
    ON DELETE SET NULL;
