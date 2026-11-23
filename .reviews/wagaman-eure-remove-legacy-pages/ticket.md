# UF2-158: Remove back-end pages not used by FISH (part 1)

Jira UF2-158 has a title only ("Remove back-end pages not used by FISH"). Cory approved removing the old CASA / SPCA
pages that nothing in the FISH app links to (2026-11-23). This is part 1; see "Out of scope".

## Background

The repo carries a second, older app next to FISH: `index.php` + `header.php` / `footer.php` / `universal.inc`
("Rappahannock CASA", "Fredericksburg SPCA" titles), with event, volunteer sign-up, check-in time, leaderboard,
old donation form and old donor search pages. The FISH app (`include/fishnet-layout.php` nav: dashboard, donor,
donations, campaigns, communications, treasurer, reports, dataImports, manageUsers) links to none of them; its only
link into the old app is the avatar link to `viewProfile.php` (`include/fishnet-layout.php:91`). Several of the old
pages have no login check (`eventApproved.php`, `deleteLocation.php`, `deleteService.php`, `viewVolunteerProfile.php`,
`profileEditForm.php`) and some already fail because they require files that do not exist (`database/dbServices.php`,
`database/dbMessages.php`) or tables that do not exist (`dbpendingsignups`, `dbeventsservices`, `dbservices`, ...).

## Scope

Delete these 50 pages. No kept file links to, includes or requests them except the links removed below, the README
and the test lists; the remaining mentions are listed under acceptance criterion 2:

- `addEvent.php` - `editEvent.php` - `eventSuccess.php` - `eventFailure.php` - `viewAllEvents.php` - 
`specificEvent.php` - `date.php` - `calendar.php` - `adminViewingEvents.php` - `eventsOpenForSignUpReview.php` - 
`eventSignUp.php` - `eventApproved.php` - `signupPending.php` - `signupSuccess.php` - `requestFailed.php` - 
`eventFailureBadDepartureTime.php` - `fromPendingRejectSignup.php` - `viewSignUpList.php` - `cancelEvent.php` - 
`completeEvent.php` - `deleteEvent.php` - `deleteMultipleEvents.php` - `eventList.php` - `eventSearch.php` - 
`setTimes.php` - `editTimes.php` - `deleteTimes.php` - `leaderboard.php` - `milestonePoints.php` - `selectVOTM.php` - 
`volunteerManagement.php` - `volunteerReport.php` - `processReport.php` - `getVolunteers.php` - 
`viewVolunteerProfile.php` - `profileEditForm.php` - `editProfile.php` - `deletePerson.php` - `deleteLocation.php` - 
`deleteService.php` - `template.php` - `infoBox.php` - `donorSuccess.php` - `newDonor.php` - `donationAddEdit.php` - 
`addDonationHandler.php` - `editDonationHandler.php` - `searchindex.php` - `search_donors.php` - 
`search_multi_criteria.php`

Also delete helpers only these pages use: `database/dbEvents.php`, `domain/Event.php` (only dbEvents uses it),
`js/calendar.js` (only calendar.php loads it), the stylesheets and script only deleted pages load (`js/event.js`,
`css/event.css`, `css/editprofile.css`, `css/management_tw.css`, `css/hours-report.css`; each re-checked with git grep
before deleting), and `fetch_donations_for_event()` in `database/dbDonations.php` (only
viewAllEvents / specificEvent / date call it; the rest of the file stays).

Edit the kept old pages so they do not link to deleted pages:
- `index.php`: remove the Event Management box (viewAllEvents), the Add/Edit Donations box (donationAddEdit) and the
  Search box (searchindex).
- `header.php` side menu: remove Calendar, Event Management, Search Database and Add/Edit Donations. The Tools title
  then heads nothing, so the whole `access_level >= 2` block (now empty) is removed.
- `viewProfile.php` (the FISH avatar target): remove the Edit Profile, View Volunteer Hours (both), View Milestones &
  Points and My Volunteering Report buttons. The `$accessLevel < 2` block then holds nothing and is removed. Change Password and Reset Password stay; Return to
  Dashboard now points at FISH's `dashboard.php` instead of the old `index.php`.
- Level-2 users opening the old `index.php` directly now see its Dashboard heading with no boxes. Accepted: nothing in
  FISH links there any more (viewProfile now returns to dashboard.php), and the page is in the follow-up list.

