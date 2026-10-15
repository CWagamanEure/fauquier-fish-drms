---
name: review-swarm
description: Required review gate for this repo. Runs four specialist auditors over a ticket draft (before any implementation) or over the code on the current branch (before any push), records the result under .reviews/, and loops until no critical finding remains. Use it when starting a ticket, before pushing, or when asked to audit a change.
---

# /review-swarm {ticket|code} [subject]

This skill enforces `docs/process/change-review-process.md`: nothing is implemented until its ticket draft
is approved by the four-auditor swarm, and nothing is pushed until the code on the branch is approved
by the same swarm. Approval means every auditor reported and no critical finding survived verification.

Records live in `.reviews/<branch>/` (branch name with `/` replaced by `-`). The repository is public,
so only the ticket, the responses files and the two `*-approved.json` stubs are committed; the full
round records (`*-round-<n>.json`) are gitignored and stay on the author's machine. Responses files list
findings by title and outcome, never with file-and-line pointers to unfixed, out-of-scope vulnerabilities.
`scripts/review-hash.sh` prints the content hash that `scripts/review-check.sh` (run by the pre-push hook
and by the GitHub Actions check) compares against `code-approved.json`.

## ticket mode: `/review-swarm ticket UF2-83`

1. Make sure you are on the feature branch for the work (never `dev` or `main`).
2. Write the ticket draft to `.reviews/<branch>/ticket.md` with these sections: Jira keys, Problem,
   Scope (in and out), Approach (files and tables to touch, reusing existing helpers), Acceptance
   criteria (testable), Verification plan (commands), Risks. Pull the Jira description and any
   Confluence requirement with the Atlassian tools; quote them, do not paraphrase from memory.
3. Run the workflow:
   `Workflow({ scriptPath: ".claude/workflows/review-swarm.js", args: { mode: "ticket", subject: "<branch>", ticketPath: ".reviews/<branch>/ticket.md", round: 1 } })`
4. Write the returned object to `.reviews/<branch>/ticket-round-<n>.json`.
5. If `approved` is false, or any confirmed high finding is not something you will knowingly accept:
   revise `ticket.md`, append a "Responses to round <n>" section explaining what changed for each
   finding (fixed / accepted with reason), and run again with `round: <n+1>` and
   `previous: ".reviews/<branch>/ticket-round-<n>.json"`. Repeat until approved.
6. Write `.reviews/<branch>/ticket-approved.json` as a stub of the approving round (`mode`, `subject`,
   `round`, `approved`, auditors with `approve` and `finding_count`, counts per severity and status; the
   full record stays local) and start implementing.
   Tell the user how many rounds it took and which highs were accepted, with the reasons.

The saved-workflow registry is read when a session starts, so `scriptPath` is the reliable form;
`name: "review-swarm"` also works in a session started after the file existed.

## code mode: `/review-swarm code`

1. Finish the implementation. Fetch and merge or rebase onto `origin/dev` first, then run the project
   checks yourself (`make test-auth`, the verification plan from the ticket). Do not run the swarm on
   code you have not tested, and remember a later merge or rebase counts as a change.
2. Commit everything (the diff command only sees commits), then record what is being reviewed:
   `reviewed_commit=$(git rev-parse HEAD)` and `reviewed_tree=$(scripts/review-hash.sh)`. Run:
   `Workflow({ scriptPath: ".claude/workflows/review-swarm.js", args: { mode: "code", subject: "<branch>", ticketPath: ".reviews/<branch>/ticket.md", diffCommand: "git diff origin/dev...HEAD", round: 1, reviewed_commit: "<sha>", reviewed_tree: "<hash>" } })`
3. Write the result to `.reviews/<branch>/code-round-<n>.json`.
4. Fix confirmed critical and high findings. A high may be accepted instead of fixed only outside the
   Security specialty, and only with a reason. Write `.reviews/<branch>/code-round-<n>-responses.md`
   with a "Responses to round <n>" section (one line per finding: fixed / accepted because / disputed),
   re-run every check, commit, recompute `reviewed_commit` and `reviewed_tree` from the new HEAD, and run
   the swarm again with `round: <n+1>` and `previous:` pointing at the last record. Repeat until approved.
5. Write `.reviews/<branch>/code-approved.json` as a stub of the approving round: `mode`, `subject`,
   `round`, `approved`, `reviewed_commit`, `tree` (must equal the round's `reviewed_tree`, which must
   equal `scripts/review-hash.sh` now), the auditors with their `approve` flag and `finding_count`, and
   the count of findings per severity and status. Commit it, then push. The hook and the Actions check
   refuse a push or PR whose content hash differs from the record, so any edit after approval means
   another round. Commit the stub as a new commit: never amend or squash after approval, or the
   `reviewed_commit` in the record stops being reachable.
6. Open the PR with the template; link both records. Report rounds, accepted highs, and what the
   auditors did not cover.

## Branches that predate the process

Merge `dev` into the branch, write `.reviews/<branch>/ticket.md` describing what was built, run
`/review-swarm code`, commit the stub. The ticket gate may be skipped for work that predates the
process; say so in the PR.

## Rules

- Never edit a record to make it pass. If a finding is wrong, the verification stage will have marked
  it `disputed`; if it did not, fix the code or argue it in the responses file and re-run. Removing a
  file-and-line pointer to a pre-existing, out-of-scope vulnerability from a responses file is allowed
  and is not that.
- `REVIEW_SWARM_SKIP=1 git push` exists for emergencies only and must be mentioned in the PR.
- The swarm does not replace `make test-auth` or a teammate's review; it comes before both. The
  per-auditor `approve` flag is informational; the gate is defined by confirmed criticals.
- `make test-gate` checks the gate itself after any change under `.githooks/`, `scripts/review-*.sh` or
  `.github/workflows/`.
