# Data & Imports page front end

Branch `TCD-data-imports-frontend`, by Tristan Chavez-Duran, off `origin/dev` (867d794). The page was designed
(three layout options, the team picked the two-column one) and built before this ticket was written, so per
`.claude/skills/review-swarm/SKILL.md` ("Branches that predate the process") this ticket describes what was
built and the ticket gate is skipped; the PR says so.

## Jira keys

None recorded for this branch. Related sprint plan items: Sprint 5 "Import x3" (donations, mass communication,
donors; template and web page; check for errors and duplicates before submit) and Sprint 6 "export data page"
and "duplicates".

## Problem

The sidebar's Data & Imports link points at `dataImports.php`, which does not exist. The team needs the page laid
out so the backend can be wired to it:

1. Import: pick a data type (Donors, Cash donations, Non-cash donations, Volunteer hours, Communications), get
   that type's template, upload one .csv/.xlsx file. Nothing is saved until the file is checked.
2. Export: eight CSV exports (all emails; cash donations between two dates; non-cash donations between two
   dates; donors with hours and donation totals; donor list with contact and status; donor tax receipts for a
   year; all volunteer hours; LYBUNT and SYBUNT contact list).
3. Recent imports: one row per import run.
4. Needs review: rows that had an error or might be a duplicate, with Fix / Merge / Skip.

## Scope

In (front end only, no data, no handlers):
- `dataImports.php`: page head; Import card with type radio chips, a per-type template link shown with CSS
  `:has()`, a file drop zone (`accept=".csv,.xlsx"`), Upload & check; Export card with one form per export
  (date range or year where needed); Recent imports and Needs review tables with empty-state rows and a
  commented example row for the backend. Every form carries `auth_csrf_field()` and posts to `#` until the
  handlers exist.
- `css/imports.css`: styles for the page, loaded after fishnet.css, donor.css and forms.css.
- `js/dataImports.js`: shows the chosen file name in the drop zone and highlights it while dragging.
- `tests/auth_integration.py`: add `dataImports.php` to `PROTECTED_PAGES`.

Out: import parsing, duplicate detection, CSV export handlers, template files, permissions beyond the layout's
login check, any SQL or migration. The legacy `import.php` and `export.php` are untouched.

## Approach

Reuse `include/fishnet-layout.php` (`fishnet_open`/`fishnet_close`, `h()`, login check), `auth_csrf_field()`,
and existing classes (`card`, `table-card`, `status`, `edit`, `td.empty`, `btn`). Output is escaped with `h()`.
Empty `$recent` / `$review` arrays mark where the backend fills data.

## Acceptance criteria

- Logged-in users see the page with the sidebar item active; anonymous visitors are redirected to login.
- Choosing an import type shows only that type's template link.
- All eight exports appear, with From/To on cash and non-cash and a year select on tax receipts.
- Recent imports and Needs review show their empty states.
- `php -l` passes; `make test-auth` has no new failures.

## Verification plan

`php -l dataImports.php`; `make test-auth`; load the page logged in and click each type chip.

## Risks

Forms post to `#` until the handlers exist (submitting reloads the page). `:has()` needs a current browser.
