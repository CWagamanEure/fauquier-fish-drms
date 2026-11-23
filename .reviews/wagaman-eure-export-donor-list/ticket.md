# Ticket: UF2-136: export the donor list with contact info and giving status as a CSV

Branch: `wagaman-eure-export-donor-list`, off `origin/dev` at 9878e2d (includes PR #54, UF2-132). PR into `dev`.

Jira UF2-136 (Task, Sprint-4 / Sprint-7, assignee Cory Wagaman-Eure, reporter Tristan Chavez-Duran, no description):

> Export donor list with contact info and status

Confluence "Sprint #3" (67633206), Upcoming:

> Exports (UF2-132–139)

The front end row already exists in `dataImports.php` (Tristan), with no filter:

> 'donor-list' => ['Donor list with contact and status', 'Contact info and giving status (Active, LYBUNT, SYBUNT, Inactive).', false]

Product decision (Cory, 2026-11-23): this is the whole donor list, so it follows the donor list page (`donor.php`):
every donor that is not deactivated and not donor 999. Unlike the emails export (a mailing list), Do not contact
donors are included, with a Yes / No column.

## Problem

The "Donor list with contact and status" row on Data & Imports has a disabled "Coming soon" button. FISH staff need
the donor list with addresses and giving status in a spreadsheet (mail merges, board reports, cleanup). UF2-133,
134, 138 and 132 built the shared pieces (`include/csvExport.php`, `exportData.php`); this adds the `donor-list`
case.

## Scope

In:
- `include/permissions.php`: `fish_can_export_donor_list()` = `fish_can('donors', 'view')`, the rule of the hours and
  emails exports and of the profile's contact-people section. (`donor.php` and the profile's own fields have no
  donors-view check today, so the export's gate is stricter than those pages; that gap is pre-existing and left
  for a separate ticket.) Donations view is not needed and does not grant it. Status and Last gift come from gift
  dates, which `donor.php` already shows to every signed-in user; no amounts are exported. The README says so.
- `database/dbDonors.php`: `const DONOR_STATUS_LABELS = ['active' => 'Active', 'lybunt' => 'LYBUNT', 'sybunt' =>
  'SYBUNT', 'inactive' => 'Inactive']`, and `donor_status_label($enum)` returning `DONOR_STATUS_LABELS[$enum] ??
  'Inactive'` (the fallback `donor.php` uses). `donor.php` line 41 uses the constant instead of its local copy; its
  output does not change.
- `exportData.php`: `require_once 'database/dbDonors.php'` (for the label helpers), and
  a `donor-list` case, in this order: `fish_can_export_donor_list()` else 403 with a plain message (`You do not have
  access to the donor list export.`), no data, `exit`; `session_write_close()`; the query; the file. Any posted
  `from`, `to` or `year` is ignored.
  - Query (one prepared statement, no parameters, through `export_query()`): `donor_summary s` LEFT JOIN
    `donor_contacts c ON c.donor_id = s.id AND c.is_primary = 1 AND s.donor_type IN ('organization',
    'foundation')` (at most one row: `uq_donor_contacts_primary`), `WHERE s.is_anonymous = 0 AND s.is_active = 1`,
    `ORDER BY s.display_name, s.id`. The same rows `donor.php` lists under its "All" filter.
  - Columns, in order: Donor ID, Donor (`display_name`), Type (`donor_type_label()`), Email,
    Phone, Street, City, State, ZIP, Main contact, Main contact email, Main contact phone, Preferred contact
    (`donor_pref_label()`, blank when NULL), Do not contact, Email updates, Mail receipts (each Yes / No),
    Status (`donor_status_label()`), Last gift (`last_gift_date`, cash or non-cash, blank when none),
    Donor since (`donor_since_date`). Values are exported as stored (no trimming); every cell goes through
    `fish_csv_cell()`.
  - Filename: `fish-donor-list-<YYYY-MM-DD>.csv`, PHP's `date('Y-m-d')` (America/New_York).
  - A database error: `error_log`, redirect with `failed`, never a partial file.
- `dataImports.php`: `$exportReady['donor-list'] = fish_can_export_donor_list()`; header comment names it.
- `exportData.php` header comment names it.
- `tests/exports_integration.py`: fixtures and tests (below). Changed existing tests, only in what they expect of
  unbuilt exports: `test_page_buttons_and_messages` expects 5 export forms and 3 "Coming soon" for staff; `noexport`
  sees 2 "No access" and 3 "Export CSV"; `nodonors` sees 3 "No access" and 2 "Export CSV".
  `test_unknown_exports_are_not_available` keeps `donor-totals` (still unbuilt).
- README: the permission and `make test-exports` paragraphs name the donor list export and its rule.

Out: the other three exports (donor totals, tax receipts, LYBUNT/SYBUNT); lifetime totals and hours (UF2-135);
filtering by status or type; other contact people than the main one; deactivated donors; `donors.org_contact`
(legacy); any schema change; changing what `donor.php` shows or who can see it; an `export_deny()` helper for the
repeated 403 block (kept as in the other cases).

## Approach

Reuse `fish_csv_send`, `fish_csv_cell`, `export_back`, `export_query`; reads go through `donor_summary` with
`is_anonymous = 0`. New: the permission helper and the status-label constant and helper. No migration.

## Acceptance criteria

1. A user with donors view who posts `export=donor-list` gets a 200 `text/csv` attachment named
   `fish-donor-list-<today>.csv` (PHP's date; the test reads `today` before and after and accepts either), with a
   BOM and the 19-column header above.
2. Every active donor except 999 has exactly one row; a deactivated donor has none; the rows are ordered by name,
   then id.
3. Status is Active / LYBUNT / SYBUNT / Inactive from the view (last gift within 12, 12-24, 24-36 months, or
   older / never); Last gift is that date or blank.
4. An organization or foundation with a main contact shows that contact's name, email and phone; without one, or
   for an individual (even with a leftover main-contact row), those cells are blank.
5. Type, Preferred contact and the three flags show labels (Yes / No); NULL fields are blank cells.
6. A Do not contact donor is included, with Do not contact = Yes.
7. Formula-leading text (name, street, contact) gets a leading `'`.
8. No donors view: 403, no data, no CSV content type. Donations view is not required.
9. The cash, non-cash, hours and emails tests pass unchanged (except the page test counts in Scope).
10. On Data & Imports, the donor-list row shows "Export CSV" with donors view and "No access" without it.

## Verification plan

- `php -l` on the changed PHP files; `make seed-demo`, then `make test-exports` (twice), `make test-auth`,
  `make test-donors`, `make test-donations`, `make test-gate`.
- Fixtures: the existing suite donors (the 1901 gifts make the suite donor Inactive; the emails-export donors cover
  Do not contact, deactivated, foundation and individual). Changed emails fixtures: `fran` (fdn's only contact)
  and `hidden` (solo's leftover contact) get `is_primary = 1` and phones, so the type condition on the join is
  tested; `main` (org) gets phone `+1 540 555 0100` (formula-leading). The emails test's expected rows do not
  change (fdn has one contact; individuals' contacts are excluded there).
  - New fixture action `active_ids` returns the ids from `SELECT id FROM donor_summary WHERE is_anonymous = 0 AND
    is_active = 1`; the test asserts the file's Donor IDs equal that set (AC2). New for status: three donors whose `last`
  has the suite prefix, each with one cash gift dated relative to PHP's today: 6 months ago (Active), 18 months
  (LYBUNT), 30 months (SYBUNT), far from the 12/24/36-month edges so the database's CURDATE and PHP's date cannot
  disagree. The Active donor gets full contact fields (street `=1 Main St`, city, state, ZIP, phone, preferred
  `mail`, `mail_receipts = 1`).
  - `setup` returns them under `status_donors`; `teardown` deletes their gifts, then the donors.
  - `clean` also deletes donations of any donor whose `last` has the suite prefix (whatever the date) before the
    donor delete, so a crashed run's recent-dated gifts cannot block cleanup. Never by date alone, never donor 999.
- Tests: file and headers; filename with PHP's date; the suite's rows picked out by Donor ID (exact row values for
  the status donors, the org with a main contact, the foundation, the individual with a leftover contact, the Do not
  contact donor; no row for the deactivated donor or 999; one row per donor id in the whole file; the suite rows' ids
  in a fixed expected order written in the test, not Python's sort, with fixture names chosen so the order is
  the same case-insensitively); permissions (noexport and viewer 200; nodonors 403 with no CSV; bad token 403); page buttons. A second run
  passes.
- Manual (not automated): export on the demo data; the row count matches `donor.php`'s total, and the non-Active
  rows match its Lapsed tile.

## Risks

- The whole donor list goes into one buffered result; fine at FISH's size.
- Status depends on the database's CURDATE(); the test keeps its gifts months away from the edges.
- The status donors' gifts are recent, so while the suite runs they appear in date ranges other suites might use;
  the export suite runs alone and removes them in teardown and clean.
- A ZIP with a leading zero opens in Excel without it (`fish_csv_cell` leaves digit-only values bare). That is the
  shared helper's behaviour; FISH's local ZIPs start with 2. Left for a later change to the helper.
- No migration, rebuild or re-login is needed after merge.

## Responses to round 1

Round 1: approved, 0 critical, 1 high, 2 medium, 8 low. Changes in this revision:

- **AC4 not tested by the fixtures (high, medium):** fixed. `fran` and `hidden` become main contacts, so a join
  without the type condition would fail the test.
- **AC2 only checks suite rows (medium):** fixed. An `active_ids` fixture; the file's ids must equal that set.
- **Permission rationale wrong (medium):** fixed. The Scope no longer says the list page checks donors view and
  names the pre-existing gap.
- **Pre-existing: list and profile have no donors-view check (low):** accepted, out of scope; to be raised as a
  separate ticket.
- **Last gift to users without donations view (low):** accepted; already on screen, no amounts. README states it.
- **Python sort vs collation (low x2):** fixed. Fixed expected id order.
- **Duplicate status labels, no fallback (low):** fixed. Constant plus `donor_status_label()`; `donor.php` uses it.
- **Reuse label helpers (low):** fixed. `donor_type_label()`, `donor_pref_label()`.
- **Manual check not doable (low):** fixed. Total and Lapsed tile.
- **AC7 contact text untested (low):** fixed. `main` gets a formula-leading phone.
- **ZIP leading zero (low):** accepted, noted in Risks.
- **403 block repeated (low, optional):** declined; kept consistent with the other cases.

## Responses to round 2

Round 2: approved, 0 critical, 0 high, 0 medium, 4 low. Handled in the implementation, not by changing the approved
text above:

- **Status donors' Donor since (low):** the status donors get `donor_since = '1900-06-01'` like the other suite
  donors, so Donor since is `1900-06-01`. A NULL Donor since is not covered by a fixture (only legacy rows have one).
- **Previous record path was an object (low):** author error in the workflow arguments (`previous` must be the path
  string), not a script bug. Round 2 auditors found the record themselves.
- **Third copy of the labels in reports.php (low):** accepted; reports.php keeps its own colour map, out of scope.
- **Cleanup must pass status_donors (low):** the class cleanup's teardown call passes `status_donors`; checked in the
  code review.
