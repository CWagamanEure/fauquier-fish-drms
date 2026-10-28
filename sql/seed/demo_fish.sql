-- Optional FISH demo data for local development (make seed-demo). Never load this on a real database.
--
-- Idempotent without fixed ids: every run first deletes the previous demo rows, then inserts fresh
-- ones with normal auto-increment ids, so real rows are never overwritten and the id sequence is
-- not pushed into a reserved range. Demo rows are recognisable by their markers:
--   * activity rows (donations, non-cash, hours, communications) are recorded_by a fish.* demo user
--   * demo donors and campaigns have notes / description starting with "[demo]"
--   * the campaign demo in section 9 marks its donors and activity 'demo-seed' (notes, reason or summary)
-- Demo users all share the password "Fish!Demo2026".
SET NAMES utf8mb4;
SET @pw := '$2y$10$yXqRVA1EFSrJmx3kuKDtmOmXm1ReT3EFSgZ076S6k6rfAbgrXRfsC';
SET @demo_users := 'fish.admin,fish.manager,fish.staff,fish.viewer,fish.former';

-- 1. Remove the previous run (children before parents). Activity is removed when it was recorded
--    by a demo user OR belongs to a demo donor, so rows a developer added by hand against a demo
--    donor do not block the donor delete.
CREATE TEMPORARY TABLE demo_donor_ids AS SELECT id FROM donors WHERE notes LIKE '[demo]%' OR notes = 'demo-seed';
DELETE FROM non_cash_distributions WHERE FIND_IN_SET(recorded_by, @demo_users)
   OR non_cash_donation_id IN (SELECT id FROM non_cash_donations WHERE donor_id IN (SELECT id FROM demo_donor_ids));
DELETE FROM communications     WHERE FIND_IN_SET(recorded_by, @demo_users) OR donor_id IN (SELECT id FROM demo_donor_ids);
DELETE FROM volunteer_hours    WHERE FIND_IN_SET(recorded_by, @demo_users) OR donor_id IN (SELECT id FROM demo_donor_ids);
DELETE FROM non_cash_donations WHERE FIND_IN_SET(recorded_by, @demo_users) OR donor_id IN (SELECT id FROM demo_donor_ids);
DELETE FROM donations          WHERE FIND_IN_SET(recorded_by, @demo_users) OR donorID  IN (SELECT id FROM demo_donor_ids);
DELETE FROM donors   WHERE id IN (SELECT id FROM demo_donor_ids);
DROP TEMPORARY TABLE demo_donor_ids;
-- Gifts a developer recorded against a demo campaign keep their row but lose the link
-- (donations.eventID has a RESTRICT foreign key; the other campaign links are ON DELETE SET NULL).
UPDATE donations SET eventID = NULL WHERE eventID IN (SELECT id FROM dbevents WHERE description LIKE '[demo]%');
DELETE FROM associations WHERE eventID IN (SELECT id FROM dbevents WHERE description LIKE '[demo]%');
DELETE FROM dbevents WHERE description LIKE '[demo]%';

-- 2. Demo staff accounts (username is the natural key, so upsert is safe here).
INSERT INTO dbpersons (id, name, password, first_name, last_name, email, role, status) VALUES
 ('fish.admin',   'Dana Admin',   @pw, 'Dana', 'Admin',   'dana.admin@example.org',   'admin',   'active'),
 ('fish.manager', 'Mia Manager',  @pw, 'Mia',  'Manager', 'mia.manager@example.org',  'manager', 'active'),
 ('fish.staff',   'Sam Staff',    @pw, 'Sam',  'Staff',   'sam.staff@example.org',    'staff',   'active'),
 ('fish.viewer',  'Vic Viewer',   @pw, 'Vic',  'Viewer',  'vic.viewer@example.org',   'viewer',  'active'),
 ('fish.former',  'Pat Former',   @pw, 'Pat',  'Former',  'pat.former@example.org',   'staff',   'inactive')
ON DUPLICATE KEY UPDATE name=VALUES(name), password=VALUES(password), first_name=VALUES(first_name),
 last_name=VALUES(last_name), email=VALUES(email), role=VALUES(role), status=VALUES(status);

