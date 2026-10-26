# Responses to round 1

Round 1: all four auditors approved, no critical findings. Fixes below were made before round 2.

- correctness/verification (high): Foundation option selectable but never saves: fixed, the option is disabled and labelled "Foundation (coming soon)"; persistence stays in a follow-up ticket.
- correctness/verification (high): contact-person rows dropped without warning: fixed, the block shows a visible "not saved yet" note telling staff to use the Contact fields; persistence stays in a follow-up ticket.
- correctness (medium) / verification (low): unused CSS (.click-row, donations radio filter, duplicate .pill): fixed, removed. .status.new and .status.lapsed are kept because other pages' badges may still use them.
- verification (medium): no test for the 12/24/36-month rule or the Lapsed filter: fixed, added test_giving_status_boundaries_and_lapsed_filter (11, 12, 13, 24, 25, 36, 37 months and no gift; checks the view, the list badges and filter=lapsed).
- verification (medium): "Inactive" means two things (giving status vs deactivated record): accepted because the author chose these labels for the list; the list tooltip now says "No dated gift in the last 36 months" and the ticket records the risk for the product owner.
- correctness/verification (low): 12-month boundary moved by one day versus 008: accepted; the rule is now written into the acceptance criteria and tested.
- correctness (low): donors with no gifts count as Lapsed next to "New this month": accepted, matches the author's rule (never gave = inactive); recorded in the ticket's risks.
- data (low): undated gifts read Inactive: fixed in wording, tooltip says "No dated gift"; counting undated gifts differently would be a later migration.
- data (low): Foundation follow-up must widen donor_summary.display_name: noted in the ticket's Out section for the follow-up.
- verification (low): stale "new" comment on donor_create(): fixed.
- verification (low): post-merge step: the ticket and PR say to run make migrate after pulling.
- CodeRabbit (minor): contact labels not tied to inputs: fixed, unique ids and for attributes, renumbered on clone.
