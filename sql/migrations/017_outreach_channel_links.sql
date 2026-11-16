-- Outreach: one link per channel (a post on Facebook and Instagram has two different links). The old single
-- outreach.post_url is copied onto its channels once and then cleared; nothing reads or writes it any more.
-- MariaDB 10.11 only. Safe to re-run.
SET NAMES utf8mb4;

ALTER TABLE outreach_channels ADD COLUMN IF NOT EXISTS post_url VARCHAR(500) NULL;

UPDATE outreach_channels c JOIN outreach o ON o.id = c.outreach_id
   SET c.post_url = o.post_url
 WHERE c.post_url IS NULL AND o.post_url IS NOT NULL AND o.post_url <> '';

UPDATE outreach SET post_url = NULL
 WHERE post_url IS NOT NULL AND EXISTS (SELECT 1 FROM outreach_channels x WHERE x.outreach_id = outreach.id);