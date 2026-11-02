# Code self-review (UF2-49)

`/review-swarm code` could not run in the session that wrote this branch (no Workflow tool), so there is
**no** `code-approved.json` and the pre-push hook will refuse a push until the author runs the swarm.
This file records the manual pass that was done instead, using the four auditor lenses.

## Checks run

Against a scratch MariaDB 10.4.28 (XAMPP binaries; the project targets 10.11) loaded with
`sql/casadb.sql`, migrations 001-008 (all applied cleanly) and `sql/seed/demo_fish.sql`, plus two fixture
donors (name `<img src=x onerror=alert(1)>`, and an inactive donor) and a `user_permissions` row removing
donors view from `fish.viewer`; served by `php -S` (PHP 8.2.4):

- `php -l` on `addDonation.php`, `addDonor.php`, `donorLookup.php`, `database/dbDonors.php`; `node --check js/addDonation.js`.
- curl, acceptance criteria 1, 2, 6, 7, 8: no session → 401 JSON; viewer without donors view → 403 JSON and
  the form shows a notice; `q=m` and `q[]=maria` → `[]`, no PHP warning; name, email (`whitaker@`) and phone
  digits (`5550101`) match; `%%` matches nothing (LIKE escaped); inactive and anonymous donors never
  returned; 10-row cap; `?donor=1005` preselects; `?donor=999`, inactive id, `abc` do not; "Save & add
  donation" → 303 `addDonation.php?donor=<new id>`, "Save donor" → 303 profile; PHP error log empty.
- Headless Chrome over DevTools, criteria 1, 3, 4, 5: 1-letter hint, results, no-match text, choosing fills
  card and panel with real figures and enables step 2, "Change" resets and disables step 2, preselect
  works, the `<img>` name renders as text in results, card and panel (no `img` element, no dialog), no JS
  exceptions.

Not run: `make test-auth`, `make test-donors` (Docker not installed), `/review-swarm code`.

## Findings from the manual pass

- Correctness: "Change" left step 2 enabled (pre-existing in PR #10) → fixed.
- Product: last gift showed as `2026-10-09` → formatted as `Oct 9, 2026`.
- Security: results were built with `innerHTML` from names (pre-existing) → DOM nodes and `textContent`
  only; preselected donor JSON encoded with `JSON_HEX_*`; email and phone matched but not returned.
- Data: all new SQL prepared via `donors_list_where`; reads only `donor_summary`; no writes added.
