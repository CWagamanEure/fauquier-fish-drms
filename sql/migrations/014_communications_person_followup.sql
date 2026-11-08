-- UF2-147 + the one-on-one form's follow-up fields (addCommunication.php).
-- person_contacted_id links a communication to the FISH NET account (dbpersons) that was contacted;
-- person_contacted (text, migration 006) stays and holds the display name, or a typed name for someone
-- without an account, so existing rows and readers are unchanged. follow_up_on is the "Remind me to follow
-- up" date; 'follow_up' is appended to the status enum (after 'failed', so stored values keep their
-- ordinals). donor_summary reads only communications.donor_id and occurred_at, so it is not recreated.
-- MariaDB 10.4+ (ADD COLUMN / ADD INDEX IF NOT EXISTS). Safe to re-run (CI replays every migration).
SET NAMES utf8mb4;

ALTER TABLE communications
  ADD COLUMN IF NOT EXISTS person_contacted_id VARCHAR(256) NULL AFTER person_contacted,
  ADD COLUMN IF NOT EXISTS follow_up_on        DATE         NULL AFTER status,
  MODIFY COLUMN status ENUM('logged','sent','delivered','opened','replied','bounced','failed','follow_up')
                       NOT NULL DEFAULT 'logged',
  ADD INDEX IF NOT EXISTS idx_comm_person_contacted (person_contacted_id);

-- Foreign key to dbpersons, added only if it is not there yet (keeps the file re-runnable; same pattern as 009).
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'communications'
                  AND CONSTRAINT_NAME = 'fk_comm_person_contacted') = 0,
  'ALTER TABLE communications ADD CONSTRAINT fk_comm_person_contacted FOREIGN KEY (person_contacted_id) REFERENCES dbpersons (id) ON DELETE SET NULL ON UPDATE CASCADE',
  'SELECT 1');
PREPARE fk_stmt FROM @sql;
EXECUTE fk_stmt;
DEALLOCATE PREPARE fk_stmt;
