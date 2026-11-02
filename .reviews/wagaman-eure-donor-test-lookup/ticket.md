# Ticket: donor tests find the demo donors by seed marker and id, not by display name

Branch: `wagaman-eure-donor-test-lookup` (off `origin/dev` at 3088ba6).

Jira: none. Request from Cory (repo admin), 2026-10-26, after PR #28 failed `make test-donors`: "why did we
make a test that requires the same email for the same person what if there are two maria lopezs", then, in
full: "yeah he rmo,ved deactivate intentionally, go fix james and my brittle test".

That request has two parts, and Cory chose how they are split. Asked "How should the fixes for James's PR
#28 reach him?", Cory answered "PR into his branch (Recommended)". So:
- This ticket (branch off `dev`) fixes the brittle name lookup that Cory wrote on 2026-10-15. It must land on
  `dev` because every branch, not just #28, runs these tests.
- James's part, updating the deactivate/reactivate tests to match the intentional removal, is ticket
  `.reviews/wagaman-eure-pr28-fixes/ticket.md` on branch `wagaman-eure-pr28-fixes`. That branch is stacked on
  PR #28's head and goes in as a PR into `Andrews-donorBackend`. It cannot land on `dev` first, because `dev`
  still has deactivate/reactivate and those tests are correct there until #28 merges. The same ticket moves
  #28's demo rows from migration 009 to the seed.
- Merge order: this branch into `dev`; then the #28 fix PR into `Andrews-donorBackend`; then #28 merges `dev`
  and turns green.

## Problem

`tests/donors_integration.py` identifies the demo donors from `sql/seed/demo_fish.sql` by display name and
assumes each name occurs once in the whole `donors` table. The app does not require that: two people can
share a name, and the duplicate check matches on email or phone only. Any other data with a seed name breaks
the tests even though the pages are correct. PR #28 shows it: its migration inserts its own "Maria Lopez"
and "Grace Community Church" (notes `demo-seed`), and six of its eight `test-donors` failures come from this:

- `view_rows` fixture (`SELECT * FROM donor_summary WHERE display_name = ? ... ORDER BY id`) returns the
  lowest id with that name, and the tests take `[0]`, which can be a non-seed donor.
- `test_list_reads_the_database` then takes the *first* list row with that name
  (`next(r for r in rows_in(body) if r[0] == name)`) and compares it to the fixture row: two different
  donors get compared (`'maria.lopez@example.com' != 'demo.mlopez@example.org'`).
- The same test asserts the `q=Maria Lopez` search returns exactly
  `["Maria Lopez", "Maria Lopez-Hernandez"]`.
- `test_profile_reads_every_tab` opens "the" Maria Lopez by name and expects the seed's "Fall Food Drive 2026"
  gifts in three tabs; it opens the other Maria Lopez. The Grace Community Church check matches by name too.

