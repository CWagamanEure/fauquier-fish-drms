# Ticket: Manage Users pages read and write the real accounts (UF2-83, 84, 85, 86, 97)

Branch: `wagaman-eure-manage-users` off `origin/dev` (1eabc90, after PR #23).
Jira (all "In Progress", assigned to Cory):
- UF2-83 "As the admin i want to manage user that can access the system" (no description).
- UF2-84 "The system shall allow for the admin to change the password of users that can use the system
  and update the db within 5 seconds" (no description).
- UF2-85 "the Shall system shall allow for the admin to edit information about the user (like thier name
  and permissions) and update within 5 seconds" (no description).
- UF2-86 "the system shall allow the admin to add new users so that new user can access the system and
  update the db within 2 seconds" (no description).
- UF2-97 "The system shall allow the admin to deactivate a user so they cannot sign in while keeping
  everything they entered". Description: "Deactivate button on the Edit user page. Blocks sign-in but
  keeps the user's records; status shows Inactive on the Manage Users list."

## Problem

`manageUsers.php`, `addUser.php`, `editUser.php` and `userPassword.php` (merged from PR #19) are static
mockups: hard-coded names, a role list (Administrator / Donor Coordinator / Treasurer / Volunteer
Coordinator / Read only) that does not match the `dbpersons.role` enum (`admin`, `manager`, `staff`,
`viewer`), a six-row permission table where the schema has seven areas (`data_import` and `users` are
separate in `user_permissions.area`), no POST handling, and a "Deactivate" button that does nothing.
The schema for all of this exists (migration 001: `dbpersons.first_name/last_name/email/role/status`,
`user_permissions`, `role_permission_presets`; triggers keep `accessLevel` in step with role and status;
migration 008 backfilled permission rows) and `include/permissions.php` already reads it, so nothing an
admin "changes" today has any effect on what `fish_can()` decides.

Facts that shape the design (verified in the tree):
- `dbpersons.id` is the username (VARCHAR(256) PK, case-insensitive collation). Every history column
  (`donors.updated_by/deactivated_by`, `donations.recorded_by`, non-cash, hours, communications) is a
  foreign key to `dbpersons(id)` with ON UPDATE CASCADE ON DELETE SET NULL (migrations 002-008), so a
  rename would cascade; the username still stays read-only because it is the session identifier
  (`$_SESSION['_id']`) and the login lookup key, and a rename would strand the user's open session.
- `dbpersons.name` is still read by legacy pages and by `fishnet_open()` (avatar initial), so it must be
  kept equal to `first_name . ' ' . last_name`.
- There is no `last_login` or audit column anywhere; the mockup's "Last login", "Password changed" and
  "Created by" have no data source, and the password page's aside claims "Every reset is logged".
- `login.php` only signs in `status = 'active'` accounts; `auth_refresh_account()` signs out a session
  whose account becomes inactive on its next request, and re-reads the role. So deactivation and role
  changes take effect without any session work here.
- `database/dbPersons.php`: `add_person()` is parameterised but inserts only id/name/password/accessLevel
  on its own connection (the trigger derives role from accessLevel, so it can never make a `viewer`) and
  seeds permission rows for that derived role; it stays as the legacy registration/`insertAdmin.php`
  path and is not used here. `retrieve_person()` is parameterised; `change_password()`/
  `reset_password()`/`remove_person()`/`retrieve_persons_by_name()` interpolate SQL.
- `vmsroot` is an active admin on every migrated database (migration 001 forces it) with a NULL email
  and name 'SUPER ADMIN'; `insertAdmin.php` returns "USER ALREADY EXISTS" for it, so it is not a lockout
  recovery path. Recovery is an UPDATE through `make db-shell`.
- `fish_can('users', ...)` presets: admin view/edit/delete, manager view only, staff and viewer nothing.
- Live dev database has 13 accounts: the 5 demo users, `vmsroot`, and 7 legacy test rows (inactive).

## Scope

In:
1. `manageUsers.php`: list every `dbpersons` row (active first, then inactive; by last name, first name,
   username). Columns: Name (initials + full name), Username, Role (display label), Permissions (one chip
   per area the user can edit, "View only" chips for view-without-edit areas, "None" when nothing),
   Email (replaces the mockup's "Last login", which has no data source; NULL renders as a dash), Status
   (`Active` / `Inactive` with the existing `status active|off` badges), actions Edit and Change
   password. Sort: `status` active first, then `COALESCE(last_name, name)`, `first_name`, `id`. Page
   requires `fish_can('users', 'view')`: otherwise HTTP 403 rendered inside the layout with the notice
   "Your account does not include Manage Users." (all four pages). `fishnet_open()` hides the sidebar
   "Manage Users" link when `fish_can('users', 'view')` is false (one-line change in
   `include/fishnet-layout.php`, which now also requires `include/permissions.php`). "+ Add user",
   "Edit" and "Change password" render only when `fish_can('users', 'edit')`. Pager line shows the real
   count; no paging (tens of rows at most). Usernames in links and redirects are `rawurlencode()`d.
2. `addUser.php` (UF2-86): fields first name, last name, username, email, role, password, confirm
   password, plus the seven-area permission table pre-filled from the chosen role's presets (default
   `staff`), plus a hidden `preset_role` naming the role those boxes were drawn from. On POST with
   `fish_can('users', 'edit')`: validate; `user_create()` runs one prepared INSERT into `dbpersons`
   (id, name, password, first_name, last_name, email, role, status='active'; the trigger sets
   `accessLevel`) and the seven `user_permissions` rows on the same connection inside
   `begin_transaction()`/`commit()`, rolling back on any `mysqli_sql_exception`. The rows come from the
   posted boxes when the posted role equals `preset_role`, otherwise from the posted role's presets (the
   boxes were drawn for another role, so without JavaScript they would disagree with the role). Redirect
   303 to `manageUsers.php?user=<username>&did=created`, where the list looks the username up with
   `user_get()` and shows "User <first last> created." through `h()`; unknown `user`/`did` values show
   nothing. Duplicate username or email (pre-check, plus `errno 1062` caught from the INSERT) is a form
   error, not an exception. Password fields are always re-rendered empty and never written to
   `error_log`.
