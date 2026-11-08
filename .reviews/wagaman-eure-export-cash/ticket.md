# Ticket: UF2-133: export all cash donations within a date range as a CSV

Branch: `wagaman-eure-export-cash`, off `origin/dev` at a68cf40 (includes PR #40). PR into `dev`.

Jira UF2-133 (Task, Sprint-4, reporter Tristan Chavez-Duran, no description):

> Export all cash donations within a date range

Confluence "Sprint #3" (67633206), Upcoming:

> Exports (UF2-132–139)

The front end already exists on `dev` (`dataImports.php`, Tristan, `.reviews/TCD-data-imports-frontend/`). Its
header comment:

> each export form posts `export` (+ from/to or year) to an export handler that streams a CSV

and its row for this export:

> 'cash' => ['Cash donations', 'Every cash gift between two dates.', 'range']

## Problem

The Export card on Data & Imports has a "Cash donations" row with From and To date boxes and an "Export CSV"
button, but every export form posts to `#`, so nothing downloads. FISH staff need the cash gifts for a period
(month, quarter, year) in a spreadsheet. This is the first of the eight exports (UF2-132..139), so it also adds
the shared pieces the other seven will reuse.

"Cash donations" means every row in the `donations` table, whatever the payment method (cash, check, card,
online, ...), the same as the Cash filter on the donations list. Non-cash gifts are a separate export (UF2-134).

## Scope

In:
- `include/csvExport.php` (new, no output of its own; required by the handler):
  - `fish_csv_cell($v)`: `null` becomes `''`; any other value is cast to string; a value that is a plain signed
    decimal (`/^-?\d+(\.\d+)?$/`) is written unchanged, so numbers stay numbers; any other string whose first
    character is `=`, `+`, `-`, `@`, a tab or a carriage return gets a leading `'` so Excel and Google Sheets
    do not run it as a formula (CSV injection). The numeric exemption matters: legacy rows can have
    amount 0.00 with a fee, so Net (`net_amount = amount - fee`) can be negative, e.g. `-10.00`.
  - `fish_csv_send($filename, array $header, iterable $rows)`: first calls `ini_set('display_errors', '0')`
    so a warning while rows are written goes to the log, not into the file; then sends `Content-Type: text/csv; charset=utf-8`,
    `Content-Disposition: attachment; filename="<filename>"` (filename built only from fixed text and validated
    dates, so no quoting issues), `Cache-Control: no-store`, `X-Content-Type-Options: nosniff`; writes a UTF-8
    BOM (Excel opens UTF-8 names correctly), then the header and each row with `fputcsv($out, $cells, ',', '"',
    '', "\r\n")` to `php://output`, every cell passed through `fish_csv_cell()`.
  - `fish_export_dates($from, $to)`: takes strings read with `fish_str($_POST, ...)` (so an array-valued
    `from[]` becomes `''` and fails validation, never a TypeError); both must pass `donation_valid_date()` (`Y-m-d`, 1900..2100) and
    `from <= to`; returns `[$from, $to]` or `null`. Both ends are inclusive.
- `exportData.php` (new, top level): the one handler every export form posts to.
  - `require_once 'include/fishnet-layout.php'`. Its `require_authentication(1)` already does the login check
    and, on every POST, the CSRF check (`auth_require_csrf`), so the handler adds no token code. Order: login,
    then CSRF on POST, then the handler's own check that the method is POST (else 405, no body).
  - `export` is read with `fish_str($_POST, 'export')`.
  - Permission: `fish_can('donations', 'view')`, else 403 with a plain message and no data. Cory decided
    (2026-11-08) that staff can export, not only admins and managers. Anyone who can view the donations list
    can download it: with donations view a user already sees, on `donations.php` and `viewDonation.php`, every
    column this file contains (donor name, fee, check number, honoree, receipt, recorder), so the export shows
    nothing new; it only puts it in one file. With the role presets this is admin, manager, staff and viewer.
    The rule lives in a helper `fish_can_export_donations()` in `include/permissions.php` so the page and the
    handler agree, and later exports get their own helper (e.g. hours exports need donors view).
  - After the permission check and date validation pass, `session_write_close()` releases the session lock
    before the query runs (README convention for export handlers).
  - `export` = `cash`: validate `from`/`to` with `fish_export_dates()`; on failure redirect 303 to
    `dataImports.php?export_error=dates#export` and send no CSV. Any other `export` value (the seven not built
    yet, or junk) redirects 303 to `dataImports.php?export_error=unavailable#export`.
  - Query (one prepared statement, `from`/`to` bound as strings), ordered `d.donation_date, d.id`. The
    statement is executed and `get_result()` called before `fish_csv_send()` sends any header; a
    `mysqli_sql_exception` there is logged with `error_log()` and answered with a 303 to
    `dataImports.php?export_error=failed#export`, never a partial file:
    `FROM donations d LEFT JOIN donor_summary s ON s.id = d.donorID LEFT JOIN dbevents e ON e.id = d.eventID
    LEFT JOIN dbpersons p ON p.id = d.recorded_by WHERE d.donation_date BETWEEN ? AND ?`. Reads donor names
    through `donor_summary` (CLAUDE.md); the anonymous donor's gifts are included (this lists gifts, not donor
    totals); for `is_anonymous = 1` the Donor column is the fixed text `Anonymous` (the view's display name for
    id 999 is not meant for people).
  - Columns, in order: Gift ID, Date, Donor ID, Donor, Amount, Fee, Net, Payment method, Check number,
    Platform, Deposit date, Campaign, In honor of (the honoree name when `in_honor_of = 1`, else blank; the
    same rule as `viewDonation.php`), Receipt sent (`DATE(receipt_sent_at)`, the stored server-local date, or
    blank), Recorded by (first name and last initial from the existing `donation_recorder_name()`, the same as
    the donations list), Notes (`reason`).
    Enum values (`payment_method`, `platform`) are exported as the same labels the donations pages show; reuse
    the existing label map from `database/dbDonations.php` if there is one, otherwise the raw value. Dates are
    `YYYY-MM-DD`.
  - Filename: `fish-cash-donations-<from>-to-<to>.csv`.
  - `get_result()` buffers the result set in PHP (fine at FISH's size, a few thousand rows); each row is
    turned into CSV cells and written as it is read. A range with no gifts still downloads a file with just the
    BOM and the header row.
- `dataImports.php`:
  - The Export card gets `id="export"`; the `cash` row's form posts to `exportData.php`. The other seven rows
    keep `action="#"` and their button is `disabled` with the text "Coming soon", so nobody posts a form that
    does nothing.
  - When `fish_can_export_donations()` is false (a custom account with donations view removed), the cash row's
    button is disabled with the text "No access" (the row stays visible so the layout does not change).
  - `export_error=dates` shows "Pick a From date on or before the To date.", `unavailable` shows "That export
    is not available yet." and `failed` shows "The export could not be made. Try again." in a `.form-errors`
    box above the export list (the class from `css/forms.css`, which the page already loads); any other value
    shows nothing. Messages are fixed strings, never the query value.
  - `css/imports.css`: a `.export-row .btn:disabled` rule (muted, `cursor: not-allowed`) so disabled buttons
    look disabled.
- `tests/auth_integration.py`: add `exportData.php` to `PROTECTED_PAGES` (anonymous POST redirects to login).
- `tests/exports_integration.py` (new) + `make test-exports` (target, `.PHONY` and `help` lines in the
  Makefile) + a `make test-exports` step in `.github/workflows/ci.yml` after `test-comms`. Same fixture pattern as `tests/donations_integration.py`
  (test users per role made and removed by the fixture, `[demo]` seed check, marked rows cleaned up).
- Docs, `README.md`: add `make test-exports` to the sentence listing what CI runs; add a `test-exports`
  paragraph next to the other per-target ones; reword the reports/exports sentence so it says generated CSV
  exports stream from `exportData.php` while stored report files still go through `download.php`.

Out:
- The other seven exports (UF2-132, 134..139): separate tickets; this one only builds the shared helper they
  will call.
- Excel `.xlsx` output, the legacy `export.php` / `python/ExportHandler.php` / `download.php?type=exports`
  path (CASA code, untouched).
- Gifts whose `donation_date` is NULL (legacy CASA rows with no parseable date) cannot be in any range and are
  not exported; the PR says so.
- No migration, no schema change. No change to the permission presets.
- Imports (UF2-125) and the Recent imports / Needs review tables.
- `docs/sequence-diagram/` and the stray report PDFs in the working tree are not part of this branch.

## Approach

- Reuse: `include/fishnet-layout.php` (login, `h()`, `fish_str()`), the existing CSRF check, `fish_can()`,
  `donation_valid_date()` and `donation_recorder_name()` from `database/dbDonations.php`, `donor_summary`.
- One handler file with a `switch` on `export`, so each later export adds one case plus its query.
- No HTML is ever written once headers for the CSV are sent; every failure path (method, token, permission,
  dates, unknown export) is decided before `fish_csv_send()` starts output.

## Acceptance criteria

1. A user with donations view (admin, manager, staff or viewer) picks From and To on the Cash donations row and clicks Export CSV: the browser downloads
   `fish-cash-donations-<from>-to-<to>.csv` with a header row and one row per cash gift whose `donation_date` is
   between From and To inclusive, ordered by date then id, with the columns listed above.
2. Gifts dated the day before From and the day after To are not in the file; gifts dated exactly From and
   exactly To are.
3. A range with no gifts downloads a file containing only the header row.
4. From after To, a missing date, an array-valued date (`from[]=x`), or an impossible date (`2026-02-30`,
   `abc`) redirects to Data & Imports with
   the dates message and returns no CSV.
5. A donor name, campaign name or note starting with `=`, `+`, `-` or `@` appears in the file with a leading
   `'`; commas, quotes and line breaks in notes survive a round trip through a CSV parser.
6. Admin, manager, staff and viewer (all have donations view) can export. A staff account with `donations`
   view removed gets 403 and no data from a direct POST, and the page shows the cash button disabled as
   "No access" for it.
7. Anonymous POST redirects to login; a POST without a valid CSRF token is refused like other POST pages; GET
   returns 405.
8. Any `export` value other than `cash` redirects with the "not available yet" message; the other seven buttons
   on the page are disabled.
9. `make test-auth`, `test-donors`, `test-users`, `test-donations`, `test-hours`, `test-comms`, `test-exports`,
   `test-gate` pass after `make seed-demo`; `php -l` on every touched PHP file; CI `tests` is green.

## Verification plan

- `make seed-demo`, then every test target in criterion 9.
- `tests/exports_integration.py` covers criteria 1 to 8. It uses fixed dates no seed or legacy row can reach
  (gifts in 1901-03, inside `donation_valid_date()`'s 1900..2100 bounds), and first asserts the database has
  no `donations` row in 1901 or on 1900-01-01..1900-01-02. The fixture inserts marked gifts on 1901-02-28,
  1901-03-01, 1901-03-15, 1901-03-31 and 1901-04-01 for a marked donor whose first name starts with `+`, for a
  campaign whose name starts with `@`, with notes `=SUM(1)`, `-x`, a leading tab, and one with a comma, a quote
  and a newline; one check gift and one card gift; one recorded by a known user; one with amount 0.00 and fee
  10.00. It posts the export for 1901-03-01..1901-03-31 as each role, parses the body with Python's `csv`
  module, filters rows to the marked donor id, and checks the response headers (`Content-Type`,
  `Content-Disposition`, `Cache-Control`), the BOM, the row set and order, the column values (Recorded by as
  `First L.`, Net `-10.00` unprefixed, every formula-like cell starting with `'`, the multi-line note intact),
  a gift for donor 999 showing `Anonymous`, and the redirects for bad input. Criterion 3 uses
  1900-01-01..1900-01-02 and asserts the body is exactly the BOM plus the header line.
- Manual: `make dev`, sign in as the demo manager, export a month that has demo gifts, open the file in a
  spreadsheet app and check the names and amounts against the donations list filtered to the same gifts; sign
  in as demo staff and confirm the export works for staff; check the disabled "Coming soon" buttons and an
  error message (From after To) look right.

## Risks

- Permission choice: Jira does not say who may export; Cory chose donations view (staff included, so viewers
  too). If FISH wants to restrict it later, only `fish_can_export_donations()` changes. The PR names this.
- After merge: no rebuild, migration or re-login needed; run `make test-exports` after `make seed-demo`.
- Teammates' later export tickets build on `include/csvExport.php` and `exportData.php`; changing their
  signatures later touches every export.
- `ci.yml` is edited (one new step), which the process treats like auth code; the PR diff shows only that step.
- `get_result()` buffers the whole result set; with FISH's data size (thousands of gifts) this is fine.

## Responses to round 1

Round 1 approved (no criticals). Changes since: Cory changed the permission rule (staff can export), so the
ticket is re-audited. Every round 1 finding:

- Non-string from/to: fixed (read with `fish_str`, array case in criterion 4 and the tests).
- PHP warnings in the CSV: fixed (`display_errors` off in `fish_csv_send`). The stack-wide php.ini default is a
  separate follow-up.
- Negative Net becomes text (data, correctness, verification): fixed (plain decimals are exempt from the prefix;
  test gift with fee > amount).
- Test ranges vs seed data / filtering by marked donor / empty-range date: fixed (1901 dates, emptiness
  asserted first, rows filtered by donor id, 1900-01-01..02 for the empty file).
- Recorded by format (three auditors): fixed (`First L.` from `donation_recorder_name()`, same as the list).
- receipt_sent_at is DATETIME: fixed (`DATE(receipt_sent_at)`).
- Anonymous donor name: fixed (`Anonymous` when `is_anonymous = 1`).
- In honor of rule: fixed (name only when `in_honor_of = 1`, as `viewDonation.php`).
- DB error after headers: fixed (execute and `get_result()` before any header; `export_error=failed`).
- get_result() streaming claim: fixed (wording).
- CSRF "confirm in implementation": fixed (automatic through `require_authentication`; order stated).
- What "cash" means: fixed (every `donations` row, any method; check and card gifts in the fixture).
- Criterion 5 coverage: fixed (`+`, `@`, `-`, tab and `=` cases in the fixture).
- README updates: fixed (three changes listed; Makefile `.PHONY`/`help`).
- Session lock: fixed (`session_write_close()` after the checks).
- Styles for errors and disabled buttons: fixed (`.form-errors`, new disabled rule).
- Post-merge steps: fixed (Risks).

## Responses to round 2

Round 2 approved (0 critical, 0 high). These clarifications were made after approval and were not re-audited;
the code gate reviews the result:

- 'Data imports' area does not govern exports (security, verification): accepted as designed; the README, a
  comment on `fish_can_export_donations()` and the PR say that donation exports follow donations view and the
  'Data imports' area governs imports only.
- Donor ID for accounts without donors view: fixed; the Donor ID cell is blank when `fish_can('donors','view')`
  is false, matching the list, which only links the id with donors view.
- Permission check placement (correctness, medium): the check is the first statement in `case 'cash':`, before
  date validation and `session_write_close()`; the default branch redirects 'unavailable' with no permission
  check (it returns no data). The tests post an unknown export as the no-donations-view account.
- `display_errors`: also turned off at the top of `exportData.php`, right after the include.
- Enum labels: `DONATION_PAYMENT_LABELS[$v] ?? (string) $v` and `DONATION_PLATFORM_LABELS[$v] ?? (string) $v`
  (blank for NULL).
- Criterion 1 wording: reads "A user with donations view (admin, manager, staff or viewer)". The
  `dataImports.php` header comment is updated to say the cash export posts to `exportData.php`.
- Donor 999 test gift: listed in the fixture (donorID 999, the marked campaign so teardown removes it, dated
  1901-03-10) and matched by Gift ID, not donor id.
- Crashed-run leftovers: setup first deletes 1901 and 1900-01-01..02 donations whose donor or campaign carries
  the suite's fixed name prefix, then asserts the ranges are empty.
- Admin test user: none; admin is covered by the presets (same donations view as manager). The suite uses
  manager, staff, viewer and a staff account with donations view removed.
- Risks memory wording: fixed below.
