-- Offline receipt identity: additive storage only, no historical backfill.
CREATE TABLE IF NOT EXISTS ticket_pos_payment_destinations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  method ENUM('transfer','nequi','daviplata','terminal') NOT NULL,
  alias VARCHAR(80) NOT NULL,
  is_active TINYINT UNSIGNED NOT NULL DEFAULT 1,
  created_by_admin_id INT UNSIGNED NOT NULL,
  created_at_utc DATETIME(6) NOT NULL DEFAULT (UTC_TIMESTAMP(6)),
  deactivated_by_admin_id INT UNSIGNED NULL,
  deactivated_at_utc DATETIME(6) NULL,
  UNIQUE KEY uq_pos_destination_method (id,method),
  CONSTRAINT fk_pos_destination_creator FOREIGN KEY (created_by_admin_id) REFERENCES admins(id) ON DELETE RESTRICT,
  CONSTRAINT fk_pos_destination_deactivator FOREIGN KEY (deactivated_by_admin_id) REFERENCES admins(id) ON DELETE RESTRICT,
  CONSTRAINT ck_pos_destination_active CHECK (
    (is_active=1 AND deactivated_by_admin_id IS NULL AND deactivated_at_utc IS NULL)
    OR (is_active=0 AND deactivated_by_admin_id IS NOT NULL AND deactivated_at_utc IS NOT NULL))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ticket_pos_payment_receipts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  method ENUM('transfer','nequi','daviplata','terminal') NOT NULL,
  destination_id BIGINT UNSIGNED NOT NULL,
  normalized_reference VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at_utc DATETIME(6) NOT NULL DEFAULT (UTC_TIMESTAMP(6)),
  UNIQUE KEY uq_pos_receipt_scope (destination_id,normalized_reference),
  CONSTRAINT fk_pos_receipt_destination FOREIGN KEY (destination_id,method)
    REFERENCES ticket_pos_payment_destinations(id,method) ON DELETE RESTRICT,
  CONSTRAINT fk_pos_receipt_order FOREIGN KEY (order_id) REFERENCES ticket_orders(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
