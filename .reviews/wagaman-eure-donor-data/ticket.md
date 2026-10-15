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
- `include/permissions.php`: `fish_can($area, $action)` reads `user_permissions` for the current account
  (one query per request, cached in a static), with `role = 'admin'` always true. First consumer: the donor
  write gate below. Later consumers: Manage Users, donations.
- Migration `008_donors_active_flag.sql`: `donors.is_active TINYINT(1) NOT NULL DEFAULT 1`,
  `updated_by VARCHAR(256) NULL`, `deactivated_at DATETIME NULL`, `deactivated_by VARCHAR(256) NULL`
  (FKs to `dbpersons.id`, ON DELETE SET NULL, like the activity tables), and `CREATE OR REPLACE VIEW
  donor_summary` in the same file exposing `is_active`. Confirm 008 is still the next number by listing
  `origin/dev:sql/migrations` right before creating it.
- Swap the four pages from the stopgap functions to the data layer, keeping James's markup and array shapes
  (`$d['id','name','type','contact','lifetime','last_gift','status']`, `$detail['cash','items','vol','comms',
  'last_contact']`, `$prof[form keys]`) so the templates change as little as possible. Delete
  `include/donorDataStopgap.php` and the `?reset_demo` handler. `h()` and `initials()` move into
  `include/fishnet-layout.php` (one definition each, `function_exists` guarded); the copies in `donor.php`
  are removed.
- Donor code shown on the profile (`D-01842` style) derives from the id; no new column.
- `tests/donors_integration.py` run by `make test-donors`, reusing `Browser` and a PHP fixture modelled on
  `FIXTURES_PHP` from `tests/auth_integration.py` (read a donor row by id, delete suite-created donors in
  `addClassCleanup`; a donor with no activity deletes cleanly under the RESTRICT keys).
- Docs: `sql/README.md` table gains the 008 row; `CLAUDE.md` loses the "still read donorDataStopgap.php"
  sentence; README "Database migrations" unchanged. After merging: `make migrate` (`make dev` does it);
  no image rebuild, no re-login; `make seed-demo` optional for demo donors.
- Jira: comment on UF2-61/67/70/73-78/81 (James) and UF2-88 (Tristan) that the pages now read real data,
  before the PR is opened, since UF2-67 is In Progress on James's side.

