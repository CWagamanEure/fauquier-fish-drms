# Ticket: PR #28 follow-up: tests match the intentional deactivate removal; CodeRabbit's two migration findings

Branch: `wagaman-eure-pr28-fixes`, based on PR #28's head (`Andrews-donorBackend` at a4d65b7). Delivered as a
pull request into `Andrews-donorBackend`, for James to review and merge (Cory, 2026-10-26: "PR into his branch").
The code this ticket is about is checked out in the worktree `/Users/corywagamaneure/projects/fish-pr28-fixes`
(PR #28 head); the main checkout is on a different branch.

Jira: none. Cory (repo admin), 2026-10-26: "yeah he rmo,ved deactivate intentionally, go fix james and my brittle
test" (the brittle-test half is `.reviews/wagaman-eure-donor-test-lookup/ticket.md` on `dev`), then "ok and can we
fix treh coderabbit things wanted on james page?", meaning the two "Major" CodeRabbit comments on PR #28, both on
`sql/migrations/009_campaigns.sql`:

- Line 30, "Backfill legacy dates and update remaining date consumers before dropping `dbevents.date`":
  009 runs `` ALTER TABLE dbevents DROP COLUMN IF EXISTS `date` ``, losing any value only kept there, while
  `database/dbEvents.php` still reads `date`.
- Line 237, "Move the demo seed data out of the schema migration".

## Problem

PR #28 fails `make test-donors` with eight failures. Two come from commit 9c3f8a6 ("Sprint #1 fixs, removing
mark inactive/active card and tags."), which removes the deactivate/reactivate action and its UI from
`updateDonor.php` (and the Inactive badge from `donorProfile.php`). Cory confirms the removal is intended, so
the tests still asserting the feature are out of date:

- `test_add_update_deactivate_reactivate` posts `action=deactivate` and expects `303 donor.php`, then
  reactivates.
- `test_permissions_gate_writes_by_role_and_row` expects staff posting `action=deactivate` to get `403`.

The other six come from the demo data in `sql/migrations/009_campaigns.sql` section 3, which inserts demo
donors, campaigns, gifts, shifts and communications. Two of the donors share display names with seed donors
("Maria Lopez", "Grace Community Church"), and the donor tests look seed donors up by name. That lookup is
fixed on `dev` by branch `wagaman-eure-donor-test-lookup`. Separately, demo rows must not live in a
migration: `make migrate` runs on every database, including a real one, and the file's own comment says
"Delete this whole section before a real deployment". `sql/README.md` and `sql/seed/demo_fish.sql`
("Optional FISH demo data for local development (make seed-demo). Never load this on a real database")
are where demo rows go.

Findings for the `date` comment (checked 2026-10-26 on a4d65b7): the three legacy rows in `casadb.sql`
(ids 3, 4, 8) have `date` NULL and startDate/endDate set, so a backfill would copy nothing there, but a real
database may differ. The column is read only by legacy event code: `database/dbEvents.php` (`ORDER BY date`,
`$result_row['date']`, `where date = ...`) and the legacy pages eventSearch.php, setTimes.php, eventApproved.php and
volunteerReport.php (the last is still linked from viewProfile.php), legacy event code that the new UI does not link to (those pages can still be
reached by URL). A backfill `UPDATE ... date` would also have to be guarded, because CI re-runs every
migration after the column is gone.

## Scope

In:
- `tests/donors_integration.py`, for the removed feature only:
  - `test_add_update_deactivate_reactivate` is renamed `test_add_and_update`. Its deactivate/reactivate block
    is replaced by: the edit page offers neither `value="deactivate"` nor `value="reactivate"`, and after a
    manager POST of `{csrf_token, action=deactivate}` the donor row read with `donor_read` is unchanged
    (every column compared to the row read before the POST, except `updated_at`), so `is_active` stays 1,
    `deactivated_*` stay NULL and no field is blanked. The add, validation, edit and anonymous-donor (id 999)
    checks stay as they are.
  - `test_permissions_gate_writes_by_role_and_row`: the staff `action=deactivate` -> 403 line becomes the same
    "row unchanged" check for a staff POST. Every other permission check stays, including the two
    `assertNotIn('value="deactivate"', ...)` checks, which still hold.
  - Unchanged: `test_anonymous_requests_redirect_and_posts_need_the_token` posts `{"action": "deactivate"}`
    with no CSRF token and expects 403. That 403 comes from the CSRF guard, not from deactivate, and still
    holds on #28.
- `sql/migrations/009_campaigns.sql`, CodeRabbit line 30: remove the `` ALTER TABLE dbevents DROP COLUMN IF EXISTS
  `date` `` statement and rewrite its comment and the section-1 heading to say `date` is kept (nullable
  `CHAR(10)`, unused by the campaign pages, and `dbCampaigns.php` inserts with an explicit column list) until
  `dbEvents.php` and the legacy event pages that read it (eventSearch, setTimes, eventApproved,
  volunteerReport) are removed. No backfill: nothing is dropped, so nothing is lost.
- `sql/migrations/009_campaigns.sql`, CodeRabbit line 237: section 3 (3a-3f) and the commented "REMOVE DEMO
  DATA" block are removed, and the header line announcing a demo section is updated. Sections 1-2 (columns,
  foreign keys, `campaign_summary` view) are otherwise unchanged.