-- Reset the demo users' permissions to their role presets (local edits are discarded).
DELETE FROM user_permissions WHERE FIND_IN_SET(user_id, @demo_users);
INSERT INTO user_permissions (user_id, area, can_view, can_edit, can_delete)
SELECT p.id, r.area, r.can_view, r.can_edit, r.can_delete
FROM dbpersons p JOIN role_permission_presets r ON r.role = p.role
WHERE FIND_IN_SET(p.id, @demo_users);

-- 3. Campaigns (dbevents doubles as the campaigns table).
INSERT INTO dbevents (name, goalAmount, startDate, endDate, startTime, endTime, description, completed, location) VALUES
 ('Fall Food Drive 2026', 15000.00, DATE_FORMAT(CURDATE() - INTERVAL 45 DAY, '%Y-%m-%d'), DATE_FORMAT(CURDATE() + INTERVAL 30 DAY, '%Y-%m-%d'), '00:00', '23:59', '[demo] Annual fall collection for the pantry', '0', 'FISH pantry');
SET @c_fall := LAST_INSERT_ID();
INSERT INTO dbevents (name, goalAmount, startDate, endDate, startTime, endTime, description, completed, location) VALUES
 ('Giving Tuesday 2025', 10000.00, '2025-12-02', '2025-12-02', '00:00', '23:59', '[demo] Online giving day', '1', 'Online');
SET @c_tuesday := LAST_INSERT_ID();
INSERT INTO dbevents (name, goalAmount, startDate, endDate, startTime, endTime, description, completed, location) VALUES
 ('Holiday Meals 2025', 8000.00, '2025-11-15', '2025-12-24', '00:00', '23:59', '[demo] Holiday meal boxes', '1', 'FISH pantry');
SET @c_holiday := LAST_INSERT_ID();

-- 4. Donors.
INSERT INTO donors (donor_type, `first`, `last`, email, phone, street, city, state, zip, preferred_contact, is_volunteer, email_updates, mail_receipts, do_not_contact, donor_since, notes) VALUES
 ('individual', 'Maria', 'Lopez', 'maria.lopez@example.com', '540-555-0101', '12 Main St', 'Warrenton', 'VA', '20186', 'email', 1, 1, 0, 0, '2021-03-14', '[demo] Regular monthly giver; volunteers Saturdays');
SET @d_maria := LAST_INSERT_ID();
INSERT INTO donors (donor_type, `first`, `last`, email, phone, street, city, state, zip, preferred_contact, is_volunteer, email_updates, mail_receipts, do_not_contact, donor_since, notes) VALUES
 ('individual', 'James', 'Whitaker', 'jwhitaker@example.com', '540-555-0102', '88 Oak Ln', 'Bealeton', 'VA', '22712', 'mail', 0, 0, 1, 0, '2019-11-02', '[demo] Lapsed: no gift in the last 12 months');
SET @d_james := LAST_INSERT_ID();
INSERT INTO donors (donor_type, org_name, org_contact, email, phone, street, city, state, zip, preferred_contact, is_volunteer, email_updates, mail_receipts, do_not_contact, donor_since, notes) VALUES
 ('organization', 'Warrenton Rotary Club', 'Priya Natarajan', 'giving@warrentonrotary.example.org', '540-555-0103', '1 Court St', 'Warrenton', 'VA', '20186', 'email', 0, 1, 1, 0, '2020-06-01', '[demo] Annual sponsor');
SET @d_rotary := LAST_INSERT_ID();
INSERT INTO donors (donor_type, `first`, `last`, email, phone, street, city, state, zip, preferred_contact, is_volunteer, email_updates, mail_receipts, do_not_contact, donor_since, notes) VALUES
 ('individual', 'Thomas', 'Barker', 'tbarker@example.com', '540-555-0104', '4 Elm Ct', 'Marshall', 'VA', '20115', 'phone', 1, 0, 0, 0, CURDATE() - INTERVAL 5 DAY, '[demo] New this month');
