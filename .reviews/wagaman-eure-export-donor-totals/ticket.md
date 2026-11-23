# Ticket: UF2-135: export every donor with their giving and volunteer hours totals as a CSV

Branch: `wagaman-eure-export-donor-totals`, off `origin/dev` at 579f218 (includes PR #55, UF2-136). PR into `dev`.

Jira UF2-135 (Task, Sprint-4 / Sprint-7, assignee Cory Wagaman-Eure, no description):

> Export all donors with a breakdown of hours and donations

Confluence "Sprint #3" (67633206), Upcoming:

> Exports (UF2-132–139)

The front end row already exists in `dataImports.php` (Tristan), with no filter:

> 'donor-totals' => ['Donors with hours and donations', 'Each donor with their total giving and volunteer hours.', false]

Decisions (Cory, 2026-11-23): lifetime totals (the row has no date filter); the same donors as the donor list export
(UF2-136): every active donor except 999, including donors with no gifts or hours (zeros), so the file is a
complete list. The export needs both donations view and donors view (decision below).

## Problem

The "Donors with hours and donations" row on Data & Imports has a disabled "Coming soon" button. FISH staff need each
donor's giving and volunteer totals in a spreadsheet (board reports, volunteer letters, year-end review). UF2-133, 134,
138, 132 and 136 built the shared pieces; this adds the `donor-totals` case.

## Scope

In:
- `include/permissions.php`: `fish_can_export_donor_totals()` = `fish_can('donations', 'view') && fish_can('donors',
  'view')`. Cash and non-cash amounts are donations data (the cash and non-cash exports need donations view; the
  profile's gift rows need it); hours and the donor list are donors view (the hours and donor list exports). A user
  who lacks either gets 403. (donor.php and the profile's tiles show lifetime cash to every signed-in user today;
  the export, a bulk file, keeps to the stricter rule of the existing exports.)
- `exportData.php`: a `donor-totals` case, in this order: `fish_can_export_donor_totals()` else 403 with a plain
  message (`You do not have access to the donor totals export.`), no data, `exit`; `session_write_close()`; the
  query; the file. Any posted `from`, `to` or `year` is ignored.
  - Query (one prepared statement, no parameters, through `export_query()`): `donor_summary s WHERE s.is_anonymous
    = 0 AND s.is_active = 1 ORDER BY s.display_name, s.id`. No joins: the view's per-donor totals are used as they
    are.
  - Columns, in order: Donor ID, Donor (`display_name`), Type (`donor_type_label()`), Status
    (`donor_status_label()`), Cash gifts (`cash_gift_count`), Cash total (gross) (`lifetime_cash`, before fees, as
    on the profile), Non-cash gifts (`noncash_gift_count`), Items donated (`items_donated`), Non-cash estimated value
    (`noncash_value`; gifts without an estimate add nothing), Volunteer shifts (`volunteer_shift_count`), Volunteer
    hours (`volunteer_hours`), First gift (`first_gift_date`, blank when none), Last gift (`last_gift_date`, blank
    when none). Numbers are written as the database returns them (`0`, `232.00`), so a spreadsheet reads them as
    numbers; every cell goes through `fish_csv_cell()`.
  - Filename: `fish-donor-totals-<YYYY-MM-DD>.csv`, PHP's `date('Y-m-d')` (America/New_York).
  - A database error: `error_log`, redirect with `failed`, never a partial file.
- `dataImports.php`: `$exportReady['donor-totals'] = fish_can_export_donor_totals()`; header comment names it.
- `exportData.php` header comment names it.
- `tests/exports_integration.py`: fixtures and tests (below). Changed existing tests, only in what they expect of
  unbuilt exports: `test_unknown_exports_are_not_available` uses `tax-receipt` instead of `donor-totals`;
  `test_hours_export_file`'s expected suite order gains the new `volunteer` entry after `last` (the hours export has
  no date range), and its row is checked (Volunteer donor, 3.00 hours); `test_donor_list_export_file`'s expected
  order gains `Items <marker>` (between Fdn and Lybunt) and `Volunteer <marker>` (after Sybunt);
  `test_page_buttons_and_messages` expects 6 export forms and 2 "Coming soon" for staff; `noexport` (no donations
  view) sees 3 "No access" and 3 "Export CSV"; `nodonors` sees 4 "No access" and 2 "Export CSV". Also the UF2-136
  code-review follow-ups: one blank line before `donor_list`, the class docstring names UF2-136 and UF2-135.
- README: the permission paragraph names the donor totals export and its two-area rule; the `make test-exports`
  paragraph names it and says the suite also makes three donors with recent-dated gifts (UF2-136 follow-up), and
  its sentence is fixed.

Out: date ranges or a year filter (UF2-137 is the per-year receipt); net (after-fee) totals; per-campaign breakdowns;
deactivated donors; donor 999 (anonymous gifts have no donor to total); any schema change; changing what donor.php
or the profile show or who can see it.

## Approach

Reuse `fish_csv_send`, `fish_csv_cell`, `export_back`, `export_query`, `donor_type_label()`,
`donor_status_label()`; reads go through `donor_summary` with `is_anonymous = 0`. New: the permission helper. No
migration.

## Acceptance criteria

1. A user with donations view and donors view who posts `export=donor-totals` gets a 200 `text/csv` attachment
   named `fish-donor-totals-<today>.csv` (PHP's date; the test reads `today` before and after and accepts either),
   with a BOM and the 13-column header above.
2. Every active donor except 999 has exactly one row (the file's ids equal the `active_ids` fixture); a deactivated
   donor has none; rows are ordered by name, then id.
3. Each total equals the donor's cash gifts, non-cash gifts and volunteer hours entries: counts, gross cash, items,
   estimated value, shifts, hours; a donor with none has `0` / `0.00` totals and blank dates.
4. Gifts and hours of donor 999 count toward no row.
5. Type and Status show labels; First and Last gift cover cash and non-cash.
6. Formula-leading names get a leading `'`; numbers are not escaped.
7. Without donations view, or without donors view: 403, no data, no CSV content type. Bad CSRF token: 403.
8. The other export tests pass unchanged, except the changes listed in Scope (page counts, the unknown-export name,
   the hours test's and donor-list test's expected order).
9. On Data & Imports, the donor-totals row shows "Export CSV" with both permissions and "No access" without either.

## Verification plan

- `php -l` on the changed PHP files; `make seed-demo`, then `make test-exports` (twice), `make test-auth`,
  `make test-donors`, `make test-donations`, `make test-gate`.
- Fixtures: the existing ones, plus two donors (below). The suite donor has 8 cash gifts (232.00 gross, one at 0.00 with a fee),
  4 non-cash gifts (54.00 items, 60.00 estimated value: only one gift has an estimate) and 3 hours entries (7.50
  hours), all in 1901; donor 999 has one of each on the suite campaign (not counted). The status donors have one
  25.00 cash gift each and nothing else; the emails donors have nothing (zeros, blank dates).
  - New: `Items <marker>` and `Volunteer <marker>` (both `donor_type` individual, `last` = marker, donor_since
    1900-06-01). `Items <marker>` with one non-cash gift on the suite campaign dated
    1901-05-01 (quantity 3, estimated value 15.00), and `Volunteer <marker>` one hours entry on the suite
    campaign dated 1901-07-01 (3.00 hours), recorded in `ids['hours']['volunteer']` so the hours test's map stays
    complete. The non-cash date is outside the March range the cash and non-cash tests use; the hours export has no
    range, so the hours test's expected list gains the entry (Scope). They show First = Last = 1901-05-01
    from a non-cash gift only (AC5), and a volunteer-only row (zero gifts, blank dates, 3.00 hours). Setup returns
    them under `totals_donors`; the class cleanup passes `totals_donors` to teardown, which deletes their non-cash gift and hours entry, then the donors; `clean`
    already removes 1901 non-cash and hours rows of prefixed donors. Expected cells: Items: `0`, `0.00`, `1`, `3.00`,
    `15.00`, `0`, `0.00`, 1901-05-01, 1901-05-01; Volunteer: `0`, `0.00`, `0`, `0.00`, `0.00`, `1`, `3.00`, blank,
    blank (Status Inactive for both).
- Tests: the request posts `from`, `to` and `year` (a March 1901 range) to show they are ignored: the suite
  donor's totals stay 232.00 / 7.50. File and headers; filename with PHP's date; the file's ids equal `active_ids`; exact rows for the suite
  donor, a status donor, the items-only and volunteer-only donors, and a donor with nothing; the suite rows in a fixed expected order; permissions (staff, manager and
  viewer 200: the viewer role has donations and donors view, as the cash and emails tests show; noexport 403;
  nodonors 403); bad token 403; page buttons. A second run passes.
- Manual (not automated): export on the demo data and compare one donor's totals with their profile tiles.

## Risks

- The view computes every total with correlated subqueries; at FISH's size (a few thousand donors) that is fine, and
  the donor list page already runs it.
- Cash total is gross; a reader may expect net. The column is named "Cash total (gross)" and the README says before fees.
- A legacy gift whose date did not parse (NULL `donation_date`) counts in Cash gifts and Cash total but not in
  First / Last gift, as on the profile tiles. The README says so.
- Status uses the database's date (CURDATE) and the filename PHP's, as in the donor list export.
- No migration, rebuild or re-login is needed after merge.

## Responses to round 1

Round 1: approved, 0 critical, 0 high, 1 medium, 3 low. Changes in this revision:

- **Fixtures cannot show non-cash dates (medium):** fixed. An items-only donor and a volunteer-only donor.
- **Posted dates not shown to be ignored (low):** fixed. The test posts a March 1901 range and year.
- **Undated legacy gifts (low):** accepted; noted in Risks and the README.
- **Two clocks (low):** accepted; noted in Risks, as in UF2-136.

## Responses to round 2

Round 2: approved, 0 critical, 1 high, 2 medium, 1 low. Changes in this revision:

- **The volunteer entry breaks the hours test (high, medium x2):** fixed. Scope and AC8 list the hours test's and the
  donor-list test's new expected order; the wrong "March ranges" sentence is corrected; cleanup passes
  `totals_donors`; both new donors are individuals.
- **Exact decimal strings (low):** fixed. Listed in the Verification plan.
