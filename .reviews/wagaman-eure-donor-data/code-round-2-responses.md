# Responses to round 2

Record: `code-round-2.json` (local; approved, 0 critical, 0 high, 1 medium, 7 low).

| Finding | Response |
|---|---|
| medium: `requestSubmit()` without a submitter still drops the filter | fixed: the sort change clicks the hidden default filter button, so the current filter is submitted |
| low: `updateDonor.php` read outside the fallback | fixed: a read fault redirects to the list, which shows the generic notice |
| low: `fish_can()` runs outside any catch | accepted: with the database down `auth_current_user()` is null and `fish_can()` returns false without a query (verified by the auditors) |
| low: `mysqli_connect()` warning precedes the notice while `display_errors` is on | accepted: pre-existing container setting, declared a follow-up in the ticket |
| other lows | accepted; recorded locally in the round record |

## Correction (added after round 3, 2026-10-15)

Round 3 pointed out that this file was written before commit d50d214 and undercounts the record. Corrected facts, left here rather than rewritten so the history stays visible:

- The record `code-round-2.json` holds 2 medium and 10 low findings (12), not 1 medium and 7 low.
- `fish_can()` outside any catch: **fixed in d50d214**, not accepted. It catches `mysqli_sql_exception`, logs, and caches `false`. The cache key now includes the user id, which also fixes the per-user cache low.
- "Other lows": two were fixed in d50d214 (the profile keeps a loaded row and clears the tab arrays on a read fault; the dead preferred-contact fallback and `?? null` were removed). Accepted and unchanged: the undated-gift assertion depends on the baseline dump, the README rebuild sentence, the duplicated normalisation in donor.php, the double `page` assignment in `pageUrl()`.
