#!/usr/bin/env bash
# Apply pending sql/migrations/NNN_*.sql files in name order and record each in schema_migrations.
# Safe to run repeatedly: applied files are skipped, and the files themselves are written to be
# idempotent, so a file that failed part-way can be fixed and re-run.
set -euo pipefail
cd "$(dirname "$0")/.."
SQL=scripts/db-sql.sh
MIGRATIONS_DIR=sql/migrations

checksum() {
  if command -v sha256sum >/dev/null 2>&1; then sha256sum "$1"; else shasum -a 256 "$1"; fi | cut -c1-64
}

printf '%s\n' "CREATE TABLE IF NOT EXISTS schema_migrations (
  filename   VARCHAR(255) NOT NULL,
  checksum   CHAR(64)     NOT NULL,
  applied_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (filename)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;" | $SQL

applied=$(printf '%s\n' "SELECT CONCAT(filename, ' ', checksum) FROM schema_migrations ORDER BY filename;" | $SQL --skip-column-names)
previously=$(printf '%s\n' "$applied" | grep -c . || true)

shopt -s nullglob
count=0
for f in "$MIGRATIONS_DIR"/[0-9][0-9][0-9]_*.sql; do
  name=$(basename "$f")
  sum=$(checksum "$f")
  seen=0; same=0
  while IFS= read -r line; do
    [[ $line == "$name "* ]] && seen=1
    [[ $line == "$name $sum" ]] && same=1
  done <<< "$applied"
  if (( seen )); then
    if (( ! same )); then
      echo "ERROR: $name changed since it was applied to this database." >&2
      echo "       Applied files are immutable: add a new migration instead, or (local dev only)" >&2
      echo "       delete its schema_migrations row and re-run if the file is still idempotent." >&2
      exit 1
    fi
    continue
  fi
  echo "Applying $name"
  $SQL < "$f"
  printf '%s\n' "INSERT INTO schema_migrations (filename, checksum) VALUES ('$name', '$sum');" | $SQL
  count=$((count + 1))
done
echo "Migrations: $count applied now, $previously previously applied."
