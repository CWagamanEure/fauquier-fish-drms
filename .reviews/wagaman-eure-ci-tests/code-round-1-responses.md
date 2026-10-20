# Responses to code round 1 (approved; fixes applied before push, so round 2 re-reviews the new tree)

- Migration-edit guard failed open on a git error (`|| true`): fixed. The step now asserts the base SHA
  is non-empty and present (`git cat-file -e`) and lets `set -e` fail on any git error. Verified
  locally with the real base (no output) and a bogus base (step fails with `::error::`).
- "Migrations are safe to re-run" could not fail (the runner skips applied files by checksum): fixed.
  After `make migrate` the step replays every `sql/migrations/[0-9][0-9][0-9]_*.sql` through
  `scripts/db-sql.sh` against the migrated database. Verified locally: all eight replay cleanly on a
  fresh volume (two print "already present" notes by design).
- Process doc said `tests` blocks already: Step 5 and the Enforcement bullet now say it blocks once the
  admin applies `branch-protection.json` after the first green run; After-merging item 1 already said so.
- `tests` is only as strong as the review of `ci.yml` with zero required approvals: recorded in the
  Enforcement bullet with the two ways to close it (one required approval, or CODEOWNERS on
  `.github/workflows/`). Admin's decision; not changed here.
- README "the PR check" wording: reworded to name `record` (advisory) and `tests`.
- Workflow header pointed at a README "Testing" section that does not exist: fixed.
- `actions/checkout@v4` by tag: accepted, noted in the header; `review-gate.yml` uses the same pin and
  the two should move together.
- Guard does not cover `sql/casadb.sql` or the demo seed; runner bind-mount ownership untested; real
  donor records in the init seed (pre-existing); `PROTECTED_PAGES` gap for the pages from PRs #10, #19,
  #21: accepted as follow-ups, the last two raised to the repo admin for a Jira ticket.
