-- Optional FISH demo data for local development (make seed-demo). Idempotent: fixed ids >= 9001
-- and ON DUPLICATE KEY UPDATE, so re-running resets the demo rows to these values.
-- Demo users all share the password "Fish!Demo2026". Never load this file on a real database.
SET NAMES utf8mb4;
SET @pw := '$2y$10$yXqRVA1EFSrJmx3kuKDtmOmXm1ReT3EFSgZ076S6k6rfAbgrXRfsC';

INSERT INTO dbpersons (id, name, password, accessLevel, first_name, last_name, email, role, status) VALUES
 ('fish.admin',  'Dana Admin',  @pw, 3, 'Dana', 'Admin',  'dana.admin@example.org',  'admin',  'active'),
 ('fish.manager','Mia Manager', @pw, 2, 'Mia',  'Manager','mia.manager@example.org', 'manager','active'),
 ('fish.staff',  'Sam Staff',   @pw, 1, 'Sam',  'Staff',  'sam.staff@example.org',   'staff',  'active'),
 ('fish.viewer', 'Vic Viewer',  @pw, 1, 'Vic',  'Viewer', 'vic.viewer@example.org',  'viewer', 'active'),
 ('fish.former', 'Pat Former',  @pw, 1, 'Pat',  'Former', 'pat.former@example.org',  'staff',  'inactive')
ON DUPLICATE KEY UPDATE name=VALUES(name), password=VALUES(password), accessLevel=VALUES(accessLevel),
 first_name=VALUES(first_name), last_name=VALUES(last_name), email=VALUES(email), role=VALUES(role), status=VALUES(status);

INSERT INTO dbevents (id, name, goalAmount, `date`, startDate, endDate, startTime, endTime, description, completed, location) VALUES
 (901, 'Fall Food Drive 2026', 15000.00, NULL, DATE_FORMAT(CURDATE() - INTERVAL 45 DAY, '%Y-%m-%d'), DATE_FORMAT(CURDATE() + INTERVAL 30 DAY, '%Y-%m-%d'), '00:00', '23:59', 'Annual fall collection for the pantry', '0', 'FISH pantry'),
 (902, 'Giving Tuesday 2025',  10000.00, NULL, '2025-12-02', '2025-12-02', '00:00', '23:59', 'Online giving day', '1', 'Online'),
 (903, 'Holiday Meals 2025',    8000.00, NULL, '2025-11-15', '2025-12-24', '00:00', '23:59', 'Holiday meal boxes', '1', 'FISH pantry')
ON DUPLICATE KEY UPDATE name=VALUES(name), goalAmount=VALUES(goalAmount), startDate=VALUES(startDate), endDate=VALUES(endDate),
 description=VALUES(description), completed=VALUES(completed), location=VALUES(location);

INSERT INTO donors (id, donor_type, `first`, `last`, org_name, org_contact, email, phone, street, city, state, zip,
                    preferred_contact, is_volunteer, email_updates, mail_receipts, do_not_contact, donor_since, notes) VALUES
 (9001, 'individual',   'Maria',  'Lopez',    NULL, NULL, 'maria.lopez@example.com', '540-555-0101', '12 Main St',   'Warrenton', 'VA', '20186', 'email', 1, 1, 0, 0, '2021-03-14', 'Regular monthly giver; volunteers Saturdays'),
 (9002, 'individual',   'James',  'Whitaker', NULL, NULL, 'jwhitaker@example.com',   '540-555-0102', '88 Oak Ln',    'Bealeton',  'VA', '22712', 'mail',  0, 0, 1, 0, '2019-11-02', 'Lapsed: last gift over a year ago'),
 (9003, 'organization', NULL, NULL, 'Warrenton Rotary Club', 'Priya Natarajan', 'giving@warrentonrotary.example.org', '540-555-0103', '1 Court St', 'Warrenton', 'VA', '20186', 'email', 0, 1, 1, 0, '2020-06-01', 'Annual sponsor'),
 (9004, 'individual',   'Thomas', 'Barker',   NULL, NULL, 'tbarker@example.com',     '540-555-0104', '4 Elm Ct',     'Marshall',  'VA', '20115', 'phone', 1, 0, 0, 0, CURDATE() - INTERVAL 5 DAY, 'New this month'),
 (9005, 'organization', NULL, NULL, 'Grace Community Church', 'Rev. Ellen Moss', 'office@gracecc.example.org', '540-555-0105', '200 Church Rd', 'Warrenton', 'VA', '20187', 'email', 0, 1, 0, 0, '2018-01-20', 'Food drive partner'),
 (9006, 'individual',   'Maria',  'Lopez-Hernandez', NULL, NULL, 'maria.lopez@example.com', '540-555-0101', '12 Main Street', 'Warrenton', 'VA', '20186', 'text', 0, 0, 0, 1, '2024-02-02', 'Possible duplicate of 9001 (same email and phone)')
