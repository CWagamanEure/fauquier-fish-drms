# Sprint 4: save one-on-one communications (donor, person contacted, campaign) and clear-time buttons on the hours form

Branch `adu-sprint4-comms-hours`, off `origin/dev` (3d0f14c).

## Jira keys

All four are assigned to Joel Adu, reported by Tristan Chavez-Duran, labelled Sprint-4, and have no
description; the summary is the only text:

- **UF2-145** "Volunteer hours: add an X button in the time slot section to quickly remove a time"
- **UF2-146** "Connect donor to the donors database"
- **UF2-147** "Connect \"person contacted\" to dbpersons"
- **UF2-148** "Connect campaign to the events database"

UF2-146..148 sit next to James Andrews's UF2-141..144, which are all prefixed "Communications Outreach:"
("connect \"Posted by\" to dbpersons", "allow selecting more than one channel", "only show active
campaigns in the campaign dropdown", "connect photo/screenshot upload to files"). So UF2-146..148 are the
**one-on-one** half of `addCommunication.php` (PR #33, Tristan's front end), whose header says:
"FRONT END ONLY: empty form, nothing is saved. Backend TODO: fill the donor, campaign and staff dropdowns,
handle the POST (check fish_can('communications', 'edit')), and save. Field names are what the backend
should read."

**Scope decision.** "Connect X to the database" on a form that saves nothing means both filling the
control from the table and storing the chosen id on save; a filled dropdown that saves nothing would not
connect anything. So this branch saves one-on-one contacts. Outreach saving stays with UF2-141..144.

## Problem

- `addCommunication.php` sets `$donors = $campaigns = $staff = []`, so every dropdown is empty and the POST
  is ignored.
- `communications.person_contacted` (migration 006) is free text. UF2-147 asks for it to point at
  `dbpersons` (staff accounts, sql/README.md). The demo seed fills it with donor-side names ("Priya
  Natarajan" at the Rotary club), so a strict dbpersons-only field would lose that case.
- The form offers status "Follow up" and a follow-up checkbox and date; the `status` enum has no
  `follow_up` value and the table has no follow-up date, so saving those would fail or be dropped.
- On `addHours.php` / `hours.php` (include/hoursForm.php) a start or end time, once typed, can only be
  removed by clearing the browser's time picker segment by segment.

## Scope

In:

1. **Migration `014_communications_person_followup.sql`** (013 is the latest). Additive, idempotent,
   MariaDB 10.11:
   ```sql
   ALTER TABLE communications
     ADD COLUMN IF NOT EXISTS person_contacted_id VARCHAR(256) NULL AFTER person_contacted,
     ADD COLUMN IF NOT EXISTS follow_up_on        DATE         NULL AFTER status,
     MODIFY COLUMN status ENUM('logged','sent','delivered','opened','replied','bounced','failed','follow_up')
                          NOT NULL DEFAULT 'logged',
     ADD INDEX IF NOT EXISTS idx_comm_person_contacted (person_contacted_id);
   -- FK fk_comm_person_contacted -> dbpersons(id) ON DELETE SET NULL ON UPDATE CASCADE, added only when
   -- information_schema shows it missing (same pattern as migration 009).
   ```
   `person_contacted` (text) stays and holds the display name, so existing rows, the seed and the
   readers (`donor_communications()`, `campaign_communications()`) keep working unchanged. `follow_up` is appended after `failed`, so existing
   ordinals are unchanged and no stored row changes. `donor_summary` reads only `communications.donor_id` and
   `occurred_at`, so it needs no recreation.

2. **`database/dbCommunications.php`** (new; every statement prepared):
   - `comm_donor_options()`: id => display_name from `donor_summary` where `is_anonymous = 0 AND
     is_active = 1`, ordered by name (UF2-146, CLAUDE.md: read donors through donor_summary).
   - Staff for "Person contacted": reuse `campaign_owner_options()` from `database/dbCampaigns.php`
     (active `dbpersons`, not `vmsroot`) (UF2-147).
   - Campaigns: reuse `campaigns_options()` from `database/dbCampaigns.php` (dbevents rows that are not
     completed) (UF2-148).
   - `comm_form_blank()`, `comm_form_from_post($post)`, `comm_form_validate(&$form, $donors, $staff,
     $campaigns)`, `comm_create($form, $byUser)`.
   - Validation: donor required and must be a key of the offered donor list (so anonymous 999, inactive
     and unknown ids are refused); type in the six enum values; direction in outbound/inbound; date a
     valid `Y-m-d` via `donation_valid_date()` and not in the future; status in logged/sent/replied/
     follow_up/bounced (the form's list); person contacted is either a key of the staff list, the special
     value `other` with a typed name (1..255 chars), or empty; campaign empty or a key of the campaign
     list; subject <= 255; summary <= 5000; follow-up date, when given, a valid date; ticking "Remind me
     to follow up" without a date is an error; a follow-up date with the box unticked is ignored.
   - Insert: `donor_id`, `campaign_id` (NULL when none), `occurred_at` = date + ' 00:00:00', `comm_type`,
     `direction`, `subject`/`summary` (NULL when blank), `person_contacted` (staff display name or the
     typed name, NULL when none), `person_contacted_id` (staff id or NULL), `status` (`follow_up` when the
     box is ticked, else the chosen status), `follow_up_on`, `recorded_by` = the signed-in user id.
     Returns the new id, or false after `error_log`.

3. **`addCommunication.php`**:
   - Gate: the page is already protected by `include/fishnet-layout.php`. `fish_can('communications',
     'view')` false: notice, no form (403 on POST). Saving needs `communications` view + edit and `donors`
     view; without them the form renders read-only (fieldset disabled, no Save) and a POST gets 403.
   - CSRF: `auth_require_csrf()` on POST (form already carries `auth_csrf_field()`).
   - Dropdowns filled from the three lookups; values re-selected after a validation error; every value
     escaped with `h()`. "Person contacted" becomes a select of staff plus "Someone else..." which shows a
     name text box (`person_contacted_other`); with JS off the text box is always visible.
   - `?donor_id=N` preselects that donor when it is in the list.
   - POST with `kind=one_on_one`: validate, insert, then 303 to `donorProfile.php?id=N&tab=comm`.
   - POST with `kind=outreach`: re-render with the error "Saving outreach posts is not available yet"
     (UF2-141..144); nothing is written. The outreach campaign select is filled from the same list.
   - Load errors (`mysqli_sql_exception`) are logged and shown as a generic notice; POST then returns 503.
4. **`donorProfile.php`**: `tab=comm` opens the Communications tab, so the redirect lands on the new row.
5. **UF2-145, `include/hoursForm.php` + `js/volunteerHours.js` + `css/volunteer.css`**: a small `×`
   button (type=button, `aria-label="Clear start time"` / `"Clear end time"`) inside the Start and End
   fields. Rendered `hidden`; the script shows it only while that field has a value and the form is not
   read-only. Clicking clears the time, and also clears Hours if Hours still holds the value the script
   last auto-filled (a typed Hours is kept), then focuses the time field. Server side nothing changes:
   blank times are already accepted by `hours_form_validate()`.
6. **Tests**: `tests/communications_integration.py` + `make test-comms`, added to the CI `tests` job.
   **Docs**: `sql/README.md` (migration 014 row), Makefile help line.

Out:

- Outreach saving, "Posted by", multi-channel, photo upload, active-only outreach campaigns (UF2-141..144).
- The Communications list page and `viewCommunication.php` (still front-end only; no ticket).
- Editing or deleting a saved communication.
- Changing `donor_communications()` / `campaign_communications()`.

## Acceptance criteria

1. The Donor dropdown lists every active, non-anonymous donor by display name and none other; posting
   donor 999, an inactive donor or an unknown id is refused with an error and writes nothing.
2. "Person contacted" lists active staff accounts (not vmsroot) and "Someone else"; saving a staff
   member stores their id in `person_contacted_id` and their name in `person_contacted`; "Someone else"
   stores the typed name and NULL id; an unknown staff id is refused.
3. The Campaign dropdown lists the campaigns the hours and donation forms offer (`hours_campaign_names()`); saving one stores its id in
   `campaign_id`; a completed or unknown campaign id is refused.
4. A valid one-on-one POST inserts exactly one `communications` row with the posted values and
   `recorded_by` = the user, and 303s to the donor profile, where the row shows on the Communications tab.
5. Missing CSRF token -> refused, nothing written. Account without communications edit -> read-only form,
   POST 403, nothing written. Without communications view -> no form.
6. Status "Follow up" and the follow-up box + date save as `status = 'follow_up'` and `follow_up_on`.
7. Validation errors re-render the form with the posted values kept and escaped (XSS name test).
8. Outreach POST writes nothing and shows the not-available message.
9. UF2-145: on addHours.php and hours.php (editable), each time field with a value shows an `×`; clicking
   it empties the field and the auto-filled Hours; a typed Hours stays. No `×` on a read-only entry.
10. Migration 014 runs twice cleanly on a migrated database; existing rows are unchanged.
11. `make test-auth`, `test-donors`, `test-users`, `test-donations`, `test-hours`, `test-gate` and
    `tests/campaigns_integration.py` still pass.

## Verification plan

- Scratch MariaDB (XAMPP binaries) with every migration + demo seed, re-run 014 twice; check
  `SHOW CREATE TABLE communications`.
- `php -l` on every changed PHP file.
- Run all suites (auth, donors, users, donations, hours, new comms) against `php -S` with the
  BASE_URL override.
- Headless Chrome walk (jscheck/xampp-walk scripts): addCommunication "Someone else" toggle; hours `×`
  buttons (clear start, auto Hours cleared, typed Hours kept, hidden on read-only).
- Apply 014 to the XAMPP `fishdb` (backup first) and load the page there.

## Risks

- `MODIFY COLUMN status` rewrites the column definition; it appends a value only, so it is safe and
  re-runnable, but it must list every existing value in order.
- A large donor table makes a full `<select>`; acceptable at FISH's size (hundreds), and the donation
  page's lookup widget can replace it later.
- Teammates must run `make migrate` after merging (014).

## Responses to round 1

All four auditors approved (0 critical). Changes made to the plan, by finding:

- **High (verification, correctness): profile "+ Log communication" links to the missing logCommunication.php.**
  Fixed in scope: the profile button and the dashboard button point at `addCommunication.php` (the profile
  one with `?donor_id=N`). The profile button shows only when the account can save a communication
  (`communications` view + edit, `donors` view), the donor is active and not anonymous, and nothing failed
  to load (same shape as `$canLogHours`). The profile's Communications tab rows are now shown only with
  `communications` view (security medium; matches campaign.php). Tested in the comms suite.
- **Medium (all four): lookups return row lists.** Staff come from
  `array_column(campaign_owner_options(), 'name', 'id')`; validation compares with
  `array_key_exists((string) $v, $map)` after string-casting, and campaign ids must be `ctype_digit`.
  Tests post an id that exists in dbevents but is not offered, and `0`.
- **Medium (correctness): two definitions of "completed".** The Campaign dropdown uses
  `hours_campaign_names()` (id => name, same list as the hours and donation forms) instead of
  `campaigns_options()`. AC3 now reads "campaigns the hours and donation forms offer".
- **Medium (security): read-only form lists donors.** `comm_donor_options()` runs only with `donors` view.
- **Medium (correctness, verification): `Follow_up` badge.** `comm_status_label()` in
  `database/dbCommunications.php` maps statuses to labels; both `comm_badge()` copies use it. The follow-up
  date is stored only (no reader shows it yet); the badge says "Follow up".
- **Medium (correctness): readonly x detection.** The PHP does not render the x buttons when
  `$v['readonly']`; the script additionally checks `closest('fieldset[disabled]')`.
- **Medium (verification): README.md.** Added to Docs: CI suite list, `make test-comms`, the
  communications save gate. sql/README.md gets the 014 columns.
- **Medium (verification) / low (correctness): campaigns suite.** `tests/campaigns_integration.py` is run
  directly in the verification plan and listed in AC11.
- **Low: follow-up rule.** One rule: a row is a follow-up when the box is ticked, or Status is Follow up,
  or a follow-up date is given; it then saves `status = 'follow_up'` and the date (if any). The box ticked
  with no date is an error. Nothing is dropped silently. Each combination is a test case.
- **Low (data): re-check donor at insert.** `comm_create()` re-checks `is_active = 1 AND id <> 999` with
  `LOCK IN SHARE MODE` in a transaction before the insert, like `hours_create()`.
- **Low: enum position.** Appended at the end (see the migration above).
- **Low: "no view reads communications".** Corrected above.
- **Low: `other` sentinel.** The value is `__other__`, which `auth_valid_username()` cannot produce.
- **Low: redundant CSRF call.** Dropped; the layout's `require_authentication()` already calls
  `auth_require_csrf()` on POST. AC5's test stays.
- **Low: donor helper location.** `donor_options()` goes in `database/dbDonors.php`.
- **Low: test teardown.** The comms suite deletes its communications rows before its donors and users.
- **Low: AC9 on hours.php.** Reworded: on an existing entry the stored Hours is kept unless the script
  filled it in this page view. The suite checks the markup (present + hidden on editable forms, absent on
  read-only); the click behaviour is checked in the headless walk.
- **Low (data, correctness): out-of-scope pre-existing items.** Noted, not changed here.