SET @d_thomas := LAST_INSERT_ID();
INSERT INTO donors (donor_type, org_name, org_contact, email, phone, street, city, state, zip, preferred_contact, is_volunteer, email_updates, mail_receipts, do_not_contact, donor_since, notes) VALUES
 ('organization', 'Grace Community Church', 'Rev. Ellen Moss', 'office@gracecc.example.org', '540-555-0105', '200 Church Rd', 'Warrenton', 'VA', '20187', 'email', 0, 1, 0, 0, '2018-01-20', '[demo] Food drive partner');
SET @d_grace := LAST_INSERT_ID();
INSERT INTO donors (donor_type, `first`, `last`, email, phone, street, city, state, zip, preferred_contact, is_volunteer, email_updates, mail_receipts, do_not_contact, donor_since, notes) VALUES
 ('individual', 'Maria', 'Lopez-Hernandez', 'maria.lopez@example.com', '540-555-0101', '12 Main Street', 'Warrenton', 'VA', '20186', 'text', 0, 0, 0, 1, '2024-02-02', '[demo] Possible duplicate of Maria Lopez (same email and phone)');
SET @d_maria2 := LAST_INSERT_ID();

-- 5. Cash donations. Explicit column lists only: net_amount is a virtual column.
INSERT INTO donations (donorID, eventID, amount, fee, reason, thanked, donation_date, payment_method, check_number, is_signed, deposit_date, platform, in_honor_of, honoree_name, send_receipt, receipt_sent_at, recorded_by) VALUES
 (@d_maria,  @c_fall,    50.00, 0.00, 'Monthly gift',          1, CURDATE() - INTERVAL 10 DAY, 'online', NULL,    NULL, NULL,                        'mighty_cause', 0, NULL,                         1, NOW() - INTERVAL 9 DAY,  'fish.staff'),
 (@d_maria,  NULL,       50.00, 1.75, 'Monthly gift',          1, CURDATE() - INTERVAL 40 DAY, 'card',   NULL,    NULL, NULL,                        'in_person',    0, NULL,                         1, NOW() - INTERVAL 39 DAY, 'fish.staff'),
 (@d_james,  NULL,       250.00, 0.00, 'Giving Tuesday 2024',       1, '2024-12-03',                'check',  '1042',  1,    '2024-12-05',                'in_person',    1, 'In memory of Ruth Whitaker', 1, '2024-12-10 10:00:00',   'fish.admin'),
 (@d_rotary, @c_fall,   2500.00, 0.00, 'Annual sponsorship',   0, CURDATE() - INTERVAL 20 DAY, 'check',  '77812', 1,    CURDATE() - INTERVAL 18 DAY, 'in_person',    0, NULL,                         1, NULL,                    'fish.staff'),
 (@d_thomas, @c_fall,    100.00, 2.90, 'First gift',           0, CURDATE() - INTERVAL 3 DAY,  'online', NULL,    NULL, NULL,                        'benevity',     0, NULL,                         1, NULL,                    'fish.staff'),
 (@d_grace,  @c_holiday, 1200.00, 0.00, 'Holiday meals',       1, '2025-12-15',                'check',  '5531',  1,    '2025-12-18',                'in_person',    0, NULL,                         1, '2025-12-20 09:30:00',   'fish.admin'),
 (999,       @c_fall,     20.00, 0.00, 'Anonymous cash in jar', 0, CURDATE() - INTERVAL 1 DAY, 'cash',   NULL,    NULL, NULL,                        'in_person',    0, NULL,                         0, NULL,                    'fish.staff');

-- 6. Non-cash donations and where they went.
INSERT INTO non_cash_donations (donor_id, campaign_id, date_received, item_description, category, quantity, weight_lbs, estimated_value, item_condition, intended_use, send_receipt, receipt_sent_at, recorded_by) VALUES
 (@d_grace, @c_fall, CURDATE() - INTERVAL 12 DAY, 'Canned vegetables and soups', 'canned_food', 240, 190.00, 360.00, 'new', 'Food pantry', 1, NOW() - INTERVAL 11 DAY, 'fish.staff');
