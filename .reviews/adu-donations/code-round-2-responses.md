# Responses to round 2 (code)

Round 2 approved (4/4 auditors, 0 critical, 0 high, 0 medium, 4 low) on commit 84b336c. No code change
after it; the three items below are accepted, and the ticket text was corrected (`.reviews/` is outside the
content hash).

- (low, correctness + verification) "Save & add donation" profile fallback not exercised by a POST —
  accepted, and a correction: the round-1 response said "Tested", but the suite only asserts that the button
  is not offered to an account without the donation-write permission; the fallback redirect itself was
  checked by reading `addDonor.php` (the `save_add` branch requires `$canAddGift`, otherwise the existing
  profile redirect runs). Follow-up: add the POST case to `tests/donations_integration.py`.
- (low, verification) Paging check sees a single-page pager — accepted: the suite proves `pageUrl()` keeps
  kind, q, range and sort, and the count and row queries share one WHERE clause (`donations_list_sql()`); a
  multi-page fixture is a follow-up.
- (low, verification) Ticket contradicted itself about the edit-mode fieldset — fixed in `ticket.md`.
