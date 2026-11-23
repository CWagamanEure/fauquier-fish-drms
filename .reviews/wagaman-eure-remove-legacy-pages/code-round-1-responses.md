# UF2-158 code review, round 1: responses

Approved: 4 of 4 auditors, 0 critical, 0 high, 0 medium, 3 low (two are the same finding).

1. **viewProfile test's `dashboard.php` check can pass because of the header** (correctness and verification,
   low). Accepted. Follow-up: assert the button target (`window.location.href='dashboard.php'`) in part 2.
   Not changed here, because a change after review would need another review round.
2. **Three images now have no references** (`images/search.svg`, `images/magnifying-glass.svg`, the
   dollar-sign PNG) (correctness, low). Accepted. They go on the part 2 follow-up list, together with the
   CSS files that were already unused (`management_base.css`, `normal_base.css`, `person.css`, `roster.css`).
