# Responses to round 1

Round 1: four auditors, no critical findings; three highs, eight mediums, eight lows. The fixes were folded
into the commit each belongs to (Reports page, imports backend) so the branch keeps one commit per part;
nothing had been pushed.

Highs (all fixed):
- .xlsx reader: no limit on unzipped size or cell coordinates (security): fixed. Each part's unzipped size
  is checked with statName() and capped at 20 MB (getFromName length too); a cell below row 2001 refuses the
  file before anything is built; columns past 60 and malformed column letters are ignored. Checked with a
  1-cell file at A999999999 and a 30 MB shared-strings zip bomb: both refused in about 0.1 s.
- Batch Import not atomic / double submit (data; security raised it as medium): fixed. Import claims the
  batch with UPDATE ... SET status='importing' WHERE status='checked' (affected_rows must be 1); each row is
  claimed the same way (status -> 'saving') before its record is created, also for row actions. Checked by
  firing three Import POSTs at once: one gift created. session_write_close() was removed so the session (and
  README's sign-out note) holds while the rows are saved.
- Docker image lacks the zip extension (verification): fixed. Dockerfile installs libzip-dev and the zip
  extension; README says XAMPP needs extension=zip (CSV works without).

Mediums and lows:
- Record creation and bookkeeping in separate steps: fixed as far as needed. The row is claimed before the
  create; a create that throws marks the row 'error'; the donor notes/donor_since follow-up and the
  rows_added counter are caught and logged so a created record is always marked imported.
- Volunteer rows stored the donor id as record_id: fixed, record_id is NULL for volunteer hours (documented
  in the migration and sql/README).
- Anonymous gifts flagged as duplicates of each other: fixed. No duplicate check for the anonymous donor
  unless a cash gift has a check number. Checked: three anonymous $20 gifts on one day are all ready.
- Preview-stage Fix/Force lost the within-file and volunteer duplicate checks: fixed. Each row's duplicate
  key is stored (import_rows.dup_key); every check (upload, Fix, Force, Import) uses import_check_full() with
  the other rows' keys. Checked: Fix on an unchanged in-file duplicate keeps it a duplicate.
- Recent imports listed other types' batches: fixed, filtered with import_can() like Needs review.
- Two-digit years always 20xx: fixed (pivot on the current year); Donor since cannot be in the future.
- reports.php fatal on array query parameters: fixed with fish_str().
- sql/README.md missing 018: added. README: new "Data imports" section and the session note corrected.
- No automated imports suite (medium): accepted for this branch; the scripted browser run is listed in the
  PR and a follow-up ticket is proposed for tests/imports_integration.py.
- Post-merge steps (run migration 018; port 018 to production and production-applied.txt before release):
  accepted, listed in the PR.
- Known issue (new donors in gift/hours/comm rows): accepted, documented in README and the PR.
- Donor notes/donor_since written after donor_create (low): accepted; donor_create has no such fields and
  the follow-up now cannot leave the row importable twice.
