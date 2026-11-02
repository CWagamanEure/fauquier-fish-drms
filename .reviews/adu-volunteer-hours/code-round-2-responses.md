# Responses to round 2 (code)

Round 2 reviewed commit ce64147, tree `f83fc70a…`, and approved it. All four auditors approved, and there
was no critical, high or medium finding; the security auditor reported nothing. The four lows are
accepted without a code change, so the approved tree stays the one that was reviewed:

- **On a load fault, hours.php's read-only notice blames the account** (correctness low): accepted.
  - It only happens during a database fault.
  - The page already shows the "Could not load everything this page needs" alert above it, and nothing
    can be saved in that state.
  - Follow-up: pick the notice in the order anonymous, then load fault, then account.
- **A partial edit trims stored whitespace without a history clause** (data low): accepted as intended
  normalisation. The ticket's normalisation table says text is trimmed. Only imported or hand-written rows
  can carry the whitespace, and the trimmed values mean the same thing.
- **Usual role groups by the raw job** (verification low): accepted.
  - The only difference is surrounding whitespace in imported rows, which this code never writes.
  - Follow-up: `GROUP BY TRIM(job)`.
- **The reset fixture covers two of the edited fixtures** (verification low): accepted, and the claim is
  narrowed here. The reset restores the legacy and timed entries, the only fixtures that later tests read.
  `on_inactive` and `big` are edited only by `test_edit_entry`, and no test reads them afterwards.
