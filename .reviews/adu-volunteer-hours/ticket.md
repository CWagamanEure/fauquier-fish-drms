# Volunteer hours: log, view, edit and delete entries from the donor profile

Branch `adu-volunteer-hours`, off `origin/dev` (3088ba6) with PR #26 (`TCDvolnteerhtml`, Tristan
Chavez-Duran's front end for `addHours.php` / `hours.php`) merged in. This branch turns those mock-up pages
into working pages; PR #26's commits ride along and GitHub marks #26 merged when this PR merges.

## Jira keys

All five are assigned to Joel Adu, reported by Tristan Chavez-Duran, with no further description than the
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

PR #26's own comments name the backend work: "handle the POST (check fish_can('donors', 'edit'), validate,
write volunteer_hours), load the entry when ?id= is given, and fill $volunteers / $jobs / $campaigns /
$summary / $history from the database."

## Problem

- The Volunteer tab on `donorProfile.php` already reads `volunteer_hours` (migration 005) through
  `donor_hours_history()`, but rows link nowhere and "+ Log hours" shows for every account.
- `addHours.php` and `hours.php` (PR #26) are identical mock-ups with hard-coded data. Nothing saves,
  `hours.php` ignores `?id=`, and the donor is whatever `?donor_id=` says (defaulting to 1).
- The mock-up form has fields `volunteer_hours` lacks: start time, end time, location, "Hours verified by
  coordinator", "Counts toward service-hour letter", a change history, and "Same shift for more people".

## Scope

In:
1. Migration `010_volunteer_hours_details.sql` (009 is taken by open PR #28). Additive and idempotent:
   - `volunteer_hours` gains `start_time TIME NULL`, `end_time TIME NULL`, `location VARCHAR(255) NULL`,
     `is_verified TINYINT(1) NOT NULL DEFAULT 0` and `counts_for_letter TINYINT(1) NOT NULL DEFAULT 0`.
   - New table `volunteer_hours_changes`: `id`, `volunteer_hours_id` (FK, ON DELETE CASCADE), `changed_at`,
     `changed_by` (FK `dbpersons`, ON DELETE SET NULL) and `summary VARCHAR(1000)`.
   - Nothing reads `volunteer_hours` columns by position, and `donor_summary` only sums `hours` and counts
     rows, so the view is not recreated.
2. `database/dbVolunteerHours.php` holds the data and form layer:
   - Reads: get one entry with donor name, recorder name and campaign name; the change history; the side
     summary (hours and shifts this calendar year, last shift, most frequent job); the job options.
   - Form: blank, from row, from post, validate.
   - Writes: create (one row per volunteer, in one transaction), update (writes a change summary row in the
     same transaction, or nothing when no field changed), delete.
   - Every statement is prepared. It reuses `donation_valid_date()`, `donation_today()`,
     `donation_campaigns()` and `donation_recorder_name()` from `database/dbDonations.php`, and `donor_get()`
     from `database/dbDonors.php`.
3. `addHours.php?donor_id=N` (UF2-117/118) logs new hours:
   - The donor comes from `donor_id`, from GET or the POST's hidden field. It must exist, be active and
     not be anonymous (id 999); otherwise the page redirects to `donor.php`.
   - The form has date, start and end time, hours, job, campaign, location, notes, and "Same shift for
     more people", which adds extra volunteers through `donorLookup.php`.
   - Save writes one row per volunteer with the same values, records the user in `recorded_by`, and
     redirects (303) to `donorProfile.php?id=N&tab=vol&hours=<count>`.
   - Errors re-render the form with what was typed. The side panel shows the real summary.
4. `hours.php?id=N` (UF2-119/120) opens one entry:
   - Everything is loaded from the row: the donor comes from the stored row, never from the request, and
     an unknown id redirects to `donor.php`.
   - It has the same fields as add, minus extra volunteers, plus the two status checkboxes.
   - Save changes updates the row, writes the change line, and redirects to
     `hours.php?id=N&saved=1` (the user sees the saved entry). The Change history panel lists "Entry
     logged" (from `created_at` and `recorded_by`) and each change row, newest first, with who and when.
   - "Delete entry" is a POST with `action=delete`. It needs `fish_can('donors', 'delete')`, asks for
     confirmation in the browser, and redirects to `donorProfile.php?id=N&tab=vol&hours_deleted=1`.
   - Without `donors edit` the page shows the entry read-only, with fields disabled and no Save or Delete.
     This is how a viewer views an entry (UF2-119).
5. `donorProfile.php` (UF2-116/117/119):
   - "+ Log hours" appears only with `donors edit`, an active, non-anonymous donor, and no load error.
   - Each Volunteer row gets an "Edit" link to `hours.php?id=` (or "View" without `donors edit`).
   - `?tab=vol` opens the Volunteer tab, and `hours=<n>` / `hours_deleted=1` show a notice.
6. `js/volunteerHours.js`:
   - Hours fill in from start and end time until the user types an hours value.
   - Extra-volunteer chips are found with `donorLookup.php`, built with `textContent` only, and each adds a
     hidden `extra_donor[]` input.
   - The delete confirmation.
7. Tests and docs:
   - New suite `tests/volunteer_hours_integration.py`, with `make test-hours` and a CI step.
   - `addHours.php` and `hours.php` are added to `PROTECTED_PAGES` in `tests/auth_integration.py`.
   - Updates to `README.md`, `sql/README.md` and the comment at the top of `css/volunteer.css`.

Out:
- Any `volunteers` permission area. The `users` enum has none, so donor permissions govern volunteer
  records, as PR #26's comment says.
- Overnight shifts (end earlier than start), and a cross-donor list or report of hours.
- Setting `donors.is_volunteer` automatically when hours are logged.
- The other open PRs' work (#27, #28).
- The pre-existing missing `donors view` gate on `donorProfile.php` itself. It is unchanged here, but the
  new pages gate on it.

## Approach

### Permissions

| Action | Requires |
|---|---|
| See a page's data (summary, entry, campaigns) | `fish_can('donors', 'view')`; otherwise a notice and no data |
| Show the add form and accept add or edit POSTs | `fish_can('donors', 'edit')`; a POST without it gets 403 and writes nothing |
| Delete | `fish_can('donors', 'delete')`; staff has edit but not delete |

- With the default presets, admin and manager can do all three, staff can log and edit, and viewer can
  only view.
- Every POST carries `auth_csrf_field()`, which `include/fishnet-layout.php` already verifies.

### Validation (`hours_form_validate`)

- **Date:** required, valid by `donation_valid_date()`, and not after `donation_today()`.
- **Times:** optional, `HH:MM` (seconds are accepted and dropped). When both are given, end must be after
  start.
- **Hours:**
  - When empty and both times are given, hours are taken from the times, rounded to 0.01.
  - Otherwise hours are required, must match `^\d{1,3}(\.\d{1,2})?$`, and must be > 0 and ≤ 24.
  - When both times are given, hours must be no more than the time span. A break makes them shorter,
    never longer.
- **Job:** empty or one of the options. The options are the PR #26 list plus every distinct non-empty job
  already stored, so seeded and legacy values such as "Client intake desk" stay selectable. On edit, the
  stored value is always accepted.
- **Campaign:** empty or a key of `donation_campaigns($storedCampaign)`, so open campaigns plus the
  entry's own.
- **Location:** at most 255 characters. **Notes:** at most 2000.
- **Extra volunteers (add only):**
  - Integer ids, de-duplicated, excluding the main donor, at most 20.
  - Each must be active and non-anonymous. A bad one is an error naming that it could not be added, and
    nothing is written.
- Checkbox values are only read on edit; on add they are 0.

### Change summary on edit

- Each changed field produces one clause, joined by "; ". Examples: "Hours changed 4 → 4.5",
  "Date changed Oct 22, 2026 → Oct 23, 2026", "Job changed Pantry sorting → Office help",
  "Campaign changed None → Fall Food Drive 2026", "Marked verified", "Notes edited".
- The summary is stored as text and rendered with `h()`.
- When nothing changed, the update is skipped, no change row is written, and the user still lands on the
  saved page.

### Side summary

- One query over `volunteer_hours` for the donor: the hours and row count this calendar year, the latest
  date, and the most frequent non-empty job (ties go to the most recent use).
- The year window is computed in PHP and bound as parameters, as in the donations page.
- `addHours.php` and `hours.php` share one rendering include, `include/hoursForm.php`. Each page sets its
  mode and loads its own data; the include holds only markup, so the mock-up layout from PR #26 is kept.

## Acceptance criteria

1. **Log hours (UF2-118).** Staff logs hours from `donorProfile.php?id=N` via "+ Log hours" (UF2-117):
   - The form opens with the donor named and today's date.
   - Saving writes exactly one `volunteer_hours` row: donor N, the date, hours, job, campaign, location,
     notes and times, `recorded_by` = the staff id, both flags 0.
   - The user lands back on the profile's Volunteer tab, which shows the new row and the notice "Hours saved."
2. **Hours from times.** With start 09:00, end 13:30 and hours left empty, the row is saved with 4.50.
   With hours 5 for that span, it is refused.
3. **Rejected input writes nothing and keeps the form.** Each of these is refused with a message, the
   form re-renders with the typed values, and the row count is unchanged:
   - missing date, future date, date "2026-02-30"
   - hours 0, 25, "abc" or 1.234
   - end before start, or times "25:00"
   - a job outside the options, an unknown or finished campaign id
   - location over 255 characters
   - an extra volunteer that is inactive or anonymous
4. **Same shift for more people.** Adding two extra volunteers writes three rows with identical values
   and redirects with `hours=3`. A duplicate id or the main donor listed again is ignored.
5. **Bad donor.** A missing, unknown, anonymous (999) or inactive `donor_id` on `addHours.php` redirects
   to `donor.php`, for GET and POST alike, and writes nothing.
6. **View an entry (UF2-119).** `hours.php?id=X` shows the stored values, the donor's name and real
   summary, and a Change history starting with "Entry logged" by the recorder. An unknown or non-numeric
   id redirects to `donor.php`.
7. **Edit an entry (UF2-120).**
   - Changing hours and job on an older entry updates only that row and writes one change row reading
     "Hours changed 4 → 4.5; Job changed …", which the history shows.
   - The donor stays the stored donor even if the POST carries another `donor_id`.
   - A legacy job not in the list is kept when the entry is saved unchanged, and saving unchanged writes no
     change row.
   - The two status checkboxes save.
8. **Delete.** A manager deletes an entry: the row and its change rows are gone, and the profile shows
   "Hours entry deleted." Staff sees no Delete panel, and its delete POST gets 403 with the row kept.
9. **Volunteer tab (UF2-116).** The tab lists the donor's rows newest first with real totals. Each row links
   to `hours.php?id=`, labelled Edit (staff) or View (viewer).
10. **Permissions.**
    - Viewer: no "+ Log hours", `hours.php` read-only with no Save or Delete, `addHours.php` shows the
      cannot-log notice, and both pages' POSTs get 403.
    - An account without `donors view` sees a notice and no entry data on either page.
    - Anonymous requests redirect to `/login.php`, and POSTs without the form token are refused.
11. **Escaping.** Donor names, jobs, notes, locations and change summaries containing `<img onerror>`
    render escaped on all three pages.
12. Existing suites still pass, and `php -l` is clean on every changed file.

## Verification plan

- Migrations 001–010 are applied to a scratch MariaDB, then re-applied to confirm they are idempotent,
  then `make seed-demo` (via `scripts/seed-demo.sh`).
- The full suite runs against `php -S` with a `BASE_URL` override:
  - `tests/auth_integration.py`. Direct-file-URL checks need the Apache rules; that is noted as an
    environment-only failure, as last time.
  - `donors_integration.py`, `users_integration.py`, `donations_integration.py` and the new
    `volunteer_hours_integration.py`.
  - `make test-gate`, and `php -l` on all changed PHP.
- XAMPP on the user's machine: apply migration 010 (back up `fishdb` first), sync the htdocs copy to this
  branch, and walk through the Log hours → profile → Edit → history → delete flow in a browser.
- CI `tests` must be green on the PR before it is merged to `dev`.

## Risks

- PR #28 also edits `donorProfile.php`, and whichever merges second resolves the conflict. This branch keeps
  its profile changes to the Volunteer tab block and the notice lines.
- Two migrations numbered 009 and 010 merging in either order is fine, because `db-migrate.sh` records
  filenames, not a sequence.
- The extra-volunteer search shows active donors only, which `donorLookup.php` already enforces. It needs
  `donors view`, which every account that can log hours has.
- `donation_campaigns()` lives in `dbDonations.php`, so the hours pages load that file. That is acceptable
  until a campaigns data layer exists (PR #28 adds `dbCampaigns.php`; switching to it is a later cleanup).
