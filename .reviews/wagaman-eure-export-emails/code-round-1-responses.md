# Responses to round 1

Round 1: approved, 0 critical, 0 high, 0 medium, 6 low. No code changed after this round.

- **Viewers can download every email at once (low, security):** accepted, as in the ticket's round 1 responses. The
  same addresses are visible under donors view one profile at a time; the README states the rule. A separate export
  permission or an export log is a possible follow-up.
- **Unicode spaces other than U+00A0 can give a blank-looking cell (low, data):** accepted. Only legacy or imported data
  could hold such a value; the code already trims more than the ticket asked for.
- **Email trimming differs from the ticket text (low x2, correctness and verification):** noted. The code trims ASCII
  whitespace and the no-break space (falling back to `trim()` for invalid UTF-8) and skips a row that is blank after
  trimming; the `Nbsp` fixture covers it. The PR description says so. The approved ticket is not edited.
- **No GET-405 test for the emails export (low, verification):** accepted. The method check runs before the export switch
  for every export and is tested by `test_method_and_token` and `test_hours_method_and_token`.
- **Manual check against an organization's profile not run (low, verification):** accepted; the commit message and PR
  say it was not run.
