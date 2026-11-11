# Ticket: Auto-deploy `main` to SiteGround with GitHub Actions

Branch: `wagaman-eure-siteground-deploy` (off `origin/dev`, PR targets `dev`)

## Jira keys

None. Requested directly by the repository owner on 2026-11-11 (deploy from `main`); no Jira issue or
Confluence requirement exists for it.

## Problem

The app was first deployed to SiteGround on 2026-11-11 by uploading a hand-built zip into `public_html`.
Every later change needs the same manual zip, upload and extract, and nothing guarantees the server
matches `main`. We want a push to `main` to publish the site automatically.

Facts checked on 2026-11-11 (read-only, over SSH):

- The server has `/bin/rsync`; SSH is public-key only on port 18765.
- `public_html` holds 310 entries. Everything except three is a tracked file: the root `.htaccess`
  (holds the database credentials as `SetEnv` lines, never committed), `php_errorlog`, and an empty
  `fish-site` directory left over from the first upload.
- The uploaded zip was the tracked tree minus development-only paths (list under Approach).
- The repository is public.
- The code reads `.api_env` in two places: the web root (`emailgen.php`) and `ai-email-gen/`
  (`ai-email-gen/index.php`). Neither exists on the server yet.

## Scope

In:

- `.github/workflows/deploy.yml`: deploys on `push` to `main`, and on manual `workflow_dispatch`.
- `scripts/deploy-siteground.sh`: the one rsync invocation, used by the workflow, the test and local dry
  runs.
- `scripts/deploy-siteground.filter`: the rsync filter rules.
- `scripts/deploy-schema-guard.sh` and `sql/production-applied.txt`: the schema guard (below).
- `scripts/deploy-probe.sh`: the exposure probes (below).
- `.github/workflows/ci.yml`: one added step, `make test-deploy`, in the existing `tests` job, so a
  filter or guard regression fails the pull request and not only the deploy.
- `sql/README.md`: one paragraph on the production release rule for migrations, linking the deploy doc.
- `tests/deploy/deploy_test.sh` and a `make test-deploy` target (added to `.PHONY` and the `help` text).
- `docs/deploy-siteground.md`: one-time setup, release order, how to dry-run, limits.
- `README.md`: a short link to that doc, the fact that a push to `main` deploys, and `make test-deploy`.

Out:

- Database schema or data. Migrations are MariaDB-only and the server runs MySQL 8; they stay a manual
  step. The workflow only detects migrations not recorded as applied (see schema guard).
- The server's root `.htaccess` (credentials) and both `.api_env` files. They are edited on the server only.
- Any change to application PHP, to `review-gate.yml` or to
  `docs/process/change-review-process.md` (the review process itself does not change).
- Creating the GitHub environment, secrets and variables, and importing the deploy key into SiteGround.
  The repository owner does these; the doc gives the exact steps.
- Removing the tracked sample artefacts in the runtime directories (`uploads/*.pdf|docx`,
  `reportGeneration/reports/temp_plot_*.png`) from the repository. They keep deploying as today; follow-up.
- Deploying from `dev`, preview environments, rollback automation.

## Approach

### Workflow (`deploy.yml`)

- Triggers: `push` on `main`, and `workflow_dispatch` with input `dry_run` (boolean, default true). Never `pull_request` or `pull_request_target`. A job-level
  `if` requires `github.ref == 'refs/heads/main'`.
- `permissions: contents: read`; `actions/checkout@v4` with `persist-credentials: false`; no third-party actions.
- `concurrency: group: deploy-siteground, cancel-in-progress: false`.
- `environment: production`, restricted by the owner to `main`. Secrets: `SG_SSH_KEY`, `SG_HOST`,
  `SG_USER`, `SG_PORT`, `SG_PATH`, `SG_KNOWN_HOSTS`. Variables: `SG_SITE_URL`, `SG_DEPLOY_ENABLED`.
