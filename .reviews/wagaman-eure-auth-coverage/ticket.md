# Ticket: cover the pages merged from PRs #10, #19 and #21 in the auth tests; enable CodeRabbit on dev

Branch: `wagaman-eure-auth-coverage` off `origin/dev` (7e69823, after PR #22).
Jira: none (follow-up recorded in `.reviews/wagaman-eure-ci-tests/code-round-2-responses.md`, PR #22).

## Problem

`tests/auth_integration.py` checks that every entry point in its hand-maintained `PROTECTED_PAGES` and
`PROTECTED_APIS` lists redirects anonymous GET and POST to `/login.php`. The eleven pages merged from
PRs #10 (TCD-FISHNET-donations), #19 (TCD-manage-users) and #21 (TDC-campaignpages) are not listed:

addCampaign.php addDonation.php addUser.php campaign.php campaigns.php donations.php editUser.php
manageUsers.php newDonor.php userPassword.php viewDonation.php

Each one includes `include/fishnet-layout.php` (verified by grep), so each is guarded today, but the new
`tests` CI check would not notice if a later edit dropped the include. Nothing fails when a new page is
added without being listed, which is how this gap appeared.

Separately, CodeRabbit is installed on the repository but reports "reviews are disabled for this base
branch" on PRs into `dev`, because it only reviews PRs into the default branch (`main`) unless told
otherwise.

## Scope

In:
1. Add the eleven pages to `PROTECTED_PAGES` in `tests/auth_integration.py`. They all use the layout
   include, so they belong in the 303-redirect list, not the 401-JSON `PROTECTED_APIS` list.
2. Add a drift test to `tests/auth_integration.py`: every tracked top-level `*.php` file (from
   `git ls-files`, run on the host side of the test, falling back to a directory listing of ROOT when git
   is unavailable) that contains `fishnet-layout.php` or `require_authentication(` must appear in
   `PROTECTED_PAGES` or `PROTECTED_APIS`; otherwise the test fails naming the file. Includes under
   `include/`, `database/` and other subdirectories are not entry points and are excluded (the existing
   lists already name the subdirectory entry points explicitly). This makes the gap fail CI next time
   instead of waiting for a reviewer to notice.
3. Add `.coderabbit.yaml` with `reviews.auto_review.base_branches: ["^dev$"]` so CodeRabbit reviews PRs
   into `dev` as well as `main` (docs.coderabbit.ai/reference/configuration).
4. Documentation follow-ups promised in PR #22's round-2 responses: After-merging item 1 in
   `docs/process/change-review-process.md` and the `ci.yml` header say `tests` is "already" required;
   it is now true (applied 2026-10-20), so reword to state the date. `sql/README.md` item 4 ("run
   `make migrate` twice") prescribes a check that proves nothing because the second run skips by checksum;
   replace it with the CI replay command (`scripts/db-sql.sh < sql/migrations/<file>` after `make migrate`).
   Change the migration-edit guard in `ci.yml` from `--diff-filter=MDR` to `--diff-filter=MDRT` so a file
   replaced by a symlink also fails.

Out:
- Pages that are not guarded (`deleteLocation.php`, `deleteService.php`, `eventApproved.php`,
  `profileEditForm.php`, `registrationForm.php`, `viewVolunteerProfile.php`, `header.php`, `footer.php`,
  `infoBox.php`): pre-existing legacy, needs its own ticket to decide guard vs delete. The drift test
  only covers files that already carry a guard marker.
- Any change to page behaviour, permissions (`fish_can`) or schema.
- `sql/casadb.sql` donor data question.

## Approach

- `PROTECTED_PAGES` gains the eleven names, grouped on their own line with a comment naming the PRs.
- New test `test_every_guarded_entry_point_is_listed` in `AuthenticationTests`: compute the set of
  guarded top-level PHP files by reading each file under ROOT (not via docker), compare with the two
  lists, assert the difference is empty with the file names in the message. Reading is done with
  `errors="ignore"` so legacy encodings do not crash the test.
- `.coderabbit.yaml`: three lines plus a comment pointing at the docs.
- Doc wording edits as listed.

## Acceptance criteria

1. `make test-auth` passes (21 tests: 20 existing plus the drift test) on the current tree.
2. Removing the `fishnet-layout.php` include from `campaigns.php` makes
   `test_anonymous_pages_and_mutation_handlers_require_login` fail for that page (GET and POST).
3. Adding a new top-level file containing `fishnet-layout.php` without listing it makes the drift test
   fail and names the file; deleting the file restores the pass.
4. `.coderabbit.yaml` parses as YAML and uses the documented key path.
5. The `tests` check on the PR is green.
6. `scripts/review-check.sh HEAD wagaman-eure-auth-coverage` passes before push.

## Verification plan

- `make test-auth` on the running local stack (criterion 1).
- Criteria 2 and 3 by temporary local edits, reverted with `git checkout`/`rm` before commit.
- `python3 -c 'import yaml,sys; print(yaml.safe_load(open(".coderabbit.yaml")))'` (criterion 4).
- Criterion 5 observed on the PR; the PR description records it.

## Risks

- The drift test reads files from the host working tree while the page tests hit the container; both
  see the same bind-mounted tree, so there is no mismatch. In CI, the checkout is the same tree.
- The drift test's marker regex could miss a guard written differently (for example a page that
  includes the layout via a variable path). That produces a false pass for that file only, the same as
  today; it never produces a false failure except for a file that genuinely is guarded and unlisted.
- CodeRabbit regex: `^dev$` anchored, so `dev-foo` branches are not matched. Intentional.

## Responses to round 1 (approved; 3 high, 5 medium, 6 low, all adopted unless noted)

- Drift test inverted (high, security): every `*.php` at the top level and under `python/`,
  `reportGeneration/` (recursive) and `ai-email-gen/` must be in `PROTECTED_PAGES`, `PROTECTED_APIS` or a
  new `KNOWN_UNGUARDED` list (login.php, logout.php, insertAdmin.php, header/footer/infoBox fragments,
  and the six legacy unguarded pages, each with a reason). A `KNOWN_UNGUARDED` file that gains a guard
  marker fails; a listed file that no longer exists fails. (Also closes the subdirectory medium.)
- No git in the test (two highs): `make test-auth` runs inside the web container, which has no git. The
  test enumerates with `ROOT.glob(...)` only; the ticket's "host side" wording was wrong. Untracked local
  files are scanned too (criterion 3 relies on it); CI checks out tracked files only.
- CSRF (medium): the five new pages with POST forms and no `auth_csrf_field()` (addDonation, addUser,
  editUser, newDonor, userPassword) get the field, and the drift test asserts every scanned file with a
  POST form contains `auth_csrf_field(`. Verified before the change that these five were the only ones.
- CodeRabbit (medium): the sources agree the head branch's `.coderabbit.yaml` is read with highest
  precedence, so this PR itself should receive a review. New criterion 7: CodeRabbit posts on this PR; if
  not, the PR description records why.
- Docs (medium, lows): also fixed the "applies the migrations twice" and "admin applies after the first
  green run" sentences in the process doc, the README test-auth paragraph, and the ci.yml header (which
  did not contain "already"; its sentence now states the date). The README replay instruction mirrors
  the full CI loop. The earlier record's "14 pages" was a miscount; the merge diffs show 11 and the drift
  test makes the count self-verifying.
- Jira (low): no UF2 item exists for this process work; none created.
- After merging: no action for teammates (no schema, image or session change).
- Untracked `docs/sequence-diagram/` stays out of the commit (added to Out).