Email is not a usable key either: the seed gives "Maria Lopez" and "Maria Lopez-Hernandez" the same email on
purpose (the duplicate-donor demo). Separately, `rows_in` reads the status badge with
`<span class="status (\w+)">`, so any added attribute on the span (PR #27 adds `title=`) crashes the helper
with `AttributeError` instead of failing an assertion.

## Scope

In (only `tests/donors_integration.py`):
- Replace the `view_rows` fixture with `demo_row`: the `donor_summary` row whose `display_name` equals the
  name, `is_anonymous = 0`, and whose `donors.notes` starts with `[demo]` (the marker every seed donor
  carries and `seed_check` already counts). Bound parameters only. It returns all matches; the Python side
  asserts exactly one, with a message naming the seed, so a seed change fails clearly.
- `rows_in` also returns the donor id, read from the row's `donorProfile.php?id=N` link, as a 7th element.
  Existing positions 0-5 keep their meaning.
- New fixture `search_ids`: the ids the list search should return, from the same bound-parameter `LIKE`
  as `counts.name_only` (`donor_summary`, `is_anonymous = 0`, `is_active = 1`, display_name or email),
  `ORDER BY id`.
- `test_list_reads_the_database`: find each demo donor's list row by id, not name, with
  `next(..., None)` plus `assertIsNotNone` whose message names the seed donor and id (no bare
  `StopIteration`). The `q=Maria Lopez` check becomes exact on ids: the sorted result ids equal
  `search_ids`, and both seed Maria ids are in it. Together with the existing count check this is at least
  as strict as the old exact-names check: it still fails on any missing or extra row, and it no longer
  assumes nobody else is named Maria Lopez.
- `test_profile_reads_every_tab`: Maria Lopez and Grace Community Church come from `demo_row`; Grace's list
  row is found by id the same way.
- `rows_in` status regex tolerates other attributes on the badge: `<span class="status (\w+)"[^>]*>`.

Out:
- Any page, schema, seed or migration change, and any change to what the tests assert about page behaviour
  (values compared, permission checks, deactivate/reactivate). The deactivate/reactivate test update is in
  scope of Cory's request but lands on `wagaman-eure-pr28-fixes` (see the split above), not here.
- PR #27's status change (Active/LYBUNT/SYBUNT/Inactive): the regex fix only lets `rows_in` parse its badge;
  the expected status values are still that PR's to update.
- Other test files (`users_integration.py` has its own `rows_in`, keyed by user id already).

## Approach

- `demo_row` query: `SELECT s.* FROM donor_summary s JOIN donors d ON d.id = s.id WHERE s.display_name = ?
  AND s.is_anonymous = 0 AND d.notes LIKE '[demo]%' ORDER BY s.id`. MariaDB `LIKE` gives no special meaning
  to `[`, as `seed_check` already relies on.
- A small Python helper `demo_donor(name)` calls the fixture and asserts `len == 1`. `demo_row` does not
  filter `is_active`: the profile test also opens donors directly. When a seed donor is matched against the
  list (which shows active donors only), a missing row fails with the `assertIsNotNone` message, which says
  the donor may be inactive.
- The id regex is `donorProfile\.php\?id=(\d+)` inside the row's own `<tr>`; `donor.php` renders that link
  in every row (the "Select" cell).

## Acceptance criteria

1. On `dev` data (`make seed-demo`), `make test-donors` passes with the same number of tests as before:
   "Ran 5 tests ... OK" (measured on `dev` at 3088ba6, 2026-10-26; the 21 in round 1 was `test-auth`'s count).
2. With an extra donor named "Maria Lopez" and one named "Grace Community Church" (no `[demo]` note, with
   their own email) present, `make test-donors` still passes.
3. If a seed name occurs twice among `[demo]` rows, the test fails with an assertion naming the seed, not an
   `IndexError`/`StopIteration`.
4. A status badge with an extra attribute (`<span class="status active" title="x">`) is parsed by `rows_in`.
5. No other assertion is weakened: every value compared before is still compared, against the same
   source.

## Verification plan

- `make dev`, `make seed-demo`, `make test-donors` on the branch: passes.
- Criterion 2: insert two extra donors with `scripts/db-sql.sh` (names "Maria Lopez" and
  "Grace Community Church", notes `test-dup`), run `make test-donors`, then delete them. Passes.
- Criterion 3: temporarily copy the seed Maria Lopez with a `[demo]` note, run the one test, see the clear
  assertion, delete the copy.
- Criterion 4: a one-off `python -c` call of `rows_in` on a sample row with `title=`.
- `make test-auth`, `make test-users`, `make test-donations`, `make test-gate`: unchanged, pass.
- Cross-check against PR #28: apply its migration `009` on top and run `make test-donors`; expect only the
  two deactivate/reactivate failures to remain (those belong to that PR).

## Risks

- A future seed donor without a `[demo]` note would be invisible to `demo_row`; the explicit "exactly one"
  assertion makes that fail loudly rather than silently.
- `rows_in` callers that unpack exactly six fields would break; none do (all index by position).

## Responses to round 1

- Critical, "Ticket drops half of the user's request": fixed. The request is now quoted in full, and the split
  is recorded with Cory's own answer about where James's part goes ("PR into his branch"). It names the other
  ticket and branch, explains why the deactivate assertions cannot land on `dev` first, and gives the merge order.
- High, "Ticket leaves the deactivate/reactivate failures in place": fixed by the same change. They are not
  left in place; they are updated on `wagaman-eure-pr28-fixes`.
- Medium/low, "q=Maria Lopez check is weaker" (correctness and verification): fixed. A new `search_ids`
  fixture makes the check exact on ids.
- Low, "lookup by id can still raise StopIteration": fixed with `next(..., None)` plus `assertIsNotNone`.
- Low, "demo_row does not filter is_active": addressed. It is left unfiltered on purpose, and the list
  match fails with a message saying the donor may be inactive.
- Medium, "AC1's 21 tests": fixed. Measured 5 on `dev`; 21 was `test-auth`.
- Low, "PR #27 parses but does not pass": fixed. Stated in Out.

## Responses to round 2 (approved; reported findings)

- High, "moving #28's demo rows into the seed clashes with one `[demo]` per name": not a clash. The moved rows
  keep their own `demo-seed` note, not `[demo]`, so `demo_rows` does not match them. Converting them is listed
  as a follow-up in the #28 fix ticket.
- Medium, "cross-check on a throwaway DB": done last, with a `mariadb-dump` before and a restore after (see
  results).
- Medium, "rendered-name check after the id lookup": done (`list_row` asserts the row's name too).
- Medium, "companion ticket does not exist yet": it is written and gated on its own branch before any of its
  code.
- Low, 8-row page limit: `assertLessEqual(len(expected_ids), 8, ...)`. Low, `demo_donor` uses `self.assert*`:
  done. Low, spell out the cross-check: see results.

## Verification results (2026-10-26, on dev 3088ba6 + this change)

1. `make seed-demo`, `make test-donors`: Ran 5 tests, OK.
2. Extra non-`[demo]` "Maria Lopez" and "Grace Community Church" inserted: Ran 5, OK; rows deleted after.
3. Second `[demo]` Maria Lopez: 3 failures, each `AssertionError: 2 != 1 : expected one [demo] donor named
   'Maria Lopez' from sql/seed/demo_fish.sql, found 2`; no IndexError/StopIteration. Row deleted after.
4. `rows_in` on `<span class="status active" title="x">`: parsed as `active`, id 1011.
5. `make test-auth` 21 OK, `make test-users` 8 OK, `make test-donations` 12 OK, `make test-gate` 18 passed.
6. Cross-check: database dumped, PR #28's `009_campaigns.sql` applied with `make migrate`, `make test-donors`:
   Ran 5, OK. This used dev's PHP, so it checks #28's duplicate-name data only; the two deactivate failures
   need #28's PHP and are handled on `wagaman-eure-pr28-fixes`. Database restored from the dump, the leftover
   `campaign_summary` view dropped, and `schema_migrations` ends at 008 again.
