# Responses to round 2 (code)

Round 2 approved (all four auditors, no critical, high or medium; 1 low). All round-1 findings were confirmed resolved.

- **Interrupted run leaves 1902 rows that block later runs** (verification low): accepted. It only affects a local
  database after a killed run (CI starts fresh), and the setUpClass message says what is wrong. Manual cleanup:
  delete 1902 expenses, then 1902 donations, then the `dbevents`/`donors` rows whose description/notes are
  'treasurer suite'. An automatic cleanup can come later.
