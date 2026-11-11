# Responses to round 2 (code)

Round 2 approved (13 findings: 1 medium, 12 low, 0 critical, 0 high). No code was changed after it, so
the approved tree is the pushed tree. All findings are accepted without a code change:

- medium (verification): live verification evidence is not readable in the branch — accepted; it is in the PR description.
- low (security): the enable-switch re-check on a partial re-run relies on GitHub supplying the current variable value, not observed — accepted; to be confirmed once in Actions after the first enabled run. Removing the key in SiteGround is the certain stop.
- low (security): the environment branch rule, not the workflow checks, is what protects the key — accepted; setup step 1 requires and confirms it. Doc wording is a follow-up.
- low (security): residual limit of the new probes — accepted.
- low (data, correctness, verification): the real-tree guard assertion in warning mode cannot fail — accepted; strict behaviour is tested on fixtures and CI shows the warning step.
- low (correctness): the empty-directory test does not exercise the tripwire change — accepted; follow-up.
- low (correctness): enable and tip checks exist in two copies — accepted; the gate copy skips quietly, the deploy copy fails loudly, on purpose.
- low (correctness, verification): the re-check step runs before the test step in real runs, a small departure from the ticket's step order — accepted; it uses no secret.
- low (verification): the doc's list of what the server `.htaccess` must deny is narrower than the probes — accepted; follow-up.
- low (verification): process doc check list stale — accepted follow-up (same as round 1).
