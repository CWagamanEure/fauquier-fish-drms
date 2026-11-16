# Ticket: Record migrations 015-018 as applied on production

Branch: `wagaman-eure-release-sprint5` (off `origin/dev` at 6922cd4, PR targets `dev`)

## Jira keys

None. Requested directly by the repository owner on 2026-11-15 so the Sprint 5 features (PRs #48, #49,
#50) can be released to the live site for the sprint review.

## Problem

`origin/dev` holds four migrations that `sql/production-applied.txt` does not list:
`015_donor_foundation.sql`, `016_outreach.sql`, `017_outreach_channel_links.sql`,
`018_import_batches.sql`. `scripts/deploy-schema-guard.sh` therefore refuses to deploy `main` once `dev`
is merged into it (docs/deploy-siteground.md, "Releasing a migration").

The owner approved applying them, and they were applied to the SiteGround database on 2026-11-16 02:03
server time, over SSH with the owner's key, as a hand port to MySQL 8.

Facts checked on the server (MySQL 8.4.11):

- Before: `donors.donor_type` was `enum('individual','organization')`; none of `outreach`,
  `outreach_channels`, `outreach_files`, `import_batches`, `import_rows` existed; 19 tables; 319 donors.
- A full `mysqldump` was taken first:
  `~/db-backups/fish-before-sprint5-20261116-020305.sql` (outside `public_html`, mode 600).
- After: `donors.donor_type` is `enum('individual','organization','foundation')`; the five tables exist;
  `outreach_channels.post_url` exists; `donors` still has 319 rows; `donor_summary` returns 319 rows and
  `WHERE status <> 'active' AND is_anonymous = 0` runs without a collation error (302 rows).
- The login page of the live site answered 200 after the change. The live code (main at 2e32629) does
  not read the new tables or the new enum value.

The port differs from the MariaDB files in these ways only:

- `SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci` before recreating `donor_summary` (the same collation
  the production views were created with on 2026-11-11).
- 017: `ADD COLUMN IF NOT EXISTS` (not MySQL syntax) became a plain `ADD COLUMN`. Its two `UPDATE`
  statements were left out: `outreach` was created empty in the same run, so they had no rows to change.
- 018: the three trailing `ALTER TABLE` statements were left out. They only repair databases that ran an
  earlier draft of the file; production never did, and `CREATE TABLE` already gives the final shape.

Before it was run on production, the ported script was run against a `mysql:8.0` container loaded with
the schema produced by `sql/casadb.sql` plus migrations 001-014 (dumped from MariaDB and converted with
the steps recorded for the first deploy). It applied without error, and inserts into the new tables and a
foundation donor row worked. That container was 8.0, production is 8.4.

## Scope

In:

- `sql/production-applied.txt`: add the four filenames, and extend the header comment with the date
  015-018 were applied.

Out:

- Any change to the migration files, application code, the deploy scripts or the workflow.
- Committing the ported SQL to the repository. Per docs/deploy-siteground.md it goes in the pull request
  description.
- Merging `dev` into `main`. That is a separate step the owner confirms.

## Approach

Append the four filenames to `sql/production-applied.txt` in order and add one comment line:
`# 015-018 were applied by hand (MySQL 8 port) on 2026-11-16; see the pull request for the SQL.`

## Acceptance criteria

- `sql/production-applied.txt` lists every file in `sql/migrations/` (001-018), one per line.
- `scripts/deploy-schema-guard.sh` exits 0 on this branch.
- No other tracked file changes apart from this branch's `.reviews/` records.

## Risks

- The guard trusts this file. The facts above are the evidence that the migrations are really applied.
- Once this is merged and `dev` is merged to `main`, the deploy publishes PRs #48-#50 to the live site.
  Those were reviewed in their own pull requests; this ticket does not re-review them.
- The .xlsx import path needs PHP's zip extension. `php -m` over SSH lists `zip`; the web PHP was not
  checked and can differ. CSV import does not need it.

## Verification plan

1. `scripts/deploy-schema-guard.sh` exits 0 and prints "every migration is recorded as applied on
   production" on this branch (it fails on `origin/dev` before the change).
2. `git diff origin/dev --stat` shows only `sql/production-applied.txt` and `.reviews/` files.
3. The pull request's `tests` check passes (it runs `make test-deploy`, which covers the schema guard).
   `make test-deploy` is not run locally: it needs GNU rsync or Docker.
4. Re-run the read-only schema check on the server immediately before pushing and confirm the "After"
   facts still hold.

## Responses to round 1

Round 1 approved: no critical or high findings, 7 medium, 10 low. Nothing above was edited; the changes
are recorded here.

- Authorization and who ran it (security, verification; medium): fixed here. The owner asked for the
  script to be run and answered "go" to a description of the backup and the script. Claude Code ran it,
  over SSH with the owner's key, not through phpMyAdmin, and before this ticket gate ran. The gate
  therefore reviews the record, not the action. The owner confirms in the pull request.
- "After" facts prove existence, not shape (data, correctness, verification; medium): fixed. A second
  read-only check at 2026-11-16 02:07 UTC ran `SHOW CREATE TABLE` for the five tables. Every column, key
  and foreign key matches the ported script (the differences are quoting and spacing only), `donor_type`
  has the three values, and the stored `donor_summary` definition contains `foundation`. The output goes
  in the pull request.
- Ported SQL not available to the review (data; medium): fixed. The script goes in the pull request
  description, and the code-gate reviewers are pointed at the shape check above.
- Sprint 5 code was never run against MySQL 8 (correctness; medium): accepted. Not run: Docker is off on
  the owner's machine at their request. The auditors read the new queries and found no MariaDB-only
  syntax. The owner clicks through the new pages on the live site after the release (checklist in the
  pull request).
- Verification step 3 (verification; medium): fixed. Step 1 is the proof of the criterion. The CI run of
  `make test-deploy` checks the real tree in warning mode only. `make test-deploy-docker` is not run
  locally, for the reason above.
- Date and timezone (data, correctness; low): fixed. The server clock is UTC; the comment line says
  `2026-11-16 02:03 UTC`.
- `schema_migrations` on production (data; low): fixed in the comment. The table exists on production
  and was not written; the header says this file is the record for production.
- What goes in the public pull request (security; low): fixed. SQL statements and schema-check output
  only: no host, account, database name or user, connection command or donor rows.
- Backup retention (security; low): accepted with a date. `~/db-backups` is mode 700 and outside
  `public_html`; no copy was taken off the server. The owner removes it once the release is confirmed.
- Staging (verification; low): fixed. Files are staged by explicit path.
- Post-release notes (verification; low): fixed. Teammates need no action; the checklist is in the pull
  request.
- Stale-import recovery, upload probe (correctness, security; low): out of scope, follow-ups.