- Dry-run mapping, stated once: `DRY_RUN` is `false` on `push`; on `workflow_dispatch` it is the
  `dry_run` input. The job summary says in plain words "REAL DEPLOY" or "DRY RUN".
- Enable switch: a `push` run does nothing but print a notice unless `vars.SG_DEPLOY_ENABLED == 'true'`.
  So merging this work to `main` deploys nothing. The owner creates the secrets, dispatches a dry run,
  reads it, and only then sets the variable. Dispatched runs do not need the variable.
- Step order: (0) on `push`, if `SG_DEPLOY_ENABLED` is not `true`, print the notice and stop green,
  before anything else; also on `push`, stop with a notice if `github.sha` is no longer the tip of
  `main` (a re-run of an old run must not publish an older commit); (1) `make test-deploy` on the runner,
  so the filter and guards are proven on the exact rsync that deploys, before any secret is used;
  (2) fail naming any missing secret or variable (names only); `SG_SITE_URL` is required on every run
  that reaches this step; (3) schema guard, real deploys only; (4) exposure probes; (5) write key and
  known_hosts to `0600` files under `$RUNNER_TEMP`; (6) run the script; (7) exposure probes again, real
  deploys only; (8) remove the key, `if: always()`.
- Secrets reach steps through `env:`, never interpolated into `run:` text.
- Schema guard (`scripts/deploy-schema-guard.sh`): `sql/production-applied.txt` is a tracked list of the
  migration filenames the owner has applied on SiteGround (001 to 014 today, which is what the imported
  dump contained). The guard fails, naming the files, when `sql/migrations/` holds a `.sql` file that is
  not in the list, or the list is missing. It is stateless, so it cannot be bypassed by a second push,
  and it runs on every real deploy, push or dispatched. The release rule: port and apply the migration
  on SiteGround, add its filename to the list in the same pull request that reaches `main`. There is no
  override input.
- Exposure probes (`scripts/deploy-probe.sh SITE_URL`): the URL must start with `https://`; redirects are
  not followed. `login.php` must answer 200. Each of `/.htaccess`, `/.api_env`, `/ai-email-gen/.api_env`,
  `/php_errorlog`, `/.env`, `/.git/HEAD`, `/sql/casadb.sql`, `/reportGeneration/reports/makeReport.py`,
  `/python/exports/` and the tracked sample `/uploads/CPSC326_Final_Review%20(1).pdf` must answer exactly
  403 or 404; anything else (200, 3xx, 000) fails the job. Running them before rsync, on dry runs too,
  means an exposure blocks the deploy instead of being found after it. All ten answered 403 and
  `login.php` 200 on the live site on 2026-11-11. The probes do not cover the database.

### Script (`deploy-siteground.sh`, `set -euo pipefail`)

- Source is the tracked tree by construction: `git archive HEAD` is extracted into a fresh temp directory
  (removed on exit) and rsync runs from there. Untracked and git-ignored files cannot be uploaded.
- Refuses to run (non-zero, rsync never invoked) when: a required variable is empty; `SG_PORT` is not
  digits; `SG_PATH` does not end in `/public_html/` or contains `..`; the extracted tree has no
  `login.php`; `SG_HOST` or `SG_USER` has a character outside `[A-Za-z0-9._-]`; `SG_PATH` has a
  character outside `[A-Za-z0-9._/-]`; the rsync binary (`RSYNC`, default `rsync`) is not GNU rsync 3
  (macOS openrsync is refused, its filter behaviour is untested); or `DRY_RUN=false` with a remote
  destination while `GITHUB_ACTIONS` is not `true`. That last guard stops accidental real deploys from a
  laptop; it is not a security boundary.
- `DRY_RUN` is a dry run unless it is exactly `false`.
- One invocation: `rsync -rlz --checksum --delete --delete-after --itemize-changes
  --filter='merge scripts/deploy-siteground.filter' [--dry-run] -e "ssh -p PORT -i KEY
  -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile=FILE -o BatchMode=yes"
  -- SRC/ USER@HOST:PATH`. No owner, group, permission or time flags. No `--delete-excluded`.
