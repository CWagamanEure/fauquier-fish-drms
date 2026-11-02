# Volunteer hours: log, view, edit and delete entries from the donor profile

Branch `adu-volunteer-hours`, off `origin/dev` (3088ba6) with PR #26 merged in. PR #26 (`TCDvolnteerhtml`) is
Tristan Chavez-Duran's front end for `addHours.php` and `hours.php`; this branch turns those mock-up pages
into working pages. PR #26's commits ride along, and GitHub marks #26 merged when this PR merges.

## Jira keys

All five are assigned to Joel Adu and reported by Tristan Chavez-Duran. Their only description is the
Sprint 3 sheet line quoted here:

- **UF2-116** Link the database to the Volunteer tab in the donor profile. "link the db to the volunteer tab in donor profile"
- **UF2-117** Link the Log Hours button to addHours.php. "link the button log hours to the addHours.php"
- **UF2-118** Save new volunteer hour entries. "be able to save new entrys"
- **UF2-119** View new volunteer hour entries. "be able to view new entries"
- **UF2-120** Edit older volunteer hour entries. "be able to edit older entired"

Confluence, Group Acceptance Criteria (page 21495809), Tristan's story:

> As an Admin, I want to monitor a supporter's volunteer hours and communication touchpoints on their
> profile so that I can understand their time investment and engagement frequency with our organization.
> - The admin will be able to record volunteers hours in the portal.
> - The admin will be able to update and edit recorded hours in the portal.
> - The admin will be able to save after they have added or updated records.

PR #26's comments name the backend work: "handle the POST (check fish_can('donors', 'edit'), validate,
write volunteer_hours), load the entry when ?id= is given, and fill $volunteers / $jobs / $campaigns /
$summary / $history from the database."

**Scope decision.** The user asked for all of their tickets to be finished, including the mock-up's
fields. The mock-up's reporter is also the reporter of all five tickets, and it shows delete, the two
status flags, the change history and the group shift. These are built here rather than left as
non-working controls on a page that saves.

## Problem

- The Volunteer tab on `donorProfile.php` already reads `volunteer_hours` (migration 005) through
  `donor_hours_history()`, but its rows link nowhere and "+ Log hours" shows for every account.
