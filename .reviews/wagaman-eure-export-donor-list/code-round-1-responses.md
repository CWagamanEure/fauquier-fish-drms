# Responses to code review round 1 (wagaman-eure-export-donor-list)

Round 1: approved, 0 critical, 0 high, 0 medium, 4 low, at 50a7053. All four accepted; none changes behaviour, and
fixing them here would change the reviewed tree.

- **Two blank lines before `donor_list` (low):** accepted; cosmetic. To be fixed in the next change to
  `tests/exports_integration.py` (UF2-135).
- **AC10 checked by counts only (low):** accepted. The counts change exactly as the donor-list row predicts for each
  role; a per-row check is a possible later improvement to the page test.
- **README and class docstring still say 1901-only gifts (low):** accepted; documentation only. To be fixed with
  UF2-135, which edits the same paragraph and docstring.
- **Manual check not run (low):** accepted; stated in the commit and the PR. `active_ids` covers the row set
  against `donor_summary`, the same rows donor.php counts.