- Test seam: when `SG_LOCAL_DEST` is set the destination is that local directory, no ssh is used, the
  ssh variables are not required, and a real run is allowed outside CI (nothing remote can be touched).
  The `/public_html/` suffix guard, the rsync flags and the filter are the same. `SG_FILTER_FILE`
  replaces the filter only when `SG_LOCAL_DEST` is set and is refused otherwise. The default filter is
  read from the script's own directory in the checked-out tree. The test never sets `GITHUB_ACTIONS`.
- After rsync (dry or real) the script scans the itemized output and exits non-zero if any `*deleting`
  line names `.htaccess`, `.api_env`, `php_errorlog`, or a path under `uploads/`,
  `reportGeneration/reports/`, `python/exports/` or `.well-known/`. In a dry run this fails before
  anything changes; in a real run it is a tripwire and the test proves the filter prevents it.

### Filter (`deploy-siteground.filter`), first match wins

1. Server-only files, never uploaded and never deleted (`-` exclude rules, which hide and protect):
   `/.htaccess` (root only; subdirectory `.htaccess` files are tracked and deploy), `.api_env` at any
   depth, `php_errorlog` at any depth.
2. Never deleted (`P` protect rules), tracked files inside still upload: `/uploads/**`,
   `/reportGeneration/reports/**`, `/python/exports/**`, `/.well-known/**`.
3. Development-only paths, not uploaded and deleted from the server if present (`H` hide rules, which do
   not protect): `/.git/`, `/.github/`, `/.githooks/`, `/.claude/`, `/.reviews/`, `/sql/`, `/tests/`,
   `/scripts/`, `/docker/`, `/docs/`, `/README.md`, `/CLAUDE.md`, `/Makefile`, `/Dockerfile`,
   `/compose.yaml`, `/Go/`, `/LICENSE.txt`, `/package.json`, `/package-lock.json`, `/.gitignore`, `/.gitattributes`,
   `/.dockerignore`, `/.env`, `/.env.*`, `/.coderabbit.yaml`. Root-anchored, so
   `ai-email-gen/Dockerfile` and `ai-email-gen/Makefile` keep deploying as today.

If the implementation finds a rule type behaves differently on the runner's rsync than described, the
test decides: the behaviour in criterion 5 is the requirement, the rule letters are the means.

## Acceptance criteria

1. A push to `main` runs the deploy job; a pull request, or a push to any other branch, does not.
2. A push with `SG_DEPLOY_ENABLED` unset transfers nothing. With it `true`, a push is a real deploy and
   the summary says so. `workflow_dispatch` defaults to a dry run that changes nothing.
3. The job has read-only repository permissions and no step prints a secret value.
4. SSH host key checking is strict against the pinned key.
5. `make test-deploy` executes `deploy-siteground.sh` itself (through `SG_LOCAL_DEST`), asserts on the
   resulting tree, and passes on GNU rsync:
   a. development-only paths in the source are not uploaded;
   b. development-only paths already on the destination (`sql/dump.sql`, `.git/HEAD`, `.env`) are deleted;
   c. `.htaccess`, `.api_env`, `ai-email-gen/.api_env`, `php_errorlog`, `.well-known/x` and untracked
      files under the three runtime directories survive, in the same run as (b);
   d. a stale file elsewhere on the destination is deleted;
   e. tracked files in runtime directories and `ai-email-gen/Dockerfile` are uploaded;
   f. an untracked, git-ignored file in the working tree is not uploaded;
   g. each guard refuses with a non-zero exit and an unchanged destination: empty variable, bad port, path
      not ending in `/public_html/`, path with `..`, `SG_FILTER_FILE` without `SG_LOCAL_DEST`, and
      `DRY_RUN=false` with a remote destination and `GITHUB_ACTIONS` unset (the `login.php` guard cannot
      be reached through `git archive HEAD` of this repository and is untested);
   h. `DRY_RUN` unset, `true` or any value other than `false` leaves the destination unchanged;
   i. the deletion tripwire exits non-zero when `SG_FILTER_FILE` is a filter that would delete a
      protected file;
   j. the schema guard, in a temporary directory: all migrations listed passes; an unlisted migration
      fails and is named; a missing list fails.