- `addHours.php` and `hours.php` (PR #26) are mock-ups with hard-coded data. They differ only in
  `$isEdit`. Nothing saves, `hours.php` ignores `?id=`, and the donor is whatever `?donor_id=` says
  (defaulting to 1).
- The mock-up form has fields that `volunteer_hours` lacks: start and end time, location, "Hours verified
  by coordinator", "Counts toward service-hour letter", a change history, and "Same shift for more people".

## Scope

In:

1. Migration `010_volunteer_hours_details.sql` (009 is taken by open PR #28). Nothing reads
   `volunteer_hours` by position or with `SELECT *`, and `donor_summary` only uses `SUM(hours)` and
   `COUNT(*)`, so the view is not recreated. The file is additive and idempotent:

   ```sql
   ALTER TABLE volunteer_hours
     ADD COLUMN IF NOT EXISTS start_time        TIME         NULL AFTER hours,
     ADD COLUMN IF NOT EXISTS end_time          TIME         NULL AFTER start_time,
     ADD COLUMN IF NOT EXISTS location          VARCHAR(255) NULL AFTER job,
     ADD COLUMN IF NOT EXISTS is_verified       TINYINT(1)   NOT NULL DEFAULT 0 AFTER notes,
     ADD COLUMN IF NOT EXISTS counts_for_letter TINYINT(1)   NOT NULL DEFAULT 0 AFTER is_verified;
   CREATE TABLE IF NOT EXISTS volunteer_hours_changes (
     id                 INT          NOT NULL AUTO_INCREMENT,
     volunteer_hours_id INT          NOT NULL,
     changed_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
     changed_by         VARCHAR(256) NULL,
     summary            TEXT         NOT NULL,
     PRIMARY KEY (id),
     KEY idx_vhc_entry (volunteer_hours_id, changed_at),
     KEY idx_vhc_changed_by (changed_by),
     CONSTRAINT fk_vhc_entry      FOREIGN KEY (volunteer_hours_id) REFERENCES volunteer_hours (id) ON DELETE CASCADE ON UPDATE CASCADE,
     CONSTRAINT fk_vhc_changed_by FOREIGN KEY (changed_by)         REFERENCES dbpersons (id)      ON DELETE SET NULL ON UPDATE CASCADE
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
   ```

2. `database/dbVolunteerHours.php` holds the data and form layer.
   - Every statement is prepared.
   - It reuses `donation_valid_date()`, `donation_today()`, `donation_campaigns()` and
     `donation_recorder_name()` from `database/dbDonations.php`, and `donor_get()` from
     `database/dbDonors.php`.
   - **Reads:**
     - `hours_get($id)`: the entry, its donor's display name and flags, the recorder's name, and the
       campaign name.
     - `hours_changes($id)`: the change history, newest first, with the time as `UNIX_TIMESTAMP(changed_at)`
       so PHP formats it in America/New_York whatever the DB session zone is.
     - `hours_summary($donorId)`: the side panel.
     - `hours_job_options()`: the job list.
     - `hours_campaign_names($keepId)`: id => name only, built from `donation_campaigns()`, so no goal or
       raised money reaches the hours pages.
   - **Form functions:** blank, from row, from post, validate.
   - **Writes:**
     - `hours_create()`: one row per volunteer. It re-checks every donor (`is_active = 1 AND id <> 999`,
       `LOCK IN SHARE MODE`) inside the insert transaction.
     - `hours_update()`: reads the stored row `FOR UPDATE` inside the transaction, computes the change
       summary from that row, and writes the update and the change row together. When nothing changed,
       it writes nothing.
     - `hours_delete()`.
   - `hours_label($n)`: the one hours formatter, 2 decimals with trailing zeros trimmed (4.00 → 4,
     4.50 → 4.5, 2.25 → 2.25).
3. `include/permissions.php` gets two helpers, named after `fish_can_record_donations()`:
   - `fish_can_log_hours()` = donors view && donors edit
   - `fish_can_delete_hours()` = donors view && donors delete
4. `addHours.php?donor_id=N` (UF2-117/118):
   - **Donor.** It comes from `donor_id` (GET, or the POST's hidden field) and must be an existing,
     active, non-anonymous donor; otherwise the page redirects (303) to `donor.php`.
   - **Fields.** Date, start and end time, hours, job, campaign, location, notes, and "Same shift for more
     people" (extra volunteers found through `donorLookup.php`).
   - **Save.** It writes one row per volunteer with the same values, with `recorded_by` set to the user
     and both flags 0. It then redirects (303) to `donorProfile.php?id=N&tab=vol&hours=<count>`.
   - **Errors.** The form re-renders with what was typed, and the side panel shows the real summary.
5. `hours.php?id=N` (UF2-119/120):
   - **Loading.** The id comes only from `$_GET['id']` (`ctype_digit`) for load, save and delete. Forms
     post back to `hours.php?id=N`, and any POSTed id or `donor_id` is ignored. The donor comes from the
     stored row. An unknown or non-numeric id redirects to `donor.php`.
   - **Fields.** The same as add, minus extra volunteers, plus the two status checkboxes.
   - **Save changes.** It redirects to `hours.php?id=N&saved=1`, which shows "Hours saved."
   - **Change history.** It lists the change rows, newest first ("by <name>", or no name when the account
     no longer exists), and then "Entry logged" with its date and recorder.
   - **Delete entry.** A separate POST form with `action=delete` and its own `auth_csrf_field()`. It needs
     `fish_can_delete_hours()`, asks for confirmation in the browser, and redirects to
     `donorProfile.php?id=<donor>&tab=vol&hours_deleted=1`.
   - **Read-only.** Without `fish_can_log_hours()`, the page shows the entry with fields disabled and no
     Save or Delete. This is how a viewer views an entry (UF2-119). Entries of the anonymous donor (999)
     are always read-only, because 999 is never written.
   - **Inactive donors.** Entries of an inactive donor stay editable, matching the stored-donor rule in
     `donation_form_validate()`.
6. `include/hoursForm.php`:
   - Defines `hours_form_render(array $v)`, which holds only the markup for both pages, keeping PR #26's
     layout.
   - It runs no queries and does not read the request. Requested directly, it only defines the function
     and prints nothing.
7. `donorProfile.php` (UF2-116/117/119):
   - "+ Log hours" shows only when all of these hold: `fish_can_log_hours()`, an active donor, a
     non-anonymous donor, and no load error.
   - Each Volunteer row links to `hours.php?id=`. The link is labelled "Edit" when `fish_can_log_hours()`
     and the donor is not anonymous, otherwise "View".
   - `?tab=vol` opens the Volunteer tab. `hours=<n>` (read as `(int)`, shown only when ≥ 1) shows a fixed
     notice, "Hours saved." (n = 1) or "Hours saved for n volunteers." `hours_deleted=1` shows
     "Hours entry deleted."
   - The local `fmt_hours()` is replaced by `hours_label()` for the tab, its totals and the tile.
8. `js/volunteerHours.js`:
   - **Hours from times.** Hours fill in from the start and end time until the user types in the hours
     box. The value is minutes / 60 rounded to 2 decimals, the same rule the server uses.
   - **Extra volunteers.** Chips come from `donorLookup.php` results, are built with `textContent` only,
     and each one carries a hidden `extra_donor[]` input.
   - **Delete.** The confirmation prompt.
9. Tests and docs:
   - **New suite** `tests/volunteer_hours_integration.py`. It creates its own users, donors (active,
     inactive, an XSS name) and campaigns (open, completed '1'), plus a legacy-shaped entry with an
     off-list job, NULL times, location and recorder, and multi-line notes. It deletes its hours and change
     rows before its donors. Demo rows are never asserted on.
   - **Makefile:** a `test-hours` target, with its help line and `.PHONY` entry. **CI:** a "Volunteer
     hours page tests" step.
   - **Auth suite:** `addHours.php` and `hours.php` are added to `PROTECTED_PAGES` in
     `tests/auth_integration.py`.
   - **README.md:** the CI target list in "Change review process", a `make test-hours` line next to the
     other suites, and who can log, edit and delete hours in the permissions paragraph.
   - **`sql/README.md`:** the schema table rows for the 010 columns and `volunteer_hours_changes`, and the
     "001–008" heading.
   - **CSS:** the comment at the top of `css/volunteer.css`, which names a page that does not exist.

Out:

- A separate `volunteers` permission area. The `user_permissions.area` enum has none, so donor
  permissions govern volunteer records, as PR #26's comment says.
- Overnight shifts (an end earlier than the start), and a cross-donor list or report of hours.
- Setting `donors.is_volunteer` automatically when hours are logged.
- Communications. The Confluence story also covers "communication touchpoints", but the profile's
  "+ Log communication" target is not one of these tickets.
- The other open PRs' work (#27, #28).
- The pre-existing missing `donors view` gate on `donorProfile.php` itself. It is unchanged here; the new
  pages gate on it.

## Approach

### Permissions

| Action | Requires | Without it |
|---|---|---|
| Show any data on `addHours.php` / `hours.php` (donor name, summary, entry, campaigns) | `fish_can('donors', 'view')` | A notice, and no entry or donor data |
| Show the add form; accept add and edit POSTs | `fish_can_log_hours()` | A notice on `addHours.php` and a read-only `hours.php`; POSTs get 403 and write nothing |
| Delete | `fish_can_delete_hours()` | The delete panel is hidden; the POST gets 403 |

- With the presets: admin and manager can do all three, staff can log and edit but not delete, and viewer
  can only view.
- Every POST carries `auth_csrf_field()`, which `require_authentication()` in `include/fishnet-layout.php`
  verifies.
- The pages show no money: campaigns as names only, and the donor as display name only.

### Normalisation (form from row, form from post, and the diff)

| Value | Normalised to |
|---|---|
| Times | `HH:MM` (stored `09:00:00` → `09:00`; posted seconds dropped) |
| Hours | integer hundredths for comparison; shown with `hours_label()` |
| NULL text and campaign | `''` |
| Text | trimmed; notes have CRLF converted to LF |

- An empty optional field is written back as NULL.
- The diff compares the normalised posted values with the normalised `FOR UPDATE` row, so an untouched
  entry (times, NULL fields, multi-line notes) produces no change.

### Validation (`hours_form_validate`)

- **Date:** required, valid by `donation_valid_date()`, and not after `donation_today()`.
- **Times:** optional, `HH:MM` 00:00–23:59. When both are given, end must be after start. The span is
  whole minutes.
- **Hours:**
  - When the hours box is empty and both times are given, hours = round(span / 60, 2).
  - Otherwise hours must match `^\d{1,2}(\.\d{1,2})?$`, be > 0 and be ≤ 24.
  - With both times, `round(hours × 60) ≤ span`. The time-derived value always passes, because rounding
    to 0.01 h moves it by at most 0.3 minutes. A larger number is refused.
  - Example: 09:00–13:40 is 280 minutes, so 4.67 passes (280.2 rounds to 280) and 4.68 fails (280.8
    rounds to 281).
- **Job:** empty, or one of the options: PR #26's list plus every distinct non-empty job already stored,
  so seeded and legacy values stay selectable. On edit the stored value is always accepted.
- **Campaign:** empty, or a key of `hours_campaign_names($storedCampaign)`, which holds the open campaigns
  plus the entry's own.
- **Location:** at most 255 characters. **Notes:** at most 2000.
- **Extra volunteers (add only):**
  - Integer ids, de-duplicated, excluding the main donor, at most 20.
  - Each must exist and be active and non-anonymous. A bad id (unknown, inactive, 999, or not a number) is
    one error and nothing is written.
- **Status checkboxes:** read only on edit; on add they are 0.

### Change summary on edit

- One clause per changed field, joined by "; ". For example: "Hours changed 4 → 4.5",
  "Date changed Oct 22, 2026 → Oct 23, 2026", "Job changed Pantry sorting → Office help",
  "Campaign changed None → Fall Food Drive 2026", "Marked verified", "Notes edited".
- Each quoted value is cut to 80 characters with "…", so the summary stays short even for long legacy
  values and TEXT campaign names. The column is TEXT anyway.
- Stored as text and rendered with `h()`.

### Side summary (`hours_summary`)

- One prepared query over the donor's `volunteer_hours`:
  - hours and number of shifts in the current calendar year (Jan 1 – Dec 31 computed in PHP and bound)
  - the latest `activity_date`
  - the most frequent non-empty job, with ties going to the most recent use
- Shown as "Hours this year", "Shifts this year", "Last shift" and "Usual role" ("—" when there is none).

## Acceptance criteria

1. **Log hours (UF2-117/118).** Staff opens "+ Log hours" on `donorProfile.php?id=N`; the form names the
   donor and shows today's date. Saving writes exactly one `volunteer_hours` row:
   - donor N, with the date, hours, job, campaign, location, notes and times entered
   - `recorded_by` = the staff id, and both flags 0
   - the user lands on the profile's Volunteer tab, which shows the row and "Hours saved."
2. **Hours from times.**
   - Start 09:00, end 13:30, hours empty: saved as 4.50.
   - 09:00–13:40: hours empty saves 4.67, and hours 4.67 typed saves.
   - Hours 4.68, and hours 5 for 09:00–13:30, are refused.
3. **Rejected input writes nothing and keeps the form.** Each of these is refused with a message, the form
   re-renders with the typed values, and the row count is unchanged:
   - **Date:** missing, future, or "2026-02-30"
   - **Hours:** 0, 25, "abc" or 1.234
   - **Times:** end before start, or "25:00"
   - **Job and campaign:** a job outside the options, an unknown campaign id, or a completed campaign id
   - **Location:** over 255 characters
   - **Extra volunteer:** inactive, anonymous (999), unknown, or "x"
4. **Same shift for more people.** Two extra volunteers write three rows with the same values and redirect
   with `hours=3`; the profile says "Hours saved for 3 volunteers." A repeated id, or the main donor listed
   again, is ignored.
5. **Bad donor.** On `addHours.php`, a missing, unknown, anonymous (999) or inactive `donor_id` redirects
   to `donor.php` for GET and POST, and writes nothing.
6. **View an entry (UF2-119).** `hours.php?id=X` shows:
   - the stored values (hours with `hours_label`) and the donor's name
   - the real summary
   - a Change history ending in "Entry logged" with the recorder's name, or with no name for a NULL
     recorder
   An unknown or non-numeric id redirects to `donor.php`.
7. **Edit an entry (UF2-120).**
   - Changing hours 4 → 4.5 and the job updates only that row, and writes one change row "Hours changed
     4 → 4.5; Job changed …", which the history shows. The page shows "Hours saved."
   - A POSTed `donor_id` or `id` for another record is ignored.
   - Saved untouched, the legacy entry (off-list job, NULL times, location and recorder, multi-line notes)
     writes no change row and leaves the row as it was, NULLs included. An entry with times saved untouched
     also writes none. Retyping hours "4" for a stored 4.00 writes none.
   - The two status checkboxes save.
   - An inactive donor's entry can be edited.
8. **Delete.**
   - A manager deletes an entry: the row and its change rows are gone, and the profile shows "Hours entry
     deleted."
   - Staff sees no delete panel; its delete POST gets 403 and the row is kept.
9. **Volunteer tab (UF2-116).**
   - The tab lists the donor's rows newest first, with totals using `hours_label`; a 2.25-hour row shows
     "2.25".
   - Each row links to `hours.php?id=`, labelled Edit (staff) or View (viewer).
10. **Permissions.**
    - **Viewer:** no "+ Log hours", `hours.php` read-only with no Save or Delete, `addHours.php` shows the
      cannot-log notice, and POSTs to either page get 403.
    - **Donors edit without donors view:** a notice and no entry or donor data on both pages, and POSTs get
      403.
    - **Donors view without donations view:** the hours pages show no `$` amounts.
    - **Anonymous requests** redirect to `/login.php`, and POSTs without the form token are refused.
11. **Escaping.** Donor names, jobs, notes, locations and change summaries containing `<img onerror>`
    render escaped on all three pages.
12. **Regression.** The existing suites still pass, and `php -l` is clean on every changed file.

## Verification plan

Docker is not installed on this machine, so the local procedure is:

1. **Scratch database.** A scratch MariaDB (XAMPP 10.4 binaries, utf8mb4 server defaults) loads
   `sql/casadb.sql`, then 001–010 through a copy of `scripts/db-migrate.sh` pointed at it. Every file
   001–010 is then piped through the client a second time, to check that the series is idempotent the way
   `ci.yml` checks it. Then `sql/seed/demo_fish.sql` is loaded.
2. **Suites.** They run against `php -S`, with a runner that overrides `BASE_URL`:
   - **`auth_integration.py`:** the direct-file-URL checks need the Apache rules and are an
     environment-only failure, as before.
   - **`donors_integration.py`, `users_integration.py`, `donations_integration.py`** and the new
     **`volunteer_hours_integration.py`**.
   - **`tests/review_gate.sh`** (`make test-gate`) and **`php -l`** on all changed PHP.
3. **XAMPP fishdb.** It already has 001–008 (applied on 2026-10-21). After a backup, 010 is applied
   through the same pointed copy of `db-migrate.sh`, which records it in `schema_migrations`. The htdocs
   copy is synced to this branch, and the Log hours → profile → Edit → history → delete flow is walked in
   a browser.
4. **CI.** MariaDB 10.11, `db-migrate.sh` and the replay check run in CI. Its `tests` job must be green on
   the PR before the merge into `dev`.

## After merging

- Run `make migrate` (or `make dev`) to apply 010; on XAMPP, apply 010 as in step 3 above.
- No image rebuild, re-login or session change is needed.
- Run `make seed-demo` before `make test-hours`.

## Risks

- PR #28 also edits `donorProfile.php`, and whichever merges second resolves the conflict. This branch keeps
  its profile edits to the Volunteer tab, the notices, the tab radio and the hours formatter.
- Migrations 009 (PR #28) and 010 can merge in either order, because `db-migrate.sh` keys on filenames. PR
  #28's 009 only touches `dbevents` and adds a view.
- The extra-volunteer search uses `donorLookup.php`, which needs donors view. Every account that passes
  `fish_can_log_hours()` has it.
- `hours_campaign_names()` builds on `donation_campaigns()` from `dbDonations.php`. That is acceptable until
  a campaigns data layer exists (PR #28 adds `dbCampaigns.php`; switching to it is a later cleanup).

## Responses to round 1

The ticket was approved in round 1, with no critical. Every finding is answered below, and round 2
re-checks the revisions.

- **Hours rounded from the times can exceed the span** (high, data, correctness and verification): fixed.
  The span check is now `round(hours × 60) ≤ span` minutes, and JS and PHP use the same rounding. AC2 adds
  4.67, which saves, and 4.68, which is refused.
- **Write and delete gates do not require donors view** (security medium; correctness and verification
  medium): fixed. `fish_can_log_hours()` and `fish_can_delete_hours()` both include view, and AC10 adds an
  edit-without-view case. The lookup risk line is now true.
- **Reused helpers return financial fields** (security low): fixed. Campaigns are passed as id => name
  only, the donor as display name only, and AC10 asserts that no `$` appears.
- **`include/hoursForm.php` reachable directly** (security low): fixed. It only defines
  `hours_form_render()`.
- **Where the POST id comes from, and notice rendering** (security low): fixed. The id comes from GET only,
  and the notices are fixed text from `(int)`.
- **Normalisation for change detection** (data and correctness medium; verification low): fixed with the
  normalisation table, and AC7 adds the untouched cases.
- **Summary VARCHAR(1000) overflow** (data and correctness medium): fixed. The column is TEXT, and quoted
  values are cut to 80 characters.
- **DDL types and collation unspecified** (data medium; correctness low): fixed. The DDL is now in the
  ticket.
- **Verification plan names a non-existent script and a Docker-only path** (data medium): fixed. The plan
  describes the real local procedure, and the 10.11 and db-migrate proof is left to CI.
- **Read and check inside the transaction** (data low): fixed. `FOR UPDATE` on edit, and the donor
  re-check in the insert transaction.
- **History and tests assume a recorder and seed rows** (data low): fixed. NULL recorder shows no name,
  and the suite builds its own fixtures.
- **Profile rounds hours to one decimal** (verification medium): fixed. `hours_label()` everywhere, and
  AC9 checks 2.25.
- **Post-merge steps and doc touch points** (verification medium): fixed. See "After merging" and Scope 9.
- **Inactive or anonymous donor edits undefined** (correctness low): fixed. Inactive entries stay editable
  and 999's are read-only; the profile label follows the same rule.
- **Scope beyond the Jira keys** (correctness low): accepted. See the scope decision at the top: the
  user's instruction and the reporter's own mock-up.
- **Small spec gaps** (verification low): fixed. Unknown extra id in AC3, the saved notice, the inactive
  rule, communications under Out, and the time zone (history times read as `UNIX_TIMESTAMP` and formatted in
  PHP's America/New_York).

## Responses to round 2

Round 2 approved the ticket with no critical and no high finding. Every finding is taken into the
implementation as follows, and the code round checks it.

- **Anonymous-donor (999) entries read-only only in the UI** (security low, data medium, correctness low):
  fixed. Save and delete POSTs on an entry whose stored donor is 999 get 403 and write nothing.
  `hours_update()` and `hours_delete()` also carry `AND donor_id <> 999` on the locked row. The suite
  posts both against a 999 entry.
- **`changed_by` source; no trace of a delete** (security low): fixed. `changed_by` and `recorded_by`
  are always `auth_current_user()->get_id()`. A delete writes an `error_log()` line with the entry id,
  donor, date, hours and the deleting user before the row is removed.
- **Read-only rule and delete gate disagree** (correctness low): fixed.
  `fish_can_delete_hours()` = donors view && edit && delete, and the panel and the POST use the same check.
- **No stored-value exemption on edit** (data low, correctness low): fixed. As in
  `donation_form_validate()`, a field whose normalised posted value equals the normalised stored value
  passes unchanged: date, times, hours, job, campaign, location and notes.
- **"Entry logged" date source** (data, correctness and verification low): fixed. It is
  `UNIX_TIMESTAMP(created_at)`, formatted in PHP like the change rows.
- **Auto-fill can overwrite stored or re-rendered hours** (correctness medium): fixed. The script never
  computes on load. It recomputes only on a start or end input event, and only while the hours box is
  empty or still holds the script's own last value. A server-rendered value counts as typed.
- **Extra volunteers lost after a validation error** (correctness medium): fixed. On error the page
  rebuilds a chip and hidden input for each valid posted id, with the display name read server-side. Ids
  that are not valid are dropped, and the error names how many could not be added. The suite asserts that
  the hidden inputs survive a date error.
- **JS behaviour untested** (verification medium): accepted for the Python suite, which has no browser.
  Verification step 3 adds a headless Chrome check of the auto-fill (4.67, a typed value kept, a stored
  value not overwritten), an XSS-named chip shown as text, two chips saving three rows, and a cancelled
  delete confirmation keeping the row.
- **`fmt_hours()` also formats non-cash quantities** (correctness low): fixed. `fmt_hours()` stays for the
  Items tab. Only the Volunteer tab, its footer and the Volunteer hours tile switch to `hours_label()`.
- **viewDonation.php still rounds to 1 decimal** (correctness low): reworded. `hours_label()` covers the
  hours pages and the donor profile, and the donation pages' side panel is out of scope.
- **Process doc lists CI targets** (verification low): fixed. `docs/process/change-review-process.md`
  gains `make test-hours`.
- **Check order** (verification low): fixed. The order is authentication and token, then donors view
  (notice, or 403 on POST), then id or donor resolution (303 to `donor.php`), then log or delete permission
  (403). On `addHours.php` the donor comes only from the URL's `donor_id`. The form posts to
  `addHours.php?donor_id=N`, and no hidden donor field is read.
- **PR #28's 009 inserts demo hours rows** (data low): Risks corrected. The refused job in the suite is a
  random token, and the fixture is described as "a job not in PR #26's list".
- **Small spec gaps** (verification low): fixed.
  - More than 20 extra volunteers is an error.
  - Flag wording: "Marked verified" / "Unmarked verified" and "Counts toward service letter" / "No longer
    counts toward service letter".
  - Time and location changes read as "Start time changed — → 09:00" and "Location changed A → B".
  - Suite dates are today or relative to today, and the expected summary comes from a PHP fixture, so the
    suite does not break at the new year.
