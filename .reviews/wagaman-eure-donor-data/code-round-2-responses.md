# Responses to round 2

Record: `code-round-2.json` (local; approved, 0 critical, 0 high, 1 medium, 7 low).

| Finding | Response |
|---|---|
| medium: `requestSubmit()` without a submitter still drops the filter | fixed: the sort change clicks the hidden default filter button, so the current filter is submitted |
| low: `updateDonor.php` read outside the fallback | fixed: a read fault redirects to the list, which shows the generic notice |
| low: `fish_can()` runs outside any catch | accepted: with the database down `auth_current_user()` is null and `fish_can()` returns false without a query (verified by the auditors) |
| low: `mysqli_connect()` warning precedes the notice while `display_errors` is on | accepted: pre-existing container setting, declared a follow-up in the ticket |
| other lows | accepted; recorded locally in the round record |
