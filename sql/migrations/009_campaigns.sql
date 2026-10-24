-- Migration 009: campaigns.
-- dbevents doubles as the campaigns table. This adds the campaign columns, a campaign_summary view
-- (totals per campaign, like donor_summary does for donors), and a DEMO DATA section at the end.
-- MariaDB 10.4+ (ADD COLUMN IF NOT EXISTS, CREATE OR REPLACE VIEW). Safe to re-run.
-- Rename the file if 009 is already taken in sql/migrations.
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------------------------
-- 1. New columns on dbevents, and the old single-day `date` column is dropped. Nothing else is renamed or dropped:
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

-- The old events code stored one event day in `date`. Campaigns use startDate/endDate instead, so it goes.
-- Only the column is dropped: dbevents itself is the campaigns table and stays. This does break the legacy
-- dbEvents.php, which orders and looks events up by `date` (that file is being discarded). Re-runnable.
ALTER TABLE dbevents DROP COLUMN IF EXISTS `date`;

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

-- =============================================================================================
-- 3. DEMO DATA (everything below is for demos only; each row is marked 'demo-seed' or is a named
--    demo campaign). Delete this whole section before a real deployment, and run the "remove demo
--    data" statements at the very bottom if it was already loaded. Dates are relative to the day
--    this runs, so the statuses (active / upcoming / completed) make sense on demo day.
--    Owners are looked up by username; if that account does not exist the owner is simply blank.
-- =============================================================================================

-- 3a. Demo donors, volunteers and organizations (skipped when the email already exists).
INSERT INTO donors (donor_type, `first`, `last`, org_name, email, phone, preferred_contact, street, city, state, zip,
                    is_volunteer, email_updates, donor_since, notes)
SELECT s.donor_type, s.f, s.l, s.org, s.email, s.phone, s.pref, s.street, s.city, 'VA', s.zip, s.vol, 1, s.since, 'demo-seed'
FROM (
            SELECT 'individual' AS donor_type, 'John' AS f, 'Miller' AS l, NULL AS org, 'demo.jmiller@example.org' AS email, '(540) 555-0101' AS phone, 'email' AS pref, '12 Oak Ct' AS street, 'Warrenton' AS city, '20186' AS zip, 0 AS vol, '2021-02-10' AS since
  UNION ALL SELECT 'individual', 'Ravi',  'Patel',  NULL, 'demo.rpatel@example.org',   '(540) 555-0102', 'email', '8 Orchard Ln',   'Warrenton', '20186', 1, '2024-02-10'
  UNION ALL SELECT 'individual', 'Lin',   'Chen',   NULL, 'demo.lchen@example.org',    '(540) 555-0103', 'email', '27 Fairfax St',  'Remington',  '22734', 1, DATE_FORMAT(CURDATE() - INTERVAL 20 DAY, '%Y-%m-%d')
  UNION ALL SELECT 'individual', 'Maria', 'Alvarez', NULL, 'demo.malvarez@example.org', '(540) 555-0104', 'phone', '91 Elm St',      'Catlett',    '20119', 0, '2022-05-20'
  UNION ALL SELECT 'individual', 'Ana',   'Ruiz',   NULL, 'demo.aruiz@example.org',    '(540) 555-0105', 'email', '4 Maple Ave',    'Bealeton',   '22712', 1, '2023-01-15'
  UNION ALL SELECT 'individual', 'Tom',   'Barker', NULL, 'demo.tbarker@example.org',  '(540) 555-0106', 'email', '60 Ridge Rd',    'Midland',    '22728', 1, '2023-03-02'
  UNION ALL SELECT 'individual', 'Maria', 'Lopez',  NULL, 'demo.mlopez@example.org',   '(540) 555-0107', 'phone', '73 River Rd',    'The Plains', '20198', 1, '2024-06-18'
  UNION ALL SELECT 'organization', NULL, NULL, 'Main St. Church',        'demo.mainst@example.org',   '(540) 555-0100', 'email', '100 Main St',   'Warrenton', '20186', 0, '2019-03-14'
  UNION ALL SELECT 'organization', NULL, NULL, 'Oak Ridge Elementary',   'demo.oakridge@example.org', '(540) 555-0120', 'email', '45 School Rd',  'Bealeton',  '22712', 0, '2022-09-12'
  UNION ALL SELECT 'organization', NULL, NULL, 'Warrenton Rotary',       'demo.rotary@example.org',   '(540) 555-0110', 'phone', '300 Lee Hwy',   'Warrenton', '20186', 0, '2017-06-01'
  UNION ALL SELECT 'organization', NULL, NULL, 'Fauquier Thrift',        'demo.thrift@example.org',   '(540) 555-0164', 'phone', '12 Commerce Dr', 'Warrenton', '20186', 0, '2023-11-05'
  UNION ALL SELECT 'organization', NULL, NULL, 'Grace Community Church', 'demo.grace@example.org',    '(540) 555-0133', 'email', '8 Church St',   'Remington', '22734', 0, '2020-04-19'
) s
WHERE NOT EXISTS (SELECT 1 FROM donors x WHERE x.email = s.email);

