-- UF2-41 / UF2-48 / UF2-26: cash donation details needed by the FISH donation pages.
-- Adds a typed donation_date alongside the legacy char(10) MM/DD/YYYY `date`; two triggers
-- keep them in sync no matter which column a writer uses, so old and new code coexist.
-- MariaDB 10.11 only. Idempotent.
SET NAMES utf8mb4;

ALTER TABLE donations
  ADD COLUMN IF NOT EXISTS donation_date   DATE NULL,
  ADD COLUMN IF NOT EXISTS payment_method  ENUM('cash','check','card','online','digital_token','stock','other') NULL,
  ADD COLUMN IF NOT EXISTS check_number    VARCHAR(32) NULL,
  ADD COLUMN IF NOT EXISTS is_signed       TINYINT(1) NULL,
  ADD COLUMN IF NOT EXISTS deposit_date    DATE NULL,
  ADD COLUMN IF NOT EXISTS platform        ENUM('in_person','mighty_cause','benevity','other') NULL,
  ADD COLUMN IF NOT EXISTS in_honor_of     TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS honoree_name    VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS send_receipt    TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS receipt_sent_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS recorded_by     VARCHAR(256) NULL,
  ADD COLUMN IF NOT EXISTS created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ADD COLUMN IF NOT EXISTS updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  ADD COLUMN IF NOT EXISTS net_amount      DECIMAL(12,2) AS (amount - COALESCE(fee, 0)) VIRTUAL;

ALTER TABLE donations
  ADD INDEX IF NOT EXISTS idx_donations_donation_date (donation_date),
  ADD INDEX IF NOT EXISTS idx_donations_donor_date    (donorID, donation_date),
  ADD INDEX IF NOT EXISTS idx_donations_recorded_by   (recorded_by);

-- Guarded foreign key: ALTER TABLE ... ADD CONSTRAINT has no reliable IF NOT EXISTS.
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                   WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'donations'
                     AND CONSTRAINT_TYPE = 'FOREIGN KEY' AND CONSTRAINT_NAME = 'fk_donations_recorded_by');
SET @sql := IF(@fk_exists = 0,
  'ALTER TABLE donations ADD CONSTRAINT fk_donations_recorded_by FOREIGN KEY (recorded_by) REFERENCES dbpersons (id) ON DELETE SET NULL ON UPDATE CASCADE',
  'SELECT ''fk_donations_recorded_by already present'' AS note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Backfill from M/D/YYYY (padded or not) and ISO YYYY-MM-DD text; anything else stays NULL.
UPDATE donations
SET donation_date = STR_TO_DATE(`date`, '%c/%e/%Y')
WHERE donation_date IS NULL
  AND `date` REGEXP '^[0-9]{1,2}/[0-9]{1,2}/[0-9]{4}$'
  AND STR_TO_DATE(`date`, '%c/%e/%Y') BETWEEN '1900-01-01' AND '2100-12-31';
UPDATE donations
SET donation_date = STR_TO_DATE(`date`, '%Y-%m-%d'),
    `date` = DATE_FORMAT(STR_TO_DATE(`date`, '%Y-%m-%d'), '%m/%d/%Y')
WHERE donation_date IS NULL
  AND `date` REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'
  AND STR_TO_DATE(`date`, '%Y-%m-%d') BETWEEN '1900-01-01' AND '2100-12-31';

DELIMITER $$
CREATE OR REPLACE TRIGGER trg_donations_date_sync_bi
BEFORE INSERT ON donations FOR EACH ROW
BEGIN
  IF NEW.donation_date IS NULL AND NEW.`date` REGEXP '^[0-9]{1,2}/[0-9]{1,2}/[0-9]{4}$' THEN
    SET NEW.donation_date = STR_TO_DATE(NEW.`date`, '%c/%e/%Y');
  ELSEIF NEW.donation_date IS NULL AND NEW.`date` REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}$' THEN
    SET NEW.donation_date = STR_TO_DATE(NEW.`date`, '%Y-%m-%d');
  END IF;
  IF NEW.donation_date IS NOT NULL THEN
    -- legacy readers parse `date` as %m/%d/%Y, so always store that representation
    SET NEW.`date` = DATE_FORMAT(NEW.donation_date, '%m/%d/%Y');
  END IF;
END$$
CREATE OR REPLACE TRIGGER trg_donations_date_sync_bu
BEFORE UPDATE ON donations FOR EACH ROW
BEGIN
  IF NOT (NEW.`date` <=> OLD.`date`) THEN
    -- legacy editor changed the text column
    SET NEW.donation_date = CASE
      WHEN NEW.`date` REGEXP '^[0-9]{1,2}/[0-9]{1,2}/[0-9]{4}$' THEN STR_TO_DATE(NEW.`date`, '%c/%e/%Y')
      WHEN NEW.`date` REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}$' THEN STR_TO_DATE(NEW.`date`, '%Y-%m-%d')
      ELSE NULL END;
    IF NEW.donation_date IS NOT NULL THEN
      SET NEW.`date` = DATE_FORMAT(NEW.donation_date, '%m/%d/%Y');
    END IF;
  ELSEIF NOT (NEW.donation_date <=> OLD.donation_date) THEN
    -- new pages changed the DATE column
    SET NEW.`date` = IF(NEW.donation_date IS NULL, NULL, DATE_FORMAT(NEW.donation_date, '%m/%d/%Y'));
  END IF;
END$$
DELIMITER ;
