-- UF2-116..120: the Log / Edit volunteer hours pages (addHours.php, hours.php) record shift times, a
-- location and two coordinator flags, and keep a change history per entry.
-- MariaDB 10.11 only. Idempotent.
SET NAMES utf8mb4;

ALTER TABLE volunteer_hours
  ADD COLUMN IF NOT EXISTS start_time        TIME         NULL AFTER hours,
  ADD COLUMN IF NOT EXISTS end_time          TIME         NULL AFTER start_time,
  ADD COLUMN IF NOT EXISTS location          VARCHAR(255) NULL AFTER job,
  ADD COLUMN IF NOT EXISTS is_verified       TINYINT(1)   NOT NULL DEFAULT 0 AFTER notes,      -- "Hours verified by coordinator"
  ADD COLUMN IF NOT EXISTS counts_for_letter TINYINT(1)   NOT NULL DEFAULT 0 AFTER is_verified; -- "Counts toward service-hour letter"

-- One row per saved edit: a plain-text summary such as "Hours changed 4 → 4.5; Job changed A → B".
-- The entry's own created_at / recorded_by stand for "Entry logged", so creating an entry writes no row here.
CREATE TABLE IF NOT EXISTS volunteer_hours_changes (
  id                 INT          NOT NULL AUTO_INCREMENT,
  volunteer_hours_id INT          NOT NULL,
  changed_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  changed_by         VARCHAR(256) NULL,
  summary            TEXT         NOT NULL,
  PRIMARY KEY (id),
  KEY idx_vhc_entry      (volunteer_hours_id, changed_at),
  KEY idx_vhc_changed_by (changed_by),
  CONSTRAINT fk_vhc_entry      FOREIGN KEY (volunteer_hours_id) REFERENCES volunteer_hours (id) ON DELETE CASCADE  ON UPDATE CASCADE,
  CONSTRAINT fk_vhc_changed_by FOREIGN KEY (changed_by)         REFERENCES dbpersons (id)      ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
