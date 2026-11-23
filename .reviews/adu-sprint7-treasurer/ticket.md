# Ticket: wire the Treasurer page to cash donations and expenses

Branch: `adu-sprint7-treasurer`, off `origin/dev` (9208f9b).

Jira (Sprint 7, assigned to Joel; each description reads only "Sprint 7 task (from the sprint planning sheet)."):
- UF2-163 "Treasurer: have all cash donations flow into the Treasurer page"
- UF2-164 "Treasurer: connect Money In card to all cash donations for the month"
- UF2-165 "Treasurer: connect Money Out card to expenses"
- UF2-166 "Treasurer: connect Net card (money in minus money out)"
- UF2-167 "Treasurer: connect Entries card (donations + expenses)"

Related, not in scope: UF2-168 "Treasurer: build form to add an expense" (Tristan, To Do), UF2-154 Treasurer
front end (Tristan, In Review, already on dev as `treasurer.php` + `css/treasurer.css`).

## Problem

`treasurer.php` is front end only: `$log`, `$years` and `$funds` are empty arrays with "Backend TODO" notes,
so the four cards always show $0.00 / 0 and the log says "No donations or expenses". There is no expenses
table anywhere in the schema (`grep -ri expense sql/` finds nothing), so Money Out has nothing to read. No
open PR or remote branch touches expenses or the Treasurer page.

## Scope

