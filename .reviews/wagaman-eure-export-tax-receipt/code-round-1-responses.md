# Code review round 1: responses

Round 1: approved, 0 critical, 0 high, 0 medium, 5 low. All accepted; no code change (a change would need a new round).

- **Item list ambiguous with `; ` in a description (low):** accepted. The format is the ticket's; the Non-cash gifts
  count gives the true number.
- **Duplicated permission body and `$yesNo` (low):** accepted. One named helper per export, as in the other cases.
- **Name-then-id tie-break and grouping untested (low):** accepted. Covered by reading the code (`ORDER BY
  s.display_name, s.id`); a follow-up test can add two donors with the same name.
- **Page buttons checked by counts, not on the tax row (low):** accepted, as in the earlier exports' tests.
- **Manual demo-data check not run (low):** accepted and disclosed in the commit and the PR.
