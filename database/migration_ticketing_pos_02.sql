-- #1995: expand-only POS handoff. Apply explicitly after T24 POS + T08 webhook.
-- Wompi rows keep real payment_attempt_id; NULL only for a verified paid POS sale.
ALTER TABLE ticket_issue_outbox
  MODIFY COLUMN payment_attempt_id BIGINT UNSIGNED NULL;

-- A terminal is recorded by an authorized operator; not a processor integration.
ALTER TABLE ticket_pos_sales
  MODIFY COLUMN method ENUM('cash','terminal','transfer','wompi_qr') NOT NULL;
