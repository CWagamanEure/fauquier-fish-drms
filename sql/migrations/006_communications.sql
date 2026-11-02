-- UF2-11 / UF2-21 / UF2-23 / UF2-27: communication log per supporter.
-- MariaDB 10.11 only. Idempotent.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS communications (
  id               INT NOT NULL AUTO_INCREMENT,
  donor_id         INT NOT NULL,
  campaign_id      INT NULL,                                 -- dbevents.id, optional
  occurred_at      DATETIME NOT NULL,
  comm_type        ENUM('email','phone','mail','text','in_person','other') NOT NULL,
  direction        ENUM('outbound','inbound') NOT NULL DEFAULT 'outbound',
  subject          VARCHAR(255) NULL,
  summary          TEXT NULL,                                -- content summary (UF2-21)
  person_contacted VARCHAR(255) NULL,                        -- who was reached (UF2-21)
  status           ENUM('logged','sent','delivered','opened','replied','bounced','failed') NOT NULL DEFAULT 'logged',
  recorded_by      VARCHAR(256) NULL,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_comm_donor_date  (donor_id, occurred_at),
  KEY idx_comm_date        (occurred_at),
  KEY idx_comm_campaign    (campaign_id),
  KEY idx_comm_recorded_by (recorded_by),
  CONSTRAINT fk_comm_donor       FOREIGN KEY (donor_id)    REFERENCES donors (id)    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_comm_campaign    FOREIGN KEY (campaign_id) REFERENCES dbevents (id)  ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_comm_recorded_by FOREIGN KEY (recorded_by) REFERENCES dbpersons (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
