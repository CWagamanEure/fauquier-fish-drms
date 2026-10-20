-- Read model for donor.php tiles/list and the donorProfile.php header: one row per donor with
-- lifetime totals, last gift, derived status (new / active / lapsed) and last contact.
-- Aggregates are correlated per donor so a single-donor lookup (WHERE id = ?) uses the
-- (donor, date) indexes instead of aggregating every table.
-- Pages should filter is_anonymous = 0 for "Total donors" and duplicate checks.
-- MariaDB 10.11 only. Idempotent.
SET NAMES utf8mb4;

CREATE OR REPLACE VIEW donor_summary AS
SELECT u.*,
       -- new: donor record is under 90 days old; lapsed: no gift in 12 months, including never.
       CASE WHEN u.donor_since_date >= CURDATE() - INTERVAL 90 DAY THEN 'new'
            WHEN u.last_gift_date IS NULL THEN 'lapsed'
            WHEN u.last_gift_date < CURDATE() - INTERVAL 12 MONTH THEN 'lapsed'
            ELSE 'active' END AS status
FROM (
  SELECT t.*,
         -- earliest of donor_since and the first gift: a stamped donor_since must never hide
         -- older gifts imported later
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
             CASE WHEN d.donor_type = 'organization' AND COALESCE(d.org_name, '') <> '' THEN d.org_name
                  ELSE TRIM(CONCAT_WS(' ', d.`first`, d.`last`)) END   AS display_name,
             d.`first`, d.`last`, d.middle_name, d.org_name, d.org_contact, d.email, d.phone,
             d.street, d.city, d.state, d.zip,
             d.preferred_contact, d.is_volunteer, d.email_updates, d.mail_receipts, d.do_not_contact,
             d.donor_since, d.created_at, d.updated_at,
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
             (SELECT MAX(c.occurred_at)         FROM communications c  WHERE c.donor_id = d.id)  AS last_contact_at
      FROM donors d
    ) s
  ) t
) u;
