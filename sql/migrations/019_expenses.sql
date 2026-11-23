-- Migration 019: expenses (money out) for the Treasurer page (UF2-165).
-- One row per payment FISH makes. The Treasurer page reads it next to `donations`; the add-expense form
-- (UF2-168) writes it. The fund an expense is charged to comes from its campaign's "Fund / designation"
-- (dbevents.fund, migration 009); an expense with no campaign is "General Fund".
-- MariaDB 10.4+ (CREATE TABLE IF NOT EXISTS, CHECK). Additive and safe to re-run.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS expenses (
  id            INT NOT NULL AUTO_INCREMENT,
  expense_date  DATE NOT NULL,
  payee         VARCHAR(255) NOT NULL,                  -- who was paid
  category      VARCHAR(64) NOT NULL DEFAULT 'other',   -- free text until UF2-168 fixes the list
  campaign_id   INT NULL,                               -- dbevents.id, optional; its fund is the expense's fund
  amount        DECIMAL(12,2) NOT NULL,
  notes         TEXT NULL,
  recorded_by   VARCHAR(256) NULL,                      -- dbpersons.id
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_expenses_date        (expense_date),
  KEY idx_expenses_campaign    (campaign_id),
  KEY idx_expenses_recorded_by (recorded_by),
  CONSTRAINT chk_expenses_amount_positive CHECK (amount > 0),
  CONSTRAINT fk_expenses_campaign    FOREIGN KEY (campaign_id) REFERENCES dbevents (id)  ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_expenses_recorded_by FOREIGN KEY (recorded_by) REFERENCES dbpersons (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