SET @nc_cans := LAST_INSERT_ID();
INSERT INTO non_cash_donations (donor_id, campaign_id, date_received, item_description, category, quantity, weight_lbs, estimated_value, item_condition, intended_use, send_receipt, receipt_sent_at, recorded_by) VALUES
 (@d_grace,  @c_fall,    CURDATE() - INTERVAL 12 DAY, 'Rice and pasta',               'dry_goods',        40,  80.00, 120.00, 'new',         'Food pantry',        1, NOW() - INTERVAL 11 DAY, 'fish.staff'),
 (@d_maria,  NULL,       CURDATE() - INTERVAL 30 DAY, 'Winter coats, assorted sizes', 'clothing',         15,  30.00, 300.00, 'gently_used', 'Clothing closet',    0, NULL,                    'fish.staff');
INSERT INTO non_cash_donations (donor_id, campaign_id, date_received, item_description, category, quantity, weight_lbs, estimated_value, item_condition, intended_use, send_receipt, receipt_sent_at, recorded_by) VALUES
 (@d_rotary, @c_holiday, '2025-12-10', 'Turkeys', 'fresh_produce', 40, 560.00, 1000.00, 'new', 'Holiday meal boxes', 1, '2025-12-12 14:00:00', 'fish.admin');
SET @nc_turkeys := LAST_INSERT_ID();
INSERT INTO non_cash_donations (donor_id, campaign_id, date_received, item_description, category, quantity, weight_lbs, estimated_value, item_condition, intended_use, send_receipt, receipt_sent_at, recorded_by) VALUES
 (999, NULL, CURDATE() - INTERVAL 2 DAY, 'Toothpaste and soap', 'hygiene_products', 30, 12.50, 90.00, 'new', 'Food pantry', 0, NULL, 'fish.staff');

INSERT INTO non_cash_distributions (non_cash_donation_id, distributed_on, quantity, program, recorded_by) VALUES
 (@nc_cans,    CURDATE() - INTERVAL 8 DAY, 120, 'Food pantry, week 1', 'fish.staff'),
 (@nc_cans,    CURDATE() - INTERVAL 1 DAY,  60, 'Food pantry, week 2', 'fish.staff'),
 (@nc_turkeys, '2025-12-20',                40, 'Holiday meal boxes',  'fish.admin');

-- 7. Volunteer hours.
INSERT INTO volunteer_hours (donor_id, campaign_id, activity_date, hours, job, recorded_by) VALUES
 (@d_maria,  @c_fall, CURDATE() - INTERVAL 7 DAY,  4.00, 'Pantry sorting',     'fish.staff'),
 (@d_maria,  NULL,    CURDATE() - INTERVAL 14 DAY, 3.50, 'Client intake desk', 'fish.staff'),
 (@d_thomas, @c_fall, CURDATE() - INTERVAL 2 DAY,  2.00, 'Food drive pickup',  'fish.staff');

-- 8. Communications.
INSERT INTO communications (donor_id, campaign_id, occurred_at, comm_type, direction, subject, summary, person_contacted, status, recorded_by) VALUES
 (@d_maria,  @c_fall, NOW() - INTERVAL 9 DAY,  'email',     'outbound', 'Thank you for your October gift', 'Receipt plus fall drive update',          'Maria Lopez',     'opened',    'fish.staff'),
 (@d_james,  NULL,    NOW() - INTERVAL 60 DAY, 'mail',      'outbound', 'We miss you',                     'Lapsed-donor reactivation letter',        'James Whitaker',  'sent',      'fish.admin'),
 (@d_rotary, @c_fall, NOW() - INTERVAL 25 DAY, 'phone',     'outbound', 'Sponsorship renewal',             'Confirmed $2,500 sponsorship check',       'Priya Natarajan', 'replied',   'fish.staff'),
 (@d_grace,  @c_fall, NOW() - INTERVAL 13 DAY, 'in_person', 'inbound',  'Food drive drop-off',             'Delivered 240 cans and 80 lbs dry goods',  'Rev. Ellen Moss', 'logged',    'fish.staff'),
 (@d_thomas, NULL,    NOW() - INTERVAL 3 DAY,  'email',     'outbound', 'Welcome to FISH',                 'New donor welcome and receipt',            'Thomas Barker',   'delivered', 'fish.staff');

