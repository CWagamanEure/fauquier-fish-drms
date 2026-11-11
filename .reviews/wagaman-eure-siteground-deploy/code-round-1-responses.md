# Responses to round 1 (code)

Round 1 approved (19 findings, 0 critical, 3 high that are one issue). Changes below were made after it,
so a second round reviews them.

- high (data, correctness, verification): `make test-deploy` ran the strict schema guard on the real tree, so a migration PR would fail the required check — fixed. The test now runs the guard on the real tree with `--warn` (exit 0); strict behaviour is still covered on fixtures and enforced in the deploy job.
- medium (security, correctness): re-running only the deploy job skips the enable switch and tip-of-main checks — fixed. The deploy job repeats both checks as its first step after checkout when the mode is real, before any secret is read. Documented.
- medium (verification): no evidence in the branch for the live verification items — accepted; the evidence (rsync version, dry-run list, probe output, what was and was not run) goes in the PR description, not in a tracked file.
- low (security): probes do not exercise the root deny rules for .py, .inc, Dockerfile — fixed. Three probes added that must answer exactly 403; checked against the live site.
- low (security): a failed post-deploy probe leaves the exposure live and the doc said the deploy stops — fixed in the doc (what a red after-probe means and what to do).
- low (security, verification): ticket amended after its round 3 approval — accepted. Stated in the ticket and in the PR description; the amendments were reviewed at the code gate only.
- low (security): pre-existing forced error display in application code — accepted, out of scope; follow-up for a separate ticket.
- low (data): guard is filename-only — accepted. The doc says the guard trusts the list; a filename plus checksum list is a follow-up.
- low (data): baseline claim that production matches 001-014 cannot be checked from the repository — accepted. Method (read-only schema queries over SSH on 2026-11-11: tables, views, triggers and the columns added by 008-014) is stated in the PR description.
- low (correctness): tripwire did not match deletion of a runtime directory itself — fixed. Regex matches the directory with or without a trailing path, and the filter protects the directories themselves; test added for an empty server-only directory.
- low (correctness): tip-of-main check depends on anonymous `git ls-remote` — accepted; comment added in the workflow. If the repository goes private the check fails closed (empty tip, no deploy).
- low (correctness): CR stripping relied on GNU/busybox sed — fixed with `tr -d '\r'`.
- low (verification): fixture covered only part of the hide list — fixed. The test reads every `H` rule from the real filter and asserts each path is tracked in the fixture and not uploaded (`.git` and `.env` excepted: neither can be in an archive of tracked files).
- low (verification): removing tracked samples leaves server copies and breaks the probe — fixed in the doc.
- low (verification): `docs/process/change-review-process.md` check list not updated — accepted, out of scope for this branch; follow-up.

Checks re-run after these changes: `make test-deploy-docker` (rsync 3.4.3) 98 passed, 0 failed; `make test-gate` 18 passed; `bash -n` on the four shell files; YAML parse of both workflows; `scripts/deploy-probe.sh` against the live site, all passed; `scripts/deploy-schema-guard.sh` passed; dry run against SiteGround (see PR). Not run: shellcheck, actionlint (not installed); the workflow itself.
