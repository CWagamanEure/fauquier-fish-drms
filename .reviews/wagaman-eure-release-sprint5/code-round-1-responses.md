# Responses to round 1 (code)

Round 1 approved: no critical or high findings, 5 medium, 9 low. No tracked file changed after the review.

- Applied-on-production claim, SQL and evidence live outside the branch (security, data, correctness,
  verification; medium): addressed in the pull request, which carries the ported SQL, the read-only
  schema-check output and a line for the owner's confirmation. The server check was re-run at
  2026-11-16 02:09 UTC, before the push, with the same result.
- Sprint 5 pages not run on MySQL 8; web PHP zip unchecked (verification, data; medium/low): accepted.
  Not run. The owner checks the pages on the live site after the release (checklist in the pull request).
- Backup removal date (security; low): the pull request checklist gives 2026-11-23.
- Server details in the public ticket (security; low): accepted; the ticket is a review record and is
  not edited. The pull request gives no host, account, database name or path.
- schema_migrations out of step (data; low): accepted; the header names this file as the record.
- Comment says "the pull request" without a number (correctness; low): accepted; a change would need
  another round. The pull request is the one that adds those lines.
- Procedure differed from docs/deploy-siteground.md (correctness, verification; low): disclosed in the
  ticket and the pull request; updating the doc is a follow-up.
- ticket.md changed after the ticket approval (verification; low): the change was the appended
  "Responses to round 1" section only.
- Teammate steps (verification; low): none needed; said in the pull request.
