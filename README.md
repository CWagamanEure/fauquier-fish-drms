
# Rappahannock CASA 


## Local development with Docker

Install and start Docker Desktop. Clone this repository anywhere on your computer;
XAMPP and `htdocs` are not required. From the project folder:

```bash
cp .env.example .env
# Edit .env and choose a local database root password.
docker compose up -d --build
```

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