ON DUPLICATE KEY UPDATE donor_type=VALUES(donor_type), `first`=VALUES(`first`), `last`=VALUES(`last`), org_name=VALUES(org_name), org_contact=VALUES(org_contact),
 email=VALUES(email), phone=VALUES(phone), street=VALUES(street), city=VALUES(city), state=VALUES(state), zip=VALUES(zip), preferred_contact=VALUES(preferred_contact),
 is_volunteer=VALUES(is_volunteer), email_updates=VALUES(email_updates), mail_receipts=VALUES(mail_receipts), do_not_contact=VALUES(do_not_contact),
 donor_since=VALUES(donor_since), notes=VALUES(notes);

-- Explicit column lists only: net_amount is a virtual column and must never be written.
INSERT INTO donations (id, donorID, eventID, amount, fee, reason, thanked, `date`, donation_date, payment_method, check_number, is_signed,
                       deposit_date, platform, in_honor_of, honoree_name, send_receipt, receipt_sent_at, recorded_by) VALUES
 (9001, 9001, 901,   50.00, 0.00, 'Monthly gift',          1, DATE_FORMAT(CURDATE() - INTERVAL 10 DAY, '%m/%d/%Y'), CURDATE() - INTERVAL 10 DAY, 'online', NULL,    NULL, NULL,                        'mighty_cause', 0, NULL,                        1, NOW() - INTERVAL 9 DAY,  'fish.staff'),
 (9002, 9001, NULL,  50.00, 1.75, 'Monthly gift',          1, DATE_FORMAT(CURDATE() - INTERVAL 40 DAY, '%m/%d/%Y'), CURDATE() - INTERVAL 40 DAY, 'card',   NULL,    NULL, NULL,                        'in_person',    0, NULL,                        1, NOW() - INTERVAL 39 DAY, 'fish.staff'),
 (9003, 9002, 902,  250.00, 0.00, 'Giving Tuesday',        1, '12/02/2024', '2024-12-02',                                                         'check',  '1042',  1,    '2024-12-05',                'in_person',    1, 'In memory of Ruth Whitaker', 1, '2024-12-10 10:00:00',   'fish.admin'),
 (9004, 9003, 901, 2500.00, 0.00, 'Annual sponsorship',    0, DATE_FORMAT(CURDATE() - INTERVAL 20 DAY, '%m/%d/%Y'), CURDATE() - INTERVAL 20 DAY, 'check',  '77812', 1,    CURDATE() - INTERVAL 18 DAY, 'in_person',    0, NULL,                        1, NULL,                    'fish.staff'),
 (9005, 9004, 901,  100.00, 2.90, 'First gift',            0, DATE_FORMAT(CURDATE() - INTERVAL 3 DAY, '%m/%d/%Y'),  CURDATE() - INTERVAL 3 DAY,  'online', NULL,    NULL, NULL,                        'benevity',     0, NULL,                        1, NULL,                    'fish.staff'),
 (9006, 9005, 903, 1200.00, 0.00, 'Holiday meals',         1, '12/15/2025', '2025-12-15',                                                         'check',  '5531',  1,    '2025-12-18',                'in_person',    0, NULL,                        1, '2025-12-20 09:30:00',   'fish.admin'),
 (9007,  999, 901,   20.00, 0.00, 'Anonymous cash in jar', 0, DATE_FORMAT(CURDATE() - INTERVAL 1 DAY, '%m/%d/%Y'),  CURDATE() - INTERVAL 1 DAY,  'cash',   NULL,    NULL, NULL,                        'in_person',    0, NULL,                        0, NULL,                    'fish.staff')
ON DUPLICATE KEY UPDATE donorID=VALUES(donorID), eventID=VALUES(eventID), amount=VALUES(amount), fee=VALUES(fee), reason=VALUES(reason), thanked=VALUES(thanked),
 `date`=VALUES(`date`), donation_date=VALUES(donation_date), payment_method=VALUES(payment_method), check_number=VALUES(check_number), is_signed=VALUES(is_signed),
 deposit_date=VALUES(deposit_date), platform=VALUES(platform), in_honor_of=VALUES(in_honor_of), honoree_name=VALUES(honoree_name), send_receipt=VALUES(send_receipt),
 receipt_sent_at=VALUES(receipt_sent_at), recorded_by=VALUES(recorded_by);

INSERT INTO non_cash_donations (id, donor_id, campaign_id, date_received, item_description, category, quantity, unit, estimated_value, item_condition, intended_use, send_receipt, receipt_sent_at, recorded_by) VALUES
 (9001, 9005, 901,  CURDATE() - INTERVAL 12 DAY, 'Canned vegetables and soups', 'canned_food',      240, 'items',  360.00, 'new',         'Food pantry',        1, NOW() - INTERVAL 11 DAY, 'fish.staff'),
 (9002, 9005, 901,  CURDATE() - INTERVAL 12 DAY, 'Rice and pasta',              'dry_goods',         80, 'lbs',    120.00, 'new',         'Food pantry',        1, NOW() - INTERVAL 11 DAY, 'fish.staff'),
 (9003, 9001, NULL, CURDATE() - INTERVAL 30 DAY, 'Winter coats, assorted sizes','clothing',          15, 'items',  300.00, 'gently_used', 'Clothing closet',    0, NULL,                    'fish.staff'),
 (9004, 9003, 903,  '2025-12-10',                'Turkeys',                     'fresh_produce',     40, 'items', 1000.00, 'new',         'Holiday meal boxes', 1, '2025-12-12 14:00:00',   'fish.admin'),
 (9005,  999, NULL, CURDATE() - INTERVAL 2 DAY,  'Toothpaste and soap',         'hygiene_products',  30, 'items',   90.00, 'new',         'Food pantry',        0, NULL,                    'fish.staff')