3. `editUser.php?id=<username>` (UF2-85): first name, last name, email, role editable; username shown
   read-only (reason above); the seven-area permission table with the user's current rows (falling back
   to presets for a missing row, as `fish_can()` does). Unknown `id` renders "User not found" inside the
   layout with HTTP 404. Save rule, stated on the page: if the posted role differs from the stored role
   the permissions are reset to the new role's presets inside the same transaction as the role UPDATE and
   the posted checkboxes are ignored (matches the mockup footer "Changing the role resets permissions to
   that role's defaults"); otherwise the posted checkboxes are saved as the user's rows (one
   `INSERT ... ON DUPLICATE KEY UPDATE` per area; a missing checkbox is stored as 0, so unticking
   revokes). Self-edit: when `id` is the signed-in user, the role select and the `users` row are rendered
   disabled and the server takes role and the `users` row from the stored record, never from POST (no
   403, nothing reset). Admin accounts: the `users` row is locked to the admin preset (rendered disabled,
   forced server side), so an active admin always has effective `users` edit and the last-admin rule
   below counts effective access. Aside shows "Record created" and "Last updated" (from
   `created_at`/`updated_at`; legacy rows carry migration 001's time), the Change password link and the
   Deactivate/Reactivate control. Redirect 303 to `manageUsers.php?user=<username>&did=updated`.
4. Deactivate / reactivate (UF2-97): POST `action=deactivate` sets `status = 'inactive'` (the trigger
   zeroes `accessLevel`; login refuses; existing session ends on its next request). `action=reactivate`
   sets `status = 'active'`. Nothing is deleted. The list shows the Inactive badge. Deactivate requires
   `fish_can('users', 'edit')` (not `delete`: no row is removed). The `action` branch runs before and
   independently of the form validation (as `updateDonor.php` does), so legacy rows with NULL email can
   still be deactivated or reactivated.
5. `userPassword.php?id=<username>` (UF2-84): with `fish_can('users', 'edit')`, set a new password:
   at least 8 characters (`mb_strlen`) and at most 72 bytes (`strlen`; bcrypt reads 72 bytes), must
   match the confirmation; stored with `password_hash(..., PASSWORD_BCRYPT)` via a parameterised
   `change_password()`. Password fields re-render empty on error and are never logged. Redirect 303 to
   `manageUsers.php?user=<username>&did=password` ("Password updated for <name>."). The whole aside is
   rewritten to match rule 6: who may change whose password (admins any account; a non-admin with
   `users` edit only non-admin accounts), that the account's "Last updated" time changes, that no audit
   log exists, and that the user's open sessions are not signed out (no session store to reach).
6. Protection rules enforced server side on every write:
   - You cannot deactivate your own account (403). Your own role and your own `users` row are taken from
     the stored record on save (see item 3), so an ordinary edit of your own name or email succeeds.
   - Only an `admin` can assign the `admin` role, edit a user whose role is `admin`, or change an
     admin's password. A manager-level user granted `users` edit can manage non-admin accounts only.
   - The last active admin cannot be deactivated or demoted. `vmsroot` does not count (nobody may know
     its password). Enforced atomically: the demote/deactivate path runs in a transaction that locks the
     admin rows (`SELECT id FROM dbpersons WHERE role='admin' AND status='active' AND id <> 'vmsroot'
     FOR UPDATE`), performs the UPDATE, re-counts, and rolls back with 403 when the count would be 0.
   - Deactivate/reactivate/demote attempts that break these rules return 403 with a short message; the
     GET pages hide the controls and say why.
7. Validation: first/last name 1–100 chars (column width), username trimmed by `fish_str()` then
   `preg_match('/\A[A-Za-z0-9._-]{3,64}\z/')` (so the stored form equals the typed form;
   `auth_stored_username()` would HTML-encode anything else and `login.php` lower-cases on lookup, which
   the collation also ignores), email required, valid per `filter_var(FILTER_VALIDATE_EMAIL)`, <= 255
   chars, unique (edit pre-check uses `AND id <> ?`; `errno 1062` from the write maps to the same error);
   role one of the four enum values. Permission input: only the seven enum areas and the three actions
   are read from `perm[]` (fixed constants `FISH_PERMISSION_AREAS` in `dbUsers.php` and the existing
   `FISH_PERMISSION_ACTIONS`); any other key is ignored; column names are fixed strings, only user id,
   area and 0/1 values are bound. `data_import` delete is never granted (migration 001, UF2-7): the cell
   renders as a dash and the server forces 0. Errors re-render the form with the posted values (except
   passwords), as `addDonor.php` does. No raw `$_GET`/`$_POST` value is printed; names come from the
   database through `h()`.
8. `database/dbUsers.php` (new): `users_list()`, `user_get($username)` (row plus permission matrix;
   selected columns are id, first_name, last_name, name, email, role, status, created_at, updated_at,
   never `password`), `user_create(array $form, $hash)`, `user_update($username, array $form, array
   $matrix)` (role UPDATE + preset reset or matrix upsert, one transaction), `user_set_status($username,
   $active, $byRole)` (admin-count lock above), `user_matrix_from_post(array $post)`, `role_presets()`
   (reads `role_permission_presets` once), role/area label maps. All prepared statements; writes return
   `true`, `'duplicate'`, `'last_admin'` or `false` so pages can show the right message. In
   `database/dbPersons.php`, `change_password()` and `reset_password()` are parameterised (touched
   because `userPassword.php` uses `change_password()`); nothing else in that file changes.
9. `include/userForm.php` (new): the shared account + role + permissions fieldset used by add and edit,
   rendering `perm[<area>][view|edit|delete]` checkboxes for the seven enum areas with human labels
   (Donors & profiles, Donations (cash & non-cash), Campaigns, Communications, Reports, Data imports,
   Users). `js/userForm.js` (new, optional enhancement): when the role select changes, tick the boxes to
   that role's presets (presets embedded with `json_encode(..., JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|
   JSON_HEX_QUOT)`) so the admin sees what will be saved, disables them and shows the reset notice;
   choosing the original role back re-enables them. The server rules above hold without JS.
10. Tests: new `tests/users_integration.py` (+ `make test-users`, added to `ci.yml` and README) using
    `Browser`/`assert_no_php_errors_in` from `auth_integration.py` and a `php -r` fixture like
    `donors_integration.py` (its `donor_create`/`donor_delete` cases copied for criterion 5). Covers:
    list rows equal `SELECT ... FROM dbpersons` read through the fixture and the five demo accounts show
    their role label, email and badge (`fish.former` Inactive), NULL email as a dash, pager count equal to
    the row count; viewer/staff get 403 on GET and no sidebar link; manager sees the list without
    Add/Edit links and gets 403 on POST; logged-in POST without `csrf_token` to the three form pages is
    rejected with 403 "form has expired" and writes nothing; admin creates a viewer with the viewer rows
    (then that user can log in), add with role changed but `preset_role` stale gets the presets, duplicate
    username (other case) and email errors write nothing; edit name + email rebuilds `name`; ticking
    `donors` edit for a viewer lets that viewer save a donor, unticking revokes it; role change resets to
    presets; admin edits own email without 403 and keeps the `users` row; `data_import` delete stays 0;
    deactivate blocks login, ends the open session on its next request, shows Inactive, leaves the donor
    the (staff) user created in place; reactivate restores login; password change makes the old
    password fail and the new one work; self-deactivate, demoting/deactivating the last non-vmsroot
    admin, and a manager with `users` edit touching an admin all return 403 and change nothing. Each
    write POST's elapsed time is printed; it is asserted against the Jira limit (2 s add, 5 s
    edit/password) only when `USERS_TIMING_STRICT=1` is set, so a slow CI runner cannot block PRs; the
    local run sets it and the PR records the times. All created rows (users, donor) are deleted in
    cleanup.
11. `tests/auth_integration.py`: no list change needed (the four pages are already in
    `PROTECTED_PAGES`); the drift test's CSRF check keeps passing because every form keeps
    `auth_csrf_field()`.
12. Documentation: `Makefile` (`test-users` target, `.PHONY`, help text); `.github/workflows/ci.yml`
    (step after test-donors); `README.md` CI paragraph (lines 62-64, list of targets), permissions
    paragraph (lines 104-107, "editable per user later" becomes a description of Manage Users: who can
    list, add, edit, deactivate, change passwords; admin-only and last-admin rules), Authentication
    section (deactivation and role/permission edits take effect on the target's next request; no session
    is terminated), tests paragraph (`make test-users` needs `make seed-demo`);
    `docs/process/change-review-process.md` Enforcement section (CI target list).
13. After merge: teammates `git pull` only. No `make migrate`, no image rebuild, no re-login. Run
    `make seed-demo` before `make test-users`.

Out:
- Login history / last-login column, password-change audit log, "Created by" (needs a schema change and a
  write in `login.php`; separate ticket if wanted).
- Renaming a username; deleting a user (deactivate is the only off-switch; `remove_person()` stays
  unused and un-parameterised).
- Self-service password change (`changePassword.php`, `viewProfile.php`) and the legacy
  `registrationForm.php`.
- Forcing a password change at next login, password strength rules beyond length, emailing new users.
- Sessions of a user whose password or permissions change are not terminated (PHP file sessions, no
  index by user); the next request re-reads role and status, and `fish_can()` has only a per-request
  cache, so permission edits apply on the target's next request.
- Deleting the seven legacy test accounts or fixing `vmsroot`'s credential (data decisions).
- `docs/sequence-diagram/` (untracked local folder) stays out of the commit.

## Approach

- Mirror the donor pages: `require_once 'include/fishnet-layout.php'` then `include/permissions.php`
  then `database/dbUsers.php`; compute `$canEdit = fish_can('users', 'edit')` first; POST without it →
  403 and exit before any database write; GET without `fish_can('users', 'view')` → 403.
- Role labels: admin "Administrator", manager "Manager", staff "Staff", viewer "Read only". The select's
  option values are the enum strings.
- Permissions matrix helper: `user_permission_matrix($username)` returns
  `[area => ['view' => 0|1, 'edit' => 0|1, 'delete' => 0|1]]` using the same COALESCE(user row, preset)
  query shape as `fish_can()`, so the page shows exactly what the gate enforces.
- `name` column written as `trim(first . ' ' . last)` on create and edit.
- Redirect-after-POST with `?user=&did=` and a one-line notice, like `donorProfile.php?saved=1`; the
  list page validates `did` against three fixed words and resolves `user` through `user_get()`.
- Seed: `sql/seed/demo_fish.sql` already resets the five demo users and their permission rows; tests
  create their own `users_test_<hex>` accounts so the demo rows stay canonical.
- CSS: reuse `donor.css` (`.chip`, `.status`, `.edit`, `.pw-link`) on the list and `forms.css`
  (`.perms`, `.user-chip`, `.match`, `.panel.danger`) on the forms; add a `.notice` rule only if the
  form pages lack one (noted earlier as missing on form pages).

## Acceptance criteria

1. `manageUsers.php` as `fish.admin` renders one row per `dbpersons` row (the test compares with a
   SELECT through the fixture; on the current dev database that is 13 rows, a manual check only) with
   real names, role labels, email (dash for NULL), Active/Inactive badges and a matching pager count; as
   `fish.manager` the same list without Add/Edit/Change-password controls; as `fish.staff` or
   `fish.viewer` HTTP 403 inside the layout and no "Manage Users" sidebar link.
2. Add user as admin: a `viewer` is created with exactly the viewer preset rows unless boxes were
   changed; the new account appears in the list, `name` equals "First Last", `accessLevel` follows the
   role (trigger), and the new user can log in with the given password. A stale `preset_role` with a
   different posted role yields the posted role's presets. Response time of the POST under 2 s (UF2-86),
   asserted with `USERS_TIMING_STRICT=1` locally.
3. Add user with an existing username (any letter case) or email re-renders the form with an error and
   writes nothing.
4. Edit user: name and email change; `name` is rebuilt; ticking `donors` edit for a viewer lets that
   viewer save a donor (`updateDonor.php` 303) on their next login and unticking revokes it; changing
   role from `viewer` to `manager` leaves exactly the manager preset rows; an admin's own edit keeps
   role and `users` row and returns 303; `data_import` delete is always 0; POST under 5 s (UF2-85).
5. Deactivate: `status = 'inactive'`, `accessLevel = 0`, login returns the invalid-credentials page, the
   user's existing session is redirected to login on its next request, list shows Inactive; records the
   user entered (a donor they created) are untouched; reactivate restores login (UF2-97).
6. Password: wrong confirmation or 7 characters re-renders with an error; a valid change makes the old
   password fail and the new one log in; POST under 5 s (UF2-84).
7. Protection: self-deactivate, demoting or deactivating the only active non-vmsroot admin, and a
   manager with `users` edit touching an admin account all return 403 and change nothing.
8. A logged-in POST without `csrf_token` to `addUser.php`, `editUser.php?id=` and `userPassword.php?id=`
   returns 403 "form has expired" and writes nothing (new test in `users_integration.py`; anonymous
   redirects stay covered by `test_anonymous_pages_and_mutation_handlers_require_login`).
9. `make test-auth`, `make test-donors`, `make test-users`, `make test-gate` pass locally and the `tests`
   CI check is green on the PR.
10. `grep -n '"\s*\.\s*\$\|\$[a-z]*\s*\.\s*"' database/dbUsers.php` finds no interpolated SQL; every
    query in the new file and the two touched `dbPersons.php` functions binds parameters; area and action
    keys pass through the fixed constants before any SQL is built; no raw request value is printed.
11. `scripts/review-check.sh HEAD wagaman-eure-manage-users` passes before push.

## Verification plan

- `make seed-demo && make test-users` (criteria 1–7), `make test-auth` (8), `make test-donors`,
  `make test-gate` (9) on the local stack; CI run observed on the PR.
- Manual pass in the browser as `fish.admin`: add, edit, change role, deactivate, reactivate, change
  password; as `fish.manager`: list read-only; as `fish.staff`: 403.
- Criterion 10 by the grep plus reading the file.
- PHP lint: `docker compose exec -T web sh -c 'for f in *.php include/*.php database/*.php; do php -l $f; done'`.

## Risks

- A manager-level account granted `users` edit could otherwise promote itself to admin; mitigated by
  rule 6 (admin role and admin accounts are admin-only) and the self-edit rule.
- Locking everyone out: mitigated by the atomic last-active-admin rule (vmsroot excluded) and the locked
  admin `users` row; recovery if it ever happens is `UPDATE dbpersons SET role='admin', status='active'
  WHERE id=?` through `make db-shell` (`insertAdmin.php` cannot help, see facts).
- Username validation is stricter than the legacy registration form; existing odd usernames (`vmsroot`,
  `HelloWorldTest`) are unaffected because the username is never rewritten.
- `email` UNIQUE with NULLs allowed: legacy rows have NULL email; editing such a row forces the admin
  to enter one (required field). Acceptable; the five demo rows already have emails.
- Timing criteria depend on the stack; CI prints the times and asserts them only under
  `USERS_TIMING_STRICT=1`, so the required `tests` check cannot flake on a slow runner.
- Showing all `dbpersons` rows exposes the seven legacy test accounts in the UI; they are inactive and
  an admin can leave them. Deleting them is a data decision outside this ticket.

## Responses to round 1 (approved: 0 critical, 5 high, 12 medium, 15 low; all adopted unless noted)

- Create path (4 highs, all auditors; the fifth high is the self-edit item below): `add_person()` is no longer used. `user_create()` does its own
  INSERT with role/status and the seven permission rows in one transaction (items 2, 8; facts updated).
- Self-edit (high, correctness): role and the `users` row come from the stored record on self-edit; 403
  only for self-deactivate. Admin `users` rows are locked to the admin preset, which also makes the
  last-admin count equal effective access (items 3, 6).
- Last-admin race (medium ×2, low): transaction with `SELECT ... FOR UPDATE` on admin rows, re-count,
  rollback. `vmsroot` excluded from the count (item 6). `insertAdmin.php` recovery claim replaced.
- Permission input (medium ×3, low): fixed area/action constants, missing key = 0, upsert per area,
  unknown keys ignored, `data_import` delete forced 0 and rendered as a dash (items 7, 9, criterion 10).
- Add page without JS (medium): hidden `preset_role`; role mismatch → presets (item 2, criterion 2).
- Duplicates (medium, low): `id <> ?` on edit, `errno 1062` caught and mapped (items 2, 7, 8).
- Passwords never re-rendered or logged; `mb_strlen >= 8`, `strlen <= 72` (items 2, 5).
- Reflected params (medium, low): `?user=&did=` with `rawurlencode()`, lookup via `user_get()`, `h()`;
  unknown id → 404 inside the layout (items 1, 2, 3, approach).
- Username regex `\A...\z` after trim; hash never selected into page arrays; presets JSON hex-escaped
  (items 7, 8, 9).
- Facts corrected: history FKs are ON UPDATE CASCADE; username stays read-only for session reasons.
- Deactivate branch runs before validation (item 4). "Record created" label; `COALESCE(last_name, name)`
  sort (items 1, 3).
- Tests (medium ×2, lows): no hard-coded 13; rows compared with a SELECT; CSRF test on the three pages
  (criterion 8 re-attributed); donor fixture for criterion 5 with cleanup; timing asserted only under
  `USERS_TIMING_STRICT=1` (item 10, criteria 1, 2, 8).
- Docs and after-merge (medium): items 12 and 13 added. Sidebar link hidden without `users` view;
  403 rendered inside the layout (low). `userPassword.php` aside rewritten (low).
- Not adopted: making the users-edit path count effective access for non-admin accounts beyond the
  locked admin row (covered by the admin lock); the legacy test accounts and `vmsroot` credential remain
  data decisions (Out).
