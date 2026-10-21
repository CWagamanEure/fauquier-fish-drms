# Responses to round 1 (code)

Round 1 approved (4/4 auditors, 0 critical; 0 high, 3 medium, 10 low). Fixed in the next commit unless marked
accepted. Re-run after the fixes: `donations_integration` 12/12, `donors_integration`, `users_integration`
and `auth_integration` pass on the local MariaDB 10.4 + `php -S` stack, except `auth_integration`'s
direct-file-URL checks, which need Apache's deny rules (`php -S` has none; unchanged from before this branch).
Headless Chrome pass: 14/14, no JavaScript errors.

Security
- No findings.

Data and schema
- (low) Non-cash quantity vs distributed check is not atomic — accepted: nothing writes
  `non_cash_distributions` yet (UF2-18 is out of scope); the conditional UPDATE belongs with the change that
  adds a distribution writer.
- (low) Invalid `signed` value silently cleared — fixed: now a validation error ("Choose whether the check
  is signed."), tested.

Correctness and architecture
- (medium) Enter in the donor search submits the form — fixed: Enter runs the search immediately and never
  submits; checked with real key events in headless Chrome.
- (low) Edit mode differs from the ticket text — fixed: unused `data-fixed` attribute removed; ticket updated
  to describe what is built (single checked radio, other fieldset `hidden disabled`, kind only from GET).
- (low) Permission check copy-pasted in five places — fixed: `fish_can_record_donations()` in
  `include/permissions.php`, used by addDonation.php, donations.php, viewDonation.php, dashboard.php,
  donorProfile.php and addDonor.php.
- (low) Duplicated formatting and a full campaign aggregate for one campaign — accepted: FISH has a handful of
  campaigns, so the extra correlated subqueries are cheap, and reusing `donation_campaigns()` keeps the view
  page's figures identical to the form's; the formatting helpers are two-line page-local functions.
- (low) "Save & add donation" ignores donation permissions — fixed: the button, its hint and its redirect
  appear only when `fish_can_record_donations()` holds; otherwise the save goes to the profile. Tested.

Verification and product
- (medium) AC2 list behaviour untested — fixed: a fixture donor with three dated gifts; the suite asserts the
  row order for newest / oldest / amount, the rows for 30 / 90 / year / all, campaign-name search, and that
  every pager link keeps kind, q, range and sort.
- (medium) UF2-49 endpoints untested — fixed: donorLookup.php 403 without donors view, short query, active
  non-anonymous results only, exact keys (no email or phone); addDonor.php "Save & add donation" 303 to the
  form with the new donor chosen.
- (low) AC13 timing — fixed: the UF2-54 timer now spans the save through the donations.php GET showing the
  edit, and a donorProfile.php GET after the edit is timed for UF2-55.
- (low) Legacy event page check weak — fixed: date.php added; each page must return 200 with no PHP
  diagnostics (`assert_no_php_errors_in`).
- (low) Edit-mode kind not tested on its own — fixed: a valid-donor POST with kind=noncash to a cash edit
  updates that cash row and creates no non-cash row.
- (low) AC11 missing donorProfile.php — fixed: the XSS donor's profile is in the list.
