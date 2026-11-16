# Responses to round 3

Round 3 approved: four auditors, no critical or high findings; three mediums and three lows. No code
changed after round 3.

- Recovery UPDATE assignment order gives 'saving' rows the 'ready' message (medium, data): disputed. The
  auditor reasoned from documentation without running it. The author's run (a batch claimed 20 minutes ago
  with one 'ready' and one 'saving' row) and the verification auditor's run on MariaDB 10.11 both show the
  'saving' row gets "The import stopped while this row was being saved. Check whether the record exists..."
  and the 'ready' row the "Fix it" text. Follow-up: split it into two UPDATEs anyway so it never depends on
  evaluation order.
- Preview-stage post-check can overwrite a row Import already claimed (medium, correctness): accepted for
  now. It needs Import anyway in one tab and Import in another at the same moment on the same file. Follow-up:
  make the downgrade conditional (WHERE status = 'ready').
- A failure after the record is created is reported as "try again" / can leave a row 'saving' under an
  imported batch (medium, correctness): accepted for now. It needs the database connection to drop between
  creating a record and marking its row. Follow-up: a distinct "record may exist" state and recovery of
  'saving' rows in imported batches.
- 10-minute recovery window vs set_time_limit not counting database wait (low, security and data):
  accepted; the per-row claim (ready -> saving) already stops a recovered row being saved twice. Follow-up:
  a heartbeat timestamp.
- README lacks the recovery sentence and a post-merge checklist (low, verification): accepted; listed in the
  PR (rebuild the Docker image for zip, run migration 018, XAMPP extension=zip).
