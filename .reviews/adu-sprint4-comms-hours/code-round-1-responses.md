# Responses to code round 1 (adu-sprint4-comms-hours)

All four auditors approved (0 critical, 0 high). Reviewed commit dd55907. Changes in the next commit:

## Correctness

- **Medium: Follow up status with no date becomes an error after another error.** Fixed.
  `comm_form_validate()` no longer rewrites `follow_up` / `status`; the posted values round-trip as posted.
  The stored status comes from `comm_stored_status()` at insert. Test: post status Follow up with no type,
  check the re-rendered box is unticked and the status still selected, then the corrected post saves.
- **Low: Bounced overwritten by a follow-up.** Rule revised: a follow-up date is stored whenever given,
  whatever the status; only ticking "Remind me to follow up" sets the status to `follow_up`. A date alone
  keeps the chosen status (test case: date + Replied stays Replied). Ticking the box with Bounced is an
  explicit choice and stores follow_up (tested). The ticket's Scope sentence now points here.
- **Low: typed name dropped when the select is on "Not recorded".** Fixed: a typed name with the select
  empty is treated as "Someone else" (tested).
- **Low: save error blames the donor.** Message is now neutral ("Please check the donor is still active and
  try again; if it keeps failing, ask an administrator.").
- **Low: comm_badge()/comm_type_label() duplicated.** Accepted for now: pre-existing page-local copies; both
  now share `comm_status_label()`. Left for the list-page ticket to avoid widening this diff.

## Security

- **Low: staff login ids shown to read-only viewers.** Fixed: `comm_staff_options()` is loaded only when
  the account can save (tested: a viewer's Person contacted list holds only "Not recorded" and "Someone else").

## Data

- **Low: ticket Scope text out of date.** Fixed (see above).
- **Low: campaign/staff not re-checked at insert.** Accepted: the FKs keep the link valid and the window
  is one request; the donor re-check is what protects the 999 / inactive rule.
- **Low: midnight occurred_at.** Fixed: a contact dated today gets the current time; past dates stay at
  00:00:00 (both tested).
- **Low: connection not closed on the exception path.** Fixed.

## Verification

- **Low: click behaviour not automated.** Accepted as planned: checked with a headless Chrome walk (not
  committed: it drives the local Chrome binary). Re-run after these fixes: 16/16 checks pass (x hidden
  when empty, shown when filled, clears time + auto Hours, keeps typed and stored Hours, sits inside the
  field, none on a read-only entry, edit save with a cleared start stores NULL; Someone else box toggle,
  donor preselect, save round trip to the profile tab, outreach toggle).
- **Low: half-typed time.** Fixed: the x also shows while the field reports `validity.badInput`.
- **Low: post-merge `make migrate`.** Will be in the PR description; error message made neutral (above).
- **Low: untested branches.** Added: unknown numeric donor id, 5001-character summary, no button on the
  anonymous donor's profile.
- **Low: verification items not reported.** `php -l` was run on every changed PHP file (clean). Applying
  014 to the XAMPP `fishdb` was NOT run: XAMPP's MySQL/Apache are not running and this session cannot start
  them; it is listed as a post-merge step for the author. MariaDB 10.4 (XAMPP) was used locally; CI runs 10.11.

Suites after the fixes (scratch MariaDB + php -S): communications 9/9, hours 14/14, campaigns 18/18,
donors 9/9, users 8/8, donations 13/13, review gate 18/18; auth 15/21 with the same 6 failures as unchanged
origin/dev (direct document URLs blocked only by Apache rules, which php -S does not apply).
