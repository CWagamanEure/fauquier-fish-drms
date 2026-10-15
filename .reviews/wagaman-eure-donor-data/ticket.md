# Ticket: donor pages read and write the real tables

Branch: `wagaman-eure-donor-data` (stacked on `wagaman-eure-review-process`, PR #17, until it merges).

Jira: UF2-26, UF2-27 (Cory), remainder of UF2-60 (Cory). Supports James's UF2-61, 67 (In Progress), 68,
70, 73-78, 81 (donor page tasks) and Tristan's UF2-88 (donor page CSS). Requirement text, verbatim:

- UF2-26: "The system shall allow administrators to view a supporter's complete donation history from the
  supporter's profile. The history shall include donation amount, donation date, and donation frequency so
  administrators can evaluate the supporter's financial e"
- UF2-27: "The system shall allow administrators to view a donors volunteer hours and communication
  touchpoints from the supporter's profile. The system shall display the frequency and history of these
  interactions so administrators can evaluate the donor"
- UF2-60 description: "First / initial / last / email / phone / preferred contact method / Street / City /
  Status / Zip / Donor Type (individual , Organziation)". "Status" is read here as the address State
  (the form has a State select and no donor status field); donor status (new / active / lapsed) is derived
  by `donor_summary`, and active/inactive is the new `is_active` flag. Columns landed in migration 003.
- Group Acceptance Criteria (Confluence, Tristan's stories): "The Admin can view all profiles. The Admin can
  view the dates which donors have donated on their profile. The Admin can edit the donors profile. The
  Admin can save any edits made." and "The admin will be able to record volunteer hours in the portal ...
  update and edit recorded hours ... save after they have added or updated records."

## Problem

`donor.php`, `donorProfile.php`, `addDonor.php` and `updateDonor.php` (James, PR #15) render FISH NET pages
from `include/donorDataStopgap.php`, a fake in-session data set the file itself says to replace "once
schema is set". The schema is on `dev`: `donors` has the new columns, `donor_summary` computes lifetime
cash, items, hours, last gift, status and last contact, and `donations`, `non_cash_donations`,
`volunteer_hours`, `communications` hold the activity. Nothing on the pages reads them. Adding or editing a
donor writes only to the PHP session, and any logged-in account can do it.

## Scope

In:
- A prepared-statement data layer in `database/dbDonors.php` (its only function, `create_donor()`, has no
  callers and is removed): list with filter/search/sort/pagination and tile counts, one donor with profile
  fields, the four activity histories with campaign names, create, update, deactivate, reactivate.
- `include/permissions.php`: `fish_can($area, $action)` resolves the effective permission for the current
  account in one query: the `user_permissions` row for (user, area) when one exists, otherwise the
  `role_permission_presets` row for the account's role (`SELECT COALESCE(u.can_<action>, r.can_<action>) FROM
  role_permission_presets r LEFT JOIN user_permissions u ON u.user_id = ? AND u.area = r.area WHERE r.role = ?
  AND r.area = ?`), cached per request in a static. `$action` is checked against the constant list
  view/edit/delete before any SQL is built and `$area` is always bound; the function returns false when
  there is no authenticated user, the action is unknown, or the query yields no row (unknown role or
  area). No role bypasses it, admin included (the admin preset already grants everything intended, and
  `data_import` delete stays 0). Accounts created after migration
  001 have no rows today (no PHP writes `user_permissions`), so the preset fallback is what makes new staff
  able to edit donors. Two more things close that gap for the Manage Users work: migration 008 backfills
  rows with the same idempotent `INSERT IGNORE ... FROM dbpersons JOIN role_permission_presets` that 001
  used, and `add_person()` in `database/dbPersons.php` inserts the preset rows for a new account. First
  consumer: the donor write gate below. Later consumers: Manage Users, donations. Per-user rows override
  the preset and outlive a role change, so any code that changes `dbpersons.role` (the Manage Users
  ticket) must re-apply the preset rows for that user; this is written into `sql/README.md`.
- `include/fishnet-layout.php` also gains `fish_str($source, $key)`: returns `trim($source[$key])` when the
  value is a string, `''` otherwise, so an array parameter never reaches `trim()` (a `(string)` cast of an
  array emits a PHP warning and yields "Array"). Both pages' GET readers and the `$in` closures use it;
  `page` uses `(int)`.
- Migration `008_donors_active_flag.sql`: `donors.is_active TINYINT(1) NOT NULL DEFAULT 1`,
  `updated_by VARCHAR(256) NULL`, `deactivated_at DATETIME NULL`, `deactivated_by VARCHAR(256) NULL`
  (FKs `fk_donors_updated_by` / `fk_donors_deactivated_by` to `dbpersons.id`, ON DELETE SET NULL, added with
  the `information_schema` + PREPARE guard from 002 because `ADD CONSTRAINT` has no reliable IF NOT EXISTS),
  the `user_permissions` backfill described above, and `CREATE OR REPLACE VIEW donor_summary` in the same
  file exposing `is_active`. Confirm 008 is still the next number by listing `origin/dev:sql/migrations`
  right before creating it; run the file twice by hand (`scripts/db-sql.sh < sql/migrations/008_...`)
  before the first `make migrate` records it.
- Swap the four pages from the stopgap functions to the data layer, keeping James's markup and array shapes
  (`$d['id','name','type','contact','lifetime','last_gift','status']`, `$detail['cash','items','vol','comms',
  'last_contact']`, `$prof[form keys]`) so the templates change as little as possible. Delete
  `include/donorDataStopgap.php` and the `?reset_demo` handler. `h()` and `initials()` move into
  `include/fishnet-layout.php` (one definition each, `function_exists` guarded; `initials()` takes the first
  character with `preg_match('/^(.)/us', ...)` so non-ASCII names work); the copies in `donor.php` are
  removed, and the Jira comment on UF2-67 and the PR say so, because James's in-progress branch may carry
  page-level copies that would redeclare them.
- Donor code shown on the profile (`D-01842` style) derives from the id; no new column.
- `tests/donors_integration.py` run by `make test-donors`, reusing `Browser` and a PHP fixture modelled on
  `FIXTURES_PHP` from `tests/auth_integration.py`. The fixture creates the suite's own accounts with an
  explicit `role` (`viewer`, `staff`, `manager`; `INSERT INTO dbpersons (id, name, password, role)`), creates
  a suite-owned donor with `notes` set and `state = 'DC'`, and inserts activity rows for it: one donation
  with `check_number = '<1042'` and `send_receipt = 0`, one communication with status `bounced`, one
  non-cash row; it reads donor rows by id for assertions and, in `addClassCleanup`, deletes the activity
  rows before the donor and the accounts (RESTRICT keys). Seeded demo donors are read only through search:
  the suite picks the table row whose name cell equals the exact display name ("Maria Lopez" returns two
  demo rows, which is itself asserted) and reads the id from that row's Select link. The fixture also
  offers a fixed-SQL `count` action (the four tiles over `donor_summary` with the Approach 1 WHERE and dupes
  definition; the name-only LIKE count) and a `permission` action that inserts one `user_permissions` row
  for a suite account; `setUpClass` fails with "run make seed-demo" when no donor with `notes LIKE
  '[demo]%'` exists. Its no-PHP-errors check matches both plain and HTML forms
  (`(?:<b>)?(Fatal error|Warning|Notice|Deprecated)(?:</b>)?:`), and `assert_no_php_errors` in
  `tests/auth_integration.py` is widened the same way (Apache renders PHP diagnostics as
  `<b>Warning</b>:`, which the old marker never matched).
- Docs, exact edits: `sql/README.md` heading "Sprint 1 schema (migrations 001-007)" becomes 001-008 with a
  row for 008 and the role-change re-apply rule next to the `user_permissions` row, and the sentence "there is no donor-inactive flag yet; reassign or delete the activity first"
  becomes "deactivate through updateDonor.php (`is_active`); deleting is still blocked"; `README.md` test
  paragraph gains `make test-donors` (needs `make seed-demo`); `Makefile` gains `test-donors` (.PHONY, help,
  target); `CLAUDE.md` line 4 gains `make test-donors`, loses the "still read donorDataStopgap.php"
  sentence, and Conventions gains "Writes to donor and donation data call `fish_can($area, $action)` from
  `include/permissions.php` before touching the database; GET pages render read-only when it is false";
  `README.md`'s Authentication section gains two sentences on per-area permissions (rows, preset fallback,
  viewer read-only, staff cannot deactivate). After merging: `make migrate` (`make dev` does it); no image rebuild, no re-login;
  `make seed-demo` optional for demo donors. PHP `display_errors` is on in the dev container (pre-existing,
  out of scope); read-side failures on the new pages render "Could not load donors" like the write path.
- Jira: comment on UF2-61/67/70/73-78/81 (James) and UF2-88 (Tristan) that the pages now read real data,
  before the PR is opened, since UF2-67 is In Progress on James's side.

Out: per-user revocation of donor *viewing* (`can_view`) is deferred to the Manage Users ticket (read pages
stay gated by login); the profile's per-row Edit links are removed until the donation pages land; logging a
new communication / hours / donation from the profile (UF2-87 and Joel's donation pages),
duplicate-donor merging (the tile only counts matches), campaign management, the Manage Users page,
theming. Legacy `searchindex.php`, `search_multi_criteria.php`, `search_donors.php`, `donationAddEdit.php`
and `addDonationHandler.php` stay as they are: they build SQL by string and ignore `is_active`, so inactive
donors still appear in legacy search until those handlers are parameterised (follow-up ticket). The profile
links "+ Add cash gift" / "+ Add non-cash gift" / "+ Log hours" / "+ Log communication" and the "Save & add
donation" target (`addDonation.php`) do not exist on `dev` until Joel's and Tristan's pages land; until
then "Save & add donation" redirects to the new donor's profile with a notice.

## Approach

1. `database/dbDonors.php`, all prepared statements on `connect()`, every value bound:
   - `donors_tiles()`: one WHERE clause for all four tiles, `is_anonymous = 0 AND is_active = 1`: total;
     new this month (`donor_since_date` in the current calendar month); lapsed (`status = 'lapsed'`); dupes =
     the number of donors involved in a match, where a match is a shared non-empty email (case insensitive)
     or the same non-empty phone digit string (`REGEXP_REPLACE(phone, '[^0-9]', '') <> ''`) with another
     donor in the same WHERE set.
   - `donors_list($filter, $q, $sort, $page)`: the data layer owns the allowlists (filter in
     all/individuals/organizations/lapsed, sort in last_gift/name/lifetime mapped to constant ORDER BY
     strings, unknown values fall back to the defaults; `perPage` is a constant; LIMIT/OFFSET are bound
     integers). Reads `donor_summary` where `is_anonymous = 0 AND is_active = 1`; `q` matches
     display_name and email (LIKE with `%`, `_` and `\` escaped), and the phone-digits clause is added only
     when `preg_replace('/\D/', '', $q)` has at least three digits, so "Apt 2" never matches every phone
     holder;
     ORDER BY the sort key then
     `display_name, id` so paging is deterministic (last_gift newest first, NULLs last); COUNT first, clamp
     the page, then fetch; returns rows in James's shape plus `total` and the effective page. Type renders
     as "Individual"/"Organization" from `donor_type`.
   - `donor_get($id)`: a plain read of one `donor_summary` row regardless of `is_active` (a deactivated
     donor's profile still opens, with an "Inactive" pill using the existing grey `status off` class on the
     profile header and the edit page; the list has no inactive view in this ticket). Page policy for the
     anonymous donor lives in the pages: `updateDonor.php` redirects to `donor.php` when the row's
     `is_anonymous` is set.
   - `donor_profile_fields($id)`: the `$prof` form shape from `donors` columns. `preferred_contact`
     email/phone/mail/text maps to the form labels Email/Phone/Mail/Text; **both forms gain the Text option**
     (`$preferredOptions`), so an edit never clears it. One rule for every field in `donor_form_validate()`:
     a posted value byte-identical to the stored value is accepted without further validation (legacy data
     passes through untouched, including the nine legacy rows with two emails in one field or zip `0`), an
     empty posted value clears the column, and any changed value must pass the normal rules. For the two
     selects (state, preferred contact) a stored value outside the option list is rendered as an extra
     "Other: <stored value>" option, selected, with value and label through `h()`, so the stored value is
     what gets posted. The profile's primary date is "Donor since" from `donor_summary.donor_since_date`
     (earliest of `donor_since` and the first gift; em dash when NULL, about half the legacy donors); the
     edit page's "Record created" shows `created_at`, which is never NULL (legacy rows carry the migration
     date, which is the truth about the record).
   - `donor_cash_history($id)`: `donations` LEFT JOIN `dbevents` for the campaign name (NULL when there is
     none; the template prints an em dash), ordered by `donation_date` DESC NULLS last; returns raw fields
     (`amount` COALESCEd to 0, `donation_date`, `payment_method`, `check_number`, `receipt_sent_at`,
     `send_receipt`, `thanked`, `campaign`). The template formats and escapes: payment label "Check #1042" /
     "Cash" / "Card" / "Online" / "Digital token" / "Stock" / "Other" from the enum, em dash when
     `payment_method` is NULL (all 192 legacy donations), printed through `h()`; receipt badge = sent when
     `receipt_sent_at IS NOT NULL OR thanked = 1`, pending when `send_receipt = 1` and not sent, otherwise a
     neutral "Not requested".
   - `donor_noncash_history($id)` (adds `unit`), `donor_hours_history($id)`, `donor_communications($id)`
     (`comm_type` humanised, `in_person` as "In person"; status badge shows the enum word, bounced/failed in
     a warning style): same pattern, LEFT JOIN `dbevents` for campaign names, every printed cell through
     `h()`. Each tab footer count equals the view's `cash_gift_count` / `noncash_gift_count` for the donor.
   - `donor_create(array $f)`, `donor_update($id, array $f)`: explicit column lists covering only what the
     form posts (donor_type, first, middle_name, last, org_name, email, phone, preferred_contact, street,
     city, state, zip, is_volunteer, email_updates, mail_receipts, do_not_contact, updated_by). Name fields
     are stored as submitted (trimmed, empty to NULL); a type switch does not clear the other side. Never touches `org_contact`, `notes`, `gender`,
     `donor_since` or `created_at`; `donor_create` omits `donor_since` so the column default applies and the
     new donor reads as "new". Both refuse id 999 and return false. Phone is stored as typed; comparisons
     use digits only. `updated_by` is always `auth_current_user()->get_id()`, set by the page, never a form
     field.
   - `donor_set_active($id, bool $active, $byUser)`: refuses 999; deactivating sets `is_active = 0`,
     `deactivated_at = NOW()`, `deactivated_by = $byUser`; reactivating sets `is_active = 1`, clears both,
     and sets `updated_by = $byUser`. `$byUser` is the session account id.
   - `donor_form_validate(array $f)`: one shared validator for both pages: required names by type, email
     format, zip, state in the list (or the stored value, see above), and column lengths taken from the live
     `SHOW CREATE TABLE donors` (first/last/middle 100, org_name 255, street 100, email 100, phone 30,
     city/state/zip 30) so strict-mode MariaDB never throws on length; an over-long street gets a
     field-level error, not the generic failure. Data-layer writes catch
     `mysqli_sql_exception`, log it, and return false; the page shows "Could not save the donor".
2. Pages: replace the stopgap includes/calls. String parameters are read with `fish_str()`.
   - `donor.php`: passes filter/q/sort/page to `donors_list()`, renders `$tiles` from `donors_tiles()`, and
     replaces the fake pager links with real ones (previous/next from the effective page and `total`).
   - `donorProfile.php`: header breadcrumb and big initials from the real name via `initials()`; the four
     tiles (lifetime cash, items, hours, last gift) come from the `donor_get()` view row so list and profile
     agree even when the latest gift is non-cash; tab footers are computed from the tab rows; badges per the
     mapping above; "Donor since" / "Last contact" em dash when NULL; Inactive pill when `is_active = 0`;
     id 999 shows a read-only profile with no edit link.
   - `addDonor.php` / `updateDonor.php`: **write gate**: on POST both call `fish_can('donors', 'edit')` and
     answer 403 otherwise (viewer role has `can_edit = 0`); `action=deactivate` and the new
     `action=reactivate` require `fish_can('donors', 'delete')` (staff has `can_delete = 0`; manager and
     admin have 1). GET remains readable at level 1, but when `fish_can('donors', 'edit')` is false the form
     renders read-only with a one-line notice and the Update / Mark inactive / New donor buttons are hidden;
     the POST gate is the real control; the Deactivate/Reactivate panel is likewise shown only when
     `fish_can('donors', 'delete')` is true. `updateDonor.php` shows "Reactivate" instead of "Mark inactive"
     when the donor is inactive, and redirects to `donor.php` for the anonymous donor. The post-save notice
     is a fixed flag (`donorProfile.php?id=N&saved=1`, read with `fish_str`) whose text lives in the
     template; nothing from the request is echoed. The profile's tab pagers become a plain
     "Showing 1-N of N" line. "Save & add donation"
     redirects to the new profile with a notice until `addDonation.php` exists.
3. Migration 008 + view recreate; `make migrate` picks it up.
4. Tests, docs, Jira comments.

## Acceptance criteria

1. With `make seed-demo` loaded, searching `donor.php` by each demo donor's exact display name returns a
   row whose name cell equals it, with correct type, contact, lifetime cash, last gift and status; the four
   tiles equal counts the test computes with SQL at run time over `is_anonymous = 0 AND is_active = 1` (the
   baseline has about 300 legacy donors, so no "only six" claims); a name-only search returns exactly the
   donors whose name or email matches (count equals a SQL count without the phone clause); filter, sort and
   paging change the query; `?q[]=x`, `?page[]=1`, `?sort=zzz` produce no PHP warning and fall back to
   defaults.
2. `donorProfile.php?id=<Maria Lopez>` shows her name in the breadcrumb and initials (no "Jhon" anywhere),
   the four tiles from the view, and the Cash, Non-cash, Volunteer and Communications tabs listing the
   seeded rows with campaign names and the badge mapping above. Grace Community Church (latest gift is
   non-cash) shows the same last gift in the list and on the profile. A legacy donor with an undated,
   NULL-payment gift renders without PHP warnings and with em dashes for the missing date and payment. The
   suite-owned donor's check number `<1042` renders escaped, its bounced communication shows the warning
   badge, its unreceipted gift shows "Not requested", each tab footer count equals the view's counts, and
   the cash footer reads "N gifts · avg $X" and the volunteer footer the average hours (the frequency
   reading for UF2-26/27; a per-month figure is a follow-up).
3. Adding a donor through `addDonor.php` with `action=save` (the suite never uses "Save & add donation")
   inserts a `donors` row with donor_type, middle name, organization fields, preferred contact (including
   Text) and the four preference flags; the new donor appears in the list as "new" with `updated_by` equal
   to the logged-in test account, and the profile shows the saved notice. A 101-character street gets a
   field-level validation message.
4. Editing through `updateDonor.php` changes only the posted columns (`notes`, a Text preference and a
   legacy state `DC` survive an edit); "Mark inactive" sets `is_active = 0`, `deactivated_at/by`, hides the
   donor from the list and tiles, keeps the profile reachable with an Inactive pill; "Reactivate" restores
   it and clears `deactivated_at/by`. Posting any edit or deactivate for id 999 leaves the row unchanged.
5. Anonymous requests to the four pages redirect to login; POSTs without the form token get 403; the
   suite's own `viewer` account (created by the fixture with an explicit role and no `user_permissions`
   rows, so the preset fallback is what is tested) gets 403 on the add and edit POSTs and a read-only form
   on GET; its `staff` account gets 403 on deactivate and sees no deactivate form; its `manager` account
   succeeds. The row-present branch is tested too: the fixture gives the viewer a `user_permissions` row
   with `can_edit = 1` and the edit POST then succeeds (the row goes with the account via ON DELETE
   CASCADE). `updateDonor.php` is added to `PROTECTED_PAGES` in `tests/auth_integration.py`, and that suite
   asserts a freshly registered account has seven `user_permissions` rows.
6. `include/donorDataStopgap.php` is gone and no page defines its own `h()`; `make test-auth` passes;
   `make test-donors` passes; the suite creates its own donor for the write tests (unique marker in name and
   email) and deletes it in cleanup, and reads seeded demo donors only through search.

## Verification plan

- `git fetch origin && git ls-tree origin/dev:sql/migrations` (008 is free), `make migrate`, `make seed-demo`.
- `make test-auth`, `make test-donors`, `make test-gate`.
- Manual curl walk of AC 1-5 against http://localhost:8080 with no PHP warnings.

## Risks

- James is still working UF2-67 (In Progress) on these pages; comment on his tickets before opening the
  PR, keep his markup, and merge `dev` right before the code review round.
- `donor_summary` is computed per row with correlated subqueries; fine at hundreds of donors, revisit if the
  FISH import brings tens of thousands.
- Legacy donor search and donation handlers still build SQL by string and ignore `is_active` (follow-up).
- The profile's action links and `addDonation.php` 404 until Joel's and Tristan's PRs land.

## Responses to round 1

| Finding | Response |
|---|---|
| high: no role gate on donor writes | fixed: `fish_can('donors','edit')` on both POSTs, `fish_can('donors','delete')` for deactivate/reactivate, tested for viewer/staff/manager (Approach 2, AC 5) |
| high: profile echoes `payment` unescaped | fixed: data layer returns raw fields, template formats and prints through `h()`; `<` in a check number is an AC 2 assertion |
| high: update nulls columns the form lacks; `text` preference missing | fixed: explicit column list limited to the form's fields, Text option added to both forms, `notes` and Text survive an edit (AC 4) |
| high: deleting the stopgap removes `h()`/`initials()` | fixed: both move into `include/fishnet-layout.php`, duplicates in `donor.php` removed (Scope, AC 6) |
| high: AC 1 assumes only six donors | fixed: search by exact name, tiles compared with SQL computed at test time |
| high: preferred contact Text option | fixed as above |
| medium: allowlists for sort/filter | fixed: data layer owns allowlists, constant ORDER BY strings, bound LIMIT/OFFSET |
| medium: anonymous donor 999 editable | fixed: refused in `donor_update`, `donor_set_active`, `donor_get` for editing; page redirects; tested in AC 4 |
| medium: NULL donor_since / amount / donation_date | fixed: view's `donor_since_date`, em dashes, COALESCE amount, legacy undated donor in AC 2 |
| medium: phone formats and the dupes tile | fixed: digits-only comparison on both sides via `REGEXP_REPLACE` |
| medium: badge mapping undefined | fixed: mapping written into Approach 1 and tested |
| medium: hardcoded placeholders in kept markup | fixed: breadcrumb, initials, tiles, pager named explicitly in Approach 2 |
| medium: paging tiebreaker and clamp | fixed: ORDER BY key, display_name, id; count then clamp |
| medium: no length validation under strict mode | fixed: shared `donor_form_validate()` with column lengths; error contract |
| medium: test isolation and cleanup | fixed: suite-owned donor with unique markers, PHP fixture for reads/deletes, seeded donors read through search |
| medium: list and profile disagree on last gift | fixed: profile tiles from the view row; Grace Community Church assertion |
| medium: profile header hardcoded | fixed: AC 2 asserts the name and the absence of "Jhon" |
| medium: no reactivate | fixed: Reactivate action and button, AC 4 round trip |
| medium: doc lines and post-merge steps | fixed: named in Scope |
| low: array GET params cause TypeError | fixed: cast to string; AC 1 includes the array cases |
| low: no audit of who changed a donor | fixed: `updated_by`, `deactivated_at`, `deactivated_by` in 008 |
| low: legacy endpoints ignore `is_active` | documented in Out |
| low: `create_donor()` rationale | fixed: stated as dead code |
| low: 008 numbering | fixed: check listed in the verification plan |
| low: missing link targets | documented; suite uses `action=save` only |
| low: `donor_since` default on create | fixed: omitted from the INSERT |
| low: Jira fidelity (UF2-60 "Status", UF2-67 In Progress) | fixed: quoted verbatim with the reading; UF2-67 named in Risks |

## Responses to round 2

| Finding | Response |
|---|---|
| high: `fish_can()` depends on rows nothing creates for new accounts (3 auditors) | fixed: preset fallback in one LEFT JOIN query; 008 backfills rows; `add_person()` seeds presets; no admin bypass; AC 5 tests the fallback with fixture accounts that have no rows |
| high: `(string)` cast of an array GET parameter warns (2 auditors) | fixed: `fish_str()` type check, used by GET readers and the POST closures |
| high: fixture cannot produce the data AC 2 and AC 4 assert on | fixed: fixture inserts the donation with `<1042`, the bounced communication, a non-cash row, notes and state `DC`; cleanup order stated |
| medium: `fish_can()` behaviour with no rows unstated | fixed as above |
| low: source of `updated_by` / `deactivated_by` | fixed: always the session account, never a form field; asserted in AC 3 |
| low: admin bypass over-grants | fixed: removed |
| low: `display_errors` on in the dev container | out of scope, noted; read failures render a generic message |
| medium: joins to `dbevents` must be LEFT JOINs | fixed: stated for all four histories; footer counts equal the view's counts |
| medium: street limit 255 vs VARCHAR(100) (2 auditors) | fixed: 100, limits taken from `SHOW CREATE TABLE`; 101-character street asserted |
| medium: legacy state values blanked by the edit form | fixed: "Other: <stored>" option round-trips; stored value kept when the posted one is empty; `DC` asserted |
| medium: 008 FKs need the information_schema guard | fixed: named FKs with the 002 pattern; file run twice by hand |
| low: "Created" shows the migration date | fixed: labelled "Record created"; "Donor since" is the primary date |
| low: dupes tile and empty digit strings / donor 999 | fixed: non-empty digits, one WHERE clause for all tiles |
| low: "Maria Lopez" returns two rows (2 auditors) | fixed: exact-name row selection, both rows asserted |
| medium: phone clause matches every phone holder | fixed: clause only when the query has digits; name-only search count asserted |
| medium: tiles' WHERE clause and dupes definition | fixed: one clause, dupes = donors involved |
| low: payment / comm-type NULL and other enum values | fixed: em dash for NULL, humanised words |
| low: Inactive pill placement and class | fixed: profile header and edit page, `status off` |
| low: `donor_get()` mixes page policy; reactivate semantics | fixed: plain read, page redirects on `is_anonymous`; reactivate clears the columns |
| medium: documentation edits named only partially | fixed: exact edits listed, `make test-donors` documented |
| low: viewer sees the full form then a bare 403 | fixed: read-only form with a notice when `fish_can` is false |

## Responses to round 3

| Finding | Response |
|---|---|
| high: "no PHP warning" assertions cannot see Apache's `<b>Warning</b>:` form | fixed: both suites match plain and HTML forms with one regex |
| medium: nine validator-failing legacy rows and "keep stored when empty" | fixed: one rule (identical passes, empty clears, changed validates); Other option for the two selects |
| medium: row-present branch and `add_person()` seeding untested | fixed: viewer override row asserted; registration yields seven rows (AC 5/6) |
| medium: docs omit the permission gate | fixed: CLAUDE.md, README, sql/README edits listed |
| low: `fish_can()` fail-closed and action allowlist | fixed |
| low: read pages not gated by `can_view` | deferred to Manage Users, stated in Out |
| low: per-user rows outlive a role change | documented in Scope and sql/README for the Manage Users implementer |
| low: Other option and notice are output sinks | fixed: `h()` on both, fixed-flag notice |
| low: type switch forces NULL | fixed: stored as submitted |
| low: no row from `fish_can()` | fixed |
| low: seed guard and count action for the fixture | fixed |
| low: `h()`/`initials()` redeclare risk and byte-based initials | fixed: guards, `/u` regex, Jira and PR note |
| low: staff see a deactivate button they cannot use | fixed: panel gated on delete permission |
| low: phone clause fires on one digit | fixed: three digits minimum |
| low: dead profile controls and the notice untested | fixed: plain count line, Edit links removed, notice asserted |
| low: "frequency" not asserted | fixed: footers asserted as the frequency reading |