6. A real dry run against SiteGround exits zero (so the tripwire saw no protected deletion) and lists no
   deletion other than the empty `fish-site` directory; every listed transfer is explained in the PR.
7. On an enabled push run or a dispatched run with a secret or `SG_SITE_URL` missing, the job fails after
   the test and before any step that uses the network or the key, and names what is missing.
8. A real deploy while `sql/migrations/` holds a file not in `sql/production-applied.txt` fails before
   rsync and names the file, on that push and on every later one until the list is updated.
9. `docs/deploy-siteground.md` states: what is not deployed (database, `.htaccess`, `.api_env`); that a
   release with a new migration needs the schema applied on SiteGround by the owner before the merge to
   `main`; that a deploy removes server files that are not in the repository outside the protected paths
   and overwrites server copies of tracked files, including those in the runtime directories; the
   first-time sequence (create the `production` environment with deployment branches limited to `main`
   and confirm it before storing the key, a required reviewer recommended; secrets; merge; dispatched dry
   run; set `SG_DEPLOY_ENABLED`); that the deploy key gives shell access to the site account and so to
   the database credentials; that the server root `.htaccess` must keep denying dot-files and
   `php_errorlog`; that SiteGround caching can delay visible changes; and that teammates need no
   `make dev`, `make migrate` or re-login for this change.
10. `make test-deploy` runs in the pull request `tests` job.

## Verification plan

- `make test-deploy` on GNU rsync. The Mac's built-in rsync is openrsync, which the script refuses, so
  locally the test runs inside a Docker container with GNU rsync; the PR states the rsync version. The
  same test runs in the pull request `tests` job and as the first real step of every deploy job.
- `bash -n` on the shell files; `shellcheck` and `actionlint` if installed (neither is on this machine;
  the PR says which ran); a YAML parse of `deploy.yml` and `ci.yml`.
- `scripts/deploy-probe.sh` against the live site.
- Real dry run of the script from the branch against SiteGround with the developer's existing SSH key and
  GNU rsync (in the same container image, key mounted read-only), output and rsync version attached to
  the PR and checked against criterion 6. No real deploy is run from the branch.
- `make test-gate` (the change touches `.github/workflows/`).
- Not verifiable before merge: the workflow run itself (criteria 1, 2, 3, 4, 7 as observed in Actions,
  including the enable switch and the missing-secret step, which are workflow YAML and have no local
  test), because the workflow only runs from `main` and the owner creates the secrets. The PR says so.
  After the merge to `main` the first run is a push run that only prints the "not enabled" notice.

## Risks

- `--delete` on a live site. Mitigated by the protect rules, the guards, the tripwire, the test on the
  deploying rsync, the enable switch and the dry run before the first real deploy.
- Code reaching production before its schema. Mitigated by the schema guard and the documented order.
  The guard trusts the list: it cannot see a migration edited in place, or a filename added to the list
  without the schema being applied. The probes do not check the database.
- A leaked deploy key gives write access to the site account. Mitigated by a dedicated key held only in a
  `main`-restricted environment, no pull request triggers and no third-party actions. The doc tells the
  owner to remove the key in SiteGround and rotate the database password if a leak is suspected.

## Responses to round 1

Round 1 approved (no critical). All highs and mediums are fixed in this revision.

- Local run can publish ignored files (high, security): fixed. Source is a `git archive HEAD`
  extraction; real runs refused outside CI; criterion 5f.
- Only root `.api_env` protected (high, security; high, correctness; medium, data): fixed. Unanchored
  rules for `.api_env` and `php_errorlog`; criterion 5c; automatic deletion tripwire.
