# Code review round 6 responses (commit 83da820)

Approved by all four auditors: 0 critical, 0 high, 2 medium (one issue, two lenses), 5 low. This round
closes the gate; no further round is run. Everything below is accepted and carried into the PR as
follow-up work, except where noted.

## Medium (accepted, follow-up)
- (correctness, verification) Add user: after an error page drawn with Administrator selected, the
  JavaScript never re-enables the users row on a new account, so a Manager created from that page gets
  users 0/0/0 while the screen shows Users view ticked — **accepted**: narrow path (admin chosen, then a
  validation error, then the role changed on the same page); the server writes what it was posted and an
  admin can fix the row on the edit page. One-line follow-up in `js/userForm.js` (recompute `disabled`
  for new accounts too), listed in the PR.

## Low (accepted)
- (security) Residual risks carried from rounds 1–5 — unchanged, documented in the ticket.
- (data, verification) `tests/users_integration.py` is not safe to run as two concurrent instances
  against one stack (shared `fish.manager` grant, row count, last-admin skip) — CI runs one job; local
  runs are serial. Follow-up: wrap the `fish.manager` grant in `addCleanup`.
- (data) Add-page users row after an error page — same issue as the medium above.
- (correctness) `user_set_status()` returns `'last_admin'` for deactivating an already-inactive admin
  when vmsroot is the only active admin — the page never offers that action for an inactive account;
  message only, no data effect.

## Verification
No code changed after round 5's commit, so the round-5 results stand: `USERS_TIMING_STRICT=1 make
test-users` 8 OK (slowest writes: add 0.110 s, edit 0.070 s, password 0.097 s); `make test-auth` 21 OK;
`make test-donors` 5 OK; `make test-gate` 18 passed; `php -l` clean on all PHP files; `node --check
js/userForm.js` clean. Not run: browser pass, CI.
