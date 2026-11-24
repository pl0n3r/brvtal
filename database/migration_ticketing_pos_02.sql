-- #1995: expand-only POS handoff. Apply explicitly after T24 POS + T08 webhook.
-- Wompi rows keep real payment_attempt_id; NULL only for a verified paid POS sale.
ALTER TABLE ticket_issue_outbox
  DROP FOREIGN KEY fk_ticket_issue_outbox_attempt,
  MODIFY COLUMN payment_attempt_id BIGINT UNSIGNED NULL,
  ADD CONSTRAINT fk_ticket_issue_outbox_pos_attempt
    FOREIGN KEY (payment_attempt_id)
    REFERENCES ticket_payment_attempts(id) ON DELETE RESTRICT;

-- Append terminal AFTER existing enum options so existing MariaDB ordinal values stay stable.
-- A terminal is recorded by an authorized operator; not a processor integration.
ALTER TABLE ticket_pos_sales
  MODIFY COLUMN method ENUM('cash','transfer','wompi_qr','terminal') NOT NULL;
