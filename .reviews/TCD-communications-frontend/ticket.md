# Communications pages front end (list, add form, view page)

Branch `TCD-communications-frontend`, by Tristan Chavez-Duran, off `origin/dev` (d2da56d). The pages were
designed and built before this ticket was written (from the team's slide mock-ups), so per
`.claude/skills/review-swarm/SKILL.md` ("Branches that predate the process") this ticket describes what was
built and the ticket gate is skipped; the PR says so.

## Jira keys

None recorded for this branch. Related use case from the team's requirements: Track Communications
(FR1 communication record entry: type, date, content summary, person contacted; FR2 communication history
and search with filtering by date, type or person).

## Problem

The sidebar links to `communications.php`, which does not exist. The team needs the Communications pages laid
out so the backend can be wired to them:

1. A main page with two tabs: One-on-one (individual contacts with a donor) and Outreach (social media posts
   and mass mailings, one row per send, so a 1,000-person mailing is not 1,000 rows).
2. An add page that logs either a one-on-one contact or an outreach item linked to a campaign.
3. A view page for one outreach item: a social media post (with its photo) or a mass email (its content and
   recipients).

## Scope

In (HTML and CSS only, no data, no JavaScript):
- `communications.php`: page head with Import mailing (links to `dataImports.php`) and + Log communication;
  One-on-one and Outreach tabs as links (`?tab=outreach`); filter selects and search per tab; empty tables
  with an empty-state row; a commented example row for the backend.
- `addCommunication.php`: kind switch (One-on-one / Outreach) done with CSS `:has()`; one-on-one fields
  (donor, person contacted, type, direction, date, status, subject, optional campaign, summary, follow-up);
  outreach fields (channel, campaign, post date, posted by, link, caption, photo upload, results). Dropdown
  option lists are empty arrays for the backend. The form posts to itself; nothing is saved.
- `viewCommunication.php`: `?type=post` or `?type=email` layouts with every value shown as a dash placeholder.
- `css/communications.css`: styles for the three pages, on top of `fishnet.css`, `donor.css` and `forms.css`.
- `tests/auth_integration.py`: the three new pages added to `PROTECTED_PAGES`, so
  `test_every_entry_point_is_listed` keeps passing and the pages are covered by the login checks.

Out (follow-up backend work, not claimed by this branch):
- Reading and saving communications. There is no POST handler, no `fish_can('communications', ...)` check
  yet (nothing is written), and no outreach table (the `communications` table from migration 006 is one row
  per donor; outreach needs its own table with recipients). The file upload field is markup only.
- Edit / Duplicate / Delete on the view page are placeholders.

## Approach

Reuse the shared layout (`include/fishnet-layout.php`: login check, sidebar, `h()`), the table, pill, pager and
status styles in `donor.css`, and the form, tab and panel styles in `forms.css`. Each page declares its data
as empty arrays at the top with a "Backend TODO" comment, so the backend only fills those in.

## Acceptance criteria

- All three pages require login (they include `include/fishnet-layout.php`) and are listed in
  `PROTECTED_PAGES`; `make test-auth` `test_every_entry_point_is_listed` and the login checks pass.
- `communications.php` shows the One-on-one tab by default and the Outreach tab with `?tab=outreach`, each with
  an empty-state row and no fake data.
- `addCommunication.php` shows the one-on-one fields by default and the outreach fields when Outreach is picked
  (or with `?kind=outreach`); the form carries `auth_csrf_field()`.
- `viewCommunication.php` renders the post layout by default and the email layout with `?type=email`.
- No page prints user input without `h()`.

## Verification plan

- `php -l` on the three pages.
- `make test-auth` (run here with a local shim for the Makefile's docker compose exec calls, because Docker Hub
  images could not be pulled: MariaDB 10.11 and PHP 8.3's built-in server instead of the Apache container).
  CI is the authoritative run.
- Load each page and each variant in a browser while signed in.

## Risks

- `:has()` needs a current browser (Chrome 105+, Firefox 121+, Safari 15.4+); older browsers show both field
  sets. Acceptable for staff on current browsers; the existing donor form already relies on `:has()`.
- A user who submits the add form gets the empty form back; nothing is saved until the backend ticket.
