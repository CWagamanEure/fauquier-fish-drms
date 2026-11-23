# Responses to round 1 (code)

Round 1 approved (all four auditors, no criticals, no highs; 1 medium, 6 low). Fixed before pushing, so round 2 reviews the fixes.

- **Test window shared with the exports suite** (verification medium, correctness low): fixed. The suite now uses 1902
  (exports uses 1901), and setUpClass fails with a clear message when 1902 already holds donations or expenses.
  The docstring and the README paragraph are updated.
- **Expense fund changes silently when a campaign is deleted or re-designated** (data low): fixed in part.
  `fk_expenses_campaign` is now `ON DELETE RESTRICT`, like `donations.eventID`, so a campaign with expenses cannot
  be deleted. The only delete path is the legacy dbEvents.php, which already fails on a campaign that has gifts.
  Re-designation moving past rows is accepted (fund is read at display time, same as donations) and is documented
  in sql/README.md.
- **'General Fund' duplicated / case variants** (correctness low): fixed. The SQL fragment is built from
  `TREASURER_GENERAL_FUND`, and `treasurer_funds()` merges labels that differ only in case (checked by hand with a
  'general FUND' campaign).
- **Log dates without a year** (verification low): fixed. When the range spans years, log dates show 'M j, Y'
  (new test `test_log_dates_carry_the_year_across_years`).
- **No log limit or pagination** (correctness low): accepted. The default range is one month, and the cards and
  chart are SQL aggregates. A cap or paging can come with the CSV ticket.
- **make migrate in the PR body** (verification low): done in the PR description.

Re-run after the fixes: treasurer (11/11), exports, donations and campaigns suites OK on the scratch stack;
migration 019 re-applied and the whole series replayed twice; php -l clean.