- `sql/seed/demo_fish.sql`: the removed section is appended as a new final section, so it runs after the
  seed's own campaigns and donors. It is changed only as needed to follow the seed's "every run deletes and
  rebuilds" rule (round 1 found that an unchanged copy collides with the seed's own "Giving Tuesday 2025"
  and a second run leaves 7 gifts unlinked):
  - Campaign descriptions get the seed's `[demo] ` prefix, so the existing step-1 reset
    (`DELETE FROM dbevents WHERE description LIKE '[demo]%'`) removes them each run.
  - The moved "Giving Tuesday 2025" campaign row is dropped (with its now-unused `@gt_start`); its gifts and
    other rows link by name to the seed's own "Giving Tuesday 2025", which exists by the time the section runs.
  - "Thanksgiving Food Drive" is renamed "Thanksgiving Meals Drive" in 3b and in every 3c-3f reference. Round 2
    found that the open campaign's name appears on addDonation.php, and `tests/donations_integration.py`
    `test_campaign_card_data` asserts that string is absent (it guards against the old hard-coded mockup). The
    same test already fails on PR #28 today because 009 inserts the campaign. The test is not changed.
  - The 3c-3f joins to `dbevents` by name add `AND c.description LIKE '[demo]%'`, so moved rows link only to
    demo campaigns, never to a hand-made campaign with the same name.
  - Step 1's existing `demo_donor_ids` temporary table is widened to
    `WHERE notes LIKE '[demo]%' OR notes = 'demo-seed'`. The existing children-first deletes (by fish.*
    recorder or by donor id: distributions, communications, hours, non-cash, donations) and the donor delete
    then remove the moved donors and all their activity, including rows someone added by hand, so no RESTRICT
    foreign key can stop the reset. Every moved activity row belongs to a moved donor (3c-3f join on
    `demo.*` emails), so no separate marker deletes are needed. The section's NOT EXISTS guards then always
    insert.
  - The moved donors keep `demo-seed`, not `[demo]` (so the seed's names stay unique among `[demo]` rows,
    which the donor tests rely on). The seed header lists `demo-seed` as a second marker.
  - The commented removal statements are dropped (the reset now does that).
- Docs that describe the removed feature or the seed markers: `README.md` (staff "edit but not deactivate";
  `make test-donors` covering deactivate and reactivate) and `sql/README.md` (`is_active`/`deactivated_*` as a
  soft delete from `updateDonor.php`; "deactivate through updateDonor.php instead" of deleting; the seed
  marker description). They will say `is_active` can no longer be changed from the UI and previously
  deactivated donors stay hidden, and describe the `demo-seed` rows.

