# Code review round 5 responses (commit c1fd504)

Approved by all four auditors: 0 critical, 0 high, 2 medium (one defect, reported by two lenses), 3 low.
Round 6 reviews the fixes below.

## Medium
- (correctness, verification) `data-locked` followed the posted role, so after an error page drawn for a
  promotion to admin, reverting the select left the users row disabled and the JavaScript path saved
  users 0/0/0 — **fixed**: `include/userForm.php` now emits `data-locked` from the stored account only
  (own account, or a stored admin whose users row the server forces), while `disabled` still follows the
  form role for the presentation. Test added: the admin-promotion error page has the users row disabled
  and without `data-locked` for a non-admin target.

## Low
- (security) Accepted residual risks carried from rounds 1–4 — **accepted**, unchanged.
- (data) A forged or missing role on POST re-renders with no option selected — **accepted**: the select
  always posts a valid value; validation rejects the request and writes nothing.
- (verification) Ticket item 10 did not list the round 3–4 test steps — **fixed**: item 10 now lists the
  error re-render, stale `preset_role`, disabled boxes and Created date assertions.

## Verification after the fixes
`make seed-demo && USERS_TIMING_STRICT=1 make test-users` 8 OK (slowest writes: add 0.110 s, edit
0.070 s, password 0.097 s); `make test-auth` 21 OK; `make test-donors` 5 OK; `make test-gate` 18 passed;
`php -l` clean on every `*.php`, `include/*.php`, `database/*.php`; `node --check js/userForm.js` clean.
Not run: browser pass, CI.
