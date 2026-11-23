# Code review round 1: responses

Round 1: approved, 0 critical, 0 high, 0 medium, 4 low. All four accepted without a code change (the reviewed tree
is the pushed tree):

- **Profile tiles show lifetime cash to every signed-in user (low, pre-existing):** accepted, out of scope (ticket
  Out). To be raised with the separate ticket about donor.php and the profile having no donors-view check.
- **403 block copied in three cases (low):** accepted; kept consistent with the other cases, as in UF2-136. A
  helper can come with UF2-137 or UF2-139.
- **Per-donor teardown deletes partly redundant (low):** accepted; kept as defensive cleanup.
- **Manual check against profile tiles not run (low):** accepted; not run, and the PR description says so. Both the
  export and the tiles read `donor_summary`.
