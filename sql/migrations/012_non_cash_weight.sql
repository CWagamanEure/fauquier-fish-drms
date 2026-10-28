-- UF2-124: every non-cash gift records an item count (quantity) and a weight in pounds (weight_lbs); the
-- free-text `unit` column goes. weight_lbs is NULL only on gifts recorded before this change; the form
-- requires it on every save. MariaDB 10.11 only. Safe to re-run (CI replays every migration).
SET NAMES utf8mb4;

ALTER TABLE non_cash_donations
  ADD COLUMN IF NOT EXISTS weight_lbs DECIMAL(10,2) NULL CHECK (weight_lbs IS NULL OR weight_lbs > 0) AFTER quantity;

-- Backfill while `unit` still exists. @sql only ever holds one of these fixed statements; row values are read
-- inside the UPDATE, never pasted into the SQL text. The NOT LIKE guard keeps a re-run after a partial failure
-- (before the DROP below) from appending the sentence twice.
SET @has_unit := (SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'non_cash_donations' AND COLUMN_NAME = 'unit');

SET @sql := IF(@has_unit = 1,
  'UPDATE non_cash_donations SET weight_lbs = quantity WHERE unit = ''lbs'' AND weight_lbs IS NULL',
  'SELECT ''non_cash_donations.unit already dropped'' AS note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@has_unit = 1,
  'UPDATE non_cash_donations
      SET notes = CONCAT_WS('' '', notes, CONCAT(''Recorded before UF2-124 as '', quantity, '' '', unit, ''.''))
    WHERE unit <> ''items'' AND (notes IS NULL OR notes NOT LIKE ''%Recorded before UF2-124 as%'')',
  'SELECT ''non_cash_donations.unit already dropped'' AS note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

ALTER TABLE non_cash_donations DROP COLUMN IF EXISTS unit;
