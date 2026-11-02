# Responses to round 3

Record: `code-round-3.json` (local; approved, 5 confirmed high, 10 medium, 6 low).

| Finding | Response |
|---|---|
| high: round-1 record with pointers to a live legacy vulnerability still in branch history | fixed: branch squashed to one commit built from the current tree before the first push; no commit on the branch lists a `*-round-*.json` file (verified with `git log --name-only`); the round-2 response row is corrected to say it was removed from history |
| high: branch-protection.json names a context GitHub never reports | fixed: context is the job name `record`; the protection applied to dev and main on 2026-10-15 uses exactly that (verified via the API); docs updated |
| medium: ticket.md still says a skipped hook "cannot merge" | fixed: ticket risks reworded; the round-2 response was wrong to claim it had been |
| medium: `scripts/review-check.sh` missing from the reviewer-attention and test-gate lists | fixed in process doc and skill |
| low: review-gate.yml lacks permissions block and persist-credentials: false | fixed |
| low: `.inc` files served at the web root | fixed: `inc` added to the denied extensions; verified 403 after rebuild |
| medium: CLAUDE.md "queries use prepared statements" overstates legacy code | fixed: stated as a requirement with the legacy warning |
| low: donor_summary "belongs in" wording | fixed: read through the view, write to the tables |
| low: data prompt omits non_cash_distributions and the cascade | fixed |
| medium/low: strict: true cost not stated | fixed: strict is off, and the reason is in the Enforcement section |
| low: re-run steps do not recompute reviewed_commit/tree; PR template does not ask the reviewer to check them | fixed in skill step 4 and the PR template |
| medium: ticket mode committed the full record, contradicting the stub policy | fixed: ticket-approved.json is a stub too |
| follow-up: parameterise the legacy DELETE helpers named in the round-1 record | tracked separately; out of scope here and deliberately not pointed at |
