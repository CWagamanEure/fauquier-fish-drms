# Responses to code round 2 (adu-sprint4-comms-hours)

All four auditors approved (0 critical, 0 high). Reviewed commit c75aadb. Changes in the next commit:

- **Medium (verification) / low (correctness): a name left in the hidden "Someone else" box was saved after
  switching back to "Not recorded".** Fixed by reverting the round-1 fallback: the select decides. With
  "Not recorded" or a staff member picked, `person_other` is cleared and nothing typed is stored. Test:
  post `person_contacted` '' and a staff id, each with a leftover name; neither stores it. Browser walk:
  pick Someone else, type a name, switch back to Not recorded, save -> `person_contacted` NULL. Trade-off,
  accepted: in a browser without CSS `:has()` (the box is then always visible) a name typed without
  picking "Someone else" is not stored; all current major browsers support `:has()`.
- **Low (correctness, verification): half-typed time x never shown.** Confirmed and fixed: `sync()` also
  runs on `keyup` and `blur`. Browser walk: typing only "09" into Start shows the x (value '', badInput
  true); clicking it clears the field and hides the x.
- **Low (security): campaign names shown to viewers without campaigns view.** Accepted: the same open
  campaign names are already shown to those roles on the hours and donation forms, and the dropdown is
  disabled for non-savers.
- **Low (correctness, verification): ticket text stale.** Fixed: the round-1 follow-up response is marked
  superseded with the current rule, and the Insert bullet notes today's contacts get the current time.

Re-run after the fixes: communications 9/9; the full browser walk 19/19.
