# Data imports, templates, Treasurer and Reports pages

Branch `TCD-imports`, by Tristan Chavez-Duran, off `origin/dev` (1f19427). Four commits, one per part:
templates, Treasurer front end, Reports front end, imports backend. The work was built before this ticket
was written, so per `.claude/skills/review-swarm/SKILL.md` ("Branches that predate the process") this
ticket describes what was built and the ticket gate is skipped; the PR says so.

## Jira keys

None recorded yet. Sprint plan items: Sprint 5 "Import x3 (donations, mass communication, donors); template &
web page; check for errors & dups before submit"; Sprint 6 "treasurer & reports"; Sprint 7 "charts".

## Problem

Data & Imports had the upload form, but nothing behind it and no templates. Treasurer and Reports in the
sidebar pointed at pages that did not exist.

## Scope

1. Templates (`templates/fishnet-import-{donors,cash,noncash,volunteer,comms}.xlsx`): heading row (* =
   required), grey example row, dropdowns for fixed values, an Instructions sheet. Data & Imports links each.
2. `treasurer.php` + `css/treasurer.css`: front end only (no data): date-range money in/out log with
   donations/expenses and fund filters, totals, donations vs expenses by year bar chart. Data arrays are empty
   with Backend TODO notes (expenses need a new table later).
3. `reports.php` + `css/reports.css`: front end only (no data): range and campaign filters, Giving / Donors /
   Campaigns / Volunteers tabs with CSS bar charts, a status donut, tables and tiles.
4. Imports backend:
   - `sql/migrations/018_import_batches.sql`: `import_batches` (one per upload) and `import_rows` (one per row,
     JSON cells, status ready/error/duplicate/imported/skipped, problem text, match_id, record_id).
   - `include/importReader.php`: reads .csv (fgetcsv) or .xlsx (ZipArchive + SimpleXML, LIBXML_NONET, first
     sheet, shared strings, date/time serials). 2 MB / 2000 row limits.
   - `database/dbImports.php`: maps each template row onto the matching add page's form array and checks it
     with that page's own validate function (donor_form_validate, donation_form_validate,
     hours_form_validate, comm_form_validate) and saves with its create function. Finds the row's donor by
     Donor ID, email, or first + last name; a gift row with no donor details is the anonymous donor (999).
     Duplicate checks against stored records and within the file. Batch/row bookkeeping, all prepared.
   - `importHandler.php` (POST only): upload, import, cancel, skip, force (import a duplicate anyway), fix
     (edit cells, re-check, import). `import_can()` = data_import edit plus the add page's own right.
   - `importPreview.php` (check step: counts, problem rows with Fix / Import anyway / Skip, ready rows,
     Import / Cancel) and `importFix.php` (edit one row's cells).
   - `dataImports.php`: form posts to the handler; Recent imports and Needs review read the tables.
   - `tests/auth_integration.py`: new pages added to PROTECTED_PAGES.

Out: export handlers (separate pages exist on dev), expenses table, wiring Treasurer/Reports to data.

## Known issue (accepted, noted in the PR)

A gift / hours / communication row whose donor is not in FISH Net yet cannot create the donor: it goes to
Needs review ("No active donor found ..."). Workaround: add or import the donor, then Fix the row. Follow-up:
an "Add as new donor" action. Also: rows of an upload where nothing passed stay at the check step (Import is
disabled), reachable from Recent imports by the file name.

## Acceptance criteria

- Each template downloads; uploading it back (with the example row left in) imports only real rows.
- Rows are refused for the same reasons the add pages refuse them; errors and duplicates go to Needs review;
  Fix / Import anyway / Skip work; Cancel saves nothing; a wrong template is refused naming missing columns.
- Treasurer and Reports render their empty states for any logged-in user and are protected pages.
- Existing suites pass; auth has only the pre-existing Apache-only direct-file failures.

## Verification plan

php -l; all integration suites against php -S + MariaDB (fresh casadb + migrations 001-018 + demo seed,
migrations run twice); scripted browser upload of all five types with good, bad and duplicate rows, then
Fix / Import anyway / Skip / Cancel / wrong template; screenshots.

## Risks

Large uploads run inside one request (2000 rows max). .xlsx needs PHP's zip extension (CSV works without).
