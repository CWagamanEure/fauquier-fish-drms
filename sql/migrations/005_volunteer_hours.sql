-- UF2-27 / UF2-75: volunteer hours logged against a supporter (donor) record.
-- MariaDB 10.11 only. Idempotent.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS volunteer_hours (
  id            INT NOT NULL AUTO_INCREMENT,
  donor_id      INT NOT NULL,
  campaign_id   INT NULL,                                     -- dbevents.id, optional
  activity_date DATE NOT NULL,
  hours         DECIMAL(5,2) NOT NULL,
  job           VARCHAR(255) NULL,                            -- profile tab "Job"
  notes         TEXT NULL,
  recorded_by   VARCHAR(256) NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_vh_donor_date  (donor_id, activity_date),
  KEY idx_vh_campaign    (campaign_id),
  KEY idx_vh_recorded_by (recorded_by),
  CONSTRAINT fk_vh_donor       FOREIGN KEY (donor_id)    REFERENCES donors (id)    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_vh_campaign    FOREIGN KEY (campaign_id) REFERENCES dbevents (id)  ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_vh_recorded_by FOREIGN KEY (recorded_by) REFERENCES dbpersons (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT chk_vh_hours CHECK (hours > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
