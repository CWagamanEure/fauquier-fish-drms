# Responses to round 2

Round 2 approved: four auditors, no critical or high findings; three mediums and four lows. Fixes were
folded into the imports commit.

- Interrupted Import strands the batch and its rows (medium, data + correctness; low from security): fixed.
  Each row's save runs in its own try/catch (any Throwable), so one row's database error marks that row
  'error' and the rest of the file continues; the batch is set 'imported' in a finally block. Import
  records when it was claimed (imported_at); a batch still 'importing' 10 minutes after that (crash, lost
  connection, the 5-minute time limit) is closed by import_recover_stale() when Data & Imports is opened,
  and its leftover 'ready' / 'saving' rows go to Needs review with a message (a 'saving' row says to check
  whether the record already exists). Checked: a batch claimed 20 minutes ago with one 'ready' and one
  'saving' row was closed and both rows moved to review; a batch claimed just now was left alone.
- Forcing an in-file duplicate turned the original row into a duplicate (medium, correctness): fixed.
  import_batch_keys() leaves out rows marked "Import anyway". Checked: two identical gifts, Import anyway on
  the second, Import: both gifts created.
- Cancel / preview-stage Fix and Force could race with Import (low, security): fixed. Cancel is a
  conditional UPDATE (status must still be 'checked'); a preview-stage change that finds the batch already
  claimed sends the row to review instead of leaving it 'ready'.
- dup_key cut to 512 characters (low, data): fixed. Keys are stored and compared as SHA-256 hashes of the
  full key.
- Duplicates of a row that is later skipped are not re-checked (low, correctness): accepted; such a row
  shows "Same as row N" in Needs review and Fix (save without changes) re-checks it against the remaining
  rows. A bulk re-check can come with the follow-up work.
- Recovery rows left 'saving' never shown (low, security): covered by the recovery above.
- No automated imports suite (low, verification): accepted; follow-up ticket proposed in the PR.

Re-verified on a rebuilt database: all suites as before (auth: the 6 Apache-only direct-file failures;
outreach: its Apache-only photo URL check), the full browser walkthrough of all five types and every row
action, the size/coordinate refusals, the anonymous-gift rule and three simultaneous Import POSTs (one gift).
