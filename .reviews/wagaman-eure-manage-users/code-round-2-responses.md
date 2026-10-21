# Code review round 2 responses (commit eebfd0f)

Approved by all four auditors: 0 critical, 1 high, 1 medium, 8 low. Round 3 reviews the fixes below.

## High
- (verification) `ticket.md` was not amended for the round-1 UI revert, so criterion 1 described the
  pre-revert list — **fixed**: items 1, 3 and 5 and criteria 1 and 7 are reworded in the ticket and a
  dated "Amendment after code round 1 (UI scope)" section records the user's instruction to keep the
  mock-up UI and the data-forced changes Tristan should know about.

## Medium
- (correctness) `editUser.php` error re-render blanked the username and discarded the posted boxes —
  **fixed**: the username is taken from the stored record after `user_form_from_post()`; the matrix is
  computed before validation (posted boxes when the role is unchanged, the posted role's presets when it
  changed, i.e. what Save writes). Test extended: same-role case keeps an extra ticked box, changed-role
  case shows the new role's presets, username stays rendered.

## Low
- (security) Pre-transaction role read for last-admin and admin-only decisions — **accepted** (unchanged
  from round 1; same reasoning).
- (security) A non-admin with users edit can widen their own non-users rows and grant users delete —
  **accepted**: recorded as "Not adopted" in the ticket; lateral, not an escalation past users edit;
  admin role and admin accounts stay admin-only.
- (data, correctness) `fmt_when()` assumed the database session zone is UTC — **fixed**:
  `FISH_USER_COLUMNS` now also selects `UNIX_TIMESTAMP(created_at) AS created_ts` and the page formats
  that with `date()`, so no session-zone assumption remains.
- (correctness) Ticket items drifted from the code — **fixed** with the high above.
- (verification) Password aside copy incomplete about who may change passwords — **accepted** as UI copy
  owned by Tristan; the gap is listed in the ticket amendment and will be in the PR body.
- (verification) Criterion 6 short-password case tested only through `addUser.php` — **fixed**: a
  7-character password on `userPassword.php` is now asserted to re-render with the length error.
- (verification) README does not say when role/permission edits take effect — **fixed**: the
  Authentication paragraph now says role and per-area permission changes apply on the target's next
  request and no session is terminated.

## Verification after the fixes
`make seed-demo && USERS_TIMING_STRICT=1 make test-users` 8 OK (slowest writes: add 0.106 s, edit
0.057 s, password 0.114 s); `make test-auth` 21 OK; `make test-donors` 5 OK; `make test-gate` 18
passed; `php -l` clean on every `*.php`, `include/*.php`, `database/*.php`; criterion-10 grep clean.
Not run: browser pass, CI.
