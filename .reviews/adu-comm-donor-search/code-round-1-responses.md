# Responses to code round 1 (all four approved, no criticals or highs)

- **Server-rendered card instead of JSON** (security low, verification low): deliberate change from the ticket.
  The card and hidden input are printed with `h()` from the validated donor, so no inline script/JSON is
  needed and the preselect works without JavaScript. Tests assert the hidden input value and card text, with
  the XSS name HTML-escaped. The ticket's mentions of `CHOSEN_DONOR` JSON are superseded by this note.
- **AC1 has no committed browser test** (verification medium): not fixed in this branch; the repo has no JS
  test tooling. The headless Chrome walk (search by name and by email, 1-letter hint, Enter does not submit,
  choose fills donor_id and card, Change clears and refocuses, error re-render keeps the card, save lands on
  the donor's Communications tab, ?donor_id preselect, no JS exceptions) passed 11/11 and is listed in the PR.
- **Label points at hidden/missing control** (correctness low), **README wrap** (verification low), **shared
  search module** (correctness low): follow-ups, not changed here, to keep the approved tree.