Tests:
- `tests/auth_integration.py`: drop every deleted page from PROTECTED_PAGES, PROTECTED_APIS and KNOWN_UNGUARDED (the
  entry-point test then still proves every remaining PHP file is listed and every listed file exists). In the
  example loops, drop deleted pages and keep the remaining examples:
  - no-token POST test: drop addEvent, deleteEvent, deletePerson; add `addCampaign.php` and `addDonation.php` as FISH
    examples (both POST forms behind the layout's CSRF check; expect 403 and "form has expired"). Replace the
    `deleteEvent.php?id=1` GET-refusal check with `deleteResources.php?file=x` (kept; `auth_require_csrf()` refuses
    GET), expecting 403.
  - viewer (level 1) test: drop donationAddEdit, addEvent, addDonationHandler; replace the `search_donors.php` JSON
    403 with `toggleLock.php` (`require_authentication(2, true)`, the same level-2 boundary, so 403 for level 1). That
    loop's POSTs carry no token, so it shows refusal, not which check refused; the role gate is shown by the GET and
    JSON checks.
  - manager test: replace the searchindex / search_donors positive checks with `resources.php` 200 (level 2) and
    `toggleLock.php` not 403 (level-2 JSON; a test comment notes the GET is accepted only because the endpoint is
    inert). The `assertIn("searchindex.php", dashboard)` check is dropped; drop donationAddEdit from the loop.
  - manager and super admin tests: also `assertNotIn` each removed nav target (viewAllEvents, calendar, searchindex,
    donationAddEdit) on the index.php body, which includes header.php.
  - new test: sign in at level 1 and at level 3, GET `viewProfile.php`: 200, no PHP errors, none of editProfile,
    volunteerReport, milestonePoints in the body, and `dashboard.php` linked.
  - super admin test: drop searchindex and donationAddEdit from the loop.
  - logout replay test: drop donationAddEdit; replace the `search_donors.php` 401 with `donorLookup.php`
    (`require_authentication(1, true)`, so 401 with no session).
- `tests/donations_integration.py` `test_dashboard_and_legacy_event_pages`: keep the dashboard checks, drop the
  legacy event page loop, rename to `test_dashboard_links`.
- Update the KNOWN_UNGUARDED comment (it names infoBox, which is deleted).
- README: reword line 105 and any other line that names a deleted page. `sql/README.md` needs no change.

## Out of scope (follow-ups, listed in the PR)

- Kept for now because the login tests use them as examples or Cory has not decided: `VolunteerRegister.php` and
  `registrationForm.php` (old add-user; its username tests should move to FISH's `addUser.php` first), the documents
  library (`resources.php`, `uploadResources.php`, `deleteResources.php`, `viewResources.php`), `index.php`,
  `header.php`, `footer.php`, `universal.inc`, `viewProfile.php`, `changePassword.php`, `resetPassword.php`,
  `ReportGeneration.php` and `reportGeneration/`, `import.php` / `export.php` and `python/`, `emailgen.php`,
  `send_email.php`, `ai-email-gen/`, `toggleLock.php`.
- Dead handlers in `css/header.js` for calendar/date/eventSearch and the specificEvent link pattern in
  `include/output.php` (inert once the pages are gone; output.php is still used by viewProfile).
- Database tables only these pages used are UF2-157 (separate ticket). `get_events_attended_by()` in
  `database/dbPersons.php` becomes dead; it goes with UF2-157's cleanup.
- A pre-existing issue on one kept legacy page, tracked in a private follow-up. Not widened here.

## Acceptance criteria

1. The 50 pages and the 8 helper files listed under Scope are gone; `fetch_donations_for_event()` is gone; nothing else in
   `database/dbDonations.php` changes.
2. No tracked file links to, includes, requires or requests a deleted file. Allowed remaining mentions (grep check,
   each expected hit listed): `sql/migrations/009_campaigns.sql` comment (applied migrations are never edited),
   `database/dbCampaigns.php` prose comment, the `database/dbDonations.php` comment that names addEvent (line 167), the inert `css/header.js` handlers and `include/output.php` pattern (out
   of scope, see above), and the negative `assertNotIn` checks in the tests.
3. `index.php`, `header.php` and `viewProfile.php` render with no PHP errors and no links to deleted pages.
4. All test suites pass; the auth entry-point test passes with the trimmed lists.
5. No FISH page changes behaviour (no edits outside the files listed above).

## Verification plan

- `php -l` on every edited PHP file.
- `git grep` for each deleted filename over tracked files (excluding `.reviews/`, `docs/`): the only hits are the
  allowed mentions listed in AC2 (009 migration, dbCampaigns.php and dbDonations.php comments, css/header.js,
  include/output.php, the tests' assertNotIn lines).
- Stage by explicit path or `git add -u`; never `git add -A` (untracked report PDFs are in the tree).
- `php -l` over every tracked .php file, as CI does.
- `make seed-demo`, then every target CI runs: `make test-auth`, `test-donors`, `test-users`, `test-donations`,
  `test-hours`, `test-campaigns`, `test-outreach`, `test-comms`, `test-exports`, `test-gate`, `test-deploy`.
- Manual: sign in as the demo admin, open `index.php`, the old side menu and `viewProfile.php` (via the FISH avatar),
  and click each remaining link.

## Risks

- A FISH user reached one of these pages by a bookmark: they now get 404. Nothing in FISH links to them.
- Coverage moves: the CSRF and role-gate tests keep running on the remaining old pages plus two FISH pages.

## After merge

No migration and no re-login. The next deploy removes the pages from production (the deploy rsync uses `--delete`);
its dry run will list the 50 pages and 8 helper files as deletions, which is expected.

## Responses to round 1

Round 1: not approved, 3 critical (all the same point), 6 medium, 7 low. Changes:

- **AC2 grep cannot pass (critical x3):** fixed. AC2 now targets links, includes, requires and test requests, and lists
  each allowed remaining mention, including the applied 009 migration, which is never edited.
- **GET-refusal check dropped (medium x3):** fixed. Replaced with `deleteResources.php?file=x`.
- **Manager test loses its level-2 positive check; viewer JSON check off the boundary (medium x2):** fixed.
  `resources.php` 200 and `toggleLock.php` for the manager; `toggleLock.php` 403 for the viewer.
- **Verification plan skips suites (medium x2):** fixed. Every CI target plus `php -l` on all files.
- **Viewer POST loop cannot tell role from token refusal (low):** clarified in the plan.
- **Assets only deleted pages use (low):** added to the deletion list.
- **KNOWN_UNGUARDED comment, Tools heading, README line 105 (low x2):** named in scope.
- **get_events_attended_by() dead; a pre-existing issue on a kept page (low x2):** recorded as follow-ups (the second
  privately).
- **Post-merge note (low):** added.

## Responses to round 2

Round 2: approved, 0 critical, 2 high, 4 medium, 7 low. Revised and sent to round 3 because the fixes change the plan:

- **Grep step still says "no hits" (high x2, medium):** fixed; it now lists AC2's allowed hits, plus the dbDonations.php
  comment (low).
- **Follow-up bullet names a page with a weakness in a public file (medium):** fixed; reworded without the location.
- **Manager lands on an empty old dashboard (medium):** Return to Dashboard now points at dashboard.php; the empty
  index.php for level 2 is recorded as accepted.
- **viewProfile.php checked only by hand (medium):** new test at level 1 and 3; assertNotIn checks on index.php.
- **Empty wrappers, AC1 count, manager assertion, dry-run note, toggleLock GET, report PDFs (low):** all addressed above.

## Responses to round 3

Round 3: approved, 0 critical, 0 high, 0 medium, 6 low. All handled in the implementation, not by changing the approved
text above:

- **index.php still reachable from viewProfile's header (low):** the header.php side-menu Dashboard link points at
  dashboard.php; the logo keeps index.php, and the empty level-2 index.php stays accepted until the follow-up.
- **viewProfile `?id=` branch untested (low):** the new test also opens viewProfile.php?id=<level-1 user> at level 3.
- **Dead editSuccess banner (low):** removed with the Edit Profile button.
- **KNOWN_UNGUARDED comment's second line (low):** both comment lines updated.
- **Grep pattern (low):** each deleted name with `.php`, plus the helper paths and `fetch_donations_for_event`.
- **"broken_page" tuple names viewProfile.php (low):** viewProfile.php taken out of that tuple.
