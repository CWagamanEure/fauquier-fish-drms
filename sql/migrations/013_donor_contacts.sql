-- UF2-129: contact people for organization (later foundation) donors. One donor has many contacts, each with
-- an optional name (UF2-127: sometimes no name is ever given), title, email and phone, and at most one main
-- contact. Replaces donors.org_contact, which stays until UF2-103 and is copied in below. The form fields come
-- from include/donorForm.php; saving them is UF2-130 (rules in sql/README.md). MariaDB 10.11 only. Safe to
-- re-run (CI replays every migration).
SET NAMES utf8mb4;

-- The donor FK is ON UPDATE RESTRICT, unlike the other donor FKs: MariaDB refuses a stored generated column or
-- a CHECK on a column whose FK cascades on update (ERROR 1901). Donor ids are never renumbered.
CREATE TABLE IF NOT EXISTS donor_contacts (
  id               INT(11)      NOT NULL AUTO_INCREMENT,
  donor_id         INT(11)      NOT NULL,
  name             VARCHAR(255) NULL,
  title            VARCHAR(100) NULL,
  email            VARCHAR(100) NULL,
  phone            VARCHAR(30)  NULL,
  is_primary       TINYINT(1)   NOT NULL DEFAULT 0,
  sort_order       SMALLINT     NOT NULL DEFAULT 0,
  created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by       VARCHAR(256) NULL,
  -- One main contact per donor: NULL for other rows, and a UNIQUE index ignores NULLs.
  primary_donor_id INT(11) AS (IF(is_primary = 1, donor_id, NULL)) PERSISTENT,
  PRIMARY KEY (id),
  UNIQUE KEY uq_donor_contacts_primary (primary_donor_id),
  KEY idx_donor_contacts_donor (donor_id, sort_order),
  KEY idx_donor_contacts_updated_by (updated_by),
  CONSTRAINT fk_donor_contacts_donor FOREIGN KEY (donor_id) REFERENCES donors (id)
    ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT fk_donor_contacts_updated_by FOREIGN KEY (updated_by) REFERENCES dbpersons (id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT chk_donor_contacts_primary CHECK (is_primary IN (0, 1)),
  CONSTRAINT chk_donor_contacts_not_anonymous CHECK (donor_id <> 999),
  CONSTRAINT chk_donor_contacts_not_blank CHECK (
    NULLIF(TRIM(name), '') IS NOT NULL OR NULLIF(TRIM(title), '') IS NOT NULL
    OR NULLIF(TRIM(email), '') IS NOT NULL OR NULLIF(TRIM(phone), '') IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Copy each organization's legacy org_contact in as its main contact. Only donors with no contacts at all, so a
-- replay inserts nothing; UF2-130 clears org_contact when it saves contacts, so a deleted contact never returns.
INSERT INTO donor_contacts (donor_id, name, is_primary, sort_order)
SELECT d.id, TRIM(d.org_contact), 1, 0
  FROM donors d
 WHERE d.donor_type = 'organization' AND d.id <> 999 AND TRIM(COALESCE(d.org_contact, '')) <> ''
   AND NOT EXISTS (SELECT 1 FROM donor_contacts c WHERE c.donor_id = d.id);
