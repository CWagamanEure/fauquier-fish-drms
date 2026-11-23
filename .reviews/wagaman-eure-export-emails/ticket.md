# Ticket: UF2-132: export all donor emails as a CSV

Branch: `wagaman-eure-export-emails`, off `origin/dev` at 9208f9b (includes UF2-133, UF2-134, UF2-138 and PR #51). PR into `dev`.

Jira UF2-132 (Task, assignee Cory Wagaman-Eure, status To Do, no description):

> Export all emails

Confluence "Sprint #3" (67633206), Upcoming:

> Exports (UF2-132–139)

The front end row already exists in `dataImports.php` (Tristan), with no filter:

> 'emails' => ['All emails', 'Every donor email address, one per row.', false]

Product decisions (Cory, 2026-11-23): donors marked Do not contact are left out, and so are their organization's contact
people; contact people of other organizations are included. Deactivated donors are left out too (deactivating is
the app's delete).

## Problem

The "All emails" row on Data & Imports has a disabled "Coming soon" button. FISH staff need the donors' email
addresses in a spreadsheet to send newsletters and appeals from their email tool. UF2-133, UF2-134 and UF2-138 built
the shared pieces (`include/csvExport.php`, `exportData.php`); this adds the `emails` case.

## Scope

In:
- `include/permissions.php`: `fish_can_export_emails()` = `fish_can('donors', 'view')`. Donor and contact emails
  are shown today on the donor profile (`donorProfile.php` loads contacts only with donors view). What is new is a bulk download,
  under the same permission. Donations view is not needed and does not grant it.
- `exportData.php`: an `emails` case, in this order: `fish_can_export_emails()` else 403 with a plain message
  (`You do not have access to the email export.`), no data, `exit`; `session_write_close()`; the query; the file.
  Any posted `from`, `to` or `year` is ignored.
  - Query (one prepared statement, no parameters, through `export_query()`), a `UNION ALL` of:
    1. the donor's own email: `donor_summary s` where `s.is_anonymous = 0 AND s.is_active = 1 AND
       s.do_not_contact = 0 AND COALESCE(s.email, '') REGEXP '[^[:space:]]'`;
    2. each contact person's email: `donor_contacts c JOIN donor_summary s ON s.id = c.donor_id` with the same
       conditions on `s`, `s.donor_type IN ('organization', 'foundation')` (the types whose profile shows
       contacts, so a donor changed to individual does not export hidden contacts) and
       `COALESCE(c.email, '') REGEXP '[^[:space:]]'`.
    Each branch selects the output columns `donor_id, display_name, contact_name, contact_title, email,
    email_updates` plus sort columns `row_kind, contact_primary, contact_sort, contact_id` (`0, 0, 0, 0` on the
    donor branch; `1, c.is_primary, c.sort_order, c.id` on the contact branch). The UNION's ORDER BY uses only
    those aliases (MariaDB and MySQL 8 refuse table-qualified names there): `ORDER BY display_name, donor_id,
    row_kind, contact_primary DESC, contact_sort, contact_id`, so contacts come main contact first, as on the
    profile.
  - Columns, in order: Donor ID, Donor (`display_name`), Contact person (blank on the donor's own row), Contact
    title (blank on the donor's own row), Email (trimmed with PHP `trim()`; a row whose trimmed email is `''` is
    skipped in the generator, so a blank Email cell is never written), Email updates (the donor's
    `email_updates`, Yes / No). Every cell goes through `fish_csv_cell()`.
  - Rows are not de-duplicated: the same address on two donors, or on a donor and its contact, appears on each
    row, so staff can see where it came from.
  - Filename: `fish-donor-emails-<YYYY-MM-DD>.csv`, PHP's `date('Y-m-d')` (America/New_York), as the hours export.
  - A database error: `error_log`, redirect with `failed`, never a partial file (the query runs before any header).
- `dataImports.php`: `$exportReady['emails'] = fish_can_export_emails()`; the row's description becomes
  `Donor and contact-person emails, one per row. Leaves out Do not contact and deactivated donors.`; header
  comment names the emails export.
- `exportData.php` header comment names the emails export.
- `tests/exports_integration.py`: fixtures and tests (below). Changed existing tests, only in what they expect of
  unbuilt exports: `test_unknown_exports_are_not_available` uses `donor-totals` instead of `emails`;
  `test_page_buttons_and_messages` expects 4 export forms and 4 "Coming soon" for staff; `noexport` sees 2 "No
  access" and 2 "Export CSV"; `nodonors` sees 2 "No access" and 2 "Export CSV".
- README: the permission paragraph and the `make test-exports` paragraph name the emails export and its rule,
  including that viewers (donors view) can download it and that Email updates is shown, not applied.

Out: the other four exports; any schema change; filtering by Email updates (the column lets staff filter);
`donors.org_contact` (legacy text, replaced by `donor_contacts` in 013); de-duplicating addresses; validating
address format; legacy `export.php`.

## Approach

Reuse `fish_csv_send`, `fish_csv_cell`, `export_back`, `export_query`. The only new function is the permission
helper. No migration. Reads go through `donor_summary` (with `is_anonymous = 0`), as CLAUDE.md requires.

## Acceptance criteria

1. A user with donors view who posts `export=emails` gets a 200 `text/csv` attachment named
   `fish-donor-emails-<today>.csv` (PHP's date; the test reads `today` before and after and accepts either),
   starting with a BOM, then the 6-column header above.
2. An active donor with an email who is not Do not contact has one row with blank contact cells; each of their
   contact people with an email has one row with name and title.
3. Left out: a contact row on an individual donor; a donor marked Do not contact (and their contacts); a deactivated donor (and their contacts); donor
   999; a blank or whitespace-only email on a donor or a contact (the donor's other rows still appear).
4. Order: by donor name then id; within a donor, their own email first, then contacts main contact first, then
   by sort order, then id.
5. Email is trimmed; Email updates is Yes / No; a NULL contact name or title is a blank cell.
6. Formula-leading text in an email, donor name or contact name gets a leading `'`.
7. No donors view: 403, no data, no CSV content type. Donations view is not required.
8. The cash, non-cash and hours tests pass unchanged (except the two test changes in Scope).
9. On Data & Imports, the emails row shows "Export CSV" with donors view and "No access" without it.

## Verification plan

- `php -l` on the changed PHP files; `make seed-demo`, then `make test-exports` (twice), `make test-auth`,
  `make test-donors`, `make test-donations`, `make test-gate`.
- Fixtures, every donor's `last` starting with the suite prefix so `clean` finds them. `setup` returns the new
  donor ids as `email_donors`; `teardown` takes them as `email_donors` and deletes each by id before the existing
  donor delete (`donor_contacts` cascades; they have no gifts or hours):
  - the existing suite donor gets email `  =a.<marker>@example.test  ` and `email_updates = 1`;
  - an organization donor (`org_name` `@Org <marker>`, whitespace-only email, `email_updates = 0`) with contacts:
    sort 0 `-Lee <marker>`, title `Director`, email `lee.<marker>@example.test`; sort 0 again (higher id) with
    NULL name and title and email `info.<marker>@example.test`; sort 1 with a name and NULL email; sort 2,
    `is_primary = 1`, name `Main <marker>`, email `main.<marker>@example.test` (exported first of the contacts);
  - a Do not contact donor (`donor_type` organization) with an email and a contact with an email;
  - a deactivated donor (`donor_type` foundation) with an email and a contact with an email;
  - an active foundation donor with no email and one contact with an email (exported: covers the foundation type);
  - an individual donor with no email and a leftover contact row with an email (not exported).
- Tests: file and headers; filename with PHP's date; the suite's rows picked out by Donor ID (exact rows and
  relative order for the suite donor and the org; none for the Do not contact or deactivated donors; no row with
  Donor ID 999); trimming, Yes/No, blanks, escaping; permissions (noexport and viewer get 200; nodonors gets 403
  with no CSV); GET 405 and bad token 403; the page buttons. A second run passes.
- Manual (not automated): export on the demo data and compare one organization's rows with its profile.

## Risks

- The whole donor list goes into one buffered result; at FISH's size (a few thousand donors) that is fine.
  `donor_summary` computes per-donor totals this export does not use; accepted for the rule that donor reads go
  through the view.
- The fixtures change the suite donor's email; no existing test reads it.
- A donor's Do not contact flag is the only opt-out; contacts have none of their own. Email updates is shown, not
  applied, so a list built from the file must still filter that column if staff want only opted-in people.
- No migration, rebuild or re-login is needed after merge.

## Responses to round 1

Round 1: approved, 0 critical, 0 high, 5 medium, 8 low. Changes in this revision:

- **UNION ORDER BY must use aliases (medium, low):** fixed. The Scope lists the aliased sort columns and the ORDER BY.
- **Contact order differs from the profile (medium x2):** fixed. Contacts are ordered main contact first; AC4 and a
  fixture (`is_primary = 1` at sort 2) cover it.
- **Opt-out fixtures need a donor type (medium):** fixed. The Do not contact donor is an organization and the
  deactivated donor a foundation; an active foundation donor covers the foundation half of the type rule.
- **Row description no longer matches (medium, low):** fixed. The new description is in the Scope.
- **Teardown signature (low):** fixed. Setup returns and teardown takes `email_donors`.
- **SQL blank check vs PHP trim() (low x2):** fixed. The generator skips a row whose trimmed email is empty.
- **Viewer can download every email (low):** accepted. The same addresses are visible to viewers one profile at a time,
  and the hours export uses the same rule. The README states it. A separate export permission or an export log is
  out of scope.
- **Email updates shown, not applied (low):** accepted, by the product decision of 2026-11-23 (leave out only Do not
  contact). The README states it.
- **Query not in database/dbDonors.php (low):** accepted. Each export keeps its query in its own case so
  `export_query()` runs it before any header, as cash, non-cash and hours do; it reads `donor_summary` as required.
