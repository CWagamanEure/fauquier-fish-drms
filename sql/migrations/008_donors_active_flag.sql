-- Donor pages read real data (UF2-26, UF2-27, UF2-60): soft-delete flag and audit columns on donors,
-- permission rows for accounts created since 001, and donor_summary recreated to expose the new columns.
-- MariaDB 10.11 only. Idempotent.
SET NAMES utf8mb4;

ALTER TABLE donors
  ADD COLUMN IF NOT EXISTS is_active      TINYINT(1)   NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS updated_by     VARCHAR(256) NULL,
  ADD COLUMN IF NOT EXISTS deactivated_at DATETIME     NULL,
  ADD COLUMN IF NOT EXISTS deactivated_by VARCHAR(256) NULL;

ALTER TABLE donors
  ADD INDEX IF NOT EXISTS idx_donors_active         (is_active),
  ADD INDEX IF NOT EXISTS idx_donors_updated_by     (updated_by),
  ADD INDEX IF NOT EXISTS idx_donors_deactivated_by (deactivated_by);

-- Guarded foreign keys (ADD CONSTRAINT has no reliable IF NOT EXISTS); same pattern as 002.
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                   WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'donors'
                     AND CONSTRAINT_TYPE = 'FOREIGN KEY' AND CONSTRAINT_NAME = 'fk_donors_updated_by');
SET @sql := IF(@fk_exists = 0,
  'ALTER TABLE donors ADD CONSTRAINT fk_donors_updated_by FOREIGN KEY (updated_by) REFERENCES dbpersons (id) ON DELETE SET NULL ON UPDATE CASCADE',
  'SELECT ''fk_donors_updated_by already present'' AS note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                   WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'donors'
                     AND CONSTRAINT_TYPE = 'FOREIGN KEY' AND CONSTRAINT_NAME = 'fk_donors_deactivated_by');
SET @sql := IF(@fk_exists = 0,
  'ALTER TABLE donors ADD CONSTRAINT fk_donors_deactivated_by FOREIGN KEY (deactivated_by) REFERENCES dbpersons (id) ON DELETE SET NULL ON UPDATE CASCADE',
  'SELECT ''fk_donors_deactivated_by already present'' AS note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Accounts created since 001 have no user_permissions rows (nothing in the app wrote them until now).
-- Seed them from the role presets, like 001 did; IGNORE keeps rows an admin has already edited.
INSERT IGNORE INTO user_permissions (user_id, area, can_view, can_edit, can_delete)
SELECT p.id, r.area, r.can_view, r.can_edit, r.can_delete
FROM dbpersons p JOIN role_permission_presets r ON r.role = p.role;

-- donor_summary freezes its column list when created, so it is recreated here with the new columns.
CREATE OR REPLACE VIEW donor_summary AS
SELECT u.*,
       -- new: donor record is under 90 days old; lapsed: no gift in 12 months, including never.
       CASE WHEN u.donor_since_date >= CURDATE() - INTERVAL 90 DAY THEN 'new'
            WHEN u.last_gift_date IS NULL THEN 'lapsed'
            WHEN u.last_gift_date < CURDATE() - INTERVAL 12 MONTH THEN 'lapsed'
            ELSE 'active' END AS status
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
             CASE WHEN d.donor_type = 'organization' AND COALESCE(d.org_name, '') <> '' THEN d.org_name
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
