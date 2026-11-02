# Ticket: UF2-124: non-cash gifts record a quantity and a weight in pounds; the Unit dropdown goes

Branch: `wagaman-eure-noncash-pounds`, off `origin/dev` at 0a64dfd (includes PR #28 campaigns and #33). PR into `dev`.

Jira UF2-124, "Change how Non-Cash donations are added: remove dropdown and add quantity and pounds entry":

> All non-cash donations are measured in quantity and pounds. Remove the dropdown from the Non-Cash donation
> form and add a field where the user can enter the poundage.

Clarified by Cory (2026-10-28): "the dropdown" is the **Unit** dropdown (items | lbs | bags | boxes), not
Category. The Unit dropdown is replaced by a typed **Pounds** box next to **Quantity**; Pounds is **required**.
Category stays.

## Problem

Today the non-cash form (`addDonation.php`, add and edit) takes one number, Quantity, plus a Unit chosen from
`DONATION_UNITS = ['items', 'lbs', 'bags', 'boxes']` (`database/dbDonations.php:40`). A gift is therefore
stored either as a count or as a weight, never both, in `non_cash_donations.quantity` +
`non_cash_donations.unit` (`VARCHAR(30) NOT NULL DEFAULT 'items'`, migration 004). FISH measures every
in-kind gift both ways.

## Scope

In:
- New migration `sql/migrations/012_non_cash_weight.sql` (CI refuses edits to applied migrations):
  - `ADD COLUMN IF NOT EXISTS weight_lbs DECIMAL(10,2) NULL CHECK (weight_lbs IS NULL OR weight_lbs > 0)
    AFTER quantity`: the check is declared inline, so it is added with the column and skipped with it on a
    re-run (checked on a scratch table by the round 1 correctness auditor). NULL is allowed in the database only for gifts recorded before
    this change; the form requires it for every new or edited gift.
  - Backfill, only while `unit` still exists (guarded through `information_schema` + a prepared statement,
    so the CI replay of 012 after the column is gone is a no-op):
    - `unit = 'lbs'`: `weight_lbs = quantity`.
    - every `unit <> 'items'` (lbs, bags, boxes, or any free text the seed used such as cans/coats/kits):
      append `Recorded before UF2-124 as <quantity> <unit>.` to `notes` (CONCAT_WS with a space, so NULL
      notes become just that sentence), so the old meaning is not lost. `quantity` itself is not changed.
      The UPDATE also has `AND (notes IS NULL OR notes NOT LIKE '%Recorded before UF2-124 as%')`, so a run
      that stopped before the DROP and is re-run does not append the sentence twice. The quantity is
      printed as stored (`DECIMAL(10,2)`, e.g. `240.00 cans`).
    - `@sql` only ever holds fixed UPDATE text chosen by the `information_schema` check; all row values
      (`quantity`, `unit`, `notes`) are read inside the UPDATE expression, never concatenated into `@sql`.
  - `DROP COLUMN IF EXISTS unit`.
- `database/dbDonations.php`: remove `DONATION_UNITS`; add `weight_lbs` to the non-cash form defaults, the
  stored-row-to-form mapping, the POST-to-form mapping (`weight_lbs` field), validation (required, same
  `$number()` helper and `DONATION_NUMBER_PATTERN` as Quantity, min 0.01, max `DONATION_QUANTITY_MAX`,
  message "Pounds is required." / the helper's existing wording for bad numbers). Pounds is required on
  every save, including edits of pre-UF2-124 rows whose `weight_lbs` is NULL: this is a deliberate exception
  to the existing "unchanged stored value passes" rule (`$unchanged`), per Cory ("required"); the validation
  comment names it, `donation_bind_values`,
  `DONATION_NONCASH_COLUMNS` (`unit = ?` becomes `weight_lbs = ?`; `DONATION_NONCASH_TYPES` keeps its length)
  and the list/detail SELECTs (`n.unit` becomes `n.weight_lbs`; the cash half of the UNION selects
  `NULL AS weight_lbs`). All queries stay prepared statements with bound parameters.
- `addDonation.php`: the Unit `<select>` becomes `<label for="weight_lbs">Pounds</label>` with
  `<input type="text" inputmode="decimal" id="weight_lbs" name="weight_lbs" required ...>`, same markup
  pattern as Quantity. Quantity's label becomes "Quantity (items)".
- Display, everywhere `unit` is shown: `<qty> items · <lbs> lbs`, or `<qty> items` when `weight_lbs` is NULL
  or '' (legacy rows). One helper, `donation_qty_text($quantity, $weight_lbs)`, in a new
  `database/nonCashFormat.php` with no includes of its own, required by `dbDonations.php`, `dbDonors.php` and
  `dbCampaigns.php` so every page that shows non-cash rows has it (donorProfile.php and campaign.php do not
  load dbDonations.php). It returns plain text only (number formatting and a literal `·`, no HTML) and every
  caller escapes it with `h()`, including `amountText()` in donations.php. It is
  used by `donations.php` (list), `viewDonation.php` (Quantity row; "Given out so far" becomes
  `<n> items`, distributions are counted in items), `donorProfile.php` + `database/dbDonors.php:219`
  (non-cash history), and `database/dbCampaigns.php` (gifts tab detail; items-campaign top donors show
  `SUM(quantity)` as `<n> items` instead of `MAX(n.unit)`).
- `sql/seed/demo_fish.sql`: every `non_cash_donations` insert drops `unit` and sets `weight_lbs` to a
  plausible weight (the "Rice and pasta 80 lbs" row becomes 40 items / 80 lbs; section 9d's `g.unit`
  column becomes `g.lbs`).
- Tests: `tests/donations_integration.py` and `tests/donors_integration.py` stop using `unit`
  (raw inserts set `weight_lbs`; form posts send `weight_lbs`). New assertions:
  1. add with Pounds saves `weight_lbs` and the view page shows `24 items · 18.5 lbs`;
  2. Pounds missing, `0`, `-1`, `abc` and `1.234` are each rejected with no row written;
  3. editing a legacy row (`weight_lbs` NULL) without Pounds is rejected; with Pounds it saves;
  4. a legacy row with NULL weight shows `<n> items` on the list and view pages (no " · lbs");
  4b. the donor profile Gifts tab shows `24 items · 18.5 lbs` for a weighed gift and `<n> items` for a legacy
      row; `campaign.php?…&tab=noncash` shows the same format in the gifts tab and `<n> items` in the
      top-donors list of an items campaign;
  5. the form has no `name="unit"` field.
  The old "Choose a unit." case is removed.
- Docs: `sql/README.md` (non-cash columns: `quantity` = item count, `weight_lbs` = pounds, NULL only on
  pre-UF2-124 rows; schema heading/table extended to 012) and `README.md` (test description, if it mentions
  units).

Out:
- Campaign goals: `dbevents.item_unit` and the `campaign_summary.items_collected` sum stay as they are
  (item count). A pounds goal or pounds total for campaigns is a separate ticket if FISH wants one.
- `donor_summary.items_donated` (sum of quantity) is unchanged; no pounds total on the donor profile.
- `non_cash_distributions` stays counted in items; no pounds on distributions.
- Renaming `donations` / other UF2-111 items (closed as no change).
- Legacy pages (`editDonationHandler.php`, `addDonationHandler.php`, reports) do not touch non-cash rows.
- Campaign units: `CAMPAIGN_UNITS` still offers 'lbs' (and cans, coats, ...) as a campaign goal unit, while
  items collected is a sum of `quantity` (an item count). A campaign with an 'lbs' goal therefore labels an
  item count as pounds. That mismatch already exists on `dev` (the sum ignored `unit`); fixing it (drop 'lbs'
  or add a pounds total) is a follow-up ticket, listed in the PR.
- Whole-number quantities: Quantity still accepts two decimals; whether FISH wants whole counts only is a
  question for them, listed in the PR.
- `docs/sequence-diagram/` and the stray report PDFs are not part of this branch.

## Approach

- Reuse the existing `$number()` validator and `DONATION_NUMBER_PATTERN`, `h()` from
  `include/fishnet-layout.php`, and the existing prepared-statement helpers; `fish_can('donations', ...)`
  checks on the write path are unchanged.
- One new display helper so the "items · lbs" format is defined once.

## Acceptance criteria

1. The non-cash add and edit form shows Category, Quantity (items) and a required Pounds text box, and no
   Unit dropdown.
2. Saving a non-cash gift without a valid Pounds value (missing, 0, negative, non-numeric, >2 decimals)
   fails with an error and writes nothing; a valid value is stored in `non_cash_donations.weight_lbs`.
3. List, view, donor profile history and campaign gifts tab show `<qty> items · <lbs> lbs`, or
   `<qty> items` for rows with no weight.
4. After `make migrate` on a database at 011 that has non-cash rows with units items, lbs and others:
   `unit` column is gone; `lbs` rows have `weight_lbs = quantity`; every non-`items` row's notes end with
   the "Recorded before UF2-124 as ..." sentence; `items` rows are unchanged apart from `weight_lbs` NULL.
5. Re-running 012 against the migrated database (CI replay) succeeds and changes nothing.
6. `make test-auth`, `test-donors`, `test-users`, `test-donations`, `test-hours`, `test-gate` all pass after
   `make seed-demo`, and CI `tests` is green.

## Verification plan

- Criterion 4 on the local stack before re-seeding: the local DB is at 011 with the current demo seed
  (units items, lbs, cans, boxes, coats, kits). Save
  `SELECT id, quantity, unit, notes FROM non_cash_donations ORDER BY id`, run `make migrate`, then
  `SHOW COLUMNS FROM non_cash_donations` and `SELECT id, quantity, weight_lbs, notes ...`; compare.
- Criterion 5: `CHECKSUM TABLE non_cash_donations`, then the full CI replay
  `for f in sql/migrations/[0-9][0-9][0-9]_*.sql; do scripts/db-sql.sh < "$f"; done`, then the checksum again
  (unchanged).
- Partial-run guard: on a scratch copy at 011, run 012 with its DROP removed twice, then in full; every
  non-`items` row has the sentence exactly once.
- CI never exercises the 012 backfill (CI starts from a fresh database and the seed loads after 012), so the
  before/after SELECTs, SHOW COLUMNS and both checksums go in the PR description. The local demo rows cover
  NULL notes (seed section 6) and non-empty notes (`demo-seed`, section 9d).
- `make seed-demo` twice, then every test target (criterion 6).
- Manual: `make dev`, sign in as fish.staff, add a non-cash gift with and without Pounds, edit a legacy row,
  view list / donor profile / campaign page.

## Risks

- Old rows recorded in lbs, bags or boxes keep `quantity` as stored, so it is not a true item count (an
  `80 lbs` row shows `80 items · 80 lbs`), and their `non_cash_distributions` quantities change meaning the
  same way. The notes sentence records the original unit. Only dev/demo data exists; `make seed-demo` after
  migrating replaces the demo rows.

- Dropping `unit` loses the free-text unit word for old rows; mitigated by the notes sentence. No production
  database exists yet (the app is not deployed), so only dev/demo rows are affected.
- A legacy row's notes plus the appended sentence could exceed the form's 1000-character notes limit, which
  would block editing that row until notes are shortened. Only possible if notes were already near 1000.
- Teammates must run `make migrate` (and `make seed-demo`) after pulling; stated in the PR.
- Any teammate branch still writing `unit` will fail after merge (unknown column); stated in the PR and on
  the Jira ticket.

## Responses to round 1 (approved; 0 criticals, 1 high)

- High (correctness), "helper not loaded on donorProfile.php / campaign.php": fixed. The helper lives in a new
  `database/nonCashFormat.php` required by dbDonations, dbDonors and dbCampaigns.
- Low (security), "callers must escape the helper's output": fixed; plain text, every caller uses `h()`.
- Low (security), "no row values in the prepared SQL text": fixed in the 012 bullet.
- Medium x2 (data, correctness), "notes append not idempotent after a partial run": fixed with the NOT LIKE
  guard and a partial-run check in the verification plan.
- Low (data), "how the CHECK is added": fixed; inline on the column.
- Medium (correctness), low (verification), "required Pounds on legacy edits departs from the unchanged-value
  rule": accepted as a deliberate exception (Cory: required); stated in scope and in the PR.
- Medium (correctness), "replay only 012": fixed; full-series replay with checksum.
- Medium (correctness), low (verification), "campaigns with an 'lbs' unit / fractional counts": accepted as
  out of scope (pre-existing mismatch); follow-ups in the PR.
- Medium x2 and low x2 (data, verification, correctness), "legacy lbs rows show weight as items":
  accepted and documented in Risks; demo data only.
- Medium (verification), low (data), "criterion 3 tested on 2 of 4 pages": fixed (test 4b).
- Medium (verification), "CI never runs the backfill": fixed by putting the manual evidence in the PR.
- Low x3, "wrong base commit": fixed (0a64dfd). Low (verification), "README / stray files": fixed (README in
  docs; stray files out).
