#!/usr/bin/env bash
# Pipe SQL from stdin into the Docker database as the application user.
# Extra arguments are passed to the mariadb client (e.g. --skip-column-names).
# Credentials come from the db container's own environment, which compose sets from .env.
# Set COMPOSE_PROJECT_NAME to target a different compose project (used for throwaway test DBs).
set -euo pipefail
cd "$(dirname "$0")/.."
exec docker compose exec -T db sh -c \
  'MYSQL_PWD="$MARIADB_PASSWORD" exec mariadb --batch --default-character-set=utf8mb4 -u"$MARIADB_USER" "$MARIADB_DATABASE" "$@"' \
  sh "$@"
