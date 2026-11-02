# Responses to round 5

Record: `code-round-5.json` (local; approved, 0 critical, 0 high, 0 medium, 4 low).

| Finding | Response |
|---|---|
| low: Fork never-exempt rule lives only in the workflow YAML, so make test-gate cannot exercise it | accepted; noted for a follow-up |
| low: Amending or squashing after approval orphans the record's reviewed_commit, so the PR-template reviewer check cannot be run | accepted; noted for a follow-up |
| low: The fork exemption rule is the one piece of gate logic that make test-gate cannot exercise, although the docs say to run test-gate after changing .github/workflows/ | accepted; noted for a follow-up |
| low: README summary still calls human review a 'real control' without the not-enforced caveat added to the process doc | accepted; noted for a follow-up |
