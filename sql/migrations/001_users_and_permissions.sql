-- UF2-7 / UF2-15 / UF2-83..86: user accounts with names, email, role, status and per-area permissions.
-- MariaDB 10.11 only (ADD COLUMN IF NOT EXISTS, CREATE OR REPLACE VIEW).
-- Idempotent: safe to re-run against a database that already has it applied.
SET NAMES utf8mb4;

ALTER TABLE dbpersons
  ADD COLUMN IF NOT EXISTS first_name VARCHAR(100) NULL,
  ADD COLUMN IF NOT EXISTS last_name  VARCHAR(100) NULL,
  ADD COLUMN IF NOT EXISTS email      VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS role       ENUM('admin','manager','staff','viewer') NOT NULL DEFAULT 'staff',
  ADD COLUMN IF NOT EXISTS status     ENUM('active','inactive') NOT NULL DEFAULT 'active',
  ADD COLUMN IF NOT EXISTS created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

ALTER TABLE dbpersons
  ADD UNIQUE INDEX IF NOT EXISTS uq_dbpersons_email (email),
  ADD INDEX IF NOT EXISTS idx_dbpersons_role_status (role, status);

-- Backfill names from the legacy "first last" column: first token -> first_name, rest -> last_name.
UPDATE dbpersons
SET first_name = CASE WHEN name IS NULL OR TRIM(name) = '' THEN NULL
                      ELSE SUBSTRING_INDEX(TRIM(name), ' ', 1) END,
    last_name  = CASE WHEN name IS NULL OR LOCATE(' ', TRIM(name)) = 0 THEN NULL
                      ELSE TRIM(SUBSTRING(TRIM(name), LOCATE(' ', TRIM(name)) + 1)) END
WHERE first_name IS NULL AND last_name IS NULL;

-- Backfill role from the legacy accessLevel. Promote-only so re-running never demotes an admin-set role.
-- 3 -> admin, 2 -> manager, 1/0/NULL -> staff (column default). vmsroot is always admin.
UPDATE dbpersons
SET role = CASE WHEN id = 'vmsroot' OR accessLevel >= 3 THEN 'admin'
                WHEN accessLevel = 2 THEN 'manager'
                ELSE 'staff' END
WHERE role = 'staff' AND (id = 'vmsroot' OR accessLevel >= 2);

-- role is now the source of truth; normalize the legacy 0/NULL levels so the existing
-- require_authentication() gate works without a login-time migration.
UPDATE dbpersons
SET accessLevel = CASE role WHEN 'admin' THEN 3 WHEN 'manager' THEN 2 ELSE 1 END
WHERE accessLevel IS NULL OR accessLevel < 1;

CREATE TABLE IF NOT EXISTS user_permissions (
  id         INT NOT NULL AUTO_INCREMENT,
  user_id    VARCHAR(256) NOT NULL,
  area       ENUM('donors','donations','campaigns','communications','reports','data_import','users') NOT NULL,
  can_view   TINYINT(1) NOT NULL DEFAULT 0,
  can_edit   TINYINT(1) NOT NULL DEFAULT 0,
  can_delete TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_permissions_user_area (user_id, area),
  CONSTRAINT fk_user_permissions_user FOREIGN KEY (user_id) REFERENCES dbpersons (id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Role presets: the single source of truth for default permissions. Application code
-- re-applies these rows when a user's role changes; admins can then edit rows per user.
-- data_import never grants delete (UF2-7: view/edit only).
CREATE OR REPLACE VIEW role_permission_presets AS
          SELECT 'admin'   AS role, 'donors'         AS area, 1 AS can_view, 1 AS can_edit, 1 AS can_delete
UNION ALL SELECT 'admin',   'donations',      1, 1, 1
UNION ALL SELECT 'admin',   'campaigns',      1, 1, 1
UNION ALL SELECT 'admin',   'communications', 1, 1, 1
UNION ALL SELECT 'admin',   'reports',        1, 1, 1
UNION ALL SELECT 'admin',   'data_import',    1, 1, 0
UNION ALL SELECT 'admin',   'users',          1, 1, 1
UNION ALL SELECT 'manager', 'donors',         1, 1, 1
UNION ALL SELECT 'manager', 'donations',      1, 1, 1
UNION ALL SELECT 'manager', 'campaigns',      1, 1, 1
UNION ALL SELECT 'manager', 'communications', 1, 1, 1
UNION ALL SELECT 'manager', 'reports',        1, 1, 1
UNION ALL SELECT 'manager', 'data_import',    1, 1, 0
UNION ALL SELECT 'manager', 'users',          1, 0, 0
UNION ALL SELECT 'staff',   'donors',         1, 1, 0
UNION ALL SELECT 'staff',   'donations',      1, 1, 0
UNION ALL SELECT 'staff',   'campaigns',      1, 0, 0
UNION ALL SELECT 'staff',   'communications', 1, 1, 0
UNION ALL SELECT 'staff',   'reports',        1, 0, 0
UNION ALL SELECT 'staff',   'data_import',    0, 0, 0
UNION ALL SELECT 'staff',   'users',          0, 0, 0
UNION ALL SELECT 'viewer',  'donors',         1, 0, 0
UNION ALL SELECT 'viewer',  'donations',      1, 0, 0
UNION ALL SELECT 'viewer',  'campaigns',      1, 0, 0
UNION ALL SELECT 'viewer',  'communications', 1, 0, 0
UNION ALL SELECT 'viewer',  'reports',        1, 0, 0
UNION ALL SELECT 'viewer',  'data_import',    0, 0, 0
UNION ALL SELECT 'viewer',  'users',          0, 0, 0;

-- Seed one row per area for every existing user from their role. IGNORE keeps manual edits.
INSERT IGNORE INTO user_permissions (user_id, area, can_view, can_edit, can_delete)
SELECT p.id, r.area, r.can_view, r.can_edit, r.can_delete
FROM dbpersons p JOIN role_permission_presets r ON r.role = p.role;
