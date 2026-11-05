# Small fixes: Item values page, dashboard live stats, last-admin pop-up, permission tag wrap

Branch `TCD-smallchange`, by Tristan Chavez-Duran, off `TCD-data-imports-frontend` (adfd911, itself off
`origin/dev` 867d794); merge after that branch. The changes were built before this ticket was written, so per
`.claude/skills/review-swarm/SKILL.md` ("Branches that predate the process") this ticket describes what was
built and the ticket gate is skipped; the PR says so.

## Jira keys

None recorded yet (Jira tasks drafted for each item below).

## Problem

1. Manage Users: permission chips sat on one line (`tbody td { white-space: nowrap }`), so the table ran off the screen.
2. Edit user: demoting or deactivating the last active administrator ended on a bare text page via `exit()`.
3. Dashboard: "+Log Communication" pointed at a missing `logCommunication.php`; the cash, non-cash and volunteer
   stat cards showed hard-coded numbers and "Sept".
4. Staff need a back page to set a value for each non-cash category and apply it to every item in it, and to add
   a category. It belongs under Data & Imports.

## Scope

In:
- `manageUsers.php`, `css/donor.css`: permissions cell gets class `perm-cell`, which wraps (min 280px, max 520px);
  chips stay unbroken.
- `editUser.php`, `css/forms.css`: when `user_update` / `user_set_status` return `last_admin`, keep the 403 status
  but render the edit page with a `<dialog open>` pop-up explaining why; on demote, reset the form's role and
  matrix to the stored values. Other exits unchanged. The database-side guard is unchanged.
- `dashboard.php`, `css/dashboard.css`: +Log Communication links to `addCommunication.php` behind
  `fish_can('communications','edit')`. The three cards read the current month with prepared statements over
  date ranges: cash = SUM(donations.amount) by donation_date, compared with last month and the same month last
  year; non-cash = SUM(quantity) and SUM(estimated_value) by date_received; volunteer = SUM(hours) and
  COUNT(DISTINCT donor_id) by activity_date. A mysqli exception is logged and the cards show a dash.
  The cash and non-cash cards are gated on `fish_can('donations','view')` and the volunteer card on
  `fish_can('donors','view')`; without it the card shows a dash and "No access to …" (added in round 2).
- `itemValues.php`, `css/itemValues.css`: front-end-only page (no data, forms post to `#`), sidebar key `imports`,
  breadcrumb back to Data & Imports; one row per current category with value, per item/lb, scope, Apply; an Add a
  category panel. `dataImports.php` gets an "Item values" button. `tests/auth_integration.py` lists `itemValues.php`.

Out: the import-conflict card, Giving Trends chart and welcome name on the dashboard; any handler or migration
for item values (adding categories will need a categories table).

## Acceptance criteria

- Manage Users has no horizontal scroll at 1700px; chips wrap.
- Demoting / deactivating the last admin shows the pop-up on the edit page, saves nothing, OK closes it.
- Dashboard labels show the current month; card totals match SQL for that month; +Log Communication opens the add page.
- A user without donations view sees "No access to donations" on the cash and non-cash cards, and no figures.
- Item values opens from the Data & Imports button; anonymous visitors are redirected.
- `make test-auth` has no new failures; the users suite passes including test_admin_protections.

## Verification plan

`php -l` on changed PHP; `make test-auth`; `tests/users_integration.py`, `donations`, `donors`, `volunteer_hours`,
`campaigns` suites; screenshots of Manage Users, the pop-up, dashboard and Item values.

## Risks

Dashboard queries run on every dashboard load (four small indexed range queries). The pop-up uses `<dialog open>`
without JavaScript.