-- 3b. Demo campaigns (skipped when a campaign with the same name exists).
SET @gt_start := DATEDIFF('2025-12-02', CURDATE());

INSERT INTO dbevents (name, campaign_type, description, goalAmount, item_goal, item_unit, volunteer_goal,
                      startDate, endDate, fund, location, owner_id,
                      show_on_dashboard, email_past_donors, ask_volunteers, completed)
SELECT c.c_name, c.c_type, c.descr, c.cash_goal, c.item_goal, c.unit, c.vol_goal,
       DATE_FORMAT(CURDATE() + INTERVAL c.start_off DAY, '%Y-%m-%d'),
       DATE_FORMAT(CURDATE() + INTERVAL c.end_off DAY,   '%Y-%m-%d'),
       c.fund, c.loc, (SELECT p.id FROM dbpersons p WHERE p.id = c.own_user), 1, 0, 0, c.done
FROM (
            SELECT 'Thanksgiving Food Drive' AS c_name, 'both' AS c_type, 'Holiday meals for about 300 local families.' AS descr, 12000.00 AS cash_goal, 2000 AS item_goal, 'cans' AS unit, 40 AS vol_goal, -25 AS start_off, 10 AS end_off, 'Food Pantry' AS fund, 'FISH pantry' AS loc, 'ccoleman' AS own_user, '0' AS done
  UNION ALL SELECT 'Winter Coat Collection', 'items',      'Warm coats for kids and adults before the cold weather.', NULL, 500, 'coats', NULL, -5, 56, 'General Fund', 'FISH pantry', 'jandrews', '0'
  UNION ALL SELECT 'Fall Volunteer Push',    'volunteers', 'Recruit and schedule new volunteers for the fall pantry days.', NULL, NULL, 'items', 40, -19, 11, 'General Fund', '', 'jandrews', '0'
  UNION ALL SELECT 'Year-End Giving',        'cash',       'Our biggest appeal of the year.', 30000.00, NULL, 'items', NULL, 41, 71, 'General Fund', '', 'dprice', '0'
  UNION ALL SELECT 'Spring Pantry Restock',  'both',       'Restock the shelves after winter.', 5000.00, 800, 'items', 20, 131, 161, 'Food Pantry', 'FISH pantry', 'ccoleman', '0'
  UNION ALL SELECT 'Back to School Kits',    'items',      'School supply kits for local students.', NULL, 500, 'kits', NULL, -97, -62, 'General Fund', 'FISH pantry', 'ccoleman', '1'
  UNION ALL SELECT 'Giving Tuesday 2025',    'cash',       'Online giving day.', 10000.00, NULL, 'items', NULL, @gt_start, @gt_start, 'General Fund', '', 'dprice', '1'
) c
WHERE NOT EXISTS (SELECT 1 FROM dbevents x WHERE x.name = c.c_name);

-- 3c. Cash gifts linked to the campaigns (matched by donor email and campaign name).
SET @gt_days := DATEDIFF(CURDATE(), '2025-12-02');

INSERT INTO donations (amount, reason, fee, thanked, eventID, donorID, donation_date, payment_method, check_number,
                       is_signed, platform, send_receipt, receipt_sent_at)
SELECT g.amt, 'demo-seed', 0, IF(g.days_ago > 5, 1, 0), c.id, d.id,
       CURDATE() - INTERVAL g.days_ago DAY, g.method, g.chk,
       IF(g.method = 'check', 1, NULL),
       CASE g.method WHEN 'online' THEN 'mighty_cause' ELSE 'in_person' END,
       1, IF(g.days_ago > 5, NOW(), NULL)
