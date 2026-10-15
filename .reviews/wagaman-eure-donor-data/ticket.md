# Ticket: donor pages read and write the real tables

Branch: `wagaman-eure-donor-data` (stacked on `wagaman-eure-review-process`, PR #17, until it merges).

Jira: UF2-26, UF2-27 (Cory), remainder of UF2-60 (Cory). Supports James's UF2-61, 67, 68, 70, 73-78, 81
(donor page tasks) and Tristan's UF2-88 (donor page CSS). Related requirement text:

- UF2-26: "The system shall allow administrators to view a supporter's complete donation history from the
  supporter's profile. The history shall include donation amount, donation date, and donation frequency so
  administrators can evaluate the supporter's financial e[ngagement]."
- UF2-27: "The system shall allow administrators to view a donors volunteer hours and communication
  touchpoints from the supporter's profile. The system shall display the frequency and history of these
  interactions so administrators can evaluate the donor."
- UF2-60 field list: first, initial, last, email, phone, preferred contact method, street, city, state, zip,
  donor type (individual, organization). (Columns landed in migration 003.)
- Group Acceptance Criteria (Confluence, Tristan's stories): "The Admin can view all profiles. The Admin can
  view the dates which donors have donated on their profile. The Admin can edit the donors profile. The
  Admin can save any edits made." and "The admin will be able to record volunteer hours in the portal ...
  update and edit recorded hours ... save after they have added or updated records."

## Problem

`donor.php`, `donorProfile.php`, `addDonor.php` and `updateDonor.php` (James, PR #15) render FISH NET pages
from `include/donorDataStopgap.php`, a fake in-session data set the file itself says to replace "once
schema is set". The schema is on `dev` (PR #12): `donors` has the new columns, `donor_summary` computes
lifetime cash, items, hours, last gift, status and last contact, and `donations`, `non_cash_donations`,
`volunteer_hours`, `communications` hold the activity. Nothing on the pages reads them. Adding or editing a
donor writes only to the PHP session.

## Scope

In:
- A prepared-statement data layer in `database/dbDonors.php` (existing file, currently one interpolated
  `create_donor()`): list with filter/search/sort/pagination and tile counts, one donor with profile fields,
  the four activity histories with campaign names, create, update, deactivate.
- Migration `008_donors_active_flag.sql`: `donors.is_active TINYINT(1) NOT NULL DEFAULT 1` (James's
  "Mark inactive" button needs it; review of #12 noted there is no inactive flag), and recreate
  `donor_summary` to expose it. Inactive donors keep their history and are hidden from the list by default.
- Swap the four pages from the stopgap functions to the data layer, keeping James's markup and array shapes
  (`$d['id','name','type','contact','lifetime','last_gift','status']`, `$detail['cash','items','vol','comms',
  'last_contact']`, `$prof[form keys]`) so the templates change as little as possible; delete
  `include/donorDataStopgap.php` and the `?reset_demo` handler.
- Donor code shown on the profile (`D-01842` style) derives from the id; no new column.
- A `tests/donors_integration.py` suite run by `make test-donors` (reusing the `Browser` helper): list renders
  demo donors from the seed, filters/sort/search hit the database, profile tabs show the seeded gifts, hours
  and communications, add creates a row with the new columns, update edits it, deactivate hides it, anonymous
  requests redirect, POST without token is refused.
- README/sql README notes; Jira comments on James's tickets so he knows the pages now read real data.

Out: logging a new communication / hours / donation from the profile (UF2-87 and Joel's donation pages),
duplicate-donor merging (the tile only counts shared emails/phones), campaign management, the Manage Users
page, theming. Legacy `searchindex.php` / `search_multi_criteria.php` stay as they are.

## Approach

1. `database/dbDonors.php`, all prepared statements on `connect()`:
   - `donors_tiles()`: total (active, not anonymous), new this month (`donor_since_date` in the current
     month), lapsed (`status = 'lapsed'`), dupes (count of donors sharing a non-empty email or phone with
     another active donor).
   - `donors_list($filter, $q, $sort, $page, $perPage)`: from `donor_summary` where `is_anonymous = 0 AND
     is_active = 1`; filter individuals/organizations/lapsed; `q` matches display_name, email, phone with
     LIKE; sort last_gift (newest first, NULL last), name, lifetime; returns rows in James's shape plus
     `total`. Type is rendered as "Individual"/"Organization" from `donor_type`.
   - `donor_get($id)`: one `donor_summary` row (any active flag, so a deactivated donor's profile still opens
     with a badge).
   - `donor_profile_fields($id)`: the `$prof` form shape from `donors` columns (preferred contact mapped
     email/phone/mail/text to the form's Email/Phone/Mail/Text labels; `created` = `donor_since`).
   - `donor_cash_history($id)`: `donations` joined to `dbevents` for the campaign name; payment shown as
     "Check #1042" / "Online" / "Cash" / "Card" from `payment_method` + `check_number`; receipt
     sent/pending from `receipt_sent_at` / `send_receipt` / `thanked`; ordered by `donation_date` desc.
   - `donor_noncash_history($id)`, `donor_hours_history($id)`, `donor_communications($id)`: same pattern
     from their tables, campaign names via `dbevents`.
   - `donor_create(array $f)`, `donor_update($id, array $f)`: map the form keys to columns; `first`/`last`
     NULL for organizations, `org_name`/`org_contact` NULL for individuals; validation stays in the pages.
   - `donor_set_active($id, bool)`.
   The existing `create_donor()` (used by legacy `addDonor.php` only, now replaced) is removed.
2. Pages: replace the stopgap includes/calls; `donorProfile.php` computes its totals from the real rows the
   same way it does now; `donor.php` passes filter/q/sort/page straight to `donors_list()` and reads the
   tiles; `addDonor.php` posts to `donor_create()` and redirects to the profile (or to the donation form
   when "Save & add donation" was chosen, as the page already does); `updateDonor.php` posts to
   `donor_update()` / `donor_set_active()`.
3. Migration 008 + view recreate; `make migrate` picks it up.
4. Tests + docs + Jira comments.

## Acceptance criteria

1. With `make seed-demo` loaded, `donor.php` lists the six demo donors with correct type, contact, lifetime
   cash, last gift and status; the four tiles are computed from the database; filter, search, sort and
   paging change the query, not an in-memory array.
2. `donorProfile.php?id=<demo Maria Lopez>` shows lifetime cash, items donated, volunteer hours and last
   gift from the tables, and the Cash, Non-cash, Volunteer and Communications tabs list the seeded rows with
   campaign names and receipt/status badges.
3. Adding a donor through `addDonor.php` inserts a `donors` row with donor_type, middle name, organization
   fields, preferred contact and the four preference flags; the new donor appears in the list as "new".
4. Editing through `updateDonor.php` changes the row; "Mark inactive" sets `is_active = 0`, the donor leaves
   the list, and the profile still opens.
5. Anonymous requests to the four pages redirect to login; POSTs without the form token get 403.
6. `include/donorDataStopgap.php` is gone; `make test-auth` still passes; `make test-donors` passes.

## Verification plan

- `make migrate` (008 applies once), `make seed-demo`.
- `make test-auth`, `make test-donors`.
- Manual: the six curl/browser checks above against http://localhost:8080 with no PHP warnings.

## Risks

- James may still be editing these pages; coordinate on his tickets before the PR, keep his markup.
- `donor_summary` scans all donors for the list page; fine at hundreds of donors, revisit if FISH's import
  brings in tens of thousands.
- Legacy `search_multi_criteria.php` still builds SQL by string; untouched here, tracked separately.
