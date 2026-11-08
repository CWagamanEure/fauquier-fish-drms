# Responses to round 2

Round 2 approved: four auditors reported, no critical or high findings; three lows.

- itemValues.php has no data_import permission gate (low, carried over): accepted; front end only, the backend handler must add the gate and check the CSRF token.
- Ticket not updated for permission-gated cards (low): fixed in ticket.md (Scope and Acceptance criteria).
- No automated test for dashboard permission gating (low): accepted; included in the suggested follow-up test ticket (dashboard totals, gating, last-admin pop-up markup).
