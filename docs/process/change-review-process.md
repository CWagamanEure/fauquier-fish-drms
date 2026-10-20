# Change review process

Every change to this repository, by a person or by an AI assistant, goes through the same gate
twice: once on the plan, once on the code. The gate is a panel of four specialist auditors run by
the `/review-swarm` skill, with each critical finding adversarially re-checked by two more agents so
that a wrong finding cannot block work and a real one cannot be waved through. A round is therefore
four agents, plus two per critical.

## The four auditors

| Auditor | Looks at |
|---|---|
| Security | access gates, form tokens, SQL and shell built from input, file paths, data exposure |
| Data and schema | migrations, queries against the real tables, prepared statements, NULLs, dates, dependence on demo data |
| Correctness and architecture | logic, edge cases, error handling, reuse of existing helpers, dead code, simplicity |
| Verification and product | acceptance criteria, tests, match with the Jira ticket and the FISH NET pages, docs, post-merge steps |

Each auditor returns findings with a severity: critical, high, medium, low. Two skeptic agents then
try to refute every critical finding by reading the code or ticket; a finding both skeptics refute is
marked disputed and does not count. High, medium and low findings are reported as the auditor wrote
them; the author answers each one in the responses file.

**Approved** means all four auditors reported and no confirmed critical finding remains. High findings
are expected to be fixed; one may be accepted instead, with the reason written in the responses file,
except Security highs, which are always fixed before the record is committed (records are visible to
everyone who can read the repository). The per-auditor approve flag is informational.

## Step by step

1. **Draft the ticket.** On a feature branch off `dev`, write `.reviews/<branch>/ticket.md`: Jira keys,
   problem, scope in and out, approach (files, tables, helpers to reuse), testable acceptance criteria,
   verification plan, risks. Quote the Jira and Confluence text rather than paraphrasing it.
2. **Audit the ticket.** `/review-swarm ticket <subject>`. Revise and re-run until approved. The
   approving record is saved as `.reviews/<branch>/ticket-approved.json`. No implementation before this.
3. **Implement**, and run the checks from the verification plan yourself (`make test-auth` at least).
4. **Audit the code.** `/review-swarm code`. Fix, re-test, re-run until approved. The approving record,
   with the content hash from `scripts/review-hash.sh`, is saved as `.reviews/<branch>/code-approved.json`
   and committed.
5. **Push and open the PR** using the template, linking both records. The pre-push hook refuses a push
   without a matching approval record. A human teammate still reviews the PR; the swarm comes before that
   review, not instead of it. Branch protection currently requires the check but zero approving reviews,
   so the human review is expected rather than enforced; raise `required_approving_review_count` to 1 in
   `docs/process/branch-protection.json` and re-apply it to enforce it.

## Enforcement

- `make hooks` (run automatically by `make dev`) points git at `.githooks/`, whose `pre-push` hook guards
  against pushing unreviewed or drifted code: the approval record must be committed, say approved, and
  carry the current content hash. The branch judged is the one written on the remote, so
  `git push origin HEAD` works. `dev` and `main` are exempt because they only receive PR merges; tags are
  not gated. The hook only exists on branches that contain `.githooks/`, so merge `dev` into older
  branches before continuing them. Because the hook directory is tracked, reviewers treat changes under
  `.githooks/`, `scripts/review-hash.sh`, `scripts/review-check.sh` and `.github/workflows/review-gate.yml`
  like auth code, and confirm the PR diff is what the record's `reviewed_commit` and `tree` describe.
- `.github/workflows/review-gate.yml` runs `scripts/review-check.sh` on every PR into `dev` or `main`
  against the PR head commit. It fails the PR when the record is missing or stale, and since 2026-10-15
  branch protection on `dev` and `main` requires that check (its status-check name is the job name,
  `record`), requires a pull request, and applies to administrators, so a failing check blocks the merge
  and direct pushes are refused. `strict` is off on purpose: the gate judges the PR head, and requiring
  branches to be up to date would force a new swarm round on every open PR each time `dev` moves. Pull
  requests from forks are never exempt, whatever their branch is called. The PR template only appears once
  it exists on the default branch.
- The record is a plain file, so the real control is the human reviewer confirming it is a genuine
  workflow output (rounds, auditors, finding counts, the reviewed commit) and not hand-written.
- The repository is public. Only the ticket, the responses files and the `*-approved.json` stubs are
  committed; full round records are gitignored and stay with the author. Responses never carry
  file-and-line pointers to unfixed, out-of-scope vulnerabilities. `make test-gate` tests the gate; run it
  after any change under `.githooks/`, `scripts/review-*.sh` or `.github/workflows/`.
- The web container serves the repository root, so `docker/apache.conf` denies the dot-directories,
  `docs/`, `tests/`, `scripts/`, `sql/` and `docker/` over HTTP.
- `CLAUDE.md` tells AI sessions in this repo to follow the same two gates.
- `REVIEW_SWARM_SKIP=1 git push` (or `git push --no-verify`) bypasses the hook for emergencies and must be
  called out in the PR; the Actions check still applies.

## After merging

1. Branch protection is already applied to `dev` and `main` (2026-10-15) with the settings in
   `docs/process/branch-protection.json`: pull request required, status check `record` required,
   administrators included, no force pushes or deletions, `strict` off. To re-apply or copy to another
   branch: `gh api -X PUT repos/<owner>/<repo>/branches/<branch>/protection --input docs/process/branch-protection.json`.
2. Everyone runs `make dev` (installs the hook, rebuilds the web image for the Apache rules).
3. Branches already in flight (for example the open donation-page PRs): merge `dev`, add
   `.reviews/<branch>/ticket.md` describing what was built, run `/review-swarm code`, commit the stub.
   The ticket gate may be skipped for work that predates the process; say so in the PR.

## What the swarm does not do

It does not run the test suite, it does not replace a teammate reading the PR, and it only sees what
is on the branch and in the ticket draft. Findings it marks disputed can still be right; read them.
