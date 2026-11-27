-- #2190: append-only revisions; no financial record is replaced or deleted.
CREATE TABLE IF NOT EXISTS event_expenses (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id INT UNSIGNED NOT NULL,
  category ENUM('artistas','lugar','produccion','publicidad','logistica','otros') NOT NULL,
  concept VARCHAR(180) NOT NULL,
  amount_cop BIGINT NOT NULL,
  entry_date DATE NOT NULL,
  status ENUM('committed','paid','void') NOT NULL,
  supersedes_id BIGINT UNSIGNED NULL,
  created_at_utc DATETIME(6) NOT NULL,
  UNIQUE KEY uq_expense_supersedes (supersedes_id),
  KEY idx_expense_event_category (event_id, category, id),
  CONSTRAINT ck_expense_amount CHECK (amount_cop > 0),
  CONSTRAINT fk_expense_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE RESTRICT,
  CONSTRAINT fk_expense_previous FOREIGN KEY (supersedes_id) REFERENCES event_expenses(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS event_budgets (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id INT UNSIGNED NOT NULL,
  category ENUM('artistas','lugar','produccion','publicidad','logistica','otros') NOT NULL,
  amount_cop BIGINT NOT NULL,
  entry_date DATE NOT NULL,
  status ENUM('active','void') NOT NULL,
  supersedes_id BIGINT UNSIGNED NULL,
  created_at_utc DATETIME(6) NOT NULL,
  UNIQUE KEY uq_budget_supersedes (supersedes_id),
  KEY idx_budget_event_category (event_id, category, id),
  CONSTRAINT ck_budget_amount CHECK (amount_cop >= 0),
  CONSTRAINT fk_budget_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE RESTRICT,
  CONSTRAINT fk_budget_previous FOREIGN KEY (supersedes_id) REFERENCES event_budgets(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
