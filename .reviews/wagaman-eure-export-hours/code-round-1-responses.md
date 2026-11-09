# Responses to round 1 (code)

Round 1 reviewed d46484c and approved it: 0 critical, 0 high, 0 medium, 4 low. All four are accepted. No code changed after this approval, so no second round was run.

The ticket's round 2 notes were handled in this code: teardown deletes hours first, clean returns its own `left_hours` count, and the export_query docblock and the test docstring are updated.

- **Third copy of the inline 403 refusal block (low):** accepted. Follow-up: add an `export_forbidden()` helper with the next export ticket.
- **Posted from/to/year being ignored is not tested (low):** accepted. The hours case never reads those fields. Follow-up test.
- **Inactive donors' entries are not tested (low):** accepted. The `donor_summary` view has no `is_active` filter (011). Not tested; the PR says so.
- **Most of the verification plan is unrun (low):** already done before the review ran: `php -l`, `make seed-demo`, test-exports (twice), test-auth, test-donations, test-donors, test-hours and test-gate all passed on d46484c. Not done: the manual check of a volunteer's rows against their Volunteer tab.
