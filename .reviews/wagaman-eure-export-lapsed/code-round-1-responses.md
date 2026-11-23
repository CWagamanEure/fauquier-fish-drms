# UF2-139 code review, round 1: responses

Approved: 0 critical, 0 high, 0 medium, 6 low. All accepted without code changes, because any change would alter the
reviewed tree and need another round.

- **Stale dataImports.php header comment ("other rows ... Coming soon") (low, reported twice):** accepted. Follow-up:
  reword it to "a row missing from $exportReady shows Coming soon" next time the file is touched.
- **Test class docstring missing a comma (low):** accepted; cosmetic, same follow-up.
- **Page test checks counts, not the lapsed row's own button (low):** accepted; the mapping is one line and the
  permission tests cover the helper. Same gap as the earlier exports.
- **`failed` redirect not tested for this export (low):** accepted; it comes from the shared export_query() helper.
- **Manual cross-check not shown as run (low):** correct, it was not run. The PR says so. test-auth, test-donors,
  test-donations and test-gate were run by the author before the review (see the implementation commit message).
