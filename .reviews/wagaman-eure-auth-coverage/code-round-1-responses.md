# Code review round 1 responses (commit 15c312e)

Approved by all four auditors; no critical, high or medium findings. Lows recorded, none fixed on this branch (follow-ups):

- (low) POST-form regex only matches a quoted method attribute
- (low) Drift test does not scan domain/, which Apache also serves
- (low) KNOWN_UNGUARDED keeps legacy pages that read session state and records without any guard (pre-existing, out of scope)
- (low) README replay loop does not stop on the first failing migration
- (low) POST-form CSRF check is per-file and misses unquoted method attributes
- (low) Comment on scanned directories omits domain/
- (low) Ticket Scope/Approach sections still describe the superseded design
- (low) Ticket's acceptance criteria and Out sections were not updated to match its round-1 responses
- (low) CSRF additions on the five pages are not exercised by a test and the pages have no POST handler yet