Out: logging a new communication / hours / donation from the profile (UF2-87 and Joel's donation pages),
duplicate-donor merging (the tile only counts matches), campaign management, the Manage Users page,
theming. Legacy `searchindex.php`, `search_multi_criteria.php`, `search_donors.php`, `donationAddEdit.php`
and `addDonationHandler.php` stay as they are: they build SQL by string and ignore `is_active`, so inactive
donors still appear in legacy search until those handlers are parameterised (follow-up ticket). The profile
links "+ Add cash gift" / "+ Add non-cash gift" / "+ Log hours" / "+ Log communication" and the "Save & add
donation" target (`addDonation.php`) do not exist on `dev` until Joel's and Tristan's pages land; until
then "Save & add donation" redirects to the new donor's profile with a notice.

## Approach

1. `database/dbDonors.php`, all prepared statements on `connect()`, every value bound:
   - `donors_tiles()`: total (active, not anonymous), new this month (`donor_since_date` in the current
     calendar month), lapsed (`status = 'lapsed'`), dupes (active donors sharing a non-empty email, case
     insensitive, or the same phone digits: `REGEXP_REPLACE(phone, '[^0-9]', '')`, with another active
     donor).
   - `donors_list($filter, $q, $sort, $page)`: the data layer owns the allowlists (filter in
     all/individuals/organizations/lapsed, sort in last_gift/name/lifetime mapped to constant ORDER BY
     strings, unknown values fall back to the defaults; `perPage` is a constant; LIMIT/OFFSET are bound
     integers). Reads `donor_summary` where `is_anonymous = 0 AND is_active = 1`; `q` matches
     display_name, email (LIKE with `%`, `_` and `\` escaped) and phone digits; ORDER BY the sort key then
     `display_name, id` so paging is deterministic (last_gift newest first, NULLs last); COUNT first, clamp
     the page, then fetch; returns rows in James's shape plus `total` and the effective page. Type renders
     as "Individual"/"Organization" from `donor_type`.
   - `donor_get($id)`: one `donor_summary` row regardless of `is_active`, so a deactivated donor's profile
     still opens with an "Inactive" pill. Returns false for id 999 when called for editing.
   - `donor_profile_fields($id)`: the `$prof` form shape from `donors` columns. `preferred_contact`
     email/phone/mail/text maps to the form labels Email/Phone/Mail/Text; **both forms gain the Text option**
     (`$preferredOptions`), so an edit never clears it. `created` = `donors.created_at`; "Donor since" on
     the profile comes from `donor_summary.donor_since_date` (already folds in the first gift) and both
     render an em dash when NULL (about half the legacy donors have no dated gift).
   - `donor_cash_history($id)`: `donations` joined to `dbevents` for the campaign name, ordered by
     `donation_date` DESC NULLS last; returns raw fields (`amount` COALESCEd to 0, `donation_date`,
     `payment_method`, `check_number`, `receipt_sent_at`, `send_receipt`, `thanked`, `campaign`). The
     template formats and escapes: payment label "Check #1042" / "Online" / "Cash" / "Card" built in PHP
     and printed through `h()`; receipt badge = sent when `receipt_sent_at IS NOT NULL OR thanked = 1`,
     pending when `send_receipt = 1` and not sent, otherwise a neutral "Not requested".
   - `donor_noncash_history($id)` (adds `unit`), `donor_hours_history($id)`, `donor_communications($id)`
     (`comm_type` mapped to labels; status badge shows the enum word, bounced/failed in a warning style):
     same pattern, campaign names via `dbevents`, every printed cell through `h()`.
   - `donor_create(array $f)`, `donor_update($id, array $f)`: explicit column lists covering only what the
     form posts (donor_type, first, middle_name, last, org_name, email, phone, preferred_contact, street,
     city, state, zip, is_volunteer, email_updates, mail_receipts, do_not_contact, updated_by). `first`/`last`
     NULL for organizations, `org_name` NULL for individuals. Never touches `org_contact`, `notes`, `gender`,
     `donor_since` or `created_at`; `donor_create` omits `donor_since` so the column default applies and the
     new donor reads as "new". Both refuse id 999 and return false. Phone is stored as typed; comparisons
     use digits only.
   - `donor_set_active($id, bool $active, $byUser)`: refuses 999; sets `is_active`, `deactivated_at/by`.
   - `donor_form_validate(array $f)`: one shared validator for both pages: required names by type, email
     format, zip, state in the list, and column lengths (first/last/middle 100, org_name/street 255, email
     100, phone 30, city/state/zip 30) so strict-mode MariaDB never throws on length. Data-layer writes catch
     `mysqli_sql_exception`, log it, and return false; the page shows "Could not save the donor".
2. Pages: replace the stopgap includes/calls. All GET parameters are cast with `trim((string)(...))`.
   - `donor.php`: passes filter/q/sort/page to `donors_list()`, renders `$tiles` from `donors_tiles()`, shows
     an "Inactive" pill when a profile is inactive, and replaces the fake pager links with real ones
     (previous/next from the effective page and `total`).
   - `donorProfile.php`: header breadcrumb and big initials from the real name via `initials()`; the four
     tiles (lifetime cash, items, hours, last gift) come from the `donor_get()` view row so list and profile
     agree even when the latest gift is non-cash; tab footers are computed from the tab rows; badges per the
     mapping above; "Donor since" / "Last contact" em dash when NULL; id 999 shows a read-only profile with
     no edit link.
   - `addDonor.php` / `updateDonor.php`: **write gate**: on POST both call `fish_can('donors', 'edit')` and
     answer 403 otherwise (viewer role has `can_edit = 0`); `action=deactivate` and the new
     `action=reactivate` require `fish_can('donors', 'delete')` (staff has `can_delete = 0`; manager and
     admin have 1). GET remains readable at level 1. `updateDonor.php` shows "Reactivate" instead of "Mark
     inactive" when the donor is inactive, and redirects to `donor.php` for id 999. "Save & add donation"
     redirects to the new profile with a notice until `addDonation.php` exists.
3. Migration 008 + view recreate; `make migrate` picks it up.
4. Tests, docs, Jira comments.

## Acceptance criteria

1. With `make seed-demo` loaded, searching `donor.php` by each demo donor's exact display name returns that
   donor with correct type, contact, lifetime cash, last gift and status; the four tiles equal counts the
   test computes with SQL at run time (the baseline has about 300 legacy donors, so no "only six" claims);
   filter, search, sort and paging change the query, and `?q[]=x`, `?page[]=1`, `?sort=zzz` produce no PHP
   error and fall back to defaults.
2. `donorProfile.php?id=<Maria Lopez>` shows her name in the breadcrumb and initials (no "Jhon" anywhere),
   the four tiles from the view, and the Cash, Non-cash, Volunteer and Communications tabs listing the
   seeded rows with campaign names and the badge mapping above. Grace Community Church (latest gift is
   non-cash) shows the same last gift in the list and on the profile. A legacy donor with an undated gift
   renders without PHP warnings and with an em dash for the missing date. A check number containing `<`
   renders escaped.
3. Adding a donor through `addDonor.php` with `action=save` (the suite never uses "Save & add donation")
   inserts a `donors` row with donor_type, middle name, organization fields, preferred contact (including
   Text) and the four preference flags; the new donor appears in the list as "new" with `updated_by` set.
4. Editing through `updateDonor.php` changes only the posted columns (`notes` and a Text preference survive
   an edit); "Mark inactive" sets `is_active = 0`, hides the donor from the list and legacy-free tiles, keeps
   the profile reachable with an Inactive pill; "Reactivate" restores it. Posting any edit or deactivate for
   id 999 leaves the row unchanged.
5. Anonymous requests to the four pages redirect to login; POSTs without the form token get 403; a `viewer`
   login gets 403 on the add and edit POSTs (GET 200); a `staff` login gets 403 on deactivate; `manager`
   succeeds. `updateDonor.php` is added to `PROTECTED_PAGES` in `tests/auth_integration.py`.
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