-- 9. Campaign demo (moved from migration 009). Donors and activity are marked 'demo-seed' and campaigns
--    '[demo]'; step 1 removes them, so every run rebuilds them. Dates are relative to the day this runs, so
--    the statuses (active / upcoming / completed) make sense on demo day. Owners are looked up by username;
--    if that account does not exist the owner is simply blank. Gifts for 'Giving Tuesday 2025' go to the
--    campaign from step 3.

-- 9a. Demo donors, volunteers and organizations (skipped when the email already exists).
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

-- 9b. Demo campaigns.
INSERT INTO dbevents (name, campaign_type, description, goalAmount, item_goal, item_unit, volunteer_goal,
                      startDate, endDate, fund, location, owner_id,
                      show_on_dashboard, email_past_donors, ask_volunteers, completed)
SELECT c.c_name, c.c_type, CONCAT('[demo] ', c.descr), c.cash_goal, c.item_goal, c.unit, c.vol_goal,
       DATE_FORMAT(CURDATE() + INTERVAL c.start_off DAY, '%Y-%m-%d'),
       DATE_FORMAT(CURDATE() + INTERVAL c.end_off DAY,   '%Y-%m-%d'),
       c.fund, c.loc, (SELECT p.id FROM dbpersons p WHERE p.id = c.own_user), 1, 0, 0, c.done
FROM (
            SELECT 'Thanksgiving Meals Drive' AS c_name, 'both' AS c_type, 'Holiday meals for about 300 local families.' AS descr, 12000.00 AS cash_goal, 2000 AS item_goal, 'cans' AS unit, 40 AS vol_goal, -25 AS start_off, 10 AS end_off, 'Food Pantry' AS fund, 'FISH pantry' AS loc, 'ccoleman' AS own_user, '0' AS done
  UNION ALL SELECT 'Winter Coat Collection', 'items',      'Warm coats for kids and adults before the cold weather.', NULL, 500, 'coats', NULL, -5, 56, 'General Fund', 'FISH pantry', 'jandrews', '0'
  UNION ALL SELECT 'Fall Volunteer Push',    'volunteers', 'Recruit and schedule new volunteers for the fall pantry days.', NULL, NULL, 'items', 40, -19, 11, 'General Fund', '', 'jandrews', '0'
  UNION ALL SELECT 'Year-End Giving',        'cash',       'Our biggest appeal of the year.', 30000.00, NULL, 'items', NULL, 41, 71, 'General Fund', '', 'dprice', '0'
  UNION ALL SELECT 'Spring Pantry Restock',  'both',       'Restock the shelves after winter.', 5000.00, 800, 'items', 20, 131, 161, 'Food Pantry', 'FISH pantry', 'ccoleman', '0'
  UNION ALL SELECT 'Back to School Kits',    'items',      'School supply kits for local students.', NULL, 500, 'kits', NULL, -97, -62, 'General Fund', 'FISH pantry', 'ccoleman', '1'
) c
WHERE NOT EXISTS (SELECT 1 FROM dbevents x WHERE x.name = c.c_name AND x.description LIKE '[demo]%');

-- 9c. Cash gifts linked to the campaigns (matched by donor email and campaign name).
SET @gt_days := DATEDIFF(CURDATE(), '2025-12-02');

INSERT INTO donations (amount, reason, fee, thanked, eventID, donorID, donation_date, payment_method, check_number,
                       is_signed, platform, send_receipt, receipt_sent_at)
SELECT g.amt, 'demo-seed', 0, IF(g.days_ago > 5, 1, 0), c.id, d.id,
       CURDATE() - INTERVAL g.days_ago DAY, g.method, g.chk,
       IF(g.method = 'check', 1, NULL),
       CASE g.method WHEN 'online' THEN 'mighty_cause' ELSE 'in_person' END,
       1, IF(g.days_ago > 5, NOW(), NULL)