Out:
- Restoring deactivate/reactivate, rejecting unknown `action` values in `updateDonor.php`, or cleaning up the
  leftovers of the removal: the unused `donor_set_active` in `database/dbDonors.php`, the "Edit, deactivate or
  reactivate a donor" header comment and the unused `$action` in `updateDonor.php` (James's call; all three are
  listed in the PR).
- `tests/donations_integration.py` (see the campaign rename above).
- Backfilling or dropping `date`, or fixing or parameterising the legacy events code (tracked separately; its
  positional `INSERT INTO dbevents VALUES(...)` is already broken by 009's added columns).
- Any change to campaign pages, `database/dbCampaigns.php`, or the columns/view in 009.
- The name-based lookup in the donor tests (PR #29 into `dev`).

## Approach

- The edited tests assert on database state via the existing `donor_read` fixture, not on the HTTP status of
  the unknown action, so they do not lock in how the page reports an unsupported action.
- `make seed-demo` runs `make migrate` first, so the 009 columns the moved rows need exist before the seed.

## Acceptance criteria

1. On this branch merged with `wagaman-eure-donor-test-lookup`: `make seed-demo` then `make test-auth`,
   `make test-donors`, `make test-users`, `make test-donations` and `make test-gate` all pass.
2. On this branch alone, `make test-donors` fails only on the name-lookup assertions the other branch fixes,
   and not on deactivate/reactivate.
3. On a fresh database volume, `make migrate` alone: `dbevents.date` exists, 009 contains no `INSERT`, and the
   count of `demo-seed` rows (donors.notes, donations.reason, non_cash_donations.notes, volunteer_hours.notes,
   communications.summary) plus dbevents rows named like the 6 moved campaigns (with "Thanksgiving Meals
   Drive") is 0.
4. After `make seed-demo` twice, compared to once (same day): the same `demo-seed` row counts, the same output
   of `SELECT name, cash_raised, cash_gift_count, items_collected, item_gift_count, shifts, volunteer_hours
   FROM campaign_summary ORDER BY name` (ids change every run, so compare by name), and 0 `demo-seed`
   donations with `eventID` NULL.
5. The CI replay step (each migration re-run against the migrated database) still passes.

## Verification plan

CI does not run on this PR: `ci.yml` triggers only for PRs into `dev` and `main`. It runs on PR #28 once this
merges into it. The local runs below are the evidence and go in the PR description.

- Fresh volume. `compose.yaml` hard-codes `name: fauquier-fish-drms`, so the directory name does not separate
  projects (round 2). Copy `.env` into the worktree and append `COMPOSE_PROJECT_NAME=fish-pr28`,
  `APP_PORT=8090` and `PHPMYADMIN_PORT=8091` (it overrides `name:`, as `scripts/db-sql.sh` documents for
  throwaway databases; the tests run inside the web container, so host ports do not matter). Before `make dev`
  and again before the final `docker compose down -v`, `docker compose config | grep '^name:'` in the worktree
  must print `name: fish-pr28`, and `docker volume ls` must show `fish-pr28_db-data`. The main checkout's stack
  and volume are not touched.
- `make migrate`, then `SHOW COLUMNS FROM dbevents LIKE 'date'`, `grep -c INSERT` on 009, and the marker
  counts (criterion 3).
- `make seed-demo`, save the counts, `campaign_summary` and the NULL-eventID count; run again and diff
  (criterion 4).
- The CI replay loop: `for f in sql/migrations/[0-9][0-9][0-9]_*.sql; do scripts/db-sql.sh < "$f"; done`.
- All test targets, on this branch alone and merged with `wagaman-eure-donor-test-lookup` (criteria 1-2).

## Risks

- A database that already applied the old 009 cannot migrate: `scripts/db-migrate.sh` stops with "ERROR:
  009_campaigns.sql changed since it was applied", so `make migrate`, `make dev` and `make seed-demo` all
  fail. Such a database has also already lost `dbevents.date`, and deleting the 009 row from
  `schema_migrations` does not bring it back. Only PR #28 databases (James's, and testers') are affected,
  because 009 is not on `dev`. Recovery, stated in the PR: `docker compose down -v`, `make dev`,
  `make seed-demo`.
- Removing a feature changes a permission surface: no one can now change `is_active` from the UI, and
  previously deactivated donors stay hidden from the list. Accepted by Cory as intended; stated in the PR and
  the docs.
- Staff with donors/edit now reach the save path with any `action` value (before, deactivate needed
  donors/delete). The "row unchanged" checks cover the bare-POST case; rejecting unknown actions is James's
  call.

## Responses to round 2 (not approved; 2 confirmed criticals)

- Critical (data), "moved demo campaigns make test-donations fail": fixed by renaming the open campaign to
  "Thanksgiving Meals Drive"; the teammate's test is not changed. Criterion 1 runs test-donations.
- Critical (verification), "the worktree is not its own compose project; `down -v` would delete the main
  database": fixed. The worktree's `.env` sets `COMPOSE_PROJECT_NAME=fish-pr28` and other ports, and the
  project name and volume are checked before `make dev` and before `down -v`.
- Medium x2 (data, verification) and medium (correctness), "marker-only reset can abort on RESTRICT FKs":
  fixed by widening `demo_donor_ids`, as recommended, instead of per-table marker deletes.
- Low, "criterion 4 must compare by name": fixed with the exact query.
- Low x2, "`date` readers incomplete": fixed in the findings text and the planned 009 comment; this also
  answers the round-1 data low that round 1's responses missed.
- Low, "`@gt_start` dead; name joins not limited to demo campaigns": both fixed.
- Low x2, "updateDonor.php leftovers not in the PR note": fixed; all three are listed.

## Responses to round 1 (approved; 0 criticals)

- High x3 (data, correctness, verification), "moved block collides with the seed's Giving Tuesday 2025":
  fixed. The moved campaigns take the `[demo]` description prefix, the duplicate Giving Tuesday row is
  dropped, step 1 deletes the moved `demo-seed` rows each run, and criterion 4 now compares
  `campaign_summary` totals and NULL links, not just counts.
- High (verification), "an old-009 database cannot migrate": fixed in Risks with a recovery step for the
  PR; the verification uses a fresh volume.
- High (verification), "README.md and sql/README.md still describe deactivate and the old markers": fixed;
  both are in scope.
- Medium, "checksum behaviour described wrongly": fixed (Risks). Medium, "criterion 3 counted a dbevents
  marker that does not exist": fixed (lists the real marker columns and the campaign names, plus no INSERT).
  Medium, "CI does not run on PRs into feature branches": stated in the verification plan.
- Medium/low, "deactivate POST assertions should compare the whole row": fixed.
- Low, "moved rows not covered by the seed's reset and markers": fixed by the reset and the header line.
- Low, "the legacy events code is reachable by URL and not 'nothing calls it'": wording corrected; that code
  is out of scope and tracked separately.

## Verification results (2026-10-26, fresh volume, compose project `fish-pr28`)

1. Merged with `origin/wagaman-eure-donor-test-lookup` (trial branch, conflict kept both helpers), after
   `make seed-demo`: test-auth 21 OK, test-donors 5 OK, test-users 8 OK, test-donations 12 OK, test-gate
   18 passed. The trial merge was discarded.
2. This branch alone: test-donors fails 3 tests, all on the name lookup that the other branch fixes (two
   "Maria Lopez" rows, wrong "Grace Community Church" opened); none on deactivate/reactivate.
3. `make migrate` alone: `dbevents.date` still exists, 009 has no INSERT, `demo-seed` marker counts 0.
4. `make seed-demo` twice: identical counts (donors 12, gifts 18, noncash 10, hours 17, comms 4, campaigns 6)
   and identical `campaign_summary`; 0 seed gifts with an unlinked campaign.
5. Migration replay loop: no failures.
