-- Foundation donors: same fields and contact people as organizations, kept as their own type for reporting.
-- Adds 'foundation' to donors.donor_type and recreates donor_summary so a foundation's display_name is its
-- org_name (the only change from 011 is the CASE condition marked below).
-- MariaDB 10.11 only. Safe to re-run.
SET NAMES utf8mb4;

ALTER TABLE donors
  MODIFY COLUMN donor_type ENUM('individual','organization','foundation') NOT NULL DEFAULT 'individual';

CREATE OR REPLACE VIEW donor_summary AS
SELECT u.*,
       CASE WHEN u.last_gift_date IS NULL THEN 'inactive'
            WHEN u.last_gift_date > CURDATE() - INTERVAL 12 MONTH THEN 'active'
            WHEN u.last_gift_date > CURDATE() - INTERVAL 24 MONTH THEN 'lybunt'
            WHEN u.last_gift_date > CURDATE() - INTERVAL 36 MONTH THEN 'sybunt'
            ELSE 'inactive' END AS status
FROM (
  SELECT t.*,
         LEAST(COALESCE(t.donor_since, t.first_gift_date),
               COALESCE(t.first_gift_date, t.donor_since))          AS donor_since_date
  FROM (
    SELECT s.*,
           LEAST(COALESCE(s.first_cash_date, s.first_noncash_date),
                 COALESCE(s.first_noncash_date, s.first_cash_date))   AS first_gift_date,
           GREATEST(COALESCE(s.last_cash_date, s.last_noncash_date),
                    COALESCE(s.last_noncash_date, s.last_cash_date))  AS last_gift_date
    FROM (
      SELECT d.id, d.donor_type,
             CASE WHEN d.donor_type IN ('organization', 'foundation') AND COALESCE(d.org_name, '') <> '' THEN d.org_name   -- changed from 011
                  ELSE TRIM(CONCAT_WS(' ', d.`first`, d.`last`)) END   AS display_name,
             d.`first`, d.`last`, d.middle_name, d.org_name, d.org_contact, d.email, d.phone,
             d.street, d.city, d.state, d.zip,
             d.preferred_contact, d.is_volunteer, d.email_updates, d.mail_receipts, d.do_not_contact,
             d.donor_since, d.created_at, d.updated_at,
             d.is_active, d.updated_by, d.deactivated_at, d.deactivated_by,
             (d.id = 999)                                                          AS is_anonymous,
             (SELECT COALESCE(SUM(x.amount), 0) FROM donations x WHERE x.donorID = d.id)  AS lifetime_cash,
             (SELECT COUNT(*)                   FROM donations x WHERE x.donorID = d.id)  AS cash_gift_count,
             (SELECT MIN(x.donation_date)       FROM donations x WHERE x.donorID = d.id)  AS first_cash_date,
             (SELECT MAX(x.donation_date)       FROM donations x WHERE x.donorID = d.id)  AS last_cash_date,
             (SELECT COALESCE(SUM(n.quantity), 0)        FROM non_cash_donations n WHERE n.donor_id = d.id) AS items_donated,
             (SELECT COALESCE(SUM(n.estimated_value), 0) FROM non_cash_donations n WHERE n.donor_id = d.id) AS noncash_value,
             (SELECT COUNT(*)                            FROM non_cash_donations n WHERE n.donor_id = d.id) AS noncash_gift_count,
             (SELECT MIN(n.date_received)                FROM non_cash_donations n WHERE n.donor_id = d.id) AS first_noncash_date,
             (SELECT MAX(n.date_received)                FROM non_cash_donations n WHERE n.donor_id = d.id) AS last_noncash_date,
             (SELECT COALESCE(SUM(v.hours), 0)  FROM volunteer_hours v WHERE v.donor_id = d.id)  AS volunteer_hours,
             (SELECT COUNT(*)                   FROM volunteer_hours v WHERE v.donor_id = d.id)  AS volunteer_shift_count,
             (SELECT MAX(c.occurred_at)         FROM communications c  WHERE c.donor_id = d.id)  AS last_contact_at,
             (SELECT COUNT(*)                   FROM communications c  WHERE c.donor_id = d.id)  AS communication_count
      FROM donors d
    ) s
  ) t
) u;