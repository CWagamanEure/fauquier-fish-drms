# Ticket: UF2-138: export all volunteer hours as a CSV

Branch: `wagaman-eure-export-hours`, off `origin/dev` at fc7424d (includes PR #41 UF2-133 and PR #42 UF2-134). PR into `dev`.

Jira UF2-138 (Task, Sprint-4, assignee Cory Wagaman-Eure, reporter Tristan Chavez-Duran, no description):

> Export all volunteer hours

Confluence "Sprint #3" (67633206), Upcoming:

> Exports (UF2-132–139)

The front end row already exists in `dataImports.php` (Tristan), with no filter:

> 'hours' => ['All volunteer hours', 'Every volunteer hours entry.', false]

## Problem

The "All volunteer hours" row on Data & Imports has a disabled "Coming soon" button. FISH staff need every logged
shift in a spreadsheet (service-hour letters, volunteer totals, grant reports). UF2-133 and UF2-134 built the shared
pieces (`include/csvExport.php`, `exportData.php`); this adds the `hours` case.

## Scope

In:
- `include/permissions.php`: `fish_can_export_hours()` = `fish_can('donors', 'view')`. Hours are read today on
  `hours.php`, `addHours.php` and the donor profile's Volunteer tab, each gated by donors view only (`hours.php` and
  `addHours.php` answer 403 / read-only without it). The columns are the fields `hours.php` shows for one entry;
  what is new is a bulk download of everyone's entries, under the same permission. Donations view is not needed and does not grant it.
- `exportData.php`:
  - `export_query($sql, $what, array $params = [])`: the existing helper takes its values as an array and runs
    `$stmt->execute($params)` (or `execute()` when the array is empty) instead of always binding `'ss'`. The cash
    and noncash calls pass `[$from, $to]`; their SQL, columns and output do not change. The catch path (error_log, then
    `export_back('failed')`, which exits) is unchanged.
  - an `hours` case, in this order: `fish_can_export_hours()` else 403 with a plain message, no data, `exit` (the
    same 403 body shape as the donation exports, with its own message); `session_write_close()`; the query; the file.
    There are no dates to check; any `from`, `to` or `year` that is posted is ignored.
  - Query (one prepared statement, no parameters): `volunteer_hours v` LEFT JOIN `donor_summary s ON s.id =
    v.donor_id`, `dbevents e ON e.id = v.campaign_id`, `dbpersons p ON p.id = v.recorded_by`, `ORDER BY
    v.activity_date, v.id`. Every row is exported (no date or active filter), including entries of inactive donors.
  - Columns, in order: Entry ID, Date, Volunteer ID, Volunteer ("Anonymous" when `is_anonymous`), Hours, Start
    time, End time, Job, Location, Campaign, Verified (Yes / No), Counts for letter (Yes / No), Recorded by
    (`donation_recorder_name`), Notes. Hours is the stored decimal (e.g. `4.50`); start and end are `HH:MM`
    (`TIME_FORMAT(..., '%H:%i')`, blank when NULL). Every cell goes through `fish_csv_cell()`.
  - Volunteer ID is always shown: the export already requires donors view, which is the condition the donation
    exports use for showing Donor ID.
  - Filename: `fish-volunteer-hours-<YYYY-MM-DD>.csv`, PHP's current date (`date('Y-m-d')`), which is
    America/New_York (`docker/php.ini` and `include/fishnet-layout.php` both set it).
  - A database error: `error_log`, redirect with `failed`, never a partial file (the query runs before any header).
- `dataImports.php`: `$exportReady['hours'] = fish_can_export_hours()`.
- `tests/exports_integration.py`: hours fixtures and tests (below), with these fixture rules:
  - Every hours fixture is dated in 1901 and belongs to the suite donor or the suite campaign: the suite donor's
    entries use the suite campaign except the one NULL-campaign row, and the donor-999 entry always uses the suite
    campaign, so clean and teardown can always find it.
  - A `today` fixture action returns PHP's `date('Y-m-d')` (as `tests/volunteer_hours_integration.py` does).
  - `clean` first deletes `volunteer_hours` rows dated in 1901 or 1900-01-01..02 whose donor last name has the
    prefix OR whose campaign name is LIKE '@' + prefix (`volunteer_hours_changes` goes by `ON DELETE CASCADE`),
    alongside the non-cash delete, before the donations, dbevents and donors deletes. Never by date alone and
    never by donor 999. Its emptiness count also counts `volunteer_hours` with `activity_date` in those windows.
  - `teardown`: `DELETE FROM volunteer_hours WHERE donor_id = ? OR campaign_id = ?` before the donors delete
    (`fk_vh_donor` is RESTRICT, `fk_vh_campaign` is SET NULL).
  - Changed existing tests, only in what they expect of the page or unbuilt exports:
    `test_page_buttons_and_messages` expects 5 "Coming soon" buttons and 3 export forms for staff; `noexport`
    (no donations view) sees 2 "No access" buttons and 1 "Export CSV"; a new check that `nodonors` sees 1
    "No access" (hours) and 2 "Export CSV". `test_unknown_exports_are_not_available` is unchanged (it does not list
    `hours`).
