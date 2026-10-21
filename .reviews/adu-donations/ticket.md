# Ticket: Donations — real list, record, view and edit cash and non-cash gifts

Branch: `adu-donations`, off `origin/dev` (99c9b80) with `adu-UF2-49-donor-select` merged in (UF2-49, the
donor picker, committed earlier and never pushed; its own ticket and self-review are in
`.reviews/adu-UF2-49-donor-select/`). This branch replaces the separate UF2-42 draft
(`adu-UF2-42-donations-list`, never committed), whose list work is folded in here.

## Jira keys (all assigned to Joel Adu)

Requirement text quoted from Jira on 2026-10-21. None of these has a description except UF2-48.

| Key | Jira status | Summary (verbatim) |
|---|---|---|
| UF2-42 | Done | "As a Donor Coordinator i want to go to a page to see all donations given and have a option to sort and filter them so that i can find donations given to the organization" |
| UF2-43 | Done | "Card To hold the information of cash donations for the month" |
| UF2-44 | Done | "Card to hold the information of the the total non cash donations for the month" |
| UF2-45 | Done | "Card in the body to hold all donation information" |
| UF2-47 | Done | "New Donation Button" (the list's "+ New donation", gated here) |
| UF2-46 | In Progress | "As a Donor Coordinator I want to record Cash and Non-Cash Donation so that the donor's giving is tracked" |
| UF2-48 | In Progress | "Modify existing Cash donations table to make sure it has these fields needed to log a cash donation" |
| UF2-49 | In Progress | "The System Shall allow for the user to select a donor from a list of existing donors or create a new donor" |
| UF2-50 | Done | "The system shall have two options when making a new donation and the form will change if the user selects the cash or non-cash button" |
| UF2-51 | In Progress | "The System shall have a fillable form where the user will be able to enter information by hand" |
| UF2-52 | In Progress | "The System should change the page based on if the user changes the campaign selection" |
| UF2-53 | In Progress | "the system should change  the page based on the selection of the donor so the page will display information about the donor" |
| UF2-54 | In Progress | "The System Should Update the donations page within 5 Seconds of saving an edit on an existing donation" |
| UF2-55 | In Progress | "The system should update the donors profile who donated within 2 seconds of adding a new donation or existing  donation" |
| UF2-56 | In Progress | "The System should allow for the user to view individual donations with buttons to edit or close the donation" |
| UF2-57 | In Progress | "the System should allow for the user to edit the donation and save the changes they made" |
| UF2-99 | To Do | "Connect the Add Donor button" — description: "The Add Donor button is not connected yet. Hook it up so it opens the Add Donor page." |
| UF2-87 | To Do | "As a user when i click add cash donation or non cash  in the donor profile i want the donor to be autofilled and the cash or noncash to be selcted so that a new donation can be added without having to repeat these steps" |

UF2-42..45 are marked Done in Jira, but on `dev` `donations.php` is a static mock-up ("these are FAKE data").

UF2-48 description (verbatim, stars mark required): "First * / Last * / Address * / Number / City / State /
Zip / Email * / Phone* / If Donation is in Honor of (True or Fasle* / Name of person it is given on behalf
of / Net Amount* / Donation platforms (Mighty Casue, Benevity, InPerson)* / Type( Online Payment, Cash,
Check, Digtal Token, Stock)* / If donated as part of a campaign (True or False) * / name of the campaign /
Maybe Feilds: Check Number / Signed / Deposite date / Notes". Cory's comment on UF2-48: "the cash donation
columns from this ticket are already added in PR #12 ... Migration sql/migrations/002_donations_cash_fields.sql
adds to donations: donation_date ..., payment_method (cash / check / card / online / digital_token / stock /
other), check_number, is_signed, deposit_date, platform (in_person / mighty_cause / benevity / other),
in_honor_of, honoree_name, send_receipt, receipt_sent_at, recorded_by, and a co[mputed net_amount]".

Related requirement, not assigned here but the non-cash form must honour it — UF2-16 (verbatim): "The system
shall allow staff to manually enter non-cash donation records including donor name item description, item
category, quantity, estimated value, date received, and condition status. The system must require staff to
specify the intended use or benefiting program for each non-cash donation, and shall validate that all
required fields are completed before saving."

Confluence: searched all pages (2026-10-21). No page carries donation acceptance criteria for these keys;
"Group Acceptance Criteria" covers other stories, "Traceability Matrix" lists only UF2-58, "Sprint #1
Review" is empty, "Sprint Planning Meeting #1" says the Event page was "REPLACED WITH DONATIONS PAGE".
Joel's "Level 1 DFD" describes the donor profile reading `db.cash` / `db.noncash` (the real tables are
`donations` / `non_cash_donations`).

## Problem

On `dev` the donation pages are front-end only:

- `donations.php`: three tiles, eight rows and a 27-page pager typed into the HTML; search, range and pills
  do nothing server-side.
- `addDonation.php`: the form has `method="post"` but posts to itself and nothing reads the POST; campaigns
  are three hard-coded names with fake progress (`const CAMPAIGNS`); the date defaults to a hard-coded
  `2026-10-06`; non-cash "Intended use" is the campaign select, so the required UF2-16 field cannot be
  entered; payment types miss Digital token / Stock; platform, honoree, fee are absent.
- `viewDonation.php`: one hard-coded non-cash gift ("Main St. Church"), Edit links to a blank Add form.
- `donorProfile.php`: "+ Add cash gift" / "+ Add non-cash gift" link to `addDonation.php?donor_id=`, a
  parameter the form does not read, so neither donor nor type is pre-selected.

The schema is already in place (migrations 002 and 004); only the pages and the data layer are missing.

## Scope

### In

**Data layer — `database/dbDonations.php`**. All statements prepared with bound values. The legacy
`fetch_donations_for_event()` keeps its signature and callers but is parameterised (CLAUDE.md: "parameterise
any you touch").

Dates: "today", the month window and the 30/90/year windows are computed in PHP (`America/New_York`, set by
`include/fishnet-layout.php` / `dbinfo.php`) and bound as parameters; no new query uses `CURDATE()`, because
the Docker/CI database runs in UTC and would disagree with PHP between 8 pm and midnight Eastern. TIMESTAMP
columns (`created_at`, `updated_at`) are not shown on the view page (they come back in the DB session zone);
the page shows "Recorded by" only.

- List (UF2-42..45), from the UF2-42 draft: `donations_tiles()` — cash `SUM(amount)` with `donation_date`
  in the current calendar month; "Non-cash this month" = the number of non-cash gifts with `date_received`
  this month plus their summed `estimated_value` ("N gifts · est. $V"), not `SUM(quantity)`, because
  quantities are in mixed units (items / lbs / bags / boxes) and cannot be added; "Donations recorded" = row
  count of both tables, all time. Anonymous-donor gifts included (they are real gifts).
  `donations_list($kind, $q, $range, $sort, $page)`: `UNION ALL` of both tables; kind `all|cash|noncash`;
  range `30|90|year|all` (default 30; undated rows only under `all`); `q` LIKE on donor display name or
  campaign name, escaped with `donor_like_escape()`; sort `newest|oldest|amount` (amount = cash amount or
  non-cash estimated value; NULLs last); 10 per page; unknown values fall back; page clamped. Donor name
  from `donor_summary.display_name`, campaign from `dbevents.name`, "Recorded by" from `dbpersons`
  first name + last initial.
- `donation_campaigns($keepId = null)`: campaigns for the selects and the progress card — `dbevents` rows
  that are not finished, i.e. `NOT (COALESCE(completed, '') IN ('1', 'yes'))` (the column is nullable text
  holding `'0'`/`'1'` from the seed and addEvent, `'yes'`/`'no'` from the legacy archive helpers, or NULL),
  plus `$keepId` (the stored campaign on edit) even if finished; each with `id, name, goal (goalAmount),
  raised, gifts (non-cash gift count), item_value (non-cash estimated value)`, every aggregate a correlated
  subquery (`(SELECT COALESCE(SUM(amount),0) FROM donations WHERE eventID = e.id)` etc.), never a double
  LEFT JOIN. Ordered by name. (`dbevents` doubles as the campaigns table — see sql/README.md; UF2-96, a dedicated
  campaigns table, is James's and not done.)
- `donation_get($kind, $id)`: one gift as a form-shaped array (cash from `donations`, non-cash from
  `non_cash_donations`), with campaign name and recorder name, or null.
- `donation_form_from_post(array $post, $kind)` and `donation_form_validate(array &$form, $kind, ?array $stored)`:
  string-only reads (arrays become ''), returning a list of error messages. `$stored` is the row loaded by
  `donation_get()` on edit, null on add. Every optional field that is empty is bound as SQL NULL (campaign
  '' → NULL, never 0), reusing the `$nullable` approach of `donor_bind_values()`.
  - Number format for every money / quantity field: `^\d{1,10}(\.\d{1,2})?$` (no sign, exponent or thousands
    separator), so nothing is silently rounded by the DECIMAL columns.
  - "Kept as stored" rule (edit only, for the ~190 legacy CASA cash rows whose `payment_method`, `platform`
    and sometimes `amount` / `donation_date` are NULL, and five whose amount is 0.00): a field submitted
    exactly as stored (including empty for NULL, and 0.00) passes unchanged; a changed value must pass the
    normal rule; fee ≤ amount is checked only when one of them changed and both are present. On add every
    required field is required. Checkboxes (`in_honor_of`, `send_receipt`) always bind 0/1 and `unit` is
    required (preselected `items`), never NULL; dates must fall in 1900-2100 (the range `fish_parse_date`
    reads); the campaign must be one the form offers (`donation_campaigns($storedCampaign)`), so a finished
    campaign cannot be attached to a new gift.
  - common: `donor` a positive integer naming a row in `donors` that is active and not 999 — except on edit,
    where a donor equal to `$stored`'s donor id (from the database, never from a posted field) is accepted
    unchanged (so an anonymous or since-deactivated donor's gift can still be edited); `date_received` a real
    `Y-m-d` date, not after today (PHP date); campaign empty or an existing `dbevents.id`; notes ≤ 1000 chars.
  - cash (UF2-48): amount ≥ 0.01, number format; fee optional, number format, ≤ amount (net amount =
    `amount - fee`, the existing virtual column, shown read-only); payment type required, one of the seven
    `payment_method` enum values; platform required, one of the four `platform` values; check number (≤ 32),
    signed (yes/no) and deposit date (valid date, optional) kept only when payment type is check and cleared
    otherwise; "In honor of" checkbox → `in_honor_of`, honoree name required when checked (≤ 255) and cleared
    when not; notes → `reason` (the legacy free-text column the profile already shows); send receipt →
    `send_receipt`.
  - non-cash (UF2-16): item description required ≤ 255; category required, one of the seven enum values;
    quantity required ≥ 0.01, number format, ≤ 99,999,999.99, and on edit ≥ the gift's
    `SUM(non_cash_distributions.quantity)` so remaining stock never goes negative; unit one of
    `items|lbs|bags|boxes`; estimated value optional, number format; condition optional, one of
    `new|gently_used|used`; intended use required ≤ 255; notes → `notes`; send receipt → `send_receipt`.
- `donation_create($kind, array $form, $byUser)` → new id or false; sets `recorded_by` to the signed-in
  user id, cash `thanked = 0`. `donation_update($kind, $id, array $form)` → bool; never changes
  `recorded_by`, `created_at`, `receipt_sent_at`, `thanked`. Both catch `mysqli_sql_exception`, log, return
  false (pattern of `donor_create()` / `donor_update()`). The kind of an existing gift cannot change (the
  two kinds live in different tables): on edit, kind and id come only from validated `$_GET`, the gift is
  loaded with `donation_get()` before validation, and any posted `kind` is ignored.

**Pages**

- `donations.php` (UF2-42..45): the UF2-42 rewrite (real tiles, filters, sort, pager, empty state, View /
  Edit links carrying `?kind=&id=`, Edit and "+ New donation" only with `fish_can('donations','edit')`, no
  data without `donations` view). `pageUrl()` drops any `saved` parameter.
- `addDonation.php` (UF2-46, 48, 49, 50, 51, 52, 53, 87, and edit for UF2-57): one page for add and edit.
  - GET `?donor=<id>&kind=cash|noncash` pre-selects an active non-anonymous donor and the type (UF2-87);
    `donor_id` is accepted as an alias so old links keep working. `?kind=&id=` opens an existing gift for
    editing with every field filled, title "Edit donation", type fixed.
  - Needs `fish_can('donations','view')` AND `fish_can('donations','edit')` AND `fish_can('donors','view')`
    (edit without view must not read gifts through the edit form); otherwise a notice and no form or gift
    values, and a POST is refused with no write. "+ New donation", Edit and the profile/dashboard add links
    use the same condition.
  - Only the selected kind's fieldset is submitted: on edit only the stored kind's fieldset is rendered; on add
    the unselected one is rendered `disabled` by the server, so a POST without JavaScript cannot mix fields.
  - POST: `auth_csrf_field()` (already in the form; `require_authentication()` verifies it), read the form,
    validate; on errors re-render with the messages and the values entered (no write), with the chosen donor
    rebuilt server-side from the posted donor id (or the stored donor on edit); on success 303-redirect (PRG):
    - "Save donation" (add or edit) → `viewDonation.php?kind=<kind>&id=<id>&saved=1`, which always shows the
      saved row with a "Donation saved." notice, whatever its date (UF2-54: the donations list and this page
      read the tables on every request, so the change is on the very next response);
    - when the form was opened from a donor profile (`from=profile`, kept as a hidden field and carried
      through re-renders and "add another") → `donorProfile.php?id=<donor>&gift=1` (UF2-55);
    - "Save & add another" (add only) → `addDonation.php?donor=<id>&kind=<kind>&saved=1` (plus `from=profile`
      when set), with a "Donation saved." notice.
  - Date received defaults to today (PHP date, America/New_York) on add only; on edit it shows the stored
    date or stays empty when the stored date is NULL.
  - Every required select (payment type, platform, category) starts with an empty "Choose…" option and has
    no preselected value on add, so a missing choice fails validation instead of submitting the first option;
    optional selects (signed, condition, unit default `items`) likewise start empty or with a neutral value.
  - Campaign selects list `donation_campaigns()`; "No campaign" is the first option. Non-cash gets its own
    required "Intended use / program" text field (UF2-16) separate from the campaign select.
  - Cash form adds Fee and a read-only Net amount, Platform, "In honor of" + honoree name, and the
    Digital token / Stock / Other payment types (UF2-48).
  - Edit mode: title "Edit donation", the donor step starts with the stored donor chosen, the type step shows
    only the stored kind (rendered as text plus a hidden marker for the JS, no radio to change it), and the
    server never reads kind from POST.
  - `CAMPAIGNS` and `CHOSEN_DONOR` are embedded with `json_encode(..., JSON_HEX_TAG | JSON_HEX_AMP |
    JSON_HEX_APOS | JSON_HEX_QUOT)`, and on edit the page also embeds `STORED = {campaign, amount}` (the
    stored campaign id and the stored cash amount or non-cash estimated value).
- `js/addDonation.js` (UF2-50, 52, 53): on load it reads its state from the DOM (checked or fixed kind,
  current payment type, honoree checkbox, campaign selects), so a server-preselected type (UF2-87), an
  error re-render and edit mode all show the right fields. The campaign card uses the real `CAMPAIGNS` JSON
  (`{id: {name, goal, raised, gifts, item_value}}`), written with `textContent` only: cash shows
  "$raised of $goal after this gift" with a bar when goal > 0, or "$raised raised so far" when no goal;
  non-cash shows "N non-cash gifts, est. $V, after this gift" (no quantity sum, units differ). On edit, when
  the selected campaign is the stored one, the stored value is subtracted first so the gift is not counted
  twice. The card hides when "No campaign" is chosen. Check-only fields hide unless payment type is Check;
  honoree field shows only when "In honor of" is ticked; net amount updates as amount / fee change.
- `viewDonation.php` (UF2-56): real read-only page for `?kind=&id=`: type, every stored field (NULL shown
  as "Not recorded", never $0.00, a default label or 1970-01-01), campaign card with real progress,
  "Recorded by". The donor card's figures (town, donor since), the side panel (lifetime cash, gifts, last
  gift, hours) and the "View donor" link show only with `fish_can('donors','view')`; without it the page
  shows the donor's display name only. Buttons: "Close" → `donations.php`; "Edit donation" →
  `addDonation.php?kind=&id=` only with donations edit. Unknown or malformed `kind`/`id` → 303 to
  `donations.php`. Needs `fish_can('donations','view')`, else notice and no data. "Donation saved." notice
  on `?saved=1`.
- `donorProfile.php` (UF2-87, UF2-55): the two add buttons link to
  `addDonation.php?donor=<id>&kind=cash|noncash&from=profile` and are shown only when the user can edit
  donations and the donor is active and not anonymous; a "Donation saved." notice on `?gift=1`. Each cash and
  non-cash row gets a "View" link to `viewDonation.php`. The Cash and Non-cash tabs (rows, totals, View
  links, add buttons) render only with `fish_can('donations','view')`, else a one-line notice in each tab, so
  removing donations view hides gift rows everywhere (today the profile shows them to any signed-in user).
  The donor header tiles (lifetime cash, items, last gift) stay as they are: they are donor-summary figures
  already shown on `donor.php`. The profile reads `donor_summary` and the history tables on every request
  (no caching), so a new or edited gift appears on the next load (UF2-55).
- `dashboard.php` (UF2-99): "+Add Donor" links to `addDonor.php` (the working form, `donors` edit) instead of
  `newDonor.php` (a static copy that saves nothing); shown only with `fish_can('donors','edit')`, and
  "+Add donation" only with `fish_can('donations','edit')`. `newDonor.php` itself is left in place (still
  listed in `tests/auth_integration.py`), now unlinked. The rest of the dashboard (fake tiles) is out of scope.
- `sql/seed/demo_fish.sql` (seed, not a migration): before deleting the `[demo]` campaigns, set
  `donations.eventID = NULL` on any non-demo cash gift that points at one (`donations_ibfk_1` restricts the
  delete), so `make seed-demo` keeps working after someone records a gift against a demo campaign.
- `css/donation.css`, `css/donor.css`, `css/fishnet.css`: only what the above needs (the UF2-42 pill-rule
  removal, `.content { min-width: 0 }`, error list and field-error styles).

**Tests**

- New `tests/donations_integration.py` (pattern of `tests/donors_integration.py`, reusing its `Browser` /
  fixture approach; creates and deletes its own donor, campaigns and users, never relies on demo rows for
  assertions; teardown in a `finally`/cleanup deletes gifts, then campaigns, then the donor, then users):
  list tiles and filters against SQL with PHP-computed date bounds; add cash and non-cash (including a
  backdated gift and minimal gifts with every optional field empty); validation errors; edit of a recent
  gift, of an old gift and of a legacy-shaped row (NULL payment_method / platform / donation_date) that saves
  without inventing values; non-cash edit below the distributed quantity refused; edit-mode POST with a
  mismatched `kind` and `donor=999` ignored / refused; view page incl. "Not recorded"; permission gates
  (viewer cannot write; donations view removed hides list, view page and profile gift tabs; donors view
  removed hides the donor figures on the view page); CSRF; XSS with a donor named
  `<img src=x onerror=alert(1)>` and a campaign named `</script><img src=x onerror=alert(1)>`; the embedded
  `CHOSEN_DONOR` / `CAMPAIGNS` / checked-kind markup for AC6/AC7 (values against SQL); campaign finished
  filter (`'1'`, `'yes'`, NULL); campaign totals for a campaign with both kinds of gift; the dashboard links
  (UF2-99); junk parameters. It prints the elapsed time from POST to the redirected page (and to the next
  `donations.php` / `donorProfile.php` GET) and asserts the Jira limits (5 s for UF2-54, 2 s for UF2-55) when
  `DONATIONS_TIMING_STRICT=1`, copying the `USERS_TIMING_STRICT` pattern. `make test-donations` target
  (`.PHONY`, `help`, passes `DONATIONS_TIMING_STRICT` through); added to `.github/workflows/ci.yml` next to
  `make test-donors`.
- Docs: `README.md` (CI target list, the test-suite paragraph, the permissions paragraph gains the donations
  gate: viewer view only, staff/manager/admin add and edit) and `docs/process/change-review-process.md` (the
  list of targets the `tests` job runs). `CLAUDE.md` is not edited by this branch; its test list is left for
  the team to update. `sql/README.md` gains one line: `dbevents.completed` of `'1'`/`'yes'` hides a campaign
  from the donation form.
- `tests/auth_integration.py`: no new entry points (edit lives in `addDonation.php`). It must list
  `donorLookup.php` (added by UF2-49, which predates `test_every_entry_point_is_listed` on dev; that test
  fails on this branch today) under `PROTECTED_APIS`, since it answers JSON 401 via
  `require_authentication(1, true)`.

### Out

- New migrations or schema changes (the columns exist since 002/004).
- The rest of the dashboard (still fake figures) and the Campaigns pages.
- Deleting donations; non-cash distributions (UF2-18); actually emailing receipts (only `send_receipt` is
  stored, as now); recording a new gift for the anonymous donor 999 from this form; changing a gift's kind;
  a dedicated campaigns table (UF2-96); the Campaigns pages (still fake, James's UF2-93..96); UF2-98 (James,
  the dashboard button already links to `addDonation.php`; this branch only gates it).
- The legacy CASA pages `donationAddEdit.php`, `addDonationHandler.php`, `editDonationHandler.php`
  (untouched; their pre-existing problems are out of scope).
- UF2-48's donor fields (First, Last, Address, Email, Phone): they belong to the donor record, which the
  donor step selects or creates (UF2-49 / `addDonor.php`); they are not copied onto the gift.

## Acceptance criteria

1. Tiles: "Cash this month" = SUM(amount) of `donations` whose `donation_date` falls in the current calendar
   month (New York time); "Non-cash this month" = count and SUM(estimated_value) of `non_cash_donations`
   received this month; "Donations recorded" = rows in both tables. Each matches SQL run by hand with the same
   PHP-computed bounds.
2. List default: last 30 days, both kinds, newest first, 10 per page; "Showing x–y of N" matches SQL.
   Cash / Non-cash pills, range, sort and search each change the rows and survive paging; Enter in search
   keeps the current pill; paging never repeats a "saved" notice.
3. Adding a cash gift with valid fields (any date up to today, including months back) inserts one
   `donations` row with every entered value (amount, fee, payment_method, platform, donation_date, check
   fields only for checks, in_honor_of/honoree_name, eventID, reason, send_receipt, recorded_by = the
   signed-in user id) and redirects to `viewDonation.php` for that row, which shows those values; empty
   optional fields are stored as NULL.
4. Adding a non-cash gift inserts one `non_cash_donations` row with every entered value including
   `intended_use`, and redirects to its view page, which shows them.
5. Missing or invalid required fields (each rule above, including a required select left on "Choose…",
   over-precise numbers and quantity below the distributed amount on edit) re-render the form with a
   message per problem, the entered values and the chosen donor, and write nothing.
6. From a donor profile, "+ Add cash gift" / "+ Add non-cash gift" open the form with that donor chosen
   (server-embedded `CHOSEN_DONOR`), the side panel filled and the matching type checked with its details
   shown; saving returns to that profile, which shows the new gift and updated tiles on that response.
7. Choosing a campaign shows its real goal and raised totals (cash) or gift count and estimated value
   (non-cash) from `CAMPAIGNS`, which match SQL; changing it updates the card; "No campaign" hides it; on edit
   the stored gift is not counted twice. Finished campaigns (`'1'`, `'yes'`) are not offered except the one
   stored on the gift being edited. Choosing a donor fills the side panel with that donor's real figures.
8. `viewDonation.php?kind=cash|noncash&id=N` shows that gift's stored values (NULLs as "Not recorded"); Close
   returns to the list; Edit (only with donations edit) opens the form filled with the same values.
9. Editing and saving updates that row only (other rows and `recorded_by` unchanged) and the page loaded by
   the redirect shows the new values; a legacy row with NULL payment method / platform / date can have its
   notes edited without those fields being filled in; a POST in edit mode cannot change the kind or move the
   gift to donor 999.
10. Permissions: a `viewer` (donations view only) sees the list and the view page but no "+ New donation",
    Edit or add buttons, and a POST to `addDonation.php` writes nothing; with `donations` view removed the
    list, view page and donor-profile gift tabs show a notice and no gift rows; with `donors` view removed the
    view page shows the donor name only. No CSRF token → no write.
11. A donor named `<img src=x onerror=alert(1)>` and a campaign named `</script><img src=x onerror=alert(1)>`
    render as text on every page in scope and do not break the embedded JSON.
12. Junk parameters (`kind=x`, `id=abc`, `range[]=1`, `page=-5`, `page=999`, `donor[]=1`, `amount[]=1`)
    give the default view, a redirect or a validation message — never a PHP warning or a write.
13. UF2-54 / UF2-55 timing: POST to the redirected page, and the next GET of `donations.php` /
    `donorProfile.php` showing the change, each well under 5 s / 2 s; asserted when
    `DONATIONS_TIMING_STRICT=1`.
14. UF2-99: the dashboard "+Add Donor" opens `addDonor.php` (shown only with donors edit); "+Add donation"
    only with donations edit.

## Verification plan

No Docker on this machine. Same approach as UF2-49:

1. `php -l` on every changed PHP file; `node --check js/addDonation.js`.
2. Scratch MariaDB (XAMPP 10.4 binaries, utf8mb4 server defaults, strict mode) loaded with `sql/casadb.sql`,
   migrations 001-008 and `sql/seed/demo_fish.sql`; app served by `php -S`; run
   `tests/donations_integration.py`, `tests/donors_integration.py`, `tests/users_integration.py` and
   `tests/auth_integration.py` against it through a local runner that points `BASE_URL` at the scratch
   server (no repo change). Known local-only failures: `auth_integration`'s direct-file-URL checks rely on
   Apache rules (`docker/apache.conf`) that `php -S` does not apply. Also run the time-zone case by giving the
   scratch DB a UTC session (`SET GLOBAL time_zone = '+00:00'`) and checking the tiles still follow PHP's
   date. Re-run `make seed-demo`'s SQL after recording a gift against a demo campaign.
3. Headless Chrome pass for the JS (criteria 5, 6, 7 in a real browser), recorded as manual in the PR.
4. Then the author's XAMPP copy (`/Applications/XAMPP/htdocs/fauquier-fish-drms`) on this branch.
5. CI (`tests` job) runs `make test-auth`, `make test-donors`, `make test-users`, `make test-donations` on
   the PR against MariaDB 10.11.

## Post-merge

No migration and no session change: teammates need no `make migrate` or re-login. `make seed-demo` before
running the test targets, as before.

## Risks

- `donor_summary` is a view with correlated subqueries, joined per listed row on the list page; fine for
  hundreds of rows.
- Many legacy cash rows have no `donation_date`; they only appear under "All time".
- `dbevents` holds old CASA test events (`test`, `test2`); they appear in the campaign select until UF2-96
  replaces it.
- The insert relies on the 002 triggers to fill the legacy `date` text column from `donation_date`.
- Local verification is on MariaDB 10.4 (XAMPP), the project targets 10.11; CI covers 10.11.
- Pre-existing, out of scope: the legacy CASA donation handlers write to `donations` gated only by the
  numeric access level, not by `fish_can('donations', ...)`, and build SQL from input; the new permission
  tests cover only the FISH pages. Follow-up: retire or gate those handlers.
- `donor_summary.items_donated` (donor tiles, side panel) still sums quantities across units; unchanged here.
- Pre-existing, out of scope: `donor.php` and `donorProfile.php` do not check donors view; this branch only
  stops the donation pages from linking to them without it.

## Responses to round 1

Round 1 approved (4/4 auditors, 0 critical); 3 high, 13 medium, 16 low. Every finding is addressed in the
revised scope above unless marked accepted.

Security
- (medium) Donations-view gate bypassable through donorProfile.php; view page shows donor figures without
  donors view — fixed: profile gift tabs gated on donations view, view-page donor figures on donors view, AC10.
- (low) Edit-mode kind/id/stored donor must come from URL and DB — fixed: stated in data layer and edit mode, AC9, test.
- (low) CAMPAIGNS JSON embedding — fixed: JSON_HEX_* flags, textContent only, `</script>` campaign in AC11.
- (low) Legacy handlers bypass fish_can (out of scope) — accepted: recorded in Risks as a follow-up.

Data and schema
- (medium) PHP vs DB time zone — fixed: bounds computed in PHP and bound; TIMESTAMPs not shown; UTC check in verification.
- (medium) Edit rules force fabricated data on legacy rows — fixed: "kept as stored" rule, test, "Not recorded".
- (medium) Silent decimal rounding — fixed: one number format for all money/quantity fields, minimum 0.01.
- (low) `completed` values — fixed: `NOT (COALESCE(completed,'') IN ('1','yes'))`, tested.
- (low) Campaign totals fan-out — fixed: correlated subqueries, test with both kinds.
- (low) '' vs NULL — fixed: optional fields bound as NULL, campaign never 0, minimal-gift tests.
- (low) seed-demo FK / test cleanup order — fixed: seed nulls `eventID` first; teardown order stated.
- (low) Unit-mixed sums — fixed: tile and campaign card use gift count and estimated value.

Correctness and architecture
- (high) Redirect does not show the gift — fixed: redirect to `viewDonation.php?…&saved=1`; AC3/4/9 reworded.
- (high) Legacy rows and selects without a blank option — fixed: "Choose…" options, today only on add, kept-as-stored rule.
- (medium) JS ignores server state — fixed: JS initialises from the DOM; CHOSEN_DONOR rebuilt on re-render/edit.
- (medium) Campaign card double count on edit — fixed: `STORED` subtracted.
- (medium) Non-cash quantity vs distributions — fixed: quantity ≥ distributed on edit.
- (medium) Permission gates disagree — fixed (same as the security medium).
- (low) `completed` filter, time zone, units — fixed as above.
- (low) pageUrl carries saved — fixed: dropped, and the saved notice moved to the view page.
- (low) Legacy helper interpolated — fixed: `fetch_donations_for_event()` parameterised.

Verification and product
- (high) Redirect to list — fixed (as above).
- (medium) No timing AC/test — fixed: AC13, `DONATIONS_TIMING_STRICT`.
- (medium) Docs — fixed: Makefile, README, change-review-process.md, sql/README.md. CLAUDE.md accepted as not
  edited by this branch: it is the AI-instructions file, left for a human to change.
- (medium) seed-demo FK — fixed (as above).
- (medium) Unit-mixed tile — fixed (as above).
- (medium) Time zone — fixed (as above).
- (low) `completed` values — fixed. (low) JS criteria only manual — fixed: server-side JSON/markup asserted in
  the suite, browser pass recorded as manual. (low) Small details — fixed: pageUrl, `from=profile` carried
  through, kind from GET on edit, UF2-47 added to the table, post-merge note added.

Scope change after round 1, at Joel's request: UF2-99 (dashboard "+Add Donor") added, so every open Jira
ticket assigned to Joel is covered.

## Responses to round 2

Round 2 approved (4/4 auditors, 0 critical); 1 high, 5 medium, 11 low. Implementation started after round 1
had approved the plan (the gate is "approved = no confirmed critical"); round 2 was run to check the
round-1 fixes, and its findings are applied to both this ticket and the code.

Security
- (high) Edit form readable without donations view — fixed: add/edit needs donations view + edit + donors
  view; same condition for every add/edit link; test with edit=1/view=0.
- (medium) Donors-view gate cosmetic — fixed in scope: donor names in the list link to the profile only with
  donors view, and the view page likewise. Accepted as a follow-up (Risks): `donor.php` / `donorProfile.php`
  themselves have no donors-view gate today (pre-existing; gating the whole donor area is a separate change
  for the donor pages' owners).

Data and schema
- (low) NOT NULL columns — fixed: checkboxes bind 0/1, unit required. (low) Date lower bound — fixed: 1900-2100.
  (low) Finished campaigns accepted by the server — fixed: validated against `donation_campaigns()`.

Correctness and architecture
- (medium) Edit without view — fixed (as above). (medium) 0.00 legacy amounts — fixed: unchanged-value rule,
  test. (low) Finished campaigns — fixed. (low) Donors-view gate — fixed/accepted as above. (low) Profile
  shows NULL amounts as $0.00 — fixed: `donor_cash_history()` returns the raw amount, the tab shows an em
  dash and the average ignores NULLs. (low) Implementation before approval — see the note above.

Verification and product
- (medium) 0.00 legacy amounts — fixed (as above). (medium) Implementation timing — see the note above.
- (low) Profile tab-bar counts — fixed: hidden without donations view, asserted. (low) Shared field names
  without JS — fixed: server-side disabled / single fieldset. (low) Legacy event pages — fixed: the suite
  does a signed-in GET of `date.php`, `specificEvent.php` and `viewAllEvents.php` and checks for PHP errors.
  (low) Fixture names / seed — fixed: fixture users get first and last names and "Recorded by" is asserted;
  README says `make test-donations` needs `make seed-demo` like the other suites (for the users table rows it
  reads), though it creates its own donors, campaigns and gifts.
