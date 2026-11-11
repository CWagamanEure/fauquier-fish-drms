#!/usr/bin/env bash
# Fails when sql/migrations holds a migration that sql/production-applied.txt does not list, so code
# cannot be deployed ahead of the production schema (docs/deploy-siteground.md, "Releasing a migration").
# Usage: deploy-schema-guard.sh [--warn] [repository root]
#   --warn  report with a GitHub warning annotation and exit 0 (pull requests and dry runs)
set -euo pipefail

warn=0
if [ "${1:-}" = "--warn" ]; then warn=1; shift; fi
ROOT="${1:-$(cd "$(dirname "$0")/.." && pwd)}"
LIST="$ROOT/sql/production-applied.txt"

fail() {
  if [ "$warn" -eq 1 ]; then echo "::warning::schema guard: $*"; exit 0; fi
  echo "::error::schema guard: $*" >&2; exit 1
}

[ -f "$LIST" ] || fail "sql/production-applied.txt is missing"
# One filename per line; comments, blank lines, surrounding whitespace and a trailing CR are ignored.
applied="$(sed -e 's/\r$//' -e 's/#.*//' -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' "$LIST" | grep -v '^$' || true)"

found=0
missing=""
for f in "$ROOT"/sql/migrations/[0-9][0-9][0-9]_*.sql; do
  [ -e "$f" ] || continue
  found=1
  name="$(basename "$f")"
  printf '%s\n' "$applied" | grep -Fxq -- "$name" || missing="$missing $name"
done
[ "$found" -eq 1 ] || fail "no migration files found under sql/migrations"
[ -z "$missing" ] || fail "not recorded as applied on production:$missing. Apply them on SiteGround, then add the filenames to sql/production-applied.txt."
echo "schema guard: every migration is recorded as applied on production"
