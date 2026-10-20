# Responses to round 2

Record: `code-round-2.json` (local; approved, 6 confirmed high, 6 medium, 3 low).

| Finding | Response |
|---|---|
| high: server-side enforcement claimed but dev/main unprotected, check not required, direct pushes exempt | partly fixed, partly external: docs now say the Actions check blocks merges only once an admin requires it; `docs/process/branch-protection.json` plus the exact `gh api` command are in the "After merging" section; applying protection is a repo-admin action requested from Cory, not something this PR can do |
| high: hook refuses records over ~72KB (SIGPIPE under pipefail) | fixed: here-strings in `scripts/review-check.sh`; 200KB record case added to `make test-gate` |
| high: Actions check hashes the merge commit, not the PR head | fixed: checkout `pull_request.head.sha`, check runs `scripts/review-check.sh "$HEAD_SHA"` |
| high: Actions check has no dev/main exemption | fixed: the shared `review-check.sh` exempts dev/main for both hook and Actions |
| high (verification): same merge-commit finding | fixed as above |
| high (verification): docs say a bypassed hook "cannot merge" | fixed: wording conditional on branch protection; ticket risks corrected |
| medium: committed round-1 record publishes pointers to a live out-of-scope vulnerability | fixed by policy: full round records are gitignored (repo is public); only ticket, responses and approved stubs are committed; the round-1 record was removed from the tip here and from the branch history in round 3 (squash before first push); prompts ask auditors not to give file:line for out-of-scope pre-existing issues |
| medium: `.reviews/`, `.claude/`, `.githooks/`, `docs/`, `tests/`, `scripts/` served by the web container | fixed: `docker/apache.conf` denies every dot-directory plus docs, tests, scripts, sql, docker; verified 403 after rebuild |
| medium (data): merge-commit finding | fixed as above |
| medium: approval record not bound to the reviewed commit | fixed: skill passes `reviewed_commit` and `reviewed_tree` into the workflow; they are copied into the result and into the stub |
| low: README still says "enforces" | fixed |
| low: record validation duplicated between hook and Actions | fixed: both call `scripts/review-check.sh` |
| medium: open PRs will fail the check with no guidance | fixed in docs and skill: "Branches that predate the process" |
| low: README wording (verification) | fixed |
| low: gate test checks exit codes only; main exemption untested | fixed: message assertions, `main is exempt`, review-check cases; 18 cases |
