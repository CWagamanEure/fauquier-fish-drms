# Ticket: CI runs lint and the integration tests on every pull request

Branch: `wagaman-eure-ci-tests` (off `origin/dev` at 3357451).

Jira: none. A JQL search of UF2 for CI / pipeline / GitHub Actions / automated test / lint on 2026-10-20
returned no issue. Request from Cory (repo admin), 2026-10-20: "can we add a check to make sure all tests
pass or something like standard companies have".

## Problem

The only GitHub Actions check on this repository is `review-gate` (`record`), which looks for a
`.reviews/<branch>/code-approved.json` produced by the Claude Code `/review-swarm code` skill. The three
teammates with write access do not have Claude Code, so they cannot produce the record, and on 2026-10-20
the `record` check was removed from the required status checks on `dev` and `main` so their PRs could
merge (PR #10 has since merged into `dev`). Nothing now runs the project's tests before a merge:
`make test-auth` (login, CSRF, protected pages, download guard), `make test-donors` (donor pages against
the real tables) and `make test-gate` (the review gate itself) only run when a developer remembers to.
PHP files are not even syntax-checked, so a parse error in a page can reach `dev`.

## Scope

In:
- `.github/workflows/ci.yml`, one job named `tests`, on `pull_request` to `dev`/`main` and `push` to
  `dev`/`main`, `permissions: contents: read`, `concurrency` keyed on the ref with cancel-in-progress.
  Steps, in order, each failing the job on a non-zero exit:
  1. `actions/checkout@v4` with `persist-credentials: false`.
  2. `cp .env.example .env` (the committed local-only defaults; nothing secret).
  3. `docker compose up -d --build --wait --wait-timeout 180 web db` (not `make dev`: it would also
     start phpMyAdmin and rewrite `core.hooksPath`, neither needed on a runner).
  4. `make migrate`, `make seed-demo` (the donor tests require the demo rows).
  5. PHP lint: inside the `web` container, `find . -path ./.git -prune -o -name '*.php' -print0 | xargs -0
     -n1 -P4 php -l`, failing on any non-zero exit (`xargs` returns 123 when any invocation fails).
  6. `make test-auth`, `make test-donors`, `make test-gate`.
  7. `if: failure()` step that prints `docker compose ps` and `docker compose logs --no-color --tail=200`
     so a red run can be diagnosed from the Actions page.
- `docs/process/branch-protection.json`: `required_status_checks.contexts` becomes `["tests"]` (the
  `record` check was removed from the live protection on 2026-10-20 and is not re-required; the
  workflow stays and still reports). `strict` stays `false`.
- `docs/process/change-review-process.md`: the Enforcement bullet about branch protection records that
  since 2026-10-20 the `record` check reports but does not block, why (teammates without Claude Code),
  and that `tests` is the required check once it has run green on `dev`. The hook and the swarm stay the
  process for Claude Code sessions; nothing else in the doc changes.
- `README.md` (or the Makefile `help` text if README has no CI section): one line saying CI runs the
  same `make` targets.

Out:
- Any change to `scripts/review-check.sh`, `scripts/review-hash.sh`, `.githooks/`, or
  `.github/workflows/review-gate.yml`.
- Applying the new branch protection. That is a `gh api -X PUT ... --input docs/process/branch-protection.json`
  run by the repo admin after the first green run on `dev`; it is recorded in the doc, not done by the PR.
- Static analysis beyond `php -l` (PHPStan, Psalm), coverage, secret scanning (a GitHub repository
  setting, not a workflow), dependency audit (there is no composer/npm manifest).
- Changes to the tests themselves. If a test fails on the runner for an environment reason, that is a
  finding for this ticket to fix in the workflow, not a reason to edit the test.

## Approach

- The tests already run inside the `web` container against the compose `db` service (`Makefile`
  `test-auth`, `test-donors`: `docker compose exec -T web python tests/...`), and `tests/review_gate.sh`
  runs on the host with only git and bash. The runner image `ubuntu-latest` ships Docker Compose v2,
  make, git and bash, so the workflow calls the existing `make` targets and adds no second way to run
  the tests. Nothing is installed on the runner.
- The compose file binds `./sql/casadb.sql` into the db init directory and requires `DB_PASSWORD` and
  `DB_ROOT_PASSWORD`; `.env.example` provides both and is committed, so step 2 is enough.
- `--wait` on `docker compose up` returns non-zero if the `db` healthcheck never passes, which is the
  only readiness the tests need (`make migrate` also waits on `db`).
- Lint runs in the container so it uses the same PHP 8.2 as production and needs no `setup-php` action.
- The failure-only log dump is the only diagnostics step; the test scripts already print unittest output.

## Acceptance criteria

1. A PR into `dev` that changes nothing shows a check named `tests` that passes.
2. A PR that adds a PHP file containing a syntax error fails `tests` at the lint step with the file name
   and line in the log.
3. A PR that breaks a guard covered by `tests/auth_integration.py` (for example removing
   `require_authentication()` from a protected page) fails `tests` at the `make test-auth` step.
4. A failed run shows `docker compose ps` and the last 200 lines of service logs in the Actions log.
5. `docs/process/branch-protection.json` lists `tests` and not `record`; the process doc says which check
   blocks and since when.
6. The job finishes in under 10 minutes on `ubuntu-latest` (image build plus tests; measured on the
   first run and written into the PR description).

## Verification plan

- Locally, before pushing: `make dev && make seed-demo && make test-auth && make test-donors && make test-gate`
  and the lint command from step 5 via `docker compose exec -T web sh -c '...'`, all exit 0.
- `actionlint` is not installed locally; the workflow YAML is checked by `python3 -c "import yaml,sys;
  yaml.safe_load(open('.github/workflows/ci.yml'))"` and by the first Actions run.
- On the PR: the `tests` check runs and is green (criterion 1). Criteria 2 and 3 are exercised by
  pushing a throwaway commit with a deliberate parse error, then one that removes a guard, confirming
  the red step, then removing those commits with a new commit (never a force push) before the code
  swarm runs; the PR description says this was done and links the red runs.
- Criterion 6: read the run duration from the Actions page.

## Risks

- Runner time: building the image each run costs a few minutes. Accepted for now; a later ticket can add
  Docker layer caching (`docker/build-push-action` with GHA cache) if it becomes a problem.
- `.env.example` root password is a known value. It only ever configures a throwaway MariaDB inside the
  runner that is torn down with the job; no port is published to the internet.
- The auth tests create temporary accounts and start a temporary `php -S` server inside the container;
  both work on the local stack and the runner uses the same image, but a runner-specific failure (for
  example `/tmp` permissions) would show up only on the first run. The workflow is fixed, not the tests.
- Making `tests` required re-introduces a blocking check, this time one any teammate can satisfy by
  making tests pass. Until the admin applies it, red runs are advisory, as `record` is now.
- Sequencing with in-flight PRs #19 and #21: they do not touch the workflow and are unaffected until the
  check is made required. If they are merged before this and break a test, `tests` will be red on `dev`
  on the push event, which is the signal we want.

## Responses to round 1 (approved; recommendations applied during implementation)

- ci.yml checks out the default ref (merge commit), not the head SHA; `cancel-in-progress` only for
  `pull_request` events; header states no secrets, read-only, never `pull_request_target`.
- Lint uses `git ls-files -z '*.php'` on the host piped into the container (git is not in the image), so
  the file list is identical locally and on the runner; every broken file is reported, not just the first.
- `make seed-demo` (which chains `migrate`) then `make migrate` again, so migrations must be re-runnable;
  a `pull_request` step fails when a migration already on the base branch is modified, deleted or renamed.
- `.env` is checked for `DB_SEED_FILE` after the copy.
- Docs: Step 5, both Enforcement bullets and After-merging items 1 and 3 updated; `ci.yml` added to the
  gate-file list reviewers treat like auth code; hook and review-gate header comments left stale on
  purpose and the doc says so; README line placed in "Change review process"; PR template gains a
  `tests` checkbox.
- Criteria 2 and 3 are exercised locally with the identical commands (no throwaway pushes, the pre-push
  hook would refuse them); CI proves criterion 1 on the PR.
- Accepted, out of scope, raised to the repo admin: `sql/casadb.sql` (the compose init seed) appears to
  hold real donor contact records in a public repository (high). `PROTECTED_PAGES` in
  `tests/auth_integration.py` does not list the 11 pages merged from PRs #10, #19 and #21 (medium);
  follow-up ticket. Risk bullet about open PRs is stale: all three have merged.

## Verification run (2026-10-20, fresh volume via COMPOSE_PROJECT_NAME=fishci, APP_PORT=8090)

- `docker compose up -d --build --wait web db`, `make seed-demo` (8 migrations applied), `make migrate`
  again (0 applied, 8 previously): pass.
- Lint over 108 tracked PHP files: pass. The first draft of the lint step used `read -d ''` inside the
  container, whose `sh` is dash; it linted 0 files and exited 0. Replaced with `xargs -0 -n1 sh -c` and a
  host-side check that the file list is non-empty.
- Criterion 2: a deliberate parse error appended to `index.php` fails lint with file and line (exit 123).
- Criterion 3: removing the layout include from `dashboard.php` fails `make test-auth` with two
  failures naming `dashboard.php`; restored.
- Migration-edit step: a throwaway commit modifying `001_users_and_permissions.sql` is flagged by the
  `git diff --diff-filter=MDR base...HEAD -- sql/migrations` command; a clean tree produces no output.
- `make test-auth`: 20 tests OK. `make test-donors`: 5 tests OK. `make test-gate`: 18 passed.
- Not verified locally: the runner's own build time (criterion 6) and the Actions-side `::error::`
  annotations; both are read off the first PR run.