FROM (
            SELECT 'demo.oakridge@example.org' AS donor_email, 'Thanksgiving Meals Drive' AS campaign, 500.00 AS amt, 24 AS days_ago, 'check' AS method, '3302' AS chk
  UNION ALL SELECT 'demo.grace@example.org',    'Thanksgiving Meals Drive', 1200.00, 22, 'check',  '0455'
  UNION ALL SELECT 'demo.mainst@example.org',   'Thanksgiving Meals Drive', 2000.00, 17, 'check',  '2210'
  UNION ALL SELECT 'demo.rotary@example.org',   'Thanksgiving Meals Drive', 1500.00, 15, 'check',  '2201'
  UNION ALL SELECT 'demo.thrift@example.org',   'Thanksgiving Meals Drive',  800.00, 12, 'online', NULL
  UNION ALL SELECT 'demo.rpatel@example.org',   'Thanksgiving Meals Drive',  100.00,  9, 'online', NULL
  UNION ALL SELECT 'demo.malvarez@example.org', 'Thanksgiving Meals Drive',   40.00,  8, 'card',   NULL
  UNION ALL SELECT 'demo.jmiller@example.org',  'Thanksgiving Meals Drive',  250.00,  7, 'check',  '1187'
  UNION ALL SELECT 'demo.lchen@example.org',    'Thanksgiving Meals Drive',   75.00,  6, 'cash',   NULL
  UNION ALL SELECT 'demo.jmiller@example.org',  'Thanksgiving Meals Drive',  250.00,  3, 'check',  '1203'
  UNION ALL SELECT 'demo.thrift@example.org',   'Thanksgiving Meals Drive',  200.00,  1, 'online', NULL
  UNION ALL SELECT 'demo.mainst@example.org',   'Giving Tuesday 2025', 4000.00, @gt_days, 'check',  '2105'
  UNION ALL SELECT 'demo.grace@example.org',    'Giving Tuesday 2025', 1500.00, @gt_days, 'check',  '0390'
  UNION ALL SELECT 'demo.rotary@example.org',   'Giving Tuesday 2025', 3000.00, @gt_days, 'check',  '2150'
  UNION ALL SELECT 'demo.jmiller@example.org',  'Giving Tuesday 2025',  250.00, @gt_days, 'online', NULL
  UNION ALL SELECT 'demo.rpatel@example.org',   'Giving Tuesday 2025',  120.00, @gt_days, 'online', NULL
  UNION ALL SELECT 'demo.lchen@example.org',    'Giving Tuesday 2025',   50.00, @gt_days, 'online', NULL
  UNION ALL SELECT 'demo.malvarez@example.org', 'Giving Tuesday 2025',   40.00, @gt_days, 'card',   NULL
) g
JOIN donors  d ON d.email = g.donor_email
JOIN dbevents c ON c.name = g.campaign AND c.description LIKE '[demo]%'
WHERE NOT EXISTS (SELECT 1 FROM donations x WHERE x.reason = 'demo-seed');

-- 9d. Non-cash gifts.
INSERT INTO non_cash_donations (donor_id, campaign_id, date_received, item_description, category, quantity, weight_lbs,
                                estimated_value, item_condition, intended_use, notes, send_receipt, receipt_sent_at)
SELECT d.id, c.id, CURDATE() - INTERVAL g.days_ago DAY, g.item, g.category, g.qty, g.lbs,
       g.val, g.item_cond, c.name, 'demo-seed', 1, IF(g.days_ago > 5, NOW(), NULL)
