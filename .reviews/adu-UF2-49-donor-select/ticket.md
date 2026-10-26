# Ticket: choose an existing donor or create a new one on the Add donation form

Branch: `adu-UF2-49-donor-select`, off `origin/dev` with `origin/TCD-FISHNET-donations` (Tristan, PR #10,
open) merged in, because `addDonation.php` and `js/addDonation.js` only exist on that branch. Until PR #10
merges, this branch's diff against `dev` includes Tristan's six files; the PR description says so.

Jira: UF2-49 (Joel, In Progress). Requirement text, verbatim (the ticket has no description):

- UF2-49: "The System Shall allow for the user to select a donor from a list of existing donors or create a
  new donor"

Related, not done here: UF2-46 "As a Donor Coordinator I want to record Cash and Non-Cash Donation so that
the donor's giving is tracked" (saving the form), UF2-53 "the system should change the page based on the
selection of the donor so the page will display information about the donor" (this ticket fills the
side panel with real figures as a by-product; UF2-53 stays open for anything more), UF2-12 "search
profiles by name" (donor.php search, already on dev).

No Confluence page was consulted for this ticket.

## Problem

Step 1 of `addDonation.php` ("Donor") searches a hard-coded `DONORS` object of four fake donors in the
page source (the comment says "for test we need to type jhon"). Nothing reads the `donors` table, so a
coordinator cannot pick a real donor. The "+Add" link opens `newDonor.php`, a static copy of the donor form
that saves nothing, while the working form (`addDonor.php` on dev) has a "Save & add donation" button
whose own comment says it "will point at the donation form once it exists on dev". The search results
are also built with `innerHTML` from donor names, which would be an XSS hole once names come from the
database.

## Scope

In:
- `database/dbDonors.php`: `donors_lookup($q, $limit)` — active, non-anonymous donors whose display name,
  email or phone matches `$q`, reusing `donors_list_where('all', $q)` so the matching rules (LIKE escaping,
  phone digits only from 3 digits) are the same as the donor list. Ordered by display name, id; at most
  `$limit` (10) rows. Returns the fields the form shows: id, name, type label, town (`city, state`), donor
  since year, lifetime cash, items donated, last gift date, volunteer hours. Also `donor_lookup_row($r)` to
  shape one `donor_summary` row the same way for the preselected donor below.
- New `donorLookup.php`: GET `?q=`, JSON only. `require_authentication(1, true)` so an expired session
  gets a JSON 401 rather than a login-page redirect; `fish_can('donors', 'view')` false → JSON 403.
  Queries shorter than 2 characters (after the same string-only trim rule as `fish_str`, inlined because `include/fishnet-layout.php` would redirect instead of answering JSON; counted with `mb_strlen`) or longer than
  100 return an empty list without touching the database. `Cache-Control: no-store`. No POST handling.
- `addDonation.php`:
  - Remove the hard-coded `DONORS` object. Keep `CAMPAIGNS` (campaigns are another ticket).
  - Read `?donor=<id>`: when it names an active, non-anonymous donor (via `donor_get`), render the form
    with that donor already chosen (data passed to the script as JSON encoded with
    `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT`). Any other value is ignored.
  - "+Add" link points to `addDonor.php` and is shown only when `fish_can('donors', 'edit')`.
  - Without `fish_can('donors', 'view')` the donor step shows a notice instead of the search box.
- `js/addDonation.js`: search calls `donorLookup.php` (250 ms debounce, `AbortController` cancels the
  stale request, results ignored if the query changed meanwhile). Results and the chosen-donor card are
  built with `textContent`/DOM nodes only. Empty, error and "type at least 2 letters" states. Keyboard:
  results are buttons, so Tab/Enter work. The side panel shows the real figures; "Volunteers linked"
  becomes "Volunteer hours" (the schema has hours per donor, not linked volunteers). "View full profile"
  goes to `donorProfile.php?id=<id>` in a new tab with `rel="noopener"`. The existing `initials()` helper
  in the script is made safe for one-word and non-letter names.
