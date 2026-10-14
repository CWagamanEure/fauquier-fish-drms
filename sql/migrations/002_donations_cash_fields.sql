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

-- Parse 'M/D/YYYY' (padded or not) or 'YYYY-MM-DD' text into a DATE without ever building an
-- invalid intermediate value: STR_TO_DATE('02/30/2026') yields a date strict mode rejects, and
-- inside a trigger that warning becomes an error. Returns NULL for anything else.
DELIMITER $$
CREATE OR REPLACE FUNCTION fish_parse_date(txt VARCHAR(32)) RETURNS DATE DETERMINISTIC
BEGIN
  DECLARE y INT; DECLARE m INT; DECLARE d INT;
  IF txt IS NULL THEN RETURN NULL; END IF;
  SET txt = TRIM(txt);
  IF txt REGEXP '^[0-9]{1,2}/[0-9]{1,2}/[0-9]{4}$' THEN
    SET m = CAST(SUBSTRING_INDEX(txt, '/', 1) AS UNSIGNED);
    SET d = CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(txt, '/', 2), '/', -1) AS UNSIGNED);
    SET y = CAST(SUBSTRING_INDEX(txt, '/', -1) AS UNSIGNED);
  ELSEIF txt REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}$' THEN
    SET y = CAST(SUBSTRING_INDEX(txt, '-', 1) AS UNSIGNED);
    SET m = CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(txt, '-', 2), '-', -1) AS UNSIGNED);
    SET d = CAST(SUBSTRING_INDEX(txt, '-', -1) AS UNSIGNED);
  ELSE
    RETURN NULL;
  END IF;
  IF y < 1900 OR y > 2100 OR m < 1 OR m > 12 OR d < 1 THEN RETURN NULL; END IF;
  IF d > DAY(LAST_DAY(MAKEDATE(y, 1) + INTERVAL (m - 1) MONTH)) THEN RETURN NULL; END IF;
  RETURN MAKEDATE(y, 1) + INTERVAL (m - 1) MONTH + INTERVAL (d - 1) DAY;
END$$
DELIMITER ;

-- Backfill from M/D/YYYY (padded or not) and ISO YYYY-MM-DD text; anything else stays NULL.
UPDATE donations
SET donation_date = fish_parse_date(`date`)
WHERE donation_date IS NULL AND fish_parse_date(`date`) IS NOT NULL;
UPDATE donations
SET `date` = DATE_FORMAT(donation_date, '%m/%d/%Y')
WHERE donation_date IS NOT NULL AND NOT (`date` <=> DATE_FORMAT(donation_date, '%m/%d/%Y'));

DELIMITER $$
CREATE OR REPLACE TRIGGER trg_donations_date_sync_bi
BEFORE INSERT ON donations FOR EACH ROW
BEGIN
  IF NEW.donation_date IS NULL THEN
    SET NEW.donation_date = fish_parse_date(NEW.`date`);
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
    SET NEW.donation_date = fish_parse_date(NEW.`date`);
    IF NEW.donation_date IS NOT NULL THEN
      SET NEW.`date` = DATE_FORMAT(NEW.donation_date, '%m/%d/%Y');
    END IF;
  ELSEIF NOT (NEW.donation_date <=> OLD.donation_date) THEN
    -- new pages changed the DATE column
    SET NEW.`date` = IF(NEW.donation_date IS NULL, NULL, DATE_FORMAT(NEW.donation_date, '%m/%d/%Y'));
  END IF;
END$$
DELIMITER ;