FROM (
            SELECT 'demo.grace@example.org' AS donor_email, 'Thanksgiving Meals Drive' AS campaign, 'Soup' AS item, 'canned_food' AS category, 240 AS qty, 190.00 AS lbs, 360.00 AS val, 'new' AS item_cond, 14 AS days_ago
  UNION ALL SELECT 'demo.mainst@example.org',    'Thanksgiving Meals Drive', 'Canned vegetables', 'canned_food', 320, 250.00,  480.00, 'new', 10
  UNION ALL SELECT 'demo.oakridge@example.org',  'Thanksgiving Meals Drive', 'Dry goods',         'dry_goods',    58, 145.00, 290.00, 'new', 13
  UNION ALL SELECT 'demo.rotary@example.org',    'Thanksgiving Meals Drive', 'Canned vegetables', 'canned_food', 400, 315.00,  600.00, 'new',  8
  UNION ALL SELECT 'demo.thrift@example.org',    'Thanksgiving Meals Drive', 'Canned fruit',      'canned_food', 180, 140.00,  270.00, 'new',  5
  UNION ALL SELECT 'demo.thrift@example.org',    'Winter Coat Collection',  'Winter coats',      'clothing',    120, 360.00, 1800.00, 'gently_used', 3
  UNION ALL SELECT 'demo.grace@example.org',     'Winter Coat Collection',  'Winter coats',      'clothing',     60, 180.00,  900.00, 'gently_used', 2
  UNION ALL SELECT 'demo.malvarez@example.org',  'Winter Coat Collection',  'Kids coats',        'clothing',     12,  18.00,  180.00, 'gently_used', 1
  UNION ALL SELECT 'demo.oakridge@example.org',  'Back to School Kits',     'School supply kits','other',       300, 450.00, 1500.00, 'new', 75
  UNION ALL SELECT 'demo.rotary@example.org',    'Back to School Kits',     'School supply kits','other',       260, 390.00, 1300.00, 'new', 70
) g
JOIN donors  d ON d.email = g.donor_email
JOIN dbevents c ON c.name = g.campaign AND c.description LIKE '[demo]%'
WHERE NOT EXISTS (SELECT 1 FROM non_cash_donations x WHERE x.notes = 'demo-seed');

-- 9e. Volunteer shifts.
INSERT INTO volunteer_hours (donor_id, campaign_id, activity_date, hours, job, notes)
SELECT d.id, c.id, CURDATE() - INTERVAL g.days_ago DAY, g.hrs, g.job, 'demo-seed'
FROM (
            SELECT 'demo.aruiz@example.org' AS donor_email, 'Thanksgiving Meals Drive' AS campaign, 6 AS hrs, 'Sorting cans' AS job, 10 AS days_ago
  UNION ALL SELECT 'demo.tbarker@example.org', 'Thanksgiving Meals Drive', 4, 'Pantry intake',     9
  UNION ALL SELECT 'demo.mlopez@example.org',  'Thanksgiving Meals Drive', 3, 'Drop-off table',    8
  UNION ALL SELECT 'demo.rpatel@example.org',  'Thanksgiving Meals Drive', 3, 'Drop-off table',    6
  UNION ALL SELECT 'demo.aruiz@example.org',   'Thanksgiving Meals Drive', 5, 'Sorting cans',      5
  UNION ALL SELECT 'demo.tbarker@example.org', 'Thanksgiving Meals Drive', 4, 'Pantry intake',     4
  UNION ALL SELECT 'demo.lchen@example.org',   'Thanksgiving Meals Drive', 4, 'Packing boxes',     2
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
JOIN dbevents c ON c.name = g.campaign AND c.description LIKE '[demo]%'
WHERE NOT EXISTS (SELECT 1 FROM volunteer_hours x WHERE x.notes = 'demo-seed');

-- 9f. Communications tied to campaigns.
INSERT INTO communications (donor_id, campaign_id, occurred_at, comm_type, direction, subject, summary, status)
SELECT d.id, c.id, NOW() - INTERVAL g.days_ago DAY, g.ctype, 'outbound', g.subject, 'demo-seed', g.cstatus
FROM (
            SELECT 'demo.mainst@example.org' AS donor_email, 'Thanksgiving Meals Drive' AS campaign, 'email' AS ctype, 'Help us feed 300 families' AS subject, 'sent' AS cstatus, 10 AS days_ago
  UNION ALL SELECT 'demo.rotary@example.org',   'Thanksgiving Meals Drive', 'phone', 'Drop-off times',                    'logged', 12
  UNION ALL SELECT 'demo.oakridge@example.org', 'Winter Coat Collection',  'email', 'Coat collection starts this week',  'sent',    4
  UNION ALL SELECT 'demo.rpatel@example.org',   'Fall Volunteer Push',     'email', 'Sign up for a volunteer shift',     'sent',   16
) g
JOIN donors  d ON d.email = g.donor_email
JOIN dbevents c ON c.name = g.campaign AND c.description LIKE '[demo]%'
WHERE NOT EXISTS (SELECT 1 FROM communications x WHERE x.summary = 'demo-seed');
