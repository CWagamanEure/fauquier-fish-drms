# UF2-157: Clean up the databases (drop the dead CASA tables)

Jira: UF2-157, "Clean up the databases" (Sprint 7). The Jira text gives no detail. The owner chose the
narrow option: "Drop the dead tables only". The real-looking donor data and test logins in
`sql/casadb.sql` are a separate team decision (UF2-171) and are not touched here.

## Background

The CASA baseline (`sql/casadb.sql`) created four tables that FISH never uses:

- `associations`: links events, donations, donors, emails and files. Its only reader, `search_donors.php`,
  was deleted in UF2-158.
- `reports`: report metadata. Nothing in the repository reads or writes it. ReportGeneration writes PDF
  files to disk, not to this table.
- `files`: file paths. Only the foreign keys from `associations` and `reports` reference it.
- `emails`: email text. Only the foreign key from `associations` references it.

Survey on the local Docker database (casadb + migrations 001-018 + demo seed): all four tables have 0 rows.
The only foreign keys that touch them are the five on `associations` (to `dbevents`, `donations`, `donors`,
`emails`, `files`) and `reports.filePath` to `files`. Nothing else references them. `git grep` finds no
SQL against any of the four tables in PHP, Python, scripts or migrations. The one exception is a cleanup
line in `sql/seed/demo_fish.sql`: `DELETE FROM associations WHERE eventID IN (... '[demo]%')`.

`get_events_attended_by()` in `database/dbPersons.php` is dead now that UF2-158 removed the volunteer
pages. It has no callers and it interpolates values into SQL. It reads `dbeventpersons`/`dbevents`, which
stay.

## Scope

1. New migration `sql/migrations/019_drop_unused_casa_tables.sql`, MariaDB 10.11:
   - `DROP TABLE IF EXISTS associations;`, then `reports`, then `emails` and `files`. Dropping in this
     order removes the referencing tables before the tables they point to, so no foreign-key check fails.
   - A header comment saying what is dropped and why, and that the drop is safe to re-run.
2. `sql/seed/demo_fish.sql`: remove the `DELETE FROM associations ...` line. Otherwise `make seed-demo`
   fails once the table is gone.
3. `database/dbPersons.php`: delete only the `get_events_attended_by()` function, which has no callers.
   The file-scope `date_default_timezone_set("America/New_York");` above it stays. Delete
   `include/time.php`, whose only function (`calculateHourDuration()`) was used only by that function.
4. `sql/README.md`: one line noting that migration 019 drops the four unused CASA tables.

## Out of scope

- **`outreach.post_url`.** The earlier plan was to drop this column too. That cannot be done safely.
  CI replays every migration against the migrated database, and migration 017 (applied, checksum-locked)
  runs `UPDATE ... SET c.post_url = o.post_url ... WHERE o.post_url IS NOT NULL`. Once the column is gone,
  that replay fails with "Unknown column". The column stays: nullable, all values NULL, and documented
  as unused in 017's header. Dropping it would first need a change to the replay rule, which is a
  separate decision.
- Other CASA tables that FISH still uses or that the kept legacy pages read (`dbevents`,
  `dbeventpersons`, `dbpersons` and so on). `donations.eventID` still references `dbevents`, which
  campaigns use (migration 009).
- PII in `sql/casadb.sql` (UF2-171). The baseline dump is not edited, because Docker imports it on a
  fresh volume and migration 019 then drops the tables.

## Acceptance criteria

1. After `make migrate`, `information_schema.TABLES` has no `associations`, `reports`, `files` or
   `emails` in the app database, and every other table is unchanged.
2. Replaying `sql/migrations/[0-9][0-9][0-9]_*.sql` in order against the migrated database gives no
   errors (CI's re-run step), and `make seed-demo` succeeds twice in a row.
3. The two grep commands in verification step 5 return nothing.
4. All CI test suites still pass.

## Verification plan

1. `make migrate`, then query `information_schema.TABLES` for the four names (expect none). Compare the
   table list before and after (expect only those four gone).
2. Run the CI replay loop: `for f in sql/migrations/[0-9][0-9][0-9]_*.sql; do scripts/db-sql.sh < "$f"; done`.
3. `make seed-demo` twice.
4. Fresh volume: not run locally (it would wipe the developer's database). CI builds a fresh volume from
   `casadb.sql` + every migration + the demo seed on every run, so the PR's tests job is this check.
5. `php -l` on every tracked .php file. AC3, both expected to print nothing:
   `git grep -n -i -E '(from|into|join|update|table|references)[[:space:]]+`?(associations|reports|files|emails)`?([^a-z_]|$)' -- ':!sql/casadb.sql' ':!sql/migrations/019_*' ':!.reviews' ':!*.md'`
   and `git grep -n -E 'get_events_attended_by|calculateHourDuration|include/time\.php' -- ':!.reviews'`.
6. `make test-auth test-donors test-users test-donations test-hours test-campaigns test-outreach
   test-comms test-exports test-gate test-deploy-docker` (the local stand-in for CI's `test-deploy`, which
   needs GNU rsync). AC1 is checked by hand in step 1 and recorded in the PR; no automated test is added.

## Risks

- **Production (SiteGround, MySQL 8)** is not migrated by the deploy. The owner ports and applies 019
  by hand and lists it in `sql/production-applied.txt`; until then the PR check warns. `DROP TABLE IF EXISTS`
  works unchanged on MySQL 8. Per `docs/deploy-siteground.md`, a destructive step is applied just before
  the release merge. The code live on `main` before UF2-158 is released still has `search_donors.php`,
  which reads `associations`. So the drop goes out with, or after, the release that contains UF2-158.
  Before dropping, the owner checks that the four tables are empty on production
  (`SELECT COUNT(*)` on each). If any has rows, stop and ask the team.
- A developer's local database with rows in these tables loses them. They hold only old CASA data.

## After merge

- Teammates: run `make dev` or `make migrate` to apply 019, and rebase open branches onto `dev` before
  `make seed-demo` (an older seed still deletes from `associations`). The same note goes in the PR.

- The owner ports 019 to MySQL 8 (the same SQL), checks the row counts, applies it at release time and
  adds it to `sql/production-applied.txt`. The ported SQL goes in the PR description.

## Responses to round 1

Approved: 0 critical, 0 high, 3 medium, 7 low.

- **AC3 could not be met because review records name the function (medium), and the grep had no
  defined command (low, three findings).** Fixed. AC3 now points at two exact commands in verification step 5,
  which exclude `.reviews`, markdown, the baseline dump and 019.
- **`include/time.php` becomes dead (medium).** Fixed: it is deleted in scope item 3 and covered by the
  AC3 grep.
- **Teammates' older branches will break on `make seed-demo` (medium).** Fixed: a teammate line under
  After merge, also going in the PR.
- **Keep the timezone call above the function (low, two findings).** Fixed: scope item 3 says it stays.
  The code review will check this.
- **The fresh-volume step wipes the local database, and `test-deploy` vs `test-deploy-docker` (low).**
  Fixed: step 4 relies on CI's fresh volume, and step 6 explains `test-deploy-docker`.
- **No automated check that the tables stay dropped (low).** Accepted as is: checked by hand and recorded
  in the PR.