- No guard against schema drift (high, data) and no stated ordering (medium, verification): fixed.
  Schema guard, criterion 8, release order in criterion 9, risk listed.
- `DRY_RUN` undefined on push (high, correctness): fixed. Mapping stated; summary names the mode.
- Merge to `main` is itself a real deploy (high, verification): fixed. `SG_DEPLOY_ENABLED` switch and
  first-time sequence.
- Filter test never runs on the deploying rsync (high, verification; medium, correctness): fixed. First
  step of the deploy job; asserts on the tree; garbled sentence rewritten.
- Excluded paths are not deleted with the stated flags (high, verification; medium, security; medium,
  correctness): fixed. Rule types stated (`H`, `-`, `P`), no `--delete-excluded`, criterion 5b and 5c.
- Post-deploy check too narrow (medium, security): fixed. Extended list.
- Guards and dry-run default untested (medium, verification): fixed. Criteria 5g, 5h; the test executes
  the script.
- README, Makefile help, process doc (medium, verification): fixed. In scope; process doc stated unchanged.
- `--checksum` missing from the command (low): fixed.
- Dirty-tree guard (low): fixed by the archive source.
- Runtime-directory overwrite not documented, `.well-known` (low): fixed. Criterion 9, `P /.well-known/**`.
- Tracked artefacts re-uploaded (low, data): accepted. Same as today's server contents; removal is a
  listed follow-up.
- Wording of criteria 6 and 7 (low): fixed.
- Key scope and action pinning (low, security): partly fixed. Port and path validation, `--`, and leak
  guidance added. `actions/checkout` stays on the `v4` tag to match `ci.yml` and `review-gate.yml`
  (first-party action); SiteGround's key manager offers no forced-command restriction.

## Responses to round 2

Round 2 approved (no critical). All highs and mediums are fixed in this revision.

- Schema guard only sees the current push (high: data, correctness, verification): fixed. Replaced by a
  stateless guard against a tracked applied-list, run on every real deploy; override input removed;
  criteria 5j and 8.
- Exposure probes only after a real deploy (high, security): fixed. Probes run before rsync on every
  run, dry runs included; `SG_SITE_URL` required; doc requirement on the server `.htaccess` added. The
  live site was probed on 2026-11-11 and already denies all ten paths.
- Weak probes, "not 200" (medium, security; lows): fixed. Real sample file, directory URL, exact 403/404,
  https only, no redirects.
- Environment branch rule not covered (medium, security): fixed in criterion 9.
- Guard contradicts the local test (medium: correctness, verification; low, security): fixed. Real runs
  allowed in CI or to a local destination only.
- Filter override seam for 5i (medium: correctness, verification): fixed. `SG_FILTER_FILE`.
- Workflow-only safeguards untestable (medium, verification): partly fixed. Schema guard and probes are
  scripts with tests or a live run; the enable switch and missing-secret step stay workflow YAML and are
  listed as not verifiable before merge.
- `sql/README.md` not in scope (medium, verification): fixed.
- Re-running an old run (low): fixed, step 0. Enable switch order (low): fixed, step 0.
- Input hardening, `/Go/` (low): fixed; `/LICENSE.txt` hidden too. `testmsg.txt` keeps deploying as
  today (denied by the server rule); follow-up.
- rsync client for the real dry run (low): fixed. GNU rsync required by the script.
- Test only in the deploy job (low): fixed. One step added to `ci.yml`, criterion 10.
- Post-deploy check cannot see schema mismatch (low, data): accepted, stated in Risks.

## Responses to round 3

Round 3 approved (no critical; 1 high, 10 medium, 10 low). The amendments below replace the earlier text
where they differ. They were written after the round 3 approval and were not put through a fourth
ticket round; the code gate reviews the implementation against this ticket including these amendments.

