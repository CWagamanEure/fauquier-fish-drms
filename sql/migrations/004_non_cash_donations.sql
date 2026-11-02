-- UF2-16 / UF2-18: non-cash (in-kind) donations and their usage/distribution history.
-- MariaDB 10.11 only. Idempotent (CREATE TABLE IF NOT EXISTS with inline keys).
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS non_cash_donations (
  id               INT NOT NULL AUTO_INCREMENT,
  donor_id         INT NOT NULL,                          -- use donor 999 for anonymous drop-offs
  campaign_id      INT NULL,                              -- dbevents.id, optional
  date_received    DATE NOT NULL,
  item_description VARCHAR(255) NOT NULL,
  category         ENUM('canned_food','dry_goods','fresh_produce','clothing','household_items','hygiene_products','other') NOT NULL DEFAULT 'other',
  quantity         DECIMAL(10,2) NOT NULL DEFAULT 1,
  unit             VARCHAR(30) NOT NULL DEFAULT 'items',  -- items | lbs | bags | boxes
  estimated_value  DECIMAL(12,2) NULL,
  item_condition   ENUM('new','gently_used','used') NULL, -- "condition" is a reserved word
  intended_use     VARCHAR(255) NOT NULL,                 -- required benefiting program (UF2-16)
  notes            TEXT NULL,
  send_receipt     TINYINT(1) NOT NULL DEFAULT 0,
  receipt_sent_at  DATETIME NULL,
  recorded_by      VARCHAR(256) NULL,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ncd_donor_date  (donor_id, date_received),
  KEY idx_ncd_date        (date_received),
  KEY idx_ncd_campaign    (campaign_id),
  KEY idx_ncd_category    (category),
  KEY idx_ncd_recorded_by (recorded_by),
  CONSTRAINT fk_ncd_donor       FOREIGN KEY (donor_id)    REFERENCES donors (id)    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_ncd_campaign    FOREIGN KEY (campaign_id) REFERENCES dbevents (id)  ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_ncd_recorded_by FOREIGN KEY (recorded_by) REFERENCES dbpersons (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT chk_ncd_quantity CHECK (quantity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Where donated items went. Remaining stock = quantity - SUM(distributions.quantity).
CREATE TABLE IF NOT EXISTS non_cash_distributions (
  id                   INT NOT NULL AUTO_INCREMENT,
  non_cash_donation_id INT NOT NULL,
  distributed_on       DATE NOT NULL,
  quantity             DECIMAL(10,2) NOT NULL,
  program              VARCHAR(255) NULL,
  notes                TEXT NULL,
  recorded_by          VARCHAR(256) NULL,
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ncdist_donation    (non_cash_donation_id),
  KEY idx_ncdist_date        (distributed_on),
  KEY idx_ncdist_recorded_by (recorded_by),
  CONSTRAINT fk_ncdist_donation    FOREIGN KEY (non_cash_donation_id) REFERENCES non_cash_donations (id) ON DELETE CASCADE,
  CONSTRAINT fk_ncdist_recorded_by FOREIGN KEY (recorded_by) REFERENCES dbpersons (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT chk_ncdist_quantity CHECK (quantity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
