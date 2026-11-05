# Responses to code round 3 (adu-sprint4-comms-hours)

All four auditors approved (0 critical, 0 high, 0 medium). Reviewed commit 0c4add9.

- **Low (data, correctness, verification): the leftover-name test asserts only that "Left Behind" is not
  stored.** Accepted for this PR: the exact (None, None) pair for "Not recorded" and the (staff name, staff
  id) pair for a staff pick are asserted elsewhere in the same suite (`test_save_someone_else_and_nothing_optional`
  and `test_save_with_staff_person_and_campaign`); tightening the loop is listed as a follow-up in the PR.
- **Low (verification): ticket sentence about the Someone else box.** Fixed in ticket.md (the box is shown
  by CSS `:has()`; the trade-off is written there). `.reviews/` is outside the review hash.
