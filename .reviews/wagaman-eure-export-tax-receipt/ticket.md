# Ticket: UF2-137: export a tax receipt summary of each donor's giving for a chosen year as a CSV

Branch: `wagaman-eure-export-tax-receipt`, off `origin/dev` at 8c3d8b5 (includes PR #56, UF2-135). PR into `dev`.

Jira UF2-137 (Task, Sprint-4 / Sprint-7, assignee Cory Wagaman-Eure, no description):

> Export donor tax receipt (summary of the year's donations)

The front end row already exists in `dataImports.php` (Tristan), with a year select (this year and the 5 before,
last year selected):

> 'tax-receipt' => ['Donor tax receipts', 'One row per donor: their total giving for the chosen year.', 'year']

Decisions (Cory, 2026-11-23): a donor gets a row when they gave money or items in the year; item gifts show a count
and a list of the items, with no dollar value (FISH describes non-cash gifts, the donor values them). Cory was unsure
("idk") on the rest, so these defaults were chosen by the author and are easy to change: Do not contact donors are
included with a Yes / No column (a receipt is a tax record, not an appeal); deactivated donors who gave in the year
are included with an Active Yes / No column (the gift happened; staff decide); dated cash gifts with no amount
(legacy rows) are not counted in Cash gifts or Cash total but shown in a "Gifts with no amount" column; organizations
and foundations show their main contact, as in the donor list export. Donor 999 is left out.

## Problem

The "Donor tax receipts" row on Data & Imports has a disabled "Coming soon" button. FISH staff send year-end
giving summaries that donors use for their taxes; they need, per donor, the year's cash total and the item gifts, with
a mailing address. UF2-133, 134, 138, 132, 136 and 135 built the shared pieces; this adds the `tax-receipt` case and
the first export with a `year` filter.

## Scope

In:
- `include/permissions.php`: `fish_can_export_tax_receipts()` = `fish_can('donations', 'view') && fish_can('donors',
  'view')`, as the donor totals export: amounts are donations data, addresses are donors data. Either missing: 403.
- `include/csvExport.php`: `fish_export_year($year)`: returns `[$from, $to]` (`YYYY-01-01`, `YYYY-12-31`) when
  `$year` is a string matching `/^\d{4}\z/` between 1900 and PHP's current year, else `null`. Unit-free; used only
  by this export.
- `exportData.php`: a `tax-receipt` case, in this order: `fish_can_export_tax_receipts()` else 403 with a plain
  message (`You do not have access to the tax receipt export.`), no data, `exit`; the year (`fish_export_year`,
  else `export_back('year')`); `session_write_close()`; the query; the file. Posted `from` / `to` are ignored.
  - Query (one prepared statement, through `export_query()`, the year's two dates bound twice):
    ```sql
    SELECT s.id, s.display_name, s.donor_type, k.name AS contact_name, k.email AS contact_email, s.email, s.street,
           s.city, s.state, s.zip, s.mail_receipts, s.do_not_contact, s.is_active,
           COALESCE(c.n, 0) AS cash_n, COALESCE(c.total, 0.00) AS cash_total, COALESCE(c.no_amount, 0) AS no_amount,
           n.id AS item_id, n.item_description, n.quantity
    FROM donor_summary s
    LEFT JOIN donor_contacts k ON k.donor_id = s.id AND k.is_primary = 1 AND s.donor_type IN ('organization', 'foundation')
    LEFT JOIN (SELECT donorID, COUNT(amount) AS n, COALESCE(SUM(amount), 0.00) AS total,
                      SUM(amount IS NULL) AS no_amount
               FROM donations WHERE donation_date BETWEEN ? AND ? GROUP BY donorID) c ON c.donorID = s.id
    LEFT JOIN non_cash_donations n ON n.donor_id = s.id AND n.date_received BETWEEN ? AND ?
    WHERE s.is_anonymous = 0 AND (c.donorID IS NOT NULL OR n.id IS NOT NULL)
    ORDER BY s.display_name, s.id, n.date_received, n.id
    ```
    One row per item gift (or one row for a cash-only donor); the generator groups consecutive rows by donor id
    into one CSV row, so the item list is never truncated (no `GROUP_CONCAT` length limit).
  - Header, exactly (18 columns): `["Donor ID", "Donor", "Type", "Main contact", "Main contact email", "Email",
    "Street", "City", "State", "ZIP", "Mail receipts", "Do not contact", "Active", "Cash gifts", "Cash total (gross)",
    "Gifts with no amount", "Non-cash gifts", "Non-cash items"]`.
  - Values: Donor is `display_name`; Type `donor_type_label()`; Main contact and its email blank for individuals or
    without a main contact (`uq_donor_contacts_primary`: at most one); Mail receipts, Do not contact, Active `Yes` /
    `No`; Cash gifts counts the year's gifts with an amount (a 0.00 gift counts); Cash total (gross) is their sum,
    before processing fees, as in the donor totals export; Gifts with no amount counts the year's dated gifts whose
    amount is NULL; Non-cash gifts counts the year's item gifts; Non-cash items is `<description> (<quantity>)` per
    item gift in date order joined with `; `, quantity without trailing zeros (`10.00` → `10`, `2.50` → `2.5`),
    blank when none. Every cell through `fish_csv_cell()` (only a cell's first character is escaped); numbers as
    the database returns them.
  - Filename: `fish-tax-receipts-<year>.csv`. `year` read with `fish_str()`, so `year[]` is not a string and fails.
  - A database error: `error_log`, redirect with `failed`, never a partial file.
- `dataImports.php`: `$exportReady['tax-receipt'] = fish_can_export_tax_receipts()`; a new error message `'year' =>
  'Pick a tax year.'`; the row's description becomes `One row per donor who gave that year: cash total and items given,
  with mailing address. Includes deactivated and Do not contact donors, flagged.`; header comment names it.
- `exportData.php` header comment names it.
- `tests/exports_integration.py`: tests (below). Changed existing tests: `test_unknown_exports_are_not_available`
  uses `lapsed`; `test_page_buttons_and_messages` expects 7 export forms and 1 "Coming soon" for staff; `noexport`
  4 "No access" / 3 "Export CSV"; `nodonors` 5 "No access" / 2 "Export CSV"; the message check gains `year`.
  Fixture changes (below): the guard windows widen, and the deactivated `gone` donor gets gifts and flags.
- The fixture guard's three error messages, the class docstring and the README test paragraph name the widened
  window (1900-12-31 to 1902-12-31, plus 1900-01-01..02). The "next year" bad-year case is computed from
  `fixture("today")` (PHP's clock).
- README: the permission paragraph and the `make test-exports` paragraph name the export, its rule, that undated
  legacy gifts (NULL `donation_date`) are in no year, that deactivated donors are included and anonymous gifts are
  not (so its totals need not match the cash export's), and the "Gifts with no amount" column.

Out: printable or emailed receipt letters, marking receipts sent (`receipt_sent_at`), net totals, non-cash values,
per-gift cash lines, campaigns, volunteer hours (not deductible), the LYBUNT/SYBUNT export (UF2-139), schema
changes.

## Approach

Reuse `fish_csv_send`, `fish_csv_cell`, `export_back`, `export_query`, `donor_type_label()`; donor fields come from
`donor_summary` with `is_anonymous = 0`; gift rows from `donations` and `non_cash_donations` by their typed dates. New:
the permission helper, `fish_export_year()`, the `year` error message. No migration.

## Acceptance criteria

1. A user with donations view and donors view who posts `export=tax-receipt&year=1901` gets a 200 `text/csv`
   attachment named `fish-tax-receipts-1901.csv`, with a BOM and the 18-column header above.
2. Exactly the donors (active or deactivated, not 999) with a cash gift dated in the year or a non-cash gift
   received in the year have one row each, ordered by name then id; both ends of the year count (01-01, 12-31), a
   gift on 1900-12-31 does not count for 1901.
3. Cash gifts and Cash total count and add the year's gifts with an amount (gross, a 0.00 gift counts); a NULL
   amount counts only in Gifts with no amount; Non-cash gifts and items list the year's item gifts in date order,
   quantities without trailing zeros.
4. A donor with only item gifts has `0` / `0.00` cash; a donor with only cash has `0` non-cash gifts and a blank
   item list.
5. Type, Main contact, Mail receipts, Do not contact and Active show as above (Yes and No both tested);
   formula-leading text gets a leading `'`.
6. A missing, malformed or out-of-range year (none, `''`, `19o1`, `1899`, next year, `1901-01`, `year[]=1901`) redirects to
   `dataImports.php?export_error=year#export` with no file; the page shows "Pick a tax year.".
7. Without donations view, or without donors view: 403, no data, no CSV content type. Bad CSRF token: 403.
8. A year with no gifts gives a header-only file.
9. The other export tests pass unchanged, except the page counts, messages and unknown-export name in Scope.
10. On Data & Imports, the tax-receipt row shows "Export CSV" with both permissions and "No access" without either.

## Verification plan

- `php -l` on the changed PHP files; `make seed-demo`, then `make test-exports` (twice), `make test-auth`,
  `make test-donors`, `make test-donations`, `make test-gate`.
- Guard: the three `$outside*` windows in the fixture runner widen from 1901 to `1900-12-31`..`1902-12-31` (plus
  1900-01-01..02 as now), so setup refuses to run if anything outside the suite is dated there, and `clean` removes
  suite leftovers there.
- Fixtures already in 1901: the suite donor's 8 cash gifts (1901-02-28..04-01: 232.00 gross, one 0.00 with a fee)
  and 4 non-cash gifts (`Canned beans` 10 on 02-28, `-Canned beans` 24 on 03-01, `Canned beans` 10 on 03-31 and
  04-01); the items-only donor (`Canned beans` 3, 1901-05-01); donor 999's gifts (not exported); the volunteer-only
  donor (hours only: no row); the other emails donors and status donors (no 1901 gifts: no row).
- Changed fixtures: the items-only donor gets `mail_receipts = 1` and `do_not_contact = 1` (it has no email, so the
  emails export is unaffected, and the donor list and totals tests do not check those cells for it). The
  deactivated foundation `gone` gets `mail_receipts = 1` (Do not contact stays 0, so the emails test still proves
  the `is_active` filter), its contact
  `is_primary = 1`, and, on the suite campaign (so teardown's campaign deletes remove them): cash 25.00 on
  1901-01-01, cash with NULL amount on 1901-12-31, cash 25.00 on 1900-12-31 (outside), and a non-cash gift `Coats`
  quantity 2.50 on 1901-12-31. It stays out of the emails, donor list and donor totals exports (deactivated), and its
  dates are outside the March ranges, so no other test's expectation changes.
- Tests: year 1901: file and headers; filename; the suite rows' ids exactly [suite donor, gone, items donor] in that order among suite
  ids, and no row for 999 or the volunteer donor; exact rows for all three (suite donor: `8`, `232.00`, `0`, `4`, `Canned
  beans (10); -Canned beans (24); Canned beans (10); Canned beans (10)`, Active Yes; items donor: `0`, `0.00`,
  `0`, `1`, `Canned beans (3)`, Mail receipts Yes, Do not contact Yes, Active Yes; gone: `Gone contact <m>`, its
  email, Mail receipts Yes, Do not contact No, Active No, `1`, `25.00`, `1`, `1`, `Coats (2.5)`); order +Export, Gone, Items; year 1902: header-only (guarded); posted `from`/`to` ignored; bad years redirect; permissions (staff, manager, viewer 200; noexport 403;
  nodonors 403); bad token 403; page buttons and the `year` message. A second run passes.
  - Note: only the first character of the whole cell is escaped, so the item list cell starts `Canned beans (10)`
    and needs no `'`; the escaping test is the suite donor's name `'+Export`.
- Manual (not automated): export last year on the demo data and compare one donor with their profile's gifts.

## Risks

- Undated legacy cash gifts (NULL `donation_date`) fall in no year and are never on a receipt; README says so.
- The year select offers only the last six years; the server accepts 1900..this year so the tests can use 1901.
- `donor_summary` is computed per donor before the join; at FISH's size that is fine (the donor list page runs it).
- The Do not contact, deactivated and no-amount rules are author defaults (Cory unsure); each is one condition and
  a test row to change.
- No migration, rebuild or re-login is needed after merge.

## Responses to round 1

Round 1: approved, 0 critical, 0 high, 4 medium, 8 low. Asked Cory about deactivated donors, no-amount gifts and the
main contact; Cory was unsure, so the author chose the defaults above. Changes in this revision:

- **NULL-amount gifts (medium):** fixed. Not counted in the cash count or total; a "Gifts with no amount" column; a
  fixture.
- **Deactivated donors left out (medium x2):** changed. Included with an Active column; fixture on `gone`; README.
- **Exact header text (medium, low):** fixed. The 18 header strings are listed.
- **Year boundaries untested (medium, low):** fixed. Gifts on 1901-01-01, 1901-12-31 and 1900-12-31; guard widened.
- **1902 header-only unguarded (low x2):** fixed. The guard covers 1902.
- **Totals will not match the cash export (low):** fixed. README says why.
- **`year[]` (low x2):** fixed. In the bad-year list.
- **Yes labels and fractional quantity untested (low):** fixed. `gone` has Yes flags and quantity 2.50.
- **Main contact (low):** added, as in the donor list export.

## Responses to round 2

Round 2: approved, 0 critical, 0 high, 2 medium, 5 low. Changes in this revision:

- **`gone` as Do not contact hides the emails is_active check (medium):** fixed. The items-only donor (no email)
  carries Do not contact = Yes instead; `gone` keeps 0.
- **Test plan still lists two rows (medium, low):** fixed. Three rows, the new column in the suite donor's cells.
- **Exact-match year regex and PHP clock (low):** fixed.
- **Guard messages and README out of date (low):** fixed, in Scope.
- **No fixture on 1902-01-01 (low):** accepted. It would make 1902 non-empty; `fish_export_year` is a two-line
  function returning `YYYY-12-31`, which the code review reads.
- **No donor whose only gift has no amount (low):** accepted. The column's counting is tested on `gone`; such a
  donor gets a row by the same `c.donorID IS NOT NULL` condition.

## Responses to round 3

Round 3: approved, 0 critical, 0 high, 0 medium, 2 low. Handled in the implementation, not by changing the approved
text above:

- **`gone`'s cash gifts need `eventID` (low):** each passes `'eventID' => $campaign` and its own `$out` key.
- **Docstrings (low):** the module and class docstrings both name UF2-137 and the widened window.
