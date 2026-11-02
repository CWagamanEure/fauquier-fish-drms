# Responses to round 1 (code)

Round 1 reviewed commit 80b4354. All four auditors approved, with no critical or high finding; the
security auditor reported no findings. The fixes are in commit e8bd82d, and every check was re-run
afterwards.

- **Untouched edit rewrites stored text with surrounding whitespace** (data medium; correctness low):
  fixed. `hours_form_from_row()` now trims job and location, and converts CRLF to LF before trimming
  notes, the same as the post side. `hours_job_options()` returns `TRIM(job)`, so a stored off-list job
  with a trailing space stays selectable. The suite adds a "padded" fixture (job with a trailing space,
  location with a leading space, notes ending in a newline) and asserts that an untouched save writes no
  change row and leaves the row as it was. A mutation check confirmed that this test fails when the
  notes trim is removed.
- **Stored hours of 100 or more cannot be saved untouched** (data low; correctness low): fixed. An
  untouched value is compared with the stored DECIMAL itself, `round(hours × 100)`, not re-parsed through
  the form pattern. The suite adds a 120-hour fixture: an untouched save writes nothing, and ticking
  "verified" saves with the change row "Marked verified".
- **addHours.php fatal on a DB fault with extra volunteers posted** (correctness medium; verification
  low): fixed. Both error paths, the load fault and the exception during validation, reset
  `$form['extra']`, so the form re-renders with its error instead of a TypeError.
- **Campaign id not normalised** (correctness low): fixed. A valid campaign is stored as
  `(string)(int)`, and the suite posts "07"-style ids to check that no change row is written.
- **Read-only notice wrong for anonymous-donor entries** (correctness low): fixed. `hours.php` passes
  `readonly_notice`, which reads "Entries of the anonymous donor cannot be changed." for donor 999, and
  the suite asserts it.
- **Side summary values never asserted** (verification medium): fixed. A new `summary` fixture computes
  the hours, shifts and last shift for the year independently, with PHP's calendar year. The test checks
  all four facts (Hours this year 7, Shifts this year 2, Last shift, Usual role "Pantry sorting", the
  tie-break by the later id) and that the panel comes back on the add page after a validation error.
- **"Entry logged" with no name, and tab order** (verification low): fixed. The last timeline item on the
  legacy entry is "Entry logged" with no "·" name. On the tab, today's row comes before a row dated three
  days ago.
- **Verification plan step 3 not done** (verification medium): done.
  - The XAMPP `fishdb` was backed up to `~/Desktop/fishdb-backups/fishdb-before-010-2026-10-26.sql`.
  - 010 was applied through a copy of `db-migrate.sh` pointed at XAMPP, and recorded in
    `schema_migrations`.
  - The htdocs copy was switched to this branch at e8bd82d.
  - A headless Chrome walk on `http://localhost/fauquier-fish-drms` (Apache) passed six checks: Log hours
    from the profile, auto-fill 4.67, save back to the Volunteer tab, edit to 4.5 with "verified"
    (history "Hours changed 4.67 → 4.5; Marked verified"), and delete with its notice. The walk's row was
    deleted, and the PHP log shows the delete trace line.

The suite also resets its shared fixtures after every test, so test order no longer matters. That
replaces the hand-written restore at the end of the edit test, which could not put back an off-list job.

Checks re-run on e8bd82d:
- `php -l` on every tracked PHP file
- the auth suite: only the six Apache-only direct-file subtests fail, as before
- donors, users, donations, and volunteer hours (13 tests)
- `tests/review_gate.sh` (18 passed)
- the headless Chrome script check against `php -S` (13 checks)
