## What and why

<!-- Jira keys and one paragraph. -->

## Review swarm (required, see docs/process/change-review-process.md)

- [ ] Ticket draft approved: `.reviews/<branch>/ticket-approved.json` (rounds: )
- [ ] Code approved before push: `.reviews/<branch>/code-approved.json` (rounds: )
- [ ] Reviewer: `scripts/review-hash.sh <reviewed_commit from the record>` equals the record's `tree`, and the PR diff is that commit
- [ ] High findings accepted instead of fixed, with reasons:
- [ ] `REVIEW_SWARM_SKIP` was **not** used (if it was, say why here)

## Checks run

- [ ] `make test-auth`
- [ ] Verification plan from the ticket draft
- [ ] Teammates must run `make dev` after merging: yes / no