FROM (
            SELECT 'demo.oakridge@example.org' AS donor_email, 'Thanksgiving Food Drive' AS campaign, 500.00 AS amt, 24 AS days_ago, 'check' AS method, '3302' AS chk
  UNION ALL SELECT 'demo.grace@example.org',    'Thanksgiving Food Drive', 1200.00, 22, 'check',  '0455'
  UNION ALL SELECT 'demo.mainst@example.org',   'Thanksgiving Food Drive', 2000.00, 17, 'check',  '2210'
  UNION ALL SELECT 'demo.rotary@example.org',   'Thanksgiving Food Drive', 1500.00, 15, 'check',  '2201'
  UNION ALL SELECT 'demo.thrift@example.org',   'Thanksgiving Food Drive',  800.00, 12, 'online', NULL
  UNION ALL SELECT 'demo.rpatel@example.org',   'Thanksgiving Food Drive',  100.00,  9, 'online', NULL
  UNION ALL SELECT 'demo.malvarez@example.org', 'Thanksgiving Food Drive',   40.00,  8, 'card',   NULL
  UNION ALL SELECT 'demo.jmiller@example.org',  'Thanksgiving Food Drive',  250.00,  7, 'check',  '1187'
  UNION ALL SELECT 'demo.lchen@example.org',    'Thanksgiving Food Drive',   75.00,  6, 'cash',   NULL
  UNION ALL SELECT 'demo.jmiller@example.org',  'Thanksgiving Food Drive',  250.00,  3, 'check',  '1203'
  UNION ALL SELECT 'demo.thrift@example.org',   'Thanksgiving Food Drive',  200.00,  1, 'online', NULL
  UNION ALL SELECT 'demo.mainst@example.org',   'Giving Tuesday 2025', 4000.00, @gt_days, 'check',  '2105'
  UNION ALL SELECT 'demo.grace@example.org',    'Giving Tuesday 2025', 1500.00, @gt_days, 'check',  '0390'
  UNION ALL SELECT 'demo.rotary@example.org',   'Giving Tuesday 2025', 3000.00, @gt_days, 'check',  '2150'
  UNION ALL SELECT 'demo.jmiller@example.org',  'Giving Tuesday 2025',  250.00, @gt_days, 'online', NULL
  UNION ALL SELECT 'demo.rpatel@example.org',   'Giving Tuesday 2025',  120.00, @gt_days, 'online', NULL
  UNION ALL SELECT 'demo.lchen@example.org',    'Giving Tuesday 2025',   50.00, @gt_days, 'online', NULL
  UNION ALL SELECT 'demo.malvarez@example.org', 'Giving Tuesday 2025',   40.00, @gt_days, 'card',   NULL
) g
JOIN donors  d ON d.email = g.donor_email
JOIN dbevents c ON c.name = g.campaign
WHERE NOT EXISTS (SELECT 1 FROM donations x WHERE x.reason = 'demo-seed');

-- 3d. Non-cash gifts.
INSERT INTO non_cash_donations (donor_id, campaign_id, date_received, item_description, category, quantity, unit,
                                estimated_value, item_condition, intended_use, notes, send_receipt, receipt_sent_at)
SELECT d.id, c.id, CURDATE() - INTERVAL g.days_ago DAY, g.item, g.category, g.qty, g.unit,
       g.val, g.item_cond, c.name, 'demo-seed', 1, IF(g.days_ago > 5, NOW(), NULL)
FROM (
            SELECT 'demo.grace@example.org' AS donor_email, 'Thanksgiving Food Drive' AS campaign, 'Soup' AS item, 'canned_food' AS category, 240 AS qty, 'cans' AS unit, 360.00 AS val, 'new' AS item_cond, 14 AS days_ago
  UNION ALL SELECT 'demo.mainst@example.org',    'Thanksgiving Food Drive', 'Canned vegetables', 'canned_food', 320, 'cans',  480.00, 'new', 10
  UNION ALL SELECT 'demo.oakridge@example.org',  'Thanksgiving Food Drive', 'Dry goods',         'dry_goods',    58, 'boxes', 290.00, 'new', 13
  UNION ALL SELECT 'demo.rotary@example.org',    'Thanksgiving Food Drive', 'Canned vegetables', 'canned_food', 400, 'cans',  600.00, 'new',  8
  UNION ALL SELECT 'demo.thrift@example.org',    'Thanksgiving Food Drive', 'Canned fruit',      'canned_food', 180, 'cans',  270.00, 'new',  5
  UNION ALL SELECT 'demo.thrift@example.org',    'Winter Coat Collection',  'Winter coats',      'clothing',    120, 'coats', 1800.00, 'gently_used', 3
  UNION ALL SELECT 'demo.grace@example.org',     'Winter Coat Collection',  'Winter coats',      'clothing',     60, 'coats',  900.00, 'gently_used', 2
  UNION ALL SELECT 'demo.malvarez@example.org',  'Winter Coat Collection',  'Kids coats',        'clothing',     12, 'coats',  180.00, 'gently_used', 1
  UNION ALL SELECT 'demo.oakridge@example.org',  'Back to School Kits',     'School supply kits','other',       300, 'kits', 1500.00, 'new', 75
  UNION ALL SELECT 'demo.rotary@example.org',    'Back to School Kits',     'School supply kits','other',       260, 'kits', 1300.00, 'new', 70
) g
JOIN donors  d ON d.email = g.donor_email
JOIN dbevents c ON c.name = g.campaign
WHERE NOT EXISTS (SELECT 1 FROM non_cash_donations x WHERE x.notes = 'demo-seed');

