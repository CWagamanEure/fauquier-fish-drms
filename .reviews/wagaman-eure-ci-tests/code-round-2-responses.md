# Code review round 2 responses (commit 9fb7da6)

Approved by all four auditors, no critical or high findings. Nine findings: 3 medium, 6 low. None fixed in
this branch, because any edit changes the reviewed tree and would need a round 3; all are recorded here and
in the PR description as follow-ups.

## Medium

- After-merging item 1 and the ci.yml header say `tests` is already required. Accepted as a wording gap:
  the admin applies `docs/process/branch-protection.json` after the first green run, and from that moment
  the text is correct. Fix the wording in the PROTECTED_PAGES follow-up PR.
- Green `tests` does not cover the 14 FISH NET pages merged from PRs #10, #19 and #21, and the follow-up
  has no Jira key. Accepted and out of scope (the ticket's scope excludes test content changes). A Jira
  ticket is to be created for adding those pages to PROTECTED_PAGES / PROTECTED_APIS.

## Low

- Migration-edit guard ignores typechanges (`--diff-filter=MDR` misses `T`). Hygiene guard only; add `T`
  in the follow-up PR.
- Replaying migrations after seed-demo changes donor 999's donor_since on the CI database. Harmless on a
  throwaway volume; the donor tests do not read it.
- Migration guard does not catch a numbering collision between two open PRs. `scripts/db-migrate.sh`
  rejects it on an existing database; out of scope.
- Idempotency replay is CI-only; sql/README still prescribes running `make migrate` twice. Update
  sql/README in the follow-up PR.
- Ticket verification record still describes the superseded re-run check. Historical record, left as is.