In:
1. `sql/migrations/019_expenses.sql` (new, additive, idempotent): `CREATE TABLE IF NOT EXISTS expenses`
   - `id INT AUTO_INCREMENT PK`, `expense_date DATE NOT NULL`, `payee VARCHAR(255) NOT NULL`,
     `category VARCHAR(64) NOT NULL DEFAULT 'other'` (free text for now; UF2-168 picks the list),
     `campaign_id INT NULL` (the fund, `dbevents.id`, FK `ON DELETE SET NULL ON UPDATE CASCADE`, same as
     `non_cash_donations`), `amount DECIMAL(12,2) NOT NULL` with `CHECK (amount > 0)`, `notes TEXT NULL`,
     `recorded_by VARCHAR(256) NULL` (FK to `dbpersons(id) ON DELETE SET NULL ON UPDATE CASCADE`, same as
     donations), `created_at` / `updated_at` timestamps; keys on `expense_date` and `campaign_id`.
   - Charset/collation `utf8mb4` / `utf8mb4_general_ci` to match `donations`/`dbevents` (avoid "Illegal mix
     of collations" when joining names).
   - UF2-168's form will write this table; its shape is documented in `sql/README.md` so that ticket builds
     on it instead of inventing a second one.
2. New `database/dbTreasurer.php` (prepared statements only, dates bound):
   - `treasurer_funds()`: `['General', ...distinct dbevents.name that have at least one donation or expense,
     sorted]`. "General" = no campaign.
   - `treasurer_log($from, $to, $show, $fund)`: union of
     - cash donations: every `donations` row with `donation_date BETWEEN ? AND ?` and `amount IS NOT NULL`
       (all payment methods; "cash donations" in this codebase means the `donations` table as opposed to
       `non_cash_donations`). `who` = `donor_summary.display_name` (anonymous donor 999 shows its own
       display name), `detail` = payment method label + " #check_number" when present, `fund` =
       campaign name or "General", `amount` = gross `amount` (same figure the Donations page and
       `donations_tiles()` use).
     - expenses in the same range: `who` = payee, `detail` = category, `fund` = campaign name or "General".
     - `$show` limits to one side; `$fund` = '' (all), 'General' (`campaign_id IS NULL`) or a campaign name.
     - Ordered newest first: date DESC, then kind, then id DESC.
   - `treasurer_totals($from, $to, $fund)`: SUM/COUNT for donations and expenses in SQL (not from the
     rendered log), so the cards stay right independent of the "show" filter.
   - `treasurer_years($span)`: one row per calendar year for the last 3 or 5 years (current year included,
     oldest first, zero-filled), donations and expenses totals.
3. `treasurer.php`: replace the empty arrays with those calls; remove the Backend TODO notes that are done.
   - Money In (UF2-164) = donations total for the date range, which defaults to the current calendar month
     (`date('Y-m-01')` .. `date('Y-m-t')`, unchanged). Money Out (UF2-165) = expenses total, Net (UF2-166) =
     in − out, Entries (UF2-167) = donation count + expense count. The cards follow the date range and fund
     filter but not the "show" filter (the Entries card's caption is "donations and expenses"). The log
     footer "Net for …" uses the same Net.
   - Access: the page stays behind `fishnet-layout.php` (sign-in). Money data needs
     `fish_can('donations', 'view')`; without it the page shows "Your account cannot view donations." in
     place of the cards, log and chart, and runs no queries. (Expenses have no permission area; reusing
     donations view avoids a schema change to the permissions enum.)
   - A database error (`mysqli_sql_exception`) shows an error notice instead of a fatal.
   - Fund select options come from `treasurer_funds()`; an unknown `fund` value falls back to all.
   - "+ Add expense" and "Download CSV" buttons are unchanged (`href="#"`; UF2-168 and a later ticket).
4. `tests/treasurer_integration.py` + `make test-treasurer` + a CI step in `.github/workflows/ci.yml`:
   fixtures create their own donor, campaign, donations and expenses in a far-past window (e.g. 2001)
   via `php -r` like the other suites, and remove them afterwards; nothing asserts on demo rows.
5. `sql/README.md`: document the `expenses` table. README test list: add `make test-treasurer`.

Out: Add-expense form (UF2-168), CSV download, editing/deleting expenses, non-cash donations on the
Treasurer page, a separate "treasurer" permission area.

## Acceptance criteria

1. A cash donation dated in the selected range appears in the log as "Donation" with donor name, payment
   detail, fund and +amount; one outside the range does not; a donation with NULL amount or date is ignored.
2. With the default range (this month) Money In equals the sum of this month's donations.
3. An expense row in range appears as "Expense" (payee, category, fund, −amount); Money Out equals the sum.
4. Net = Money In − Money Out, shown with a minus sign when negative; Entries = donations + expenses count.
5. "Donations only"/"Expenses only" filters the log; the cards do not change. The fund filter limits log and
   cards to "General" or one campaign.
6. The year chart shows 3 (or 5) calendar years with the right totals.
7. An account without donations view sees no money figures or names; anonymous visitors are redirected to
   login (existing guard). Donor names, payees and categories are HTML-escaped.

## Verification plan

- New `tests/treasurer_integration.py` covering criteria 1–7 (XSS payee, permission override for a viewer
  without donations view, negative net, filters, year totals).
- `make test-auth`, `test-donations`, `test-donors` on the local scratch stack (php -S + scratch MariaDB,
  migrations incl. 019 applied, demo seed loaded); the 6 Apache-only auth subtests are expected to fail
  there and pass in CI.
- Replay all migrations twice against the migrated scratch DB (re-run safety); `php -l` on changed files.
- Page walk on the scratch server (screenshot) with seeded rows.

## Risks

- UF2-168 may want different expense columns; the table is additive and later migrations can add columns.
- New migration 019 is not yet in `sql/production-applied.txt`; the CI schema guard only warns, and the
  repo owner ports it to MySQL 8 before release (CHECK constraints are supported in MySQL 8.0.16+).

## Responses to round 1 (all four approved, no criticals; 2 high, 7 medium, 12 low)

These change the plan above; where they conflict, this section wins.

- **Fund = campaign name vs dbevents.fund** (correctness, high; data and correctness, medium on name collisions): fixed.
  The Fund column and filter use the campaign's existing "Fund / designation" (`dbevents.fund`, migration 009):
  `COALESCE(NULLIF(TRIM(e.fund), ''), 'General Fund')`. A gift or expense with no campaign is "General Fund"
  (the default `dbCampaigns.php` gives new campaigns). Merging campaigns that share a designation is the
  intent of a fund. The campaign name, when there is one, goes into Detail ("Check #123 · Winter Coat
  Collection"). `treasurer_funds()` returns the distinct fund labels; options carry an explicit `value`
  attribute; the filter validates against that list and falls back to all funds.
- **Delta tests for AC2/AC6** (verification high; data and correctness, medium): fixed. AC2 and AC6 are tested by reading
  the page, inserting fixtures dated today (plus an expense in the year before last), reading again and
  asserting the cards and the year bars moved by exactly the fixture amounts, then deleting them. Money In for
  the default month is also cross-checked against `donations_tiles()['cash_month']`. The 2001 window is used
  for AC1, 3, 4, 5 and 7.
- **eventID / teardown order** (data, medium): donations link via `d.eventID`, expenses via `x.campaign_id`.
  Teardown order is expenses, donations, campaign, donor.
- **Year chart and fund filter** (data, low; correctness, medium): the chart follows the fund filter (same predicate).
  It is index-friendly: `BETWEEN 'Y0-01-01' AND 'Y1-12-31'`, `GROUP BY YEAR()`.
- **Impossible dates** (data, low; correctness, medium): fixed. `from`/`to` must pass `checkdate()` (years 1900–2100), otherwise
  the defaults apply.
- **Shared predicates** (correctness, low): one helper builds the donation and expense WHERE clauses (range, amount IS
  NOT NULL, fund); the log, totals and years all use it. A test asserts Entries = number of log rows.
- **Donor fallbacks / helpers** (data, low; correctness, low): LEFT JOIN donor_summary and dbevents. Donor 999 shows "Anonymous",
  a missing donor shows "Unknown donor", and payment labels come from `DONATION_PAYMENT_LABELS` (dbDonations.php).
- **Error path** (security, low): a `mysqli_sql_exception` is passed to `error_log`, and the page shows the fixed text "Could not load
  treasurer figures." with no SQL text.
- **Every role sees expenses** (security, low): accepted and documented. All four role presets have donations view, so
  any signed-in role sees expenses, the same as donations today. A per-user override hides them. A dedicated
  area can come later. Noted in sql/README.md.
- **Write permission for UF2-168** (security, low): sql/README.md says expense writes need `auth_csrf_field()` and
  donations view+edit (like `fish_can_record_donations()`).
- **Restricted viewer** (verification, low): the test uses the `permission` fixture (donations can_view=0) on its own user
  and asserts that no names, fund labels or dollar figures appear.
- **Unknown fund / error notice** (verification, low): the unknown-fund fallback is added to AC5 and tested. The error notice
  is checked by hand (renaming the table on the scratch DB), not in CI.
- **Docs and post-merge** (verification, low): README CI sentence and suite paragraph, Makefile `.PHONY`/help/target,
  and the ci.yml step. After merging: `make migrate` (019_expenses). No re-login is needed.
