-- UF2-60 / UF2-9: donor fields for the FISH "Add donor" form and donor list/profile pages.
-- Legacy first/last stay the name columns (widened); organizations use org_name/org_contact.
-- MariaDB 10.11 only. Idempotent.
SET NAMES utf8mb4;

ALTER TABLE donors
  MODIFY `first` VARCHAR(100) NULL,
  MODIFY `last`  VARCHAR(100) NULL,
  ADD COLUMN IF NOT EXISTS donor_type        ENUM('individual','organization') NOT NULL DEFAULT 'individual',
  ADD COLUMN IF NOT EXISTS middle_name       VARCHAR(100) NULL,
  ADD COLUMN IF NOT EXISTS org_name          VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS org_contact       VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS preferred_contact ENUM('email','phone','mail','text') NULL,
  ADD COLUMN IF NOT EXISTS is_volunteer      TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS email_updates     TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS mail_receipts     TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS do_not_contact    TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS donor_since       DATE NULL,   -- default is set below, AFTER the backfill
  ADD COLUMN IF NOT EXISTS created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ADD COLUMN IF NOT EXISTS updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

ALTER TABLE donors
  ADD INDEX IF NOT EXISTS idx_donors_email      (email),
  ADD INDEX IF NOT EXISTS idx_donors_last_first (`last`, `first`),
  ADD INDEX IF NOT EXISTS idx_donors_org_name   (org_name),
  ADD INDEX IF NOT EXISTS idx_donors_type       (donor_type);

-- donor_since: earliest dated cash gift (requires 002's donation_date). Only fills blanks.
-- Legacy donors with no dated gift keep NULL (unknown), which donor_summary reports as lapsed.
-- The column is added WITHOUT a default on purpose: ADD COLUMN ... DEFAULT (CURRENT_DATE)
-- would stamp every existing row with the migration date and make this backfill a no-op.
UPDATE donors d
JOIN (SELECT donorID, MIN(donation_date) AS first_gift
      FROM donations WHERE donation_date IS NOT NULL GROUP BY donorID) x ON x.donorID = d.id
SET d.donor_since = x.first_gift
WHERE d.donor_since IS NULL;

-- Only now give new rows a default, so donors added through any form count as 'new' from today.
ALTER TABLE donors ALTER COLUMN donor_since SET DEFAULT (CURRENT_DATE);

-- Anonymous gifts reference donor 999 (legacy convention). Make sure the row exists even when the
-- database was seeded from an export that never had it.
INSERT IGNORE INTO donors (id, donor_type, `first`, `last`, email, notes)
VALUES (999, 'individual', 'anonymous', 'anonymous', 'anonymous', 'Placeholder for anonymous gifts; do not delete.');
