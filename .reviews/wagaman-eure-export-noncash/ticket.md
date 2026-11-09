# Ticket: UF2-134: export non-cash donation info within a date range as a CSV

Branch: `wagaman-eure-export-noncash`, off `origin/dev` at 9144a05 (includes PR #41, UF2-133). PR into `dev`.

Jira UF2-134 (Task, Sprint-4, assignee Cory Wagaman-Eure, no description):

> Export non-cash donation info within a date range

Confluence "Sprint #3" (67633206), Upcoming:

> Exports (UF2-132–139)

The front end row already exists in `dataImports.php` (Tristan):

> 'noncash' => ['Non-cash donations', 'Item gifts between two dates, with quantity and weight.', 'range']

## Problem

The Non-cash donations row on Data & Imports has From and To boxes and a disabled "Coming soon" button. FISH staff
need the item gifts for a period in a spreadsheet (food drive totals, pounds received, program reporting). UF2-133
built the shared pieces (`include/csvExport.php`, `exportData.php`); this adds the `noncash` case.

## Scope

In:
- `exportData.php`: a `noncash` case. The steps shared with `cash` (permission check first, then dates, then the
  donor-id rule and `session_write_close()`) move into one function `export_range_start()` that both cases call,
  so the order stays the same in both. It ends the request itself on every failure (403 body then `exit`, or
  `export_back('dates')`, which exits) and returns only `[$from, $to, $showDonorId]` on success, so no case can
  run its query after a refusal. The cash case's output does not change (its file tests stay as they are).
  - Permission: `fish_can_export_donations()` (donations view), the same as cash, because `viewDonation.php`
    shows every column below to anyone with donations view. Without it: 403 with a plain message, no data.
  - Dates: `fish_export_dates(fish_str($_POST,'from'), fish_str($_POST,'to'))`, else redirect with `dates`.
  - Query (one prepared statement, bound `ss`): `non_cash_donations n` LEFT JOIN `donor_summary s ON s.id =
    n.donor_id`, `dbevents e ON e.id = n.campaign_id`, `dbpersons p ON p.id = n.recorded_by`, plus a correlated
    `(SELECT COALESCE(SUM(x.quantity), 0) FROM non_cash_distributions x WHERE x.non_cash_donation_id = n.id)`
    (the subquery `donation_get()` in `database/dbDonations.php` uses), `WHERE
    n.date_received BETWEEN ? AND ? ORDER BY n.date_received, n.id`. `date_received` is `NOT NULL`, so no gift is
    left out.
  - Columns, in order: Gift ID, Date received, Donor ID (blank without donors view), Donor ("Anonymous" when
    `is_anonymous`), Item description, Category (`DONATION_CATEGORY_LABELS`), Quantity (items), Weight (lbs)
    (blank on gifts from before UF2-124), Estimated value, Condition (`DONATION_CONDITION_LABELS`), Given out so
    far (items), Intended use, Campaign, Receipt sent (`DATE(receipt_sent_at)`), Recorded by
    (`donation_recorder_name`), Notes. Quantity, weight, value and given-out are written as the stored decimals
    (e.g. `24.00`), so they stay numbers in a spreadsheet. Every cell goes through `fish_csv_cell()`.
  - Filename: `fish-noncash-donations-<from>-to-<to>.csv`.
  - A database error: `error_log`, redirect with `failed`, never a partial file (the query runs before any header,
    as in cash).
- `dataImports.php`: `$exportReady['noncash'] = fish_can_export_donations()`, so the row's button becomes "Export
  CSV" (or "No access") and the form posts to `exportData.php`.
- `tests/exports_integration.py`: non-cash fixtures and tests (below), and these fixture rules:
  - Every non-cash fixture, including the donor-999 one, sets `campaign_id` to the suite campaign, so it can always
    be found through the prefix.
  - `clean`: within the suite windows (1901, and 1900-01-01..02) it first deletes `non_cash_donations` joined to a
    donor whose last name has the prefix OR a campaign whose name has the prefix (distributions go by `ON DELETE
    CASCADE`), then the existing donations, dbevents and donors deletes. It never deletes by date alone or by
    donor 999. Its emptiness count also counts `non_cash_donations` with `date_received` in those windows (not
    distributions, which have no date_received).
  - `teardown`: first `DELETE FROM non_cash_donations WHERE donor_id = ? OR campaign_id = ?` (suite donor and
    campaign ids), then donations, dbevents, donors as now. The order matters: `fk_ncd_donor` is RESTRICT and
    `fk_ncd_campaign` is SET NULL.
  - Two existing tests change, and only in what they expect of the page or of unbuilt exports:
    `test_unknown_exports_are_not_available` drops `noncash` from its list (it keeps `emails`, `junk` and `''`);
    `test_page_buttons_and_messages` expects 6 "Coming soon" buttons and 2 export forms.
- README: the permission paragraph and the `make test-exports` paragraph both name the non-cash export.
- Comments: the header comments of `exportData.php` and `dataImports.php` and the `fish_can_export_donations()`
  docblock say cash and non-cash.

Out: the other six exports; any schema change; donations with no `non_cash_donations` row; legacy `export.php`.

## Approach

Reuse everything from UF2-133: `fish_csv_send`, `fish_csv_cell`, `fish_export_dates`, `export_back`,
`fish_can_export_donations`, the label maps and `donation_recorder_name` from `database/dbDonations.php`. No new
permission, helper file or migration.

## Acceptance criteria

1. A user with donations view who posts `export=noncash` with valid dates gets a 200 `text/csv` attachment named
   `fish-noncash-donations-<from>-to-<to>.csv`, starting with a BOM, then the 16-column header above.
2. It holds every non-cash gift with `date_received` between from and to (both ends included), and nothing from
   the day before or the day after, ordered by date and then id.
3. Labels: category and condition use the label maps. A NULL condition or weight is a blank cell. Quantity,
   weight, value and given-out are plain decimals. Given out sums `non_cash_distributions.quantity` for that gift
   (0.00 when none).
4. Donor 999 shows as "Anonymous". Donor ID is blank without donors view.
5. Formula-leading text in description, intended use, campaign, donor name or notes gets a leading `'`.
6. No donations view: 403, no data. Bad dates: 303 to `dates`. Empty range: BOM plus header only.
7. The cash-file tests (`test_cash_export_file`, `test_empty_range_is_header_only`, `test_bad_dates_redirect_without_a_file`,
   `test_permissions`, `test_method_and_token`) pass unchanged; only the two tests named in Scope change.
8. On Data & Imports, the Non-cash row shows "Export CSV" with donations view and "No access" without it.

## Verification plan

- `make seed-demo`, then `make test-exports` (cash tests unchanged plus new non-cash tests), `make test-auth`,
  `make test-donations`, `make test-donors`, `make test-gate`, and `php -l` on the changed PHP files.
- Non-cash fixtures dated in 1901 for the suite's marked donor and campaign: on 1901-02-28 (outside), 03-01 (with
  condition, weight, value, receipt, recorder, notes `=SUM(1)`, intended use `+Pantry`, and two distributions of
  3 and 2), 03-10 for donor 999, 03-31 with a NULL weight and NULL condition, and 04-01 (outside).