-- 3e. Volunteer shifts.
INSERT INTO volunteer_hours (donor_id, campaign_id, activity_date, hours, job, notes)
SELECT d.id, c.id, CURDATE() - INTERVAL g.days_ago DAY, g.hrs, g.job, 'demo-seed'
FROM (
            SELECT 'demo.aruiz@example.org' AS donor_email, 'Thanksgiving Food Drive' AS campaign, 6 AS hrs, 'Sorting cans' AS job, 10 AS days_ago
  UNION ALL SELECT 'demo.tbarker@example.org', 'Thanksgiving Food Drive', 4, 'Pantry intake',     9
  UNION ALL SELECT 'demo.mlopez@example.org',  'Thanksgiving Food Drive', 3, 'Drop-off table',    8
  UNION ALL SELECT 'demo.rpatel@example.org',  'Thanksgiving Food Drive', 3, 'Drop-off table',    6
  UNION ALL SELECT 'demo.aruiz@example.org',   'Thanksgiving Food Drive', 5, 'Sorting cans',      5
  UNION ALL SELECT 'demo.tbarker@example.org', 'Thanksgiving Food Drive', 4, 'Pantry intake',     4
  UNION ALL SELECT 'demo.lchen@example.org',   'Thanksgiving Food Drive', 4, 'Packing boxes',     2
  UNION ALL SELECT 'demo.aruiz@example.org',   'Fall Volunteer Push',     4, 'Food distribution', 15
  UNION ALL SELECT 'demo.tbarker@example.org', 'Fall Volunteer Push',     3, 'Food distribution', 14
  UNION ALL SELECT 'demo.mlopez@example.org',  'Fall Volunteer Push',     5, 'Pantry intake',     12
  UNION ALL SELECT 'demo.rpatel@example.org',  'Fall Volunteer Push',     3, 'Food distribution', 11
  UNION ALL SELECT 'demo.lchen@example.org',   'Fall Volunteer Push',     4, 'Pantry intake',      9
  UNION ALL SELECT 'demo.jmiller@example.org', 'Fall Volunteer Push',     3, 'Drop-off table',     7
  UNION ALL SELECT 'demo.aruiz@example.org',   'Fall Volunteer Push',     4, 'Food distribution',  4
  UNION ALL SELECT 'demo.tbarker@example.org', 'Fall Volunteer Push',     3, 'Pantry intake',      3
  UNION ALL SELECT 'demo.mlopez@example.org',  'Winter Coat Collection',  3, 'Coat sorting',       2
  UNION ALL SELECT 'demo.aruiz@example.org',   'Winter Coat Collection',  2, 'Coat sorting',       1
) g
JOIN donors  d ON d.email = g.donor_email
JOIN dbevents c ON c.name = g.campaign
WHERE NOT EXISTS (SELECT 1 FROM volunteer_hours x WHERE x.notes = 'demo-seed');

-- 3f. Communications tied to campaigns.
INSERT INTO communications (donor_id, campaign_id, occurred_at, comm_type, direction, subject, summary, status)
SELECT d.id, c.id, NOW() - INTERVAL g.days_ago DAY, g.ctype, 'outbound', g.subject, 'demo-seed', g.cstatus
FROM (
            SELECT 'demo.mainst@example.org' AS donor_email, 'Thanksgiving Food Drive' AS campaign, 'email' AS ctype, 'Help us feed 300 families' AS subject, 'sent' AS cstatus, 10 AS days_ago
  UNION ALL SELECT 'demo.rotary@example.org',   'Thanksgiving Food Drive', 'phone', 'Drop-off times',                    'logged', 12
  UNION ALL SELECT 'demo.oakridge@example.org', 'Winter Coat Collection',  'email', 'Coat collection starts this week',  'sent',    4
  UNION ALL SELECT 'demo.rpatel@example.org',   'Fall Volunteer Push',     'email', 'Sign up for a volunteer shift',     'sent',   16
) g
JOIN donors  d ON d.email = g.donor_email
JOIN dbevents c ON c.name = g.campaign
WHERE NOT EXISTS (SELECT 1 FROM communications x WHERE x.summary = 'demo-seed');

-- ---------------------------------------------------------------------------------------------
-- REMOVE DEMO DATA (leave commented; run these statements, in this order, when you want it gone):
--   DELETE FROM communications     WHERE summary = 'demo-seed';
--   DELETE FROM volunteer_hours    WHERE notes   = 'demo-seed';
--   DELETE FROM non_cash_donations WHERE notes   = 'demo-seed';
--   DELETE FROM donations          WHERE reason  = 'demo-seed';
--   DELETE FROM dbevents WHERE name IN ('Thanksgiving Food Drive','Winter Coat Collection','Fall Volunteer Push',
--     'Year-End Giving','Spring Pantry Restock','Back to School Kits','Giving Tuesday 2025');
--   DELETE FROM donors             WHERE notes   = 'demo-seed';
-- ---------------------------------------------------------------------------------------------