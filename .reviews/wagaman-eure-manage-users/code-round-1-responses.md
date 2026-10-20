# Code review round 1 responses (commit 593c518)

Approved by all four auditors: 0 critical, 4 high, 1 medium, 9 low. Round 2 reviews the fixes below.

## High
- (correctness) Add user with JS: custom boxes discarded when the role is not the default — **fixed**:
  `js/userForm.js` now sets the hidden `preset_role` to the selected role on a new account, so the
  server keeps the admin's adjustments; the stale-`preset_role` rule remains the no-JS fallback.
- (correctness) Edit user with JS: reverting the role leaves the other role's presets in the boxes —
  **fixed**: the script records each box's loaded state and restores it when the original role is chosen
  again.
- (correctness) UI changed beyond wiring data; user asked to leave the UI to Tristan — **fixed**: the
  discretionary changes are reverted to the mock-up markup (list keeps the "Last login" column, rendered
  as a dash because no sign-in time is recorded, the static pager links, and Edit / Change password on
  every row; edit page keeps the "Edit user" heading, the mock-up footer copy and the four Account-info
  facts with dashes where there is no data; add/edit/password forms drop `novalidate`; helper paragraphs
  under username, password, role and the permissions table removed; password aside keeps the mock-up copy
  minus the untrue "Every reset is logged" sentence; extra checkbox CSS removed). Unavoidable changes kept
  and listed in the PR: four enum role options, seven permission rows with dash cells, Initial password
  group on Add user, `?id=` links, Deactivate as a submit button, Reactivate panel and inactive notice,
  validation error block and saved notice, read-only rendering without the users edit permission. The
  "+ Add user" button stays hidden without the edit permission, as `donor.php` already does.
- (verification) Same scope finding — **fixed** as above.

## Medium
- (data) Admin-protection test deactivates real admin rows and its cleanup can fail — **fixed**: the test
  skips unless the suite's admin is the only active admin besides `fish.admin` and `vmsroot`, touches only
  that row and `fish.admin` (which the demo seed owns), and restores both with fixture SQL in `addCleanup`
  instead of a browser login.

## Low
- (security) NUL byte in a password passes validation — **fixed**: `user_password_validate()` rejects it;
  test case added.
- (security) Last-admin check keys off the role read before the transaction — **accepted**: it needs two
  admins acting on the same account inside one request window and the promoting admin stays active, so no
  lockout can follow; the concurrent-demotion race the ticket describes is serialised by the lock.
- (data) created_at shown in UTC labelled as local — **fixed**: `fmt_when()` converts from UTC to the
  app's time zone and shows the date only, as the mock-up did.
- (data, correctness) change_password() fault is an uncaught exception — **fixed**: wrapped in try/catch
  with `users_log_fault()`; `users_list()` connect() failure is pre-existing behaviour shared by
  `fish_can()`, left as is.
- (data) UI scope note — **fixed** (see High).
- (verification) Page-level last-admin 403 tested at model level only — **accepted**: through the pages
  the branch is reachable only by `vmsroot` or a race; acceptance criterion 7 is reworded below to say so.
- (verification) PR description should record the measured times and what was not run — **will do** in
  the PR body.
- (verification) `sql/README.md` role-change row — **fixed**: now points at `user_update()`.

## Ticket amendment
Acceptance criterion 7 is enforced in `database/dbUsers.php` (`user_set_status()`, `user_update()`) and
tested through the `last_admin_check` fixture; `editUser.php` maps the result to 403. Through the pages
the case needs `vmsroot` or a race, because an admin acting on another admin means two active admins.
