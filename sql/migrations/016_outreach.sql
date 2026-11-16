-- Outreach: social posts and mass mailings, not tied to one donor. One row per post, one row per channel
-- it went out on (a post can go to several), and an optional private photo. dbevents is the campaigns table.
-- MariaDB 10.11 only. Safe to re-run.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS outreach (
  id             INT NOT NULL AUTO_INCREMENT,
  campaign_id    INT NULL,
  posted_on      DATE NOT NULL,
  posted_by      VARCHAR(256) NULL,          -- dbpersons.id when a staff member was picked
  posted_by_name VARCHAR(255) NULL,          -- typed name when someone else posted it
  post_url       VARCHAR(500) NULL,
  caption        TEXT NULL,
  reach          INT UNSIGNED NULL,
  likes          INT UNSIGNED NULL,
  shares         INT UNSIGNED NULL,
  comments       INT UNSIGNED NULL,
  recorded_by    VARCHAR(256) NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_outreach_date (posted_on),
  KEY idx_outreach_campaign (campaign_id),
  KEY idx_outreach_posted_by (posted_by),
  KEY idx_outreach_recorded_by (recorded_by),
  CONSTRAINT fk_outreach_campaign    FOREIGN KEY (campaign_id) REFERENCES dbevents (id)  ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_outreach_posted_by   FOREIGN KEY (posted_by)   REFERENCES dbpersons (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_outreach_recorded_by FOREIGN KEY (recorded_by) REFERENCES dbpersons (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS outreach_channels (
  outreach_id INT NOT NULL,
  channel     ENUM('facebook','instagram','x','linkedin','mass_email','newsletter') NOT NULL,
  PRIMARY KEY (outreach_id, channel),
  CONSTRAINT fk_outreach_channels_outreach FOREIGN KEY (outreach_id) REFERENCES outreach (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS outreach_files (
  id            INT NOT NULL AUTO_INCREMENT,
  outreach_id   INT NOT NULL,
  stored_name   VARCHAR(64) NOT NULL,        -- random name under uploads/outreach/; never the user's file name
  original_name VARCHAR(255) NOT NULL,       -- for display only
  mime_type     VARCHAR(100) NOT NULL,
  size_bytes    INT UNSIGNED NOT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_outreach_files_stored (stored_name),
  KEY idx_outreach_files_outreach (outreach_id),
  CONSTRAINT fk_outreach_files_outreach FOREIGN KEY (outreach_id) REFERENCES outreach (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;