# Ticket: UF2-139: export a contact list of LYBUNT and SYBUNT donors as a CSV

Branch: `wagaman-eure-export-lapsed`, off `origin/dev` at 291b549 (includes PR #57, UF2-137). PR into `dev`.

Jira UF2-139 (Task, Sprint-4 / Sprint-7, assignee Cory Wagaman-Eure, no description):

> Export contact list for LYBUNT and SYBUNT donors

The front end row already exists in `dataImports.php` (Tristan), with no filter:

> 'lapsed' => ['LYBUNT and SYBUNT contact list', 'Contact info for donors who gave before but not this year.', false]

Decisions (author defaults, 2026-11-23; Cory has answered "idk" on similar choices, so these follow the existing
exports and are easy to change):
- LYBUNT and SYBUNT mean the app's existing statuses from `donor_summary.status` (added in migration 011, view as of 015): last gift (cash or
  non-cash) within 12-24 months (LYBUNT) or 24-36 months (SYBUNT) of the database's today, rolling, as the donor list
  page and the donor list export (UF2-136) show them. Not calendar years, so the file agrees with the rest of the app.
  So a donor who last gave 8 months ago (even if that was last calendar year) is Active and not in the file; the row
  description and README say so. Cory was told this default and that calendar years are a small change on request; no answer yet, so rolling is kept.
- It is an outreach list, so it follows the emails export (UF2-132): Do not contact donors and deactivated donors are
  left out; donor 999 is left out.
- No amounts, so it needs donors view only, as the donor list export (status and last gift date are already on
  `donor.php`).

## Problem

The "LYBUNT and SYBUNT contact list" row on Data & Imports has a disabled "Coming soon" button; it is the last one.
FISH staff need lapsed donors' contact details in a spreadsheet for win-back appeals.

## Scope

In:
- `include/permissions.php`: `fish_can_export_lapsed()` = `fish_can('donors', 'view')`, as the donor list export.
- `exportData.php`: a `lapsed` case, in this order: `fish_can_export_lapsed()` else 403 with a plain message
  (`You do not have access to the LYBUNT and SYBUNT export.`), no data, `exit`; `session_write_close()`; the query;
  the file. Any posted `from`, `to` or `year` is ignored.
  - Query (one prepared statement, no parameters, through `export_query()`): `donor_summary s` LEFT JOIN
    `donor_contacts c ON c.donor_id = s.id AND c.is_primary = 1 AND s.donor_type IN ('organization', 'foundation')`
    (as the donor list export), `WHERE s.is_anonymous = 0 AND s.is_active = 1 AND s.do_not_contact = 0 AND s.status
    IN ('lybunt', 'sybunt')`, `ORDER BY FIELD(s.status, 'lybunt', 'sybunt'), s.display_name, s.id` (explicit, not the strings' alphabetical order).
  - Header, exactly (17 columns): `["Donor ID", "Donor", "Type", "Status", "Last gift", "Email", "Phone", "Street",
    "City", "State", "ZIP", "Main contact", "Main contact email", "Main contact phone", "Preferred contact",
    "Email updates", "Mail receipts"]`.
  - Values: Type `donor_type_label()`; Status `donor_status_label()` (LYBUNT / SYBUNT); Last gift `last_gift_date`;
    Preferred contact `donor_pref_label()` (blank when NULL); Email updates and Mail receipts `Yes` / `No`; contact
    cells blank for individuals or without a main contact. Values as stored; every cell through `fish_csv_cell()`.
  - Filename: `fish-lybunt-sybunt-<YYYY-MM-DD>.csv` (not "lapsed": donor.php's Lapsed tile and filter
    also count Inactive donors), PHP's `date('Y-m-d')`.
  - A database error: `error_log`, redirect with `failed`, never a partial file.
- `dataImports.php`: `$exportReady['lapsed'] = fish_can_export_lapsed()`; the row's description becomes `Donors whose
  last gift was 12-36 months ago (LYBUNT, SYBUNT), with contact info. Leaves out donors who gave in the last 12 months,
  Inactive (36+ months or never), Do not contact and deactivated donors.`; header comment names it. All eight rows are then built, so the "Coming soon" label is never shown (kept
  in the template for a future row).
- `exportData.php` header comment names it.
- `tests/exports_integration.py`: fixtures and tests (below). Changed existing tests:
  `test_unknown_exports_are_not_available` uses only `junk` and `""` (every listed export is built);
  `test_page_buttons_and_messages` expects 8 export forms and 0 "Coming soon" for staff; `noexport` (no donations
  view) 4 "No access" / 4 "Export CSV"; `nodonors` 6 "No access" / 2 "Export CSV".
- README: the permission paragraph and the `make test-exports` paragraph name the export and its rules (rolling
  months, Inactive / Do not contact / deactivated left out, legacy gifts with an unparsed date do not affect status).

Out: calendar-year LYBUNT/SYBUNT; amounts (last gift amount, lifetime giving); donors with status Inactive (older than
36 months or never gave); other contact people than the main one; any schema change.

## Approach

Reuse `fish_csv_send`, `fish_csv_cell`, `export_back`, `export_query`, `donor_type_label()`, `donor_status_label()`,
`donor_pref_label()`; reads through `donor_summary` with `is_anonymous = 0`. New: the permission helper. No migration.

## Acceptance criteria

1. A user with donors view who posts `export=lapsed` gets a 200 `text/csv` attachment named
   `fish-lybunt-sybunt-<today>.csv` (PHP's date; the test reads `today` before and after), with a BOM and the
   17-column header above.
2. The file's ids are exactly the active, non-Do-not-contact donors (not 999) whose status is LYBUNT or SYBUNT, one row
   each, ordered LYBUNT first, then by name, then id.
3. Active-status, Inactive, Do not contact and deactivated donors have no row.
4. Status, Last gift, Type, Preferred contact and the two flags show as above; formula-leading text gets a leading `'`.
5. No donors view: 403, no data, no CSV content type. Donations view is not required. Bad CSRF token: 403.
6. The other export tests pass unchanged, except the changes listed in Scope.
7. On Data & Imports, the lapsed row shows "Export CSV" with donors view and "No access" without it.

## Verification plan

- `php -l` on the changed PHP files; `make seed-demo`, then `make test-exports` (twice), `make test-auth`,
  `make test-donors`, `make test-donations`, `make test-gate`; no ExportSuite donors left.
- Fixtures: the existing status donors (UF2-136): `active` (gift 6 months ago, full contact fields: street
  `=1 Main St`, phone, preferred `mail`, Mail receipts and Email updates Yes; no row), `lybunt` (18 months) and
  `sybunt` (30 months) (rows); the suite donor and the emails donors (1901 or no gifts: Inactive, no row).
  - New, in `status_donors` through the existing loop (teardown and the class cleanup already delete their gift,
    then the donor; `clean` finds them by the marker in `last`), each followed by a bound `UPDATE donors SET ... WHERE
    id = ?`: `lybunt_dnc` (`Dnclapsed`, gift 18 months ago, then `do_not_contact = 1`), `sybunt_gone` (`Gonelapsed`,
    gift 30 months ago, then `is_active = 0`), and `lybunt_org` (gift 18 months ago, then `donor_type =
    'organization'`, `org_name = '=Lapsed Org <marker>'`, email `lapsed.<marker>@example.test`, street `=2 Elm St`,
    city `Warrenton`, state `VA`, zip `20186`, phone `+1 540 555 0120`, `preferred_contact = 'phone'`,
    `email_updates = 1`, `mail_receipts = 1`), plus a direct bound `INSERT INTO donor_contacts (donor_id, name, email,
    phone, is_primary) VALUES (?, ?, ?, ?, 1)` for its main contact `-Pat <marker>`, `pat.<marker>@example.test`,
    `540-555-0121` (cascades with the donor).
  - No other expectation changes: the emails test checks only the suite donor's and the emails donors' rows plus a
    fixed left-out list; the donor list and donor totals tests compare ids to `active_ids` and check fixed order lists
    that skip ids not in them; the tax receipt test reads 1901 only.
  - New fixture action `lapsed_ids` returns `SELECT id FROM donor_summary WHERE is_anonymous = 0 AND is_active = 1 AND
    do_not_contact = 0 AND status IN ('lybunt', 'sybunt')`; the file's ids must equal that set (AC2). It mirrors the
    export's filter, so the named fixtures (AC3) are what prove the filter; a test comment says so.
- Tests: file and headers; filename with PHP's date; ids equal `lapsed_ids`, no duplicates, no 999; the suite rows'
  ids exactly [lybunt_org, lybunt, sybunt] in that order among suite ids (`'=Lapsed Org` sorts before `Lybunt` within
  LYBUNT), and no row for `active`, `lybunt_dnc`, `sybunt_gone` or the suite donor; LYBUNT rows before SYBUNT rows in the whole file; exact rows for `lybunt` and `sybunt` (Status,
  Last gift = their gift date, Type Individual, Email updates and Mail receipts No, other cells blank) and for `lybunt_org` (`'=Lapsed Org <m>`, Organization, LYBUNT, its gift date, `lapsed.<m>@example.test`,
  `'+1 540 555 0120`, `'=2 Elm St`, Warrenton, VA, 20186, `'-Pat <m>`, `pat.<m>@example.test`, `540-555-0121`, Phone, Yes, Yes); posted
  `from`/`to`/`year` ignored; permissions (staff, viewer, noexport 200; nodonors 403 with the message and no CSV);
  bad token 403; page buttons. A second run passes.
- Manual (not automated): export the donor list CSV and this file on the demo data; the donor list rows with Status
  LYBUNT or SYBUNT and Do not contact = No must have the same Donor IDs as this file.

## Risks

- Status uses the database's CURDATE() and the filename PHP's date, as in the donor list export; fixtures are months
  from the 12/24/36-month edges.
- Rolling statuses differ from the calendar-year LYBUNT some fundraising tools use; the description and README say
  "last gift 12-36 months ago".
- A legacy gift with an unparsed date (NULL `donation_date`) does not count toward status, as on the donor list.
- The view computes totals with correlated subqueries; fine at FISH's size, as the donor list export.
- No migration, rebuild or re-login is needed after merge.

## Responses to round 1

Round 1: approved, 0 critical, 2 high, 2 medium, 5 low. Changes in this revision:

- **AC4 untested (high x2):** fixed. A `lybunt_org` fixture with formula-leading name, street, phone and contact,
  preferred contact, Yes flags and a main contact; its exact row is asserted.
- **Rolling vs calendar (medium):** kept rolling as the author default (matches donor.php and the donor list export);
  Cory was told and can ask for calendar years. The description and README say who is left out.
- **Name clashes with donor.php's Lapsed (medium), manual check (low):** fixed. Filename `fish-lybunt-sybunt-*`, the
  description names Inactive as left out, and the manual check compares with the donor list CSV.
- **Fixture flags (low):** fixed. A bound UPDATE after each insert.
- **Sort by string order (low):** fixed. `FIELD(...)`.
- **Migration 011 (low):** fixed.
- **Unparsed legacy dates (low):** noted in Risks and README.
- **README (low):** fixed, in Scope.
- **Coming-soon branch unused, `unavailable` wording, 403 helper (low):** declined. The template branch stays for a
  future row; the message still fits unknown names.

## Responses to round 2

Round 2: approved, 0 critical, 0 high, 1 medium, 5 low. Changes in this revision:

- **Email columns blank in every checked row (medium):** fixed. `lybunt_org` and its main contact get emails (the
  emails test does not look at status donors, as the auditor found), asserted in its row.
- **How the contact is inserted (low):** fixed. A direct bound INSERT.
- **Repeated fixture sentences (low x2):** fixed. One paragraph.
- **Rolling decision unanswered (low):** recorded: no answer yet, rolling kept.
- **`lapsed_ids` mirrors the filter (low):** accepted, with a comment; the named fixtures prove the filter.

## Responses to round 3

Round 3: approved, 0 critical, 0 high, 0 medium, 1 low. Handled in the implementation, not by changing the approved
text above:

- **README still says three status donors (low):** the `make test-exports` paragraph is updated to name the new
  LYBUNT/SYBUNT donors.
