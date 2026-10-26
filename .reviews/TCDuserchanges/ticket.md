# Donor giving status (Active / LYBUNT / SYBUNT / Inactive) and the Foundation donor form front end

Branch `TCDuserchanges` (PR #27), by Tristan Chavez-Duran, merged with `origin/dev` (b735a3d). The branch
predates the review process, so per `.claude/skills/review-swarm/SKILL.md` ("Branches that predate the
process") this ticket describes what was built and the ticket gate is skipped; the PR says so.

## Jira keys

None recorded for this branch. The PR description is the only statement of intent: "changes made to donor
stuff like status and user request for how donors are saved".

## Problem

1. The donor list (`donor.php`) shows a status of New / Active / Lapsed from `donor_summary.status`
   (migrations 007/008). The requested fundraising statuses are based only on the last gift:
   Active (gift in the last 12 months), LYBUNT (12-24 months ago), SYBUNT (24-36 months ago) and
   Inactive (36+ months ago, or never).
2. The first version of this branch computed the new status in PHP inside `donor.php`, so the page
   disagreed with `donor_summary.status`, the Lapsed tile and filter, and `tests/donors_integration.py`
   (CI failure on PR #27).
3. The donor form needs a Foundation type and a list of contact people for organizations and foundations.

## Scope

In:
- `sql/migrations/011_donor_giving_status.sql`: recreate `donor_summary` with the same columns as 008;
  only the `status` CASE changes, to active / lybunt / sybunt / inactive from `last_gift_date`
  (cash or non-cash, as before).
- `database/dbDonors.php`: the "Lapsed (12+ months)" tile and the `lapsed` filter count every donor whose
  status is not `active` (lybunt, sybunt or inactive). Filter keys and tile keys are unchanged.
- `donor.php`: render `donor_summary.status` with labels and a tooltip on the cell; no PHP date logic.
- `css/donor.css`: badge colours for lybunt / sybunt / inactive; `.click-row`; donations filter styles.
  `.notice` and `.tile .sub`, which an earlier merge on this branch had dropped, are kept as on dev.
- `tests/donors_integration.py`: the tile fixture counts `status <> 'active'`; a newly added donor with no
  gifts reads `inactive` (was `new`).
- `sql/README.md`: the donor_summary row names the new statuses and migration 011.
- Front end only: `include/donorForm.php` Foundation radio and contact-people rows, `css/forms.css`,
  `js/donorForm.js` (add / remove contact rows).

Out (known, follow-up work, not claimed by this branch):
- Persisting the Foundation type and the contact people. The form shows them, but `donor_form_from_post()`,
  `donor_create()` and `donor_update()` do not read or save them yet, and `donors.donor_type` has no
  `foundation` value; a Foundation submission is saved as the existing default type. Needs a schema change
  (contacts table, donor_type value) in its own ticket. The form markup carries a comment saying so.
- Label/for association for the contact-row inputs (CodeRabbit, minor).

## Approach

Single source of truth: the status rule lives in the view, so donor.php, the tiles, the filter and the tests
read the same value. New migration file rather than editing 008 (applied files are immutable).

## Acceptance criteria

- `donor_summary.status` is one of active / lybunt / sybunt / inactive for every row, using the 12 / 24 / 36
  month boundaries on `last_gift_date`; NULL last gift is inactive.
- `donor.php` shows the matching label (Active, LYBUNT, SYBUNT, Inactive) with a tooltip, and the
  test's `<span class="status X">` parser reads X equal to `donor_summary.status`.
- Lapsed tile = count of active, non-anonymous donors whose status is not active; Lapsed filter lists them.
- Migration 011 applies on top of 010 and replays without error.
- All CI targets pass.

## Verification plan

```
make dev && make seed-demo
make migrate; for f in sql/migrations/[0-9][0-9][0-9]_*.sql; do scripts/db-sql.sh < "$f"; done
php -l on every tracked .php file
make test-auth test-donors test-users test-donations test-hours test-gate
```

Run on 2026-10-26 against MariaDB 10.11.14 and PHP 8.3 with Apache (Docker images were not reachable from
the session that ran it, so the same Make targets ran against a local MariaDB/Apache with the same config):
all pass.

## Risks

- Behaviour change: donors added in the last 90 days with no gift used to read "new"; they now read
  "inactive" and count toward Lapsed. "New this month" tile is unchanged (donor_since_date).
- Any other branch that also adds a migration numbered 011 will conflict by number.
