-- Optional FISH demo data for local development (make seed-demo). Never load this on a real database.
--
-- Idempotent without fixed ids: every run first deletes the previous demo rows, then inserts fresh
-- ones with normal auto-increment ids, so real rows are never overwritten and the id sequence is
-- not pushed into a reserved range. Demo rows are recognisable by their markers:
--   * activity rows (donations, non-cash, hours, communications) are recorded_by a fish.* demo user
--   * demo donors and campaigns have notes / description starting with "[demo]"
-- Demo users all share the password "Fish!Demo2026".
SET NAMES utf8mb4;
SET @pw := '$2y$10$yXqRVA1EFSrJmx3kuKDtmOmXm1ReT3EFSgZ076S6k6rfAbgrXRfsC';
SET @demo_users := 'fish.admin,fish.manager,fish.staff,fish.viewer,fish.former';

-- 1. Remove the previous run (children before parents). Activity is removed when it was recorded
--    by a demo user OR belongs to a demo donor, so rows a developer added by hand against a demo
--    donor do not block the donor delete.
CREATE TEMPORARY TABLE demo_donor_ids AS SELECT id FROM donors WHERE notes LIKE '[demo]%';
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
INSERT INTO non_cash_donations (donor_id, campaign_id, date_received, item_description, category, quantity, unit, estimated_value, item_condition, intended_use, send_receipt, receipt_sent_at, recorded_by) VALUES
 (@d_grace, @c_fall, CURDATE() - INTERVAL 12 DAY, 'Canned vegetables and soups', 'canned_food', 240, 'items', 360.00, 'new', 'Food pantry', 1, NOW() - INTERVAL 11 DAY, 'fish.staff');
SET @nc_cans := LAST_INSERT_ID();
INSERT INTO non_cash_donations (donor_id, campaign_id, date_received, item_description, category, quantity, unit, estimated_value, item_condition, intended_use, send_receipt, receipt_sent_at, recorded_by) VALUES
 (@d_grace,  @c_fall,    CURDATE() - INTERVAL 12 DAY, 'Rice and pasta',               'dry_goods',        80, 'lbs',    120.00, 'new',         'Food pantry',        1, NOW() - INTERVAL 11 DAY, 'fish.staff'),
 (@d_maria,  NULL,       CURDATE() - INTERVAL 30 DAY, 'Winter coats, assorted sizes', 'clothing',         15, 'items',  300.00, 'gently_used', 'Clothing closet',    0, NULL,                    'fish.staff');
INSERT INTO non_cash_donations (donor_id, campaign_id, date_received, item_description, category, quantity, unit, estimated_value, item_condition, intended_use, send_receipt, receipt_sent_at, recorded_by) VALUES
 (@d_rotary, @c_holiday, '2025-12-10', 'Turkeys', 'fresh_produce', 40, 'items', 1000.00, 'new', 'Holiday meal boxes', 1, '2025-12-12 14:00:00', 'fish.admin');
SET @nc_turkeys := LAST_INSERT_ID();
INSERT INTO non_cash_donations (donor_id, campaign_id, date_received, item_description, category, quantity, unit, estimated_value, item_condition, intended_use, send_receipt, receipt_sent_at, recorded_by) VALUES
 (999, NULL, CURDATE() - INTERVAL 2 DAY, 'Toothpaste and soap', 'hygiene_products', 30, 'items', 90.00, 'new', 'Food pantry', 0, NULL, 'fish.staff');

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
