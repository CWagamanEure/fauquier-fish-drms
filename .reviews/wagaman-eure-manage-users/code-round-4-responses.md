# Code review round 4 responses (commit 304522d)

Approved by all four auditors: 0 critical, 0 high, 2 medium, 4 low. Round 5 reviews the fixes below.

## Medium
- (correctness, verification low) Changed-role error page rendered the preset boxes enabled although
  Save ignores them — **fixed**: `include/userForm.php` draws every box disabled when the form's role
  differs from the stored role on an existing account (`data-locked` stays on the users cells only, so the
  JavaScript re-enables the rest when the stored role is chosen again). Test asserts the disabled state.
- (verification) Ticket item 3 did not describe the `preset_role` branch added in round 3 — **fixed**:
  item 3 now states the three branches (role changed → new presets; role unchanged but boxes drawn for
  another role → stored role's presets, replacing custom rows; otherwise the posted boxes) and the
  disabled rendering above. The test now gives the account a custom row first, so it distinguishes
  "presets written" from "stored rows kept".

## Low
- (security) Accepted residual risks carried from rounds 1–3 — **accepted**, unchanged.
- (data) Revert-after-error with a stale `preset_role` replaces customised rows with the stored role's
  presets — **accepted** as the documented design (ticket item 3): the admin changed the role on the first
  submit and the page shows the presets before Save.
- (correctness) Presets computed in both `editUser.php` (for the re-render) and `user_update()` (for the
  write) — **accepted**: `role_presets()` is memoised and the model must stay authoritative for callers
  other than the page.

## Verification after the fixes
`make seed-demo && USERS_TIMING_STRICT=1 make test-users` 8 OK; `make test-auth` 21 OK;
`make test-donors` 5 OK; `make test-gate` 18 passed; `php -l` clean on every `*.php`, `include/*.php`,
`database/*.php`; `node --check js/userForm.js` clean. Not run: browser pass, CI.
