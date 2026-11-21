-- T21. Additive sandbox refund ledger. Never execute on production by inference.
CREATE TABLE IF NOT EXISTS ticket_refunds (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_id BIGINT UNSIGNED NOT NULL,
 buyer_id BIGINT UNSIGNED NOT NULL,
 event_id INT UNSIGNED NOT NULL,
 request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 selection_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 reason VARCHAR(220) NOT NULL,
 amount_cop BIGINT UNSIGNED NOT NULL,
 status ENUM('requested','approved','processing','manual_pending','refunded','rejected','failed') NOT NULL DEFAULT 'requested',
 approver_admin_id INT UNSIGNED NULL,
 reviewer_note VARCHAR(220) NULL,
 provider_refund_id VARCHAR(190) NULL,
 manual_receipt_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
 created_at_utc DATETIME(6) NOT NULL DEFAULT (UTC_TIMESTAMP(6)),
 updated_at_utc DATETIME(6) NOT NULL DEFAULT (UTC_TIMESTAMP(6)),
 UNIQUE KEY uq_ticket_refund_request (order_id,request_hash),
 UNIQUE KEY uq_ticket_refund_provider (provider_refund_id),
 KEY idx_ticket_refund_buyer (buyer_id,created_at_utc),
 KEY idx_ticket_refund_event (event_id,status),
 CONSTRAINT fk_ticket_refund_order FOREIGN KEY (order_id) REFERENCES ticket_orders(id) ON DELETE RESTRICT,
 CONSTRAINT fk_ticket_refund_buyer FOREIGN KEY (buyer_id) REFERENCES ticket_buyers(id) ON DELETE RESTRICT,
 CONSTRAINT fk_ticket_refund_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS ticket_refund_entries (
 refund_id BIGINT UNSIGNED NOT NULL, entry_id BIGINT UNSIGNED NOT NULL,
 amount_cop BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY (refund_id,entry_id), UNIQUE KEY uq_ticket_refund_claim (entry_id),
 CONSTRAINT fk_ticket_refund_line FOREIGN KEY (refund_id) REFERENCES ticket_refunds(id) ON DELETE RESTRICT,
 CONSTRAINT fk_ticket_refund_entry FOREIGN KEY (entry_id) REFERENCES ticket_entries(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS ticket_refund_adjustments (
 refund_id BIGINT UNSIGNED PRIMARY KEY, order_id BIGINT UNSIGNED NOT NULL,
 event_id INT UNSIGNED NOT NULL, amount_cop BIGINT UNSIGNED NOT NULL,
 recorded_at_utc DATETIME(6) NOT NULL DEFAULT (UTC_TIMESTAMP(6)),
 CONSTRAINT fk_ticket_refund_adjustment FOREIGN KEY (refund_id) REFERENCES ticket_refunds(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS ticket_refund_audit (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, refund_id BIGINT UNSIGNED NOT NULL,
 actor_kind ENUM('buyer','staff','provider') NOT NULL, actor_id BIGINT UNSIGNED NULL,
 prior_status VARCHAR(24) NULL, next_status VARCHAR(24) NOT NULL,
 created_at_utc DATETIME(6) NOT NULL DEFAULT (UTC_TIMESTAMP(6)),
 KEY idx_refund_audit_receipt (refund_id,id),
 CONSTRAINT fk_ticket_refund_audit FOREIGN KEY (refund_id) REFERENCES ticket_refunds(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