- `addDonor.php`: "Save & add donation" (`action=save_add`) redirects to `addDonation.php?donor=<newId>`
  (303); "Save donor" keeps going to the profile. The Tips text and the stale comment are updated.

Out:
- Saving the donation (UF2-46/48), editing (UF2-56/57), campaign data (UF2-52), the donations list page.
- Recording a gift from the anonymous donor (id 999): excluded from search as everywhere else; a
  separate decision for UF2-46.
- `newDonor.php` (static, from PR #10) is left in place, no longer linked from this form; deleting it is
  Tristan's call on PR #10.
- No schema change, no migration.

## Approach

Reuse `donors_list_where`, `donor_get`, `fish_can`, `fish_str`, `h()`, `auth_csrf_field()` (form already
posts nowhere; no new POST endpoint). All SQL is prepared with bound parameters via the existing helper.
Reads go through `donor_summary` only.

## Acceptance criteria

1. Typing 2+ characters of a real donor's name, email or phone on `addDonation.php` lists matching active
   donors from the database (max 10); the fake donors are gone from the page source.
2. Inactive donors and the anonymous donor (999) never appear in results.
3. Choosing a result fills the chosen-donor card and side panel with that donor's real lifetime cash,
   items, last gift and volunteer hours, sets the hidden `donor` field to its id, and enables step 2.
4. "Change" clears the choice and returns to search.
5. A donor name containing `<img src=x onerror=alert(1)>` renders as text in results, card and panel.
6. `donorLookup.php` without a session returns 401 JSON; with a session lacking donors view, 403 JSON; a
   1-character query returns `[]`; `q[]=x` returns `[]` without a PHP warning.
7. "+Add" → `addDonor.php`; "Save & add donation" creates the donor and lands on `addDonation.php` with
   that donor preselected. "Save donor" still lands on the profile.
8. `addDonation.php?donor=999`, `?donor=<inactive id>`, `?donor=abc` render with no donor chosen.

## Verification plan

Docker is not installed on this machine, so `make test-auth` / `make test-donors` cannot run here. Instead:

- `php -l` on every touched PHP file (XAMPP PHP 8.2.4).
- Apply `sql/casadb.sql` plus migrations 001-008 and `sql/seed/demo_fish.sql` to a scratch database
  `fishdb_uf249` on the local XAMPP MariaDB (10.4, older than the 10.11 the project targets; any
  incompatibility is reported, not worked around in project files), point a scratch copy of the branch at
  it, and check criteria 1-8 with `curl` against XAMPP Apache using a logged-in session cookie.
- `node --check js/addDonation.js`.
- The author must run `make test-auth` and `make test-donors` in Docker, and `/review-swarm ticket` and
  `/review-swarm code`, before pushing: this session had no Workflow tool, so the four-auditor gate was
  applied as a manual self-review and **no** `*-approved.json` record is written.

## Risks

- Stacked on unmerged PR #10: if Tristan changes `addDonation.php`, this branch needs a merge.
- One lookup per pause in typing; each is a `donor_summary` query with correlated subqueries. Fine at FISH
  scale (hundreds of donors); revisit if the list grows to tens of thousands.
- Donor email/phone are matched but only name, type and town are returned to the browser, to keep contact
  details out of the JSON.

## Self-review (in place of `/review-swarm ticket`, which could not run in this session)

Applied the four auditor lenses to the draft; findings and outcomes:
- Correctness: a `?next=donation` parameter on the "+Add" link had no reader → removed; the
  "Save & add donation" button already carries the intent.
- Data: `donor_get` returns inactive donors too → the preselect path checks `is_active = 1` and
  `is_anonymous = 0` itself (criterion 8).
- Security: lookup JSON leaves out email and phone even though they are matched (Risks).
- Verification: Docker tests cannot run here → stated in the plan; author runs them before pushing.
