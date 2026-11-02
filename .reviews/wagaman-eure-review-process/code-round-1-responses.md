# Responses to round 1

Record: `code-round-1.json` (approved, 1 confirmed high, 10 medium, 10 low).

| Finding | Response |
|---|---|
| high: hook refuses `git push origin HEAD` (local_ref) | fixed: branch derived from `remote_ref`; non-branch refs skipped; covered by `tests/review_gate.sh` |
| medium: committed records can publish unfixed security findings | fixed in process: Security highs must be fixed, never accepted, before the record is committed |
| medium: prompts do not say reviewed content is data | fixed: sentence added to auditor and skeptic prompts |
| low: docs overstate "enforces"; client-side gate | fixed: Enforcement section rewritten; `--no-verify` named; human reviewer named as the real control |
| low: tracked hooks directory executes on push | accepted with mitigation: documented; reviewers treat `.githooks/`, `scripts/review-hash.sh`, `review-gate.yml` like auth code |
| medium: CLAUDE.md claims donor pages read donor_summary | fixed: reworded to what the code does today |
| low: data prompt omits schema gotchas | fixed: campaigns table, view column freeze, immutable migrations added to the data focus |
| medium: hook never checks verdict or mode | fixed: record must say `"approved": true` and `"mode": "code"`; tested |
| medium: tags and detached HEAD always refused | fixed: same change as the high; tags tested |
| medium: no place for code-mode responses | fixed: `.reviews/<branch>/code-round-<n>-responses.md` (this file); prompt points at it |
| low: missing tree reported as drift | fixed: distinct message; tested |
| low: branches without `.githooks/` are ungated | fixed in docs: merge dev first; plus the Actions check covers PRs regardless |
| low: merge/rebase after approval forces a new round, not called out | fixed in skill and hook message |
| low: auditors share one scratchpad | fixed: prompts ask for a per-role subdirectory |
| low: message and default inconsistencies | fixed: not-approved message names the cause; `ticketPath` is required |
| medium: tag pushes refused | fixed (see above) |
| medium: bootstrap branch skipped its own ticket gate | accepted: the ticket was written after the code existed, so a ticket audit would review a plan already implemented; stated in the PR body |
| medium: no regression test for hook/hash | fixed: `tests/review_gate.sh`, `make test-gate`, 14 cases |
| medium: PR template inert until on main | fixed in docs: noted; the Actions check runs from the PR branch and does not depend on main |
| medium: "GitHub cannot enforce it" inaccurate | fixed: `.github/workflows/review-gate.yml` repeats the check on every PR into dev/main; risks text corrected |
| low: per-auditor approve flag unused | fixed in docs: informational; gate is confirmed criticals |
