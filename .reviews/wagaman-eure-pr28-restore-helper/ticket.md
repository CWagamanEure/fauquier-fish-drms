# Ticket: PR #28: restore the `assert_deactivate_ignored` test helper dropped by a merge

Branch: `wagaman-eure-pr28-restore-helper`, based on PR #28's head (`Andrews-donorBackend` at b1d2906).
Delivered as a pull request into `Andrews-donorBackend`, for James to review and merge, like
`wagaman-eure-pr28-fixes` (PR #31). Worked in the worktree `/Users/corywagamaneure/projects/fish-pr28-fixes`.

Jira: none. Cory (repo admin), 2026-10-28: "ok can you fix", after the diagnosis below.

## Problem

PR #28's CI run 40555965376 (2026-10-27, head b1d2906) fails `make test-donors` with two errors:

```
tests/donors_integration.py line 213, test_permissions_gate_writes_by_role_and_row
tests/donors_integration.py line 390, test_add_and_update
AttributeError: 'DonorPagesTests' object has no attribute 'assert_deactivate_ignored'
```

Cause, from git history: commit 11e8197 (PR #31) added the method `assert_deactivate_ignored(self, session,
token)` to `DonorPagesTests`, plus two calls to it. On `dev`, PR #29 added `demo_donor(self, name)` at the same
place in the class. Merge b1d2906 (`dev` into `Andrews-donorBackend`) resolved that conflict by keeping only
dev's side. The definition was deleted and both calls stayed: c0fe1a1 has 1 definition and 2 calls, b1d2906 has
0 definitions and 2 calls. Every other dev change in that file came through the merge.

## Scope

In: `tests/donors_integration.py` only. Put back the 9-line method exactly as it reads at
`c0fe1a1:tests/donors_integration.py` lines 212-220, directly before `def demo_donor`, so the class has both
methods. No other line changes.

Out: application code, migrations, other tests, any other review comment on PR #28.

## Approach

- File: `tests/donors_integration.py` in the worktree. No tables, no PHP. The method uses the existing
  `fixture("donor_read", ...)` helper and the existing `session.request(...)`, both already in the file.
- Insert the block from `git show c0fe1a1:tests/donors_integration.py | sed -n '212,221p'` (the method and
  its trailing blank line) immediately above `    def demo_donor(self, name):`.

## Acceptance criteria

1. `git diff origin/Andrews-donorBackend` only adds the method (plus a blank separator line), byte-identical to
   the c0fe1a1 version.
2. The method is defined once, and both calls (lines 213 and 390) resolve to it.
3. On a fresh database with this branch: after `make seed-demo`, `make test-donors` passes with no errors.
   `make test-auth`, `make test-users`, `make test-donations`, `make test-hours` and `make test-gate` also pass (they share the
   seed but this change does not touch them; the run confirms the branch head is otherwise green).

## Verification plan

CI does not run on this PR (`ci.yml` triggers only for PRs into `dev` and `main`). It runs on PR #28 once this
merges. The local runs below are the evidence and go in the PR description.

- Worktree `.env` sets `COMPOSE_PROJECT_NAME=fish-pr28`, `APP_PORT=8090`, `PHPMYADMIN_PORT=8091`. Before
  `make dev` and before the final `docker compose down -v`, `docker compose config | grep '^name:'` must print
  `name: fish-pr28` and the published ports must be 8090 and 8091 (no duplicate port keys in `.env`).
  `grep '^DB_SEED_FILE' .env` must print nothing, so the fresh volume is built from `sql/casadb.sql` plus
  migrations, as in `ci.yml`. The main checkout's stack and volume are not touched.
- Criterion 1: `git diff origin/Andrews-donorBackend -- tests/` and a diff of the added block against
  `git show c0fe1a1:tests/donors_integration.py | sed -n '212,220p'`.
- Criterion 2: `grep -c 'def assert_deactivate_ignored'` = 1, `grep -c 'self.assert_deactivate_ignored'` = 2.
- Criterion 3: `make dev`, `make seed-demo`, then each test target; record the counts.
- Then `/review-swarm code` until approved, commit `code-approved.json`, push, open the PR into
  `Andrews-donorBackend`.

## Risks

- Low. The change is test-only and restores code that was already reviewed and approved in PR #31
  (`.reviews/wagaman-eure-pr28-fixes/code-approved.json`).
- If PR #28 is merged with `dev` again before this lands, the same conflict can come back. The PR description
  says to keep both methods.

## Responses to round 1 (approved; 0 criticals)

- Medium, "local verification database differs from CI: DB_SEED_FILE set": fixed. The worktree `.env` now has
  it commented out, and the plan requires `grep '^DB_SEED_FILE' .env` to print nothing.
- Medium, "criterion 3 leaves out make test-hours": fixed, added to criterion 3.
- Low (two auditors), "duplicate APP_PORT/PHPMYADMIN_PORT keys": fixed. The 8080/8081 lines are removed from the
  worktree `.env`, and the plan checks the published ports.
- Low, "plan does not mention the code gate": fixed, added as the last step.

## Verification results (2026-10-28, fresh volume, compose project `fish-pr28`)

- Isolation: `docker compose config` printed `name: fish-pr28`, published ports 8090/8091;
  `grep '^DB_SEED_FILE' .env` printed nothing; no `fish-pr28` volume existed before `make dev`.
- Criterion 1: the diff adds 10 lines (the method plus a blank line) above `demo_donor`; the 9 method lines are
  identical to `c0fe1a1:tests/donors_integration.py` lines 212-220.
- Criterion 2: 1 definition, 2 calls.
- Criterion 3: `make dev` (11 migrations applied), `make seed-demo`, then test-donors 6 OK, test-auth 21 OK,
  test-users 8 OK, test-donations 12 OK, test-hours 13 OK, test-gate 18 passed 0 failed.