- README: the permission paragraph and the `make test-exports` paragraph name the hours export and its rule.
- Comments: the header comments of `exportData.php` and `dataImports.php` say cash, non-cash and hours.

Out: the other five exports; any schema change; filtering by date, volunteer or campaign; the change history
table (`volunteer_hours_changes`); legacy `export.php`.

## Approach

Reuse `fish_csv_send`, `fish_csv_cell`, `export_back`, `export_query`, `donation_recorder_name`. The only new
function is the permission helper. No migration.

## Acceptance criteria

1. A user with donors view who posts `export=hours` gets a 200 `text/csv` attachment named
   `fish-volunteer-hours-<today>.csv` (PHP's date, America/New_York; the test reads `today` from PHP before and
   after the request and accepts either), starting with a BOM, then the 14-column header above.
2. It holds every `volunteer_hours` row, ordered by date and then id. The test checks that every suite entry is
   present, in that order relative to each other, and that the file has no other rows dated in 1901 (none exist,
   by clean's emptiness check).
3. Hours is a plain decimal; start and end are `HH:MM` or blank; Verified and Counts for letter are Yes / No; a
   NULL job, location, campaign, recorder or notes is a blank cell.
4. Donor 999 shows as "Anonymous" (a fixture row for 999 on the suite campaign; the app itself never writes hours for
   999); the suite donor shows its display name.
5. Formula-leading text in job, location, notes, campaign or volunteer name gets a leading `'`.
6. No donors view: 403, no data, no CSV content type. Donations view is not required (staff without donations
   view gets the file).
7. The cash and non-cash tests that exist now pass unchanged (except the page test change in Scope).
8. On Data & Imports, the hours row shows "Export CSV" with donors view and "No access" without it.

## Verification plan

- `php -l` on the changed PHP files; `make seed-demo`, then `make test-exports` (twice), `make test-auth`,
  `make test-donations`, `make test-donors`, `make test-gate`.
- Hours fixtures for the suite donor and campaign: 1901-03-01 (4.50 h, 09:00-13:30, job `=Driver`, location
  `+Annex`, notes `@note`, verified 1, counts for letter 1, recorder set) and 1901-03-01 again (a second id, to show
  the id tiebreak), and 1901-06-15 (2.00 h, no times, NULL job, location, campaign and notes, flags 0, no
  recorder), plus 1901-03-10 for donor 999 on the suite campaign. The test picks the suite's rows out of the file by entry id and checks their relative order; it does
  not assume where they sit in the file.
- Tests: file and headers; filename with PHP's date (from the `today` action, called before and after; never
  Python's `date.today()` or `CURDATE()`); Anonymous for 999; order; values, blanks, Yes/No, time format, decimals;
  escaping; noexport (no donations view) gets 200 with data; nodonors gets 403 with no CSV; viewer gets 200;
  the page buttons. A second run passes.
- Manual (not automated): export on the demo data and compare a volunteer's rows with their Volunteer tab.

## Risks

- `export_query()` now takes its bindings; the cash and non-cash tests guard their output.
- The whole table is exported. `get_result()` buffers the full result in PHP memory and only the CSV output is
  written row by row; at FISH's size (thousands of rows) that is fine.
- The filename date is America/New_York, which can differ from a user's own date if they are in another zone.
- No migration, rebuild or re-login is needed after merge.

## Responses to round 1

Round 1: approved, 0 critical, 1 high, 2 medium, 7 low. Every finding is fixed in this revision.

- **Filename-date test fails in the evening (high) / timezone not stated (medium):** fixed. The date is PHP's
  (America/New_York). The test reads it from a `today` fixture action before and after the request. Risks corrected.
- **AC4 has no donor-999 fixture (medium, low x2):** fixed. Added a 1901-03-10 row for 999 on the suite campaign, and AC4
  is now tested. The app still never writes hours for 999.
- **Fixture campaign rule contradicts the NULL-campaign row (low):** fixed. The rule now names the exception.
- **"Streamed row by row" (low x2):** fixed. The risk now says the result is buffered.
- **Simpler export_query (low):** taken. It uses `execute($params)`.
- **Column claim (low):** reworded to "the fields hours.php shows for one entry; bulk download is new".
