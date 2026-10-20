# Code review round 3 responses (commit 1f1f009)

Approved by all four auditors: 0 critical, 2 high (one defect, reported by two lenses), 3 low. Round 4
reviews the fixes below.

## High
- (correctness, verification) After an edit error with a changed role, putting the select back to the
  stored role disabled every box and Save wrote an all-zero matrix — **fixed** on both sides:
  - Server: `editUser.php` now applies the same `preset_role` rule as `addUser.php`: when the posted role
    differs from the stored role, or the boxes were drawn for another role (`preset_role` ≠ role), the
    role's presets are written; otherwise the posted boxes. A revert-after-error POST with no boxes writes
    the stored role's presets, never zeros. Test added (posts role=staff, preset_role=manager, no boxes;
    asserts the staff presets).
  - JavaScript: `js/userForm.js` reads the stored role from `data-stored-role` on the table and the role
    the boxes were drawn for from `preset_role`; choosing the stored role restores the loaded boxes when
    they were drawn for it, otherwise shows the stored role's presets editable and points `preset_role`
    at the stored role so the admin's boxes count. Not executable by the suite; read-verified.

## Low
- (security) Accepted residual risks carried from rounds 1–2 — **accepted**, unchanged.
- (correctness) Dead pieces — **fixed**: unused `$presets` removed from `include/userForm.php`;
  `FISH_USER_COLUMNS` no longer selects `created_at`/`updated_at`; `editUser.php` uses
  `isset(FISH_USER_ROLES[...])` like the other call sites.
- (verification) `created_ts` not asserted — **fixed**: the edit page test asserts the Created fact
  renders a date.

## Verification after the fixes
`make seed-demo && USERS_TIMING_STRICT=1 make test-users` 8 OK; `make test-auth` 21 OK;
`make test-donors` 5 OK; `make test-gate` 18 passed; `php -l` clean on every `*.php`, `include/*.php`,
`database/*.php`; `node --check js/userForm.js` clean; criterion-10 grep clean. Not run: browser pass, CI.