Facts re-checked on the server on 2026-11-11 (read-only): `Go/`, `LICENSE.txt`, `ReportGeneration.php`,
`VolunteerRegister.php`, `volunteerReport.php` and `php.ini` are in `public_html` (the earlier statement
that only three entries are untracked stands; the zip did not exclude `Go/` or `LICENSE.txt`).
`.env.example` is not there. The production schema matches migrations 001 to 014: 19 tables and views
including `donor_contacts` (013) and `volunteer_hours_changes` (010), the four triggers of 001 and 002,
`communications.person_contacted_id` and `follow_up_on` (014), `non_cash_donations.weight_lbs` present
and `unit` absent (012), `donor_summary.status` (011).

- Criterion 6 conflicts with the hide list (high, verification; medium, correctness): fixed. Criterion 6
  now reads: the dry run exits zero, and every deletion it lists is `fish-site/` or a path matched by a
  hide rule (expected: `Go/`, `LICENSE.txt`); each deletion and each transfer is listed and explained in
  the PR.
- Dispatched real deploy bypasses the enable switch (medium, security): fixed. `SG_DEPLOY_ENABLED`
  (a repository variable) must be `true` for every real run, push or dispatched. Only dispatched dry runs
  are exempt. The tip-of-`main` check also applies to every real run.
- Step 0 inside the environment job (medium, correctness): fixed. A first job `gate`, with no environment
  and no secrets, decides `mode` (`skip`, `dry`, `real`) and prints the notice; the `deploy` job `needs`
  it and has a job-level `if`.
- Baseline 001 to 014 not verified (medium: data, verification): fixed. Verified above; the doc's
  first-time sequence tells the owner to confirm the list before enabling.
- Old code on new schema, where the ported SQL lives, dev-to-main flow (medium, data; low,
  correctness): fixed in the doc requirements. The doc states: the list line is added in a pull request
  to `dev` after the owner has applied the migration; migrations applied ahead of the code must work
  with the code that is live, and a destructive step is applied immediately before the release merge;
  if the deploy fails after the schema was applied, fix forward and re-run; the ported MySQL 8 SQL is
  kept in the pull request description. Only the owner edits the list.
- How `make test-deploy` gets GNU rsync locally (medium: correctness, verification): fixed. The target
  runs `tests/deploy/deploy_test.sh` on the host; when the host rsync is not GNU rsync 3 the test exits
  non-zero and prints the container command, and `make test-deploy-docker` runs it in a stock
  `alpine:3.20` container with bash, git and rsync installed at run time and the repository mounted
  read-only. The project `Dockerfile` is unchanged.
- Guard case passing for the wrong reason (medium, correctness): fixed. Remote-mode guard cases run under
  `env -u GITHUB_ACTIONS` and assert on the refusal message.
- Guard silent until after the merge (medium, verification; low, data): fixed. The `ci.yml` step runs the
  schema guard on the real tree in every pull request `tests` run, and dispatched dry runs run it too,
  as a warning in the summary; it is fatal on real deploys.
- Applied-list format (low): fixed. One filename per line, `#` comments and blank lines ignored, trailing
  CR and whitespace trimmed, whole-line fixed-string match, glob `[0-9][0-9][0-9]_*.sql` as in
  `scripts/db-migrate.sh`, failure when the glob matches nothing; near-name case added to 5j.
- 404 accepted for uploaded files (low, security): fixed. The two tracked-file probes require exactly
  403; the doc notes that the `python/exports/` probe is weak. The probe prints each status it received.
- env-only rule, absolute `SG_PATH` (low, security): partly fixed. Variables, inputs and context values
  also go through `env:`. `SG_PATH` is not required to be absolute: SiteGround's path is relative to the
  account home; the character set and `/public_html/` suffix guards stay.
- Guards missing from 5g (low): fixed, the character-set and non-GNU rsync cases are added.
- README, `sql/README.md`, process doc (low): the two READMEs are edited as scoped; adding the deploy
  files to the process doc's sensitive-files sentence is a follow-up, not done here.
- `php.ini`, `testmsg.txt` (low): deliberate keep, both are on the server today and denied by the
  server `.htaccess` rule (`.ini`, `.txt`); `/php.ini` is added to the probe list.
