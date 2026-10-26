-- Migration 009: campaigns.
-- dbevents doubles as the campaigns table. This adds the campaign columns, a campaign_summary view
-- (totals per campaign, like donor_summary does for donors). Demo campaigns live in sql/seed/demo_fish.sql.
-- MariaDB 10.4+ (ADD COLUMN IF NOT EXISTS, CREATE OR REPLACE VIEW). Safe to re-run.
-- Rename the file if 009 is already taken in sql/migrations.
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------------------------
-- 1. New columns on dbevents. Nothing is renamed or dropped (the old single-day `date` column stays):
--    goalAmount = cash goal, location = drop-off location, startDate/endDate = ISO 'YYYY-MM-DD' text,
--    completed = '1' once someone marks the campaign completed.
-- ---------------------------------------------------------------------------------------------
ALTER TABLE dbevents
  ADD COLUMN IF NOT EXISTS campaign_type     ENUM('cash','items','both','volunteers') NOT NULL DEFAULT 'cash',
  ADD COLUMN IF NOT EXISTS owner_id          VARCHAR(256) NULL,
  ADD COLUMN IF NOT EXISTS item_goal         DECIMAL(12,2) NULL,
  ADD COLUMN IF NOT EXISTS item_unit         VARCHAR(30) NOT NULL DEFAULT 'items',
  ADD COLUMN IF NOT EXISTS volunteer_goal    INT NULL,
  ADD COLUMN IF NOT EXISTS fund              VARCHAR(100) NULL,
  ADD COLUMN IF NOT EXISTS show_on_dashboard TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS email_past_donors TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS ask_volunteers    TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ADD COLUMN IF NOT EXISTS updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  ADD COLUMN IF NOT EXISTS updated_by        VARCHAR(256) NULL;

-- The old events code stored one event day in `date`; campaigns use startDate/endDate instead. `date` is kept
-- (nullable, and campaign inserts name their columns) because dbEvents.php and the legacy event pages that
-- read it (eventSearch, setTimes, eventApproved, volunteerReport) still use it. Drop it in a later migration,
-- copying any value into startDate/endDate first, once that code is removed.

ALTER TABLE dbevents
  ADD INDEX IF NOT EXISTS idx_dbevents_owner (owner_id);

-- Foreign keys to dbpersons, added only if they are not there yet (this keeps the file re-runnable).
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'dbevents'
                  AND CONSTRAINT_NAME = 'fk_dbevents_owner') = 0,
  'ALTER TABLE dbevents ADD CONSTRAINT fk_dbevents_owner FOREIGN KEY (owner_id) REFERENCES dbpersons (id) ON DELETE SET NULL ON UPDATE CASCADE',
  'SELECT 1');
PREPARE fk_stmt FROM @sql;
EXECUTE fk_stmt;
DEALLOCATE PREPARE fk_stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'dbevents'
                  AND CONSTRAINT_NAME = 'fk_dbevents_updated_by') = 0,
  'ALTER TABLE dbevents ADD CONSTRAINT fk_dbevents_updated_by FOREIGN KEY (updated_by) REFERENCES dbpersons (id) ON DELETE SET NULL ON UPDATE CASCADE',
  'SELECT 1');
PREPARE fk_stmt FROM @sql;
EXECUTE fk_stmt;
DEALLOCATE PREPARE fk_stmt;

-- ---------------------------------------------------------------------------------------------
-- 2. campaign_summary: one row per campaign with its totals and its status.
--    status: completed when marked completed or past its end date, upcoming before its start date,
--    otherwise active (a campaign with no dates counts as active).
--    cash_raised = sum of linked cash gift amounts (before fees); items_collected = sum of linked
--    non-cash quantities (all units added together); shifts = linked volunteer_hours rows.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW campaign_summary AS
SELECT
  e.id,
  e.name,
  e.campaign_type,
  e.description,
  e.location,
  e.fund,
  e.owner_id,
  NULLIF(TRIM(CONCAT_WS(' ', p.first_name, p.last_name)), '') AS owner_name,
  e.goalAmount AS cash_goal,
  e.item_goal,
  e.item_unit,
  e.volunteer_goal,
  e.show_on_dashboard,
  e.email_past_donors,
  e.ask_volunteers,
  (e.completed = '1') AS is_completed,
  STR_TO_DATE(e.startDate, '%Y-%m-%d') AS start_date,
  STR_TO_DATE(e.endDate, '%Y-%m-%d') AS end_date,
  COALESCE((SELECT SUM(d.amount) FROM donations d WHERE d.eventID = e.id), 0) AS cash_raised,
  (SELECT COUNT(*) FROM donations d WHERE d.eventID = e.id) AS cash_gift_count,
  COALESCE((SELECT SUM(n.quantity) FROM non_cash_donations n WHERE n.campaign_id = e.id), 0) AS items_collected,
  (SELECT COUNT(*) FROM non_cash_donations n WHERE n.campaign_id = e.id) AS item_gift_count,
  (SELECT COUNT(*) FROM volunteer_hours v WHERE v.campaign_id = e.id) AS shifts,
  COALESCE((SELECT SUM(v.hours) FROM volunteer_hours v WHERE v.campaign_id = e.id), 0) AS volunteer_hours,
  CASE
    WHEN e.completed = '1' THEN 'completed'
    WHEN STR_TO_DATE(e.endDate, '%Y-%m-%d') < CURDATE() THEN 'completed'
    WHEN STR_TO_DATE(e.startDate, '%Y-%m-%d') > CURDATE() THEN 'upcoming'
    ELSE 'active'
  END AS status
FROM dbevents e
LEFT JOIN dbpersons p ON p.id = e.owner_id;
