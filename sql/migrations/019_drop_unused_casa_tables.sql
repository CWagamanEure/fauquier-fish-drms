-- Drop four tables inherited from the CASA baseline (sql/casadb.sql) that FISH never uses (UF2-157).
-- associations (its only reader, search_donors.php, was removed in UF2-158), reports, emails and files:
-- no code reads or writes them. Referencing tables go first, so no foreign-key check fails:
-- associations -> emails, files; reports -> files. IF EXISTS makes this safe to re-run.
-- outreach.post_url stays: migration 017 still names it and CI replays every migration.
-- MariaDB 10.11; the same statements run unchanged on MySQL 8.
DROP TABLE IF EXISTS associations;
DROP TABLE IF EXISTS reports;
DROP TABLE IF EXISTS emails;
DROP TABLE IF EXISTS files;