ON DUPLICATE KEY UPDATE donor_id=VALUES(donor_id), campaign_id=VALUES(campaign_id), date_received=VALUES(date_received), item_description=VALUES(item_description),
 category=VALUES(category), quantity=VALUES(quantity), unit=VALUES(unit), estimated_value=VALUES(estimated_value), item_condition=VALUES(item_condition),
 intended_use=VALUES(intended_use), send_receipt=VALUES(send_receipt), receipt_sent_at=VALUES(receipt_sent_at), recorded_by=VALUES(recorded_by);

INSERT INTO non_cash_distributions (id, non_cash_donation_id, distributed_on, quantity, program, recorded_by) VALUES
 (9001, 9001, CURDATE() - INTERVAL 8 DAY, 120, 'Food pantry, week 1', 'fish.staff'),
 (9002, 9001, CURDATE() - INTERVAL 1 DAY,  60, 'Food pantry, week 2', 'fish.staff'),
 (9003, 9004, '2025-12-20',               40, 'Holiday meal boxes',  'fish.admin')
ON DUPLICATE KEY UPDATE non_cash_donation_id=VALUES(non_cash_donation_id), distributed_on=VALUES(distributed_on), quantity=VALUES(quantity),
 program=VALUES(program), recorded_by=VALUES(recorded_by);

INSERT INTO volunteer_hours (id, donor_id, campaign_id, activity_date, hours, job, recorded_by) VALUES
 (9001, 9001, 901,  CURDATE() - INTERVAL 7 DAY,  4.00, 'Pantry sorting',     'fish.staff'),
 (9002, 9001, NULL, CURDATE() - INTERVAL 14 DAY, 3.50, 'Client intake desk', 'fish.staff'),
 (9003, 9004, 901,  CURDATE() - INTERVAL 2 DAY,  2.00, 'Food drive pickup',  'fish.staff')
ON DUPLICATE KEY UPDATE donor_id=VALUES(donor_id), campaign_id=VALUES(campaign_id), activity_date=VALUES(activity_date), hours=VALUES(hours),
 job=VALUES(job), recorded_by=VALUES(recorded_by);

INSERT INTO communications (id, donor_id, campaign_id, occurred_at, comm_type, direction, subject, summary, person_contacted, status, recorded_by) VALUES
 (9001, 9001, 901,  NOW() - INTERVAL 9 DAY,  'email',     'outbound', 'Thank you for your October gift', 'Receipt plus fall drive update',        'Maria Lopez',      'opened',    'fish.staff'),
 (9002, 9002, NULL, NOW() - INTERVAL 60 DAY, 'mail',      'outbound', 'We miss you',                     'Lapsed-donor reactivation letter',      'James Whitaker',   'sent',      'fish.admin'),
 (9003, 9003, 901,  NOW() - INTERVAL 25 DAY, 'phone',     'outbound', 'Sponsorship renewal',             'Confirmed $2,500 sponsorship check',     'Priya Natarajan',  'replied',   'fish.staff'),
 (9004, 9005, 901,  NOW() - INTERVAL 13 DAY, 'in_person', 'inbound',  'Food drive drop-off',             'Delivered 240 cans and 80 lbs dry goods','Rev. Ellen Moss',  'logged',    'fish.staff'),
 (9005, 9004, NULL, NOW() - INTERVAL 3 DAY,  'email',     'outbound', 'Welcome to FISH',                 'New donor welcome and receipt',          'Thomas Barker',    'delivered', 'fish.staff')
ON DUPLICATE KEY UPDATE donor_id=VALUES(donor_id), campaign_id=VALUES(campaign_id), occurred_at=VALUES(occurred_at), comm_type=VALUES(comm_type),
 direction=VALUES(direction), subject=VALUES(subject), summary=VALUES(summary), person_contacted=VALUES(person_contacted), status=VALUES(status),
 recorded_by=VALUES(recorded_by);

-- Permission rows for the demo users from the role presets (IGNORE keeps any manual edits).
INSERT IGNORE INTO user_permissions (user_id, area, can_view, can_edit, can_delete)
SELECT p.id, r.area, r.can_view, r.can_edit, r.can_delete
FROM dbpersons p JOIN role_permission_presets r ON r.role = p.role
WHERE p.id IN ('fish.admin', 'fish.manager', 'fish.staff', 'fish.viewer', 'fish.former');
