# fauquier-fish-drms

PHP 8.2 + MariaDB 10.11 donor management app for Fauquier FISH. Local stack: `make dev` (Docker),
tests: `make test-auth`, `make test-donors` (after `make seed-demo`), `make test-gate`; schema changes:
`sql/migrations/` via `make migrate` (see `sql/README.md`).

## Required review gates (docs/process/change-review-process.md)

1. Before implementing any ticket or change: write `.reviews/<branch>/ticket.md` and run
   `/review-swarm ticket` until it is approved. Do not write implementation code before that.
2. Before pushing: run every check in the ticket's verification plan, then `/review-swarm code` until it
   is approved, commit `.reviews/<branch>/code-approved.json`, and only then push.
3. Never edit a review record to make it pass, and never use `REVIEW_SWARM_SKIP` without saying so.

## Conventions

- Work on a feature branch off `origin/dev`; PRs target `dev`. Fetch first, local refs go stale.
- Every protected page includes `include/fishnet-layout.php` or calls `require_authentication()`;
  every POST form includes `auth_csrf_field()`; private files are served only through `download.php`.
- Every new or touched query uses a prepared statement with bound parameters. Most legacy helpers under
  `database/` still interpolate values into SQL; parameterise any you touch.
- Read donor data through the `donor_summary` view (filter `is_anonymous = 0` for totals and duplicate
  checks) via `database/dbDonors.php`; write to `donors` and the activity tables (`sql/README.md`). The
  anonymous donor is id 999 and is never written.
- Writes to donor and donation data call `fish_can($area, $action)` from `include/permissions.php` before
  touching the database; GET pages render read-only when it is false. `h()`, `initials()` and
  `fish_str()` live in `include/fishnet-layout.php`; pages must not redefine them.
- Keep commits and PR descriptions factual: say what was run and what was not.
