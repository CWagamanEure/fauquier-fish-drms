
# Rappahannock CASA 


## Local development with Docker

Install and start Docker Desktop. Clone this repository into a Docker-shared
folder, such as `~/Projects/fauquier-fish-drms` on macOS. XAMPP and `htdocs`
are not required. From the project folder:

```bash
make dev
```

`make dev` creates `.env` from `.env.example` only if it is missing, builds the
containers, and starts the services in the background. Existing `.env` settings
are preserved. Run `make` to list commands; use `make stop`, `make status`, and
`make logs` to stop services, inspect them, or follow logs. No host PHP, Python,
Poetry, or npm install is needed. The Makefile in `ai-email-gen/` is only for the
optional AI integration, not for starting this application.

Without Make, copy `.env.example` to `.env` once, then run
`docker compose up -d --build --wait --wait-timeout 180`. You can edit `.env`
before the first start to choose local database passwords or change ports.

- Website: http://localhost:8080/
- phpMyAdmin: http://localhost:8081/ (use DB_USER and DB_PASSWORD from .env)
- Assignment login: username `vmsroot`, password `vmsroot`.

The database and application user are created automatically. On the first start,
`sql/casadb.sql` is imported automatically. To start from an existing database,
export it first, save it in `.local/docker-seed/001-fishdb.sql`, and set
`DB_SEED_FILE=./.local/docker-seed/001-fishdb.sql` in `.env` before the first start.
Local exports and `.env` are ignored by Git. Docker and XAMPP databases are
separate copies; subsequent changes do not synchronize.

Database data persists in a Docker volume. `docker compose stop` stops the stack;
`docker compose up -d` starts it again. `docker compose down` also preserves data.
**Do not use `docker compose down -v` unless you intend to delete the database.**
Changing credentials or the seed file after initialization does not update an
existing database volume.

PHP edits appear immediately through the project folder mount. Rebuild after
changing the Dockerfile or Python dependencies. Check status with
`docker compose ps` and troubleshoot with `docker compose logs --tail=100`.
If ports are occupied, change APP_PORT or PHPMYADMIN_PORT in `.env`.

PHP and Python read DB_HOST, DB_NAME, DB_USER and DB_PASSWORD. Without environment
variables they retain the local XAMPP fishdb defaults. Docker sessions use a
separate cookie and storage directory to avoid XAMPP session conflicts.
The Python report/import/export dependencies are installed in the web container.
Optional AI/email integrations still require their own API/SMTP configuration;
no Ollama model server or external account is provisioned by this stack.

This Compose setup is for local development; services bind to localhost only.

## Change review process

Every change goes through the four-auditor review swarm twice, on the ticket draft and on the code,
before it is pushed. `make dev` installs the pre-push hook that guards against pushing unreviewed code;
the PR check and a human review are the real controls. Read `docs/process/change-review-process.md`
before starting work.

## Database migrations

Schema changes are versioned in `sql/migrations/` and applied by `make migrate`, which
`make dev` runs after the containers are healthy. The compose seed file only runs when the
database volume is first created, so existing local databases get new tables and columns
from migrations, not from re-importing a dump. `make seed-demo` loads optional FISH demo
donors, donations, hours and communications for local page work. See `sql/README.md` for how
to add a migration.
## Authentication (UF2-2 / UF2-4)

Log in at `login.php` (username first, then password) and sign out with **Log Out** in the
FISH NET top bar. Staff accounts live in `dbpersons`: `role` (admin / manager / staff /
viewer) and `status` (active / inactive) come from migration 001, and `accessLevel`
(1 standard, 2 manager, 3 super-admin) is kept in step with the role for the legacy page gate.
Inactive accounts cannot log in, and every protected request re-reads the account, so
deactivating a user or changing their role takes effect on their next request.

Login matches the HTML-encoded username representation used by registration, so users
enter their original username, including punctuation. The first login step accepts any
username without checking the database, so the page never confirms which accounts exist.

Protected PHP entry points call `require_authentication()` from `include/auth.php` before
producing output or touching data; `include/fishnet-layout.php` does this for every FISH
page. Handlers enforce the same access level as their forms. Every authenticated POST must
carry the form token: forms include it with `auth_csrf_field()`, JavaScript callers send it in
an `X-CSRF-Token` header, and `require_authentication()` rejects anything else with 403.
Deleting events, users and uploaded documents is POST-only. Login rotates the session ID;
sign-out requires a form token, destroys the session and expires its cookie, and visiting
`logout.php` directly shows a confirmation form. Authentication responses are not cached.
Report, export, import and email handlers release their session locks during long
operations so sign-out completes immediately, and imports save their result only if the
original session is still authenticated when processing finishes. Reports, exports and
uploaded documents are served through `download.php`; direct file URLs are denied by Apache
(`docker/apache.conf` and the `.htaccess` files, which must be preserved on other hosts).
Rebuild the web image with `make dev` after pulling this change. Existing sessions must log
in again.

With `make dev` running, `make test-auth` runs `tests/auth_integration.py` against the real
PHP/MariaDB stack. It creates uniquely named temporary accounts and files and removes them
afterwards, covering anonymous access, credential validation, registration-to-login, legacy
account migration, role-appropriate navigation, private downloads, session rotation, form
tokens, Unicode boundaries, concurrent sign-out and session reuse after logout. Run it only
against a local development database.

## Purpose
This project is the result of a semester's worth of collaboration among UMW students. The goal of the project was to create a web application that better suits the needs of Rappahannock CASA, specifically as a system to manage their donors. 

## Authors
In Fall 2025, the Fredericksburg SCPA Volunteer Management Web Application code was adapted to develop the Rappahannock CASA Web Application. Numerous existing database tables were retained with modifications or renamed, while new tables were introduced as needed. Certain files and functionalities from the original system were integrated, while additional features were designed specifically for the Rappahannock CASA Web Application. The team responsible for these updates and enhancements included Garrett Mckenzie, Ethan Bostick, Joshua Byrne, Max Redman, Carter Walker, and James Heathcock.

## User Types


## Features


## Design Documentation
Several types of diagrams describing the design of CASA, including sequence diagrams and use case diagrams, are available. Please contact Dr. Polack for access.

## "localhost" Installation


## Reset root user credentials


## Platform


### SiteGround Dashboard


### Localhost to Siteground

### Clearing the SiteGround cache
#### Chrome


#### Safari

# TODO just tailwind?
### External Libraries and APIs


# TODO make sure we didn't already fix any of these, add any new
### Potential Improvements


## License
The project remains under the [GNU General Public License v3.0](https://www.gnu.org/licenses/gpl.txt).

## Acknowledgements
Thank you to Dr. Polack and Fredericksburg SPCA for the opportunity to work on this project.