- Tests: the file and its headers; range ends and order; the labels, blank cells and decimals; given out = 5.00 and
  0.00; Anonymous; formula escaping; staff and viewer get 200 with data; 403 without donations view with no CSV
  content in the body; blank Donor ID without donors view; bad dates; empty range; the page buttons. A second
  run of the suite passes, which shows teardown and clean leave nothing behind.
- Manual (not automated): export a demo month and compare it with the non-cash rows on the donations list.

## Risks

- Moving the shared steps into `export_range_start()` touches the cash path. The cash tests are kept as they are to
  catch any change in its output or order.
- Large ranges: the result is streamed row by row, as cash is.
- No migration, rebuild or re-login is needed after merge.

## Responses to round 1

Round 1: not approved, with 1 confirmed critical (correctness) and 2 highs that report the same issue (data and verification).

- **AC7 cannot be met / unknown-exports test uses `noncash` (critical, high):** fixed. AC7 now names the cash-file
  tests that stay unchanged. Scope names the two tests that change and how they change.
- **Teardown not updated; RESTRICT donor FK (high):** fixed. Teardown deletes non-cash gifts by suite donor or
  campaign first; clean follows the same order.
- **Donor-999 gift must carry the suite campaign (medium x2):** fixed. Every non-cash fixture uses the suite campaign.
- **export_range_start() must exit on failure (low):** fixed. It ends the request itself, and the 403 test checks for no CSV content.
- **Cleanup never by donor 999 or date alone (low):** fixed. Stated in clean's rule.
- **Distributions emptiness unclear (low):** fixed. The count covers `non_cash_donations.date_received` only.
- **Subquery source and COALESCE (low):** fixed. The query cites `donation_get()` and is written out.
- **README two places; comments (low x3):** fixed. Both README passages and the three comments are in Scope.
- **Staff and viewer success paths (low):** fixed. Added to the tests.
