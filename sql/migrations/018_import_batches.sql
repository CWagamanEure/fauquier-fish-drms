-- 018: Data & Imports (dataImports.php, importHandler.php, importPreview.php).
-- One import_batches row per uploaded file, one import_rows row per data row in it. Rows are checked when
-- the file is uploaded (status ready / error / duplicate); "Import" creates the records for the ready rows
-- and leaves the rest in Needs review until someone fixes, imports anyway or skips them.
-- 'importing' / 'saving' are claimed with a conditional UPDATE so a double-clicked Import or row action
-- cannot create records twice. Additive and idempotent: safe to run again.

CREATE TABLE IF NOT EXISTS import_batches (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  import_type   ENUM('donors','cash','noncash','volunteer','comms') NOT NULL,
  file_name     VARCHAR(255) NOT NULL,
  status        ENUM('checked','importing','imported','cancelled') NOT NULL DEFAULT 'checked',
  rows_total    INT UNSIGNED NOT NULL DEFAULT 0,
  rows_added    INT UNSIGNED NOT NULL DEFAULT 0,
  uploaded_by   VARCHAR(256) NOT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  imported_at   DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_import_batches_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS import_rows (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  batch_id      INT UNSIGNED NOT NULL,
  sheet_row     INT UNSIGNED NOT NULL,             -- the spreadsheet row (row 1 is the headings)
  data          MEDIUMTEXT NOT NULL,               -- JSON: template heading => cell text
  status        ENUM('ready','saving','error','duplicate','imported','skipped') NOT NULL,
  problem       TEXT NULL,                         -- why the row was not ready (errors joined with "; ")
  match_id      INT NULL,                          -- the existing record a duplicate matches
  dup_key       VARCHAR(512) NULL,                 -- what makes the row the same as another row of the file
  record_id     INT NULL,                          -- the record created from this row (NULL for volunteer hours: hours_create returns no id)
  updated_by    VARCHAR(256) NULL,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_import_rows_batch (batch_id, status),
  KEY idx_import_rows_status (status),
  CONSTRAINT fk_import_rows_batch FOREIGN KEY (batch_id) REFERENCES import_batches (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Databases that ran an earlier draft of this file (numbered 015 on a feature branch) already have the
-- tables; bring them to the shape above. Each statement is a no-op when already applied.
ALTER TABLE import_batches MODIFY status ENUM('checked','importing','imported','cancelled') NOT NULL DEFAULT 'checked';
ALTER TABLE import_rows MODIFY status ENUM('ready','saving','error','duplicate','imported','skipped') NOT NULL;
ALTER TABLE import_rows ADD COLUMN IF NOT EXISTS dup_key VARCHAR(512) NULL AFTER match_id;
