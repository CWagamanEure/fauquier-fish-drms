# Responses to round 1 (code)

Round 1 approved: 0 critical, 0 high, 1 medium, 4 low. Nothing was changed after approval, so the reviewed tree is the pushed tree. Each finding is accepted for this PR; the follow-ups are listed in the PR description.

- **Commit record does not say whether the manual check was done (medium):** accepted. The manual spreadsheet check in the verification plan was not done. The PR description says so, and the reviewer is asked to do it.
- **Numeric exemption regex also matches a value ending in a newline (low):** accepted. A trailing newline cannot turn a number into a formula, so this is not an injection path. Follow-up: use `\z`.
- **Stale rebuild and re-login sentence now follows the new export text in README (low):** accepted. Follow-up: move or delete the old sentence.
- **The DB-failure path (export_error=failed) has no test (low):** accepted. The catch runs before any header is sent, and the code reviewers read it. Forcing a mysqli failure from the integration suite needs a test hook that this ticket does not add.
- **sql/README.md and the Manage Users label still suggest 'Data imports' covers exports (low):** accepted. Follow-up docs note; round 2 of the ticket review accepted the design.
