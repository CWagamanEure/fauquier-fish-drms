# Responses to round 4

Record: `code-round-4.json` (local; approved, 0 critical, 0 high, 3 medium, 6 low).

| Finding | Response |
|---|---|
| medium: Actions check exempts a fork PR whose branch is named dev or main | fixed: the workflow refuses the exemption for pull requests from forks; documented |
| medium: branch protection requires zero approving reviews while docs call human review the real control | fixed in docs: stated as expected, not enforced, with the one-line change to enforce it; raising the count is Cory's call because it stops people merging their own PRs |
| low: stale status-check name in the workflow and hook comments | fixed |
| low: the two open PRs into dev are blocked until this lands | accepted: that is the process starting; the "Branches that predate the process" section tells their authors what to do |
| other lows (hardening and efficiency notes) | accepted; recorded locally in the round record |
