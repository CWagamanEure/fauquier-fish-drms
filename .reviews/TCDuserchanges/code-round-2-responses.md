# Responses to round 2

Round 2: all four auditors approved, no critical or high findings.

- correctness (low): `.seg input:disabled + label` faded the selected type on read-only forms: fixed, the selector is now `#typeFdn[disabled] + label`.
- correctness / verification (low): Foundation-only CSS cannot apply while the option is disabled: kept for the Foundation persistence ticket, with a comment saying so.
- verification (low): .status.new / .status.lapsed are dead and the round-1 reason was wrong: fixed, both rules removed.
- verification (low): test logs vs stated environment: fixed in the ticket, which now says a local shim served the Makefile's docker compose exec calls; CI is the authoritative run.
- correctness (low): contact inputs still accept typing that is not saved: accepted for now; the visible note says contacts are not saved, and whether to hide the block until the persistence ticket is left to the author.
- verification (low): label text, tooltip, disabled Foundation and contacts note are untested: accepted; the status class is tested at every boundary, and the label and tooltip come from fixed arrays keyed by that class.
