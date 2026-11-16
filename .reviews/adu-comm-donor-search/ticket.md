# Ticket: donor search instead of a dropdown on Add communication

Branch: `adu-comm-donor-search`, off `origin/dev` (a086244).

Jira: none yet. Request from Joel (2026-11-11), verbatim: "for the add communication portion of the code, it
should be a search instead of the dropdown so fix that". Related, done: UF2-146 (donor on a one-on-one
contact comes from donor_summary, PR #40), UF2-49 (donor search on Add donation, `donorLookup.php`).

## Problem

`addCommunication.php` step 2 "Who" renders every active, non-anonymous donor as an `<option>` of
`<select id="donor_id">` (`donor_options()`). With hundreds of donors the list is long and cannot be
searched by email or phone; Add donation already has a type-ahead search for the same job.

## Scope

In:
- `addCommunication.php`:
  - Replace the donor `<select>` with the same search box as Add donation: `<input type="hidden"
    name="donor_id" id="donor_id">`, a text input `#donorSearch` (name, email or phone), a results list
    `#donorResults`, and a chosen-donor card (`#donorChosen`: initials, name, "type · town", Change
    button). POST field name stays `donor_id`, so `comm_form_from_post()` and `comm_create()` are unchanged.
  - Stop loading the full donor list. The only donor the page needs is the chosen one: from `?donor_id=`
    on GET, or from the posted `donor_id` on POST. New helper `comm_donor_choice($id)` in
    `database/dbCommunications.php` returns `[id => display_name]` when `$id` is 1–10 digits and names an
    active, non-anonymous donor (via `donor_get()`), else `[]`. That map is passed to the unchanged
    `comm_form_validate()`, so the error messages stay the same ("Choose a donor." / "Choose a donor from
    the list."). `comm_create()` still re-checks the donor inside its transaction.
  - A re-rendered form (validation errors) or a valid `?donor_id=` shows the chosen-donor card with the
    donor already filled in; an invalid preselect is ignored. The chosen donor is passed to the script as
    JSON with `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT`, shaped by `donor_lookup_row()`.
  - Without `fish_can('donors', 'view')` the step shows "Your account cannot view donors." instead of the
    search box, and no donor name is rendered (the form is read-only for that account already).
  - `<noscript>` note: the donor search needs JavaScript.
- New `js/commDonorSearch.js`: calls the existing `donorLookup.php` (250 ms debounce, `AbortController`,
  stale responses ignored, 401/403/500 messages), builds results and the card with `textContent`/DOM nodes
  only, results are buttons (Tab/Enter), Enter in the search box searches instead of submitting, Change
  clears `donor_id` and refocuses the search. Copied from `js/addDonation.js` step 1 minus the donation
  side panel; addDonation.js is not touched.
- `css/communications.css`: the donor-box / results / initials rules from `css/donation.css`, scoped under
  `.comm-form` so nothing else changes.
- `database/dbDonors.php`: `donor_options()` loses its only caller; remove it and update the comment in
  dbCommunications.php.
- `tests/communications_integration.py`: replace the donor `<select>` assertions with: the page has the
  hidden `donor_id` input and `#donorSearch` and renders no donor names; `?donor_id=` preselects only an
  active non-anonymous donor (hidden input value + name in the JSON); `donorLookup.php` finds the test
  donor, does not return the inactive donor or 999, and returns the XSS name only JSON-escaped; the
  no-donors-view account sees the notice and no search; existing POST/validation tests unchanged.

Out: Person contacted, campaign and outreach dropdowns (staff and active-campaign lists are short; the
request is about the donor list). `donorLookup.php` itself is unchanged.

## Acceptance criteria

1. Add communication shows a donor search box, not a dropdown; typing 2+ letters of a name, email or phone
   lists matching active donors; clicking one fills the form and saving works as before.
2. `?donor_id=<active donor>` (the donor profile's "Log communication" link) opens with that donor chosen.
3. A validation error re-renders with the chosen donor still shown.
4. Posting an inactive, anonymous (999), unknown or non-numeric donor id gives the same errors as today.
5. Accounts without donors view see no donor names and cannot save (unchanged 403).

## Verification plan

- `make test-comms` (updated) plus `make test-auth`, `test-donors`, `test-donations` on the local scratch
  stack (php -S; the 6 Apache-only auth subtests are expected to fail there and pass in CI).
- `php -l` on changed PHP; headless-Chrome walk on the XAMPP copy: search, choose, change, save, preselect.

## Responses to round 1 (all four approved, no criticals)

- **Lookup inside the try/catch** (security, data, correctness, verification): the chosen donor is resolved in
  the existing `try` (posted `donor_id` on POST, `?donor_id` on GET), only when `$canSeeDonors`; a
  `mysqli_sql_exception` sets `$loadError` as today (notice on GET, 503 on POST).
- **Helper shape** (correctness): `comm_donor_choice($id)` does one `donor_get()` and returns
  `['id','name','type','town']` (from `donor_lookup_row()`, trimmed, so no giving figures reach the page, per
  security) or null. The page builds the validate map `[id => name]` from it.
- **Leading zeros** (data, correctness): the helper requires `(string)(int)$id === $id`, so `007` is rejected
  exactly as today ("Choose a donor from the list."); the card is shown only when the validated donor is set.
- **Post-back with ?donor_id=** (correctness): the POST path uses only the posted value; new test posts donor B
  to `addCommunication.php?donor_id=<A>` and expects a 303 and a row for B.
- **XSS test** (verification, high): restated. The lookup endpoint is unchanged and already covered by
  `tests/donations_integration.py`; the comms suite asserts the XSS name never appears raw in the page,
  including in the embedded chosen-donor JSON (hex flags), and the script uses `textContent`.
- **AC3 test** (verification, high): new test posts a valid donor with a bad type and asserts the hidden input
  value and the chosen-donor JSON; an invalid donor re-renders with an empty input and `null`.
- **No-donors + ?donor_id=** (verification): tested; no marker name, no `#donorSearch`, notice shown.
- **Element ids** (verification): the server test asserts `#donorSearch`, `#donorResults`, `#donorChosen`,
  hidden `#donor_id` and the script tag; the JS behaviour is walked by hand (headless Chrome) and the PR says so.
- **Label** (correctness): `<label for="donorSearch">`. **Script** only rendered with donors view and returns
  early if `#donorSearch` is missing. **CSS**: only the rules `forms.css` lacks.
- **Docs** (verification): README test-comms sentence, `donor_lookup_row()` and `donorLookup.php` header
  comments (comment-only edit) updated.
- **Third copy of the search JS** (correctness, low): accepted for this ticket; follow-up to share it.
