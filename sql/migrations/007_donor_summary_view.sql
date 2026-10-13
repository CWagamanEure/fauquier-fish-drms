-- Read model for donor.php tiles/list and the donorProfile.php header: one row per donor with
-- lifetime totals, last gift, derived status (new / active / lapsed) and last contact.
-- Pages should filter is_anonymous = 0 for "Total donors" and duplicate checks.
-- MariaDB 10.11 only. Idempotent.
SET NAMES utf8mb4;

CREATE OR REPLACE VIEW donor_summary AS
SELECT s.*,
       -- new: donor record is under 90 days old (by donor_since or first gift); no fallback to
       -- created_at, so legacy rows backfilled by the migration never look new.
       -- lapsed: no gift in the last 12 months, including donors who never gave.
       CASE WHEN s.donor_since_date >= CURDATE() - INTERVAL 90 DAY THEN 'new'
            WHEN s.last_gift_date IS NULL THEN 'lapsed'
            WHEN s.last_gift_date < CURDATE() - INTERVAL 12 MONTH THEN 'lapsed'
            ELSE 'active' END AS status
FROM (
  SELECT d.id, d.donor_type,
         CASE WHEN d.donor_type = 'organization' AND COALESCE(d.org_name, '') <> '' THEN d.org_name
              ELSE TRIM(CONCAT_WS(' ', d.`first`, d.`last`)) END       AS display_name,
         d.`first`, d.`last`, d.middle_name, d.org_name, d.org_contact, d.email, d.phone,
         d.street, d.city, d.state, d.zip,
         d.preferred_contact, d.is_volunteer, d.email_updates, d.mail_receipts, d.do_not_contact,
         (d.id = 999)                                                  AS is_anonymous,
         COALESCE(c.lifetime_cash, 0)                                  AS lifetime_cash,
         COALESCE(c.cash_gift_count, 0)                                AS cash_gift_count,
         COALESCE(n.items_donated, 0)                                  AS items_donated,
         COALESCE(n.noncash_value, 0)                                  AS noncash_value,
         COALESCE(n.noncash_gift_count, 0)                             AS noncash_gift_count,
         COALESCE(v.volunteer_hours, 0)                                AS volunteer_hours,
         CASE WHEN c.last_cash_date IS NULL THEN n.last_noncash_date
              WHEN n.last_noncash_date IS NULL THEN c.last_cash_date
              ELSE GREATEST(c.last_cash_date, n.last_noncash_date) END AS last_gift_date,
         COALESCE(d.donor_since,
                  CASE WHEN c.first_cash_date IS NULL THEN n.first_noncash_date
                       WHEN n.first_noncash_date IS NULL THEN c.first_cash_date
                       ELSE LEAST(c.first_cash_date, n.first_noncash_date) END) AS donor_since_date,
         m.last_contact_at, d.created_at, d.updated_at
  FROM donors d
  LEFT JOIN (SELECT donorID, SUM(amount) AS lifetime_cash, COUNT(*) AS cash_gift_count,
                    MIN(donation_date) AS first_cash_date, MAX(donation_date) AS last_cash_date
             FROM donations GROUP BY donorID) c ON c.donorID = d.id
  LEFT JOIN (SELECT donor_id, SUM(quantity) AS items_donated, SUM(estimated_value) AS noncash_value,
                    COUNT(*) AS noncash_gift_count,
                    MIN(date_received) AS first_noncash_date, MAX(date_received) AS last_noncash_date
             FROM non_cash_donations GROUP BY donor_id) n ON n.donor_id = d.id
  LEFT JOIN (SELECT donor_id, SUM(hours) AS volunteer_hours
             FROM volunteer_hours GROUP BY donor_id) v ON v.donor_id = d.id
  LEFT JOIN (SELECT donor_id, MAX(occurred_at) AS last_contact_at
             FROM communications GROUP BY donor_id) m ON m.donor_id = d.id
) s;
