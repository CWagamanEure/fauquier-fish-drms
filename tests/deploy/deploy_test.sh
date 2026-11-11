#!/usr/bin/env bash
# Tests for scripts/deploy-siteground.sh, its filter and scripts/deploy-schema-guard.sh.
# Network-free: builds a small git repository and a fake public_html under a temp dir and runs the real
# script against it through SG_LOCAL_DEST, asserting on the resulting tree.
# Needs GNU rsync 3, git and bash.   Run: make test-deploy   (on a Mac: make test-deploy-docker)
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
RSYNC="${RSYNC:-rsync}"
export RSYNC

version="$("$RSYNC" --version 2>/dev/null | head -n 1 || true)"
case "$version" in
  "rsync  version 3."*|"rsync version 3."*) echo "using $version" ;;
  *) echo "FAIL GNU rsync 3 is required, found: ${version:-nothing}."
     echo "     macOS ships openrsync. Run: make test-deploy-docker"
     exit 1 ;;
esac

TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
pass=0; fail=0
ok()  { pass=$((pass+1)); echo "ok   $1"; }
bad() { fail=$((fail+1)); echo "FAIL $1"; }
is()  { if "${@:2}" >/dev/null 2>&1; then ok "$1"; else bad "$1"; fi; }
not() { if "${@:2}" >/dev/null 2>&1; then bad "$1"; else ok "$1"; fi; }
snap() { (cd "$1" && find . | LC_ALL=C sort && find . -type f -exec cksum {} + | LC_ALL=C sort); }

# --- a small repository shaped like the real one -------------------------------------------------
REPO="$TMP/repo"; SITE="$TMP/site/public_html/"
git init -q "$REPO"
cd "$REPO"
git config user.email t@example.org; git config user.name t; git config commit.gpgsign false
mkdir -p scripts sql/migrations tests .github/workflows docs Go ai-email-gen uploads reportGeneration/reports python/exports css
cp "$ROOT/scripts/deploy-siteground.sh" "$ROOT/scripts/deploy-siteground.filter" scripts/
echo '<?php // new' > login.php
echo 'body{}' > css/base.css
echo x > sql/casadb.sql; echo x > tests/t.py; echo x > .github/workflows/ci.yml; echo x > docs/d.md
echo x > Go/go.mod; echo x > README.md; echo x > CLAUDE.md; echo x > LICENSE.txt; echo x > Makefile
echo x > Dockerfile; echo x > compose.yaml; echo x > package.json; echo x > .env.example; echo x > .dockerignore
printf '.local/\nignored.txt\n' > .gitignore
echo x > ai-email-gen/Dockerfile; echo x > ai-email-gen/index.php
echo 'Require all denied' > uploads/.htaccess; echo x > uploads/sample.pdf
echo x > reportGeneration/reports/makeReport.py; echo 'Require all denied' > python/exports/.htaccess
git add -A; git commit -qm base
mkdir .local; echo secret > .local/backup.sql; echo secret > ignored.txt; echo x > stray.php   # untracked

seed_site() {
  rm -rf "$TMP/site"; mkdir -p "$SITE"
  ( cd "$SITE"
    mkdir -p ai-email-gen sub .well-known/acme uploads reportGeneration/reports python/exports sql .git fish-site
    echo creds > .htaccess; echo key > .api_env; echo key > ai-email-gen/.api_env
    echo log > php_errorlog; echo log > sub/php_errorlog; echo x > .well-known/acme/x
    echo user > uploads/user.pdf; echo r > reportGeneration/reports/r.pdf; echo e > python/exports/e.csv
    echo dump > sql/dump.sql; echo ref > .git/HEAD; echo env > .env; echo old > stale.php
    echo '<?php // old' > login.php )
}
run() { ( cd "$TMP" && env -u GITHUB_ACTIONS "$@" bash "$REPO/scripts/deploy-siteground.sh" ) >"$TMP/out" 2>&1; }
refused() { # refused <name> <message> <env assignments...>: exit 2, message shown, site untouched
  local name="$1" msg="$2" rc=0; shift 2
  run "$@" || rc=$?
  if [ "$rc" = 2 ] && grep -q -- "$msg" "$TMP/out" && [ "$(snap "$SITE")" = "$before" ]; then ok "refuses: $name"
  else bad "refuses: $name (exit $rc)"; sed 's/^/     /' "$TMP/out"; fi
}

# --- dry-run default (5h) -------------------------------------------------------------------------
seed_site; before="$(snap "$SITE")"
for v in "" true no FALSE; do
  rc=0; run SG_LOCAL_DEST="$SITE" ${v:+DRY_RUN="$v"} || rc=$?
  if [ "$rc" = 0 ] && grep -q 'DRY RUN' "$TMP/out" && [ "$(snap "$SITE")" = "$before" ]; then ok "DRY_RUN='${v}' changes nothing"
  else bad "DRY_RUN='${v}' changes nothing (exit $rc)"; sed 's/^/     /' "$TMP/out"; fi
done

# --- guards (5g) ----------------------------------------------------------------------------------
touch "$TMP/key" "$TMP/known"
R=(SG_HOST=h.example SG_USER=u1 SG_PORT=22 SG_PATH=www/x/public_html/ SG_KEY_FILE="$TMP/key" SG_KNOWN_HOSTS_FILE="$TMP/known")
mkdir -p "$TMP/elsewhere"
refused "local path not ending in /public_html/" "must end in /public_html/" SG_LOCAL_DEST="$TMP/elsewhere/" DRY_RUN=false
refused "local path with .."        "must not contain"   SG_LOCAL_DEST="$TMP/site/../site/public_html/" DRY_RUN=false
refused "empty variable"            "SG_HOST is empty"   "${R[@]}" SG_HOST=
refused "port not digits"           "SG_PORT must be digits" "${R[@]}" SG_PORT=22x
refused "host with odd character"   "SG_HOST has a character" "${R[@]}" "SG_HOST=h;id"
refused "user with odd character"   "SG_USER has a character" "${R[@]}" "SG_USER=u 1"
refused "remote path with odd character" "SG_PATH has a character" "${R[@]}" "SG_PATH=www/x y/public_html/"
refused "remote path not ending in /public_html/" "must end in /public_html/" "${R[@]}" SG_PATH=www/x/
refused "remote path with .."       "must not contain"   "${R[@]}" SG_PATH=www/../public_html/
refused "filter override without local destination" "only accepted together" "${R[@]}" SG_FILTER_FILE="$TMP/key"
refused "real remote run outside CI" "refused outside CI" "${R[@]}" DRY_RUN=false
printf '#!/bin/sh\necho "openrsync: protocol version 29"\n' > "$TMP/fakersync"; chmod +x "$TMP/fakersync"
refused "rsync that is not GNU rsync 3" "GNU rsync 3 is required" SG_LOCAL_DEST="$SITE" RSYNC="$TMP/fakersync"

# --- tripwire (5i): a filter without the protect rules would delete .htaccess ----------------------
: > "$TMP/empty.filter"
rc=0; run SG_LOCAL_DEST="$SITE" SG_FILTER_FILE="$TMP/empty.filter" || rc=$?
if [ "$rc" = 3 ] && grep -q 'deletion of protected paths' "$TMP/out" && [ "$(snap "$SITE")" = "$before" ]; then ok "tripwire stops a filter that would delete protected files"
else bad "tripwire (exit $rc)"; sed 's/^/     /' "$TMP/out"; fi

# --- real sync into the fake site (5a-5f) ---------------------------------------------------------
rc=0; run SG_LOCAL_DEST="$SITE" DRY_RUN=false || rc=$?
if [ "$rc" = 0 ] && grep -q 'REAL DEPLOY' "$TMP/out"; then ok "real run to a local destination succeeds"
else bad "real run to a local destination (exit $rc)"; sed 's/^/     /' "$TMP/out"; fi
cd "$SITE"
is  "uploads app code"                         grep -q new login.php
is  "uploads nested app files"                 test -f css/base.css
for p in sql tests .github docs Go scripts README.md CLAUDE.md LICENSE.txt Makefile Dockerfile compose.yaml package.json .env.example .dockerignore .gitignore; do
  not "does not upload development path $p"    test -e "$p"
done
not "deletes sql/dump.sql from the server"     test -e sql/dump.sql
not "deletes .git from the server"             test -e .git
not "deletes .env from the server"             test -e .env
not "deletes a stale file"                     test -e stale.php
not "deletes the empty fish-site directory"    test -e fish-site
is  "keeps the server .htaccess"               grep -q creds .htaccess
is  "keeps .api_env"                           grep -q key .api_env
is  "keeps ai-email-gen/.api_env"              grep -q key ai-email-gen/.api_env
is  "keeps php_errorlog"                       test -f php_errorlog
is  "keeps php_errorlog in a subdirectory"     test -f sub/php_errorlog
is  "keeps .well-known"                        test -f .well-known/acme/x
is  "keeps user uploads"                       test -f uploads/user.pdf
is  "keeps generated reports"                  test -f reportGeneration/reports/r.pdf
is  "keeps exports"                            test -f python/exports/e.csv
is  "uploads tracked uploads/.htaccess"        grep -q denied uploads/.htaccess
is  "uploads tracked uploads/sample.pdf"       test -f uploads/sample.pdf
is  "uploads tracked report script"            test -f reportGeneration/reports/makeReport.py
is  "uploads tracked python/exports/.htaccess" test -f python/exports/.htaccess
is  "uploads ai-email-gen/Dockerfile"          test -f ai-email-gen/Dockerfile
not "does not upload an ignored directory"     test -e .local
not "does not upload an ignored file"          test -e ignored.txt
not "does not upload an untracked file"        test -e stray.php
cd "$TMP"
after="$(snap "$SITE")"; rc=0; run SG_LOCAL_DEST="$SITE" DRY_RUN=false || rc=$?
if [ "$rc" = 0 ] && [ "$(snap "$SITE")" = "$after" ] && ! grep -Eq '^(\*deleting|[<>ch.][fdL])' "$TMP/out"; then ok "a second run transfers and deletes nothing"
else bad "a second run transfers and deletes nothing (exit $rc)"; sed 's/^/     /' "$TMP/out"; fi

# --- schema guard (5j) ----------------------------------------------------------------------------
G="$TMP/guard"; mkdir -p "$G/sql/migrations"
guard() { bash "$ROOT/scripts/deploy-schema-guard.sh" "$@" "$G" >"$TMP/out" 2>&1; }
echo x > "$G/sql/migrations/001_a.sql"; echo x > "$G/sql/migrations/002_b.sql"
not "guard fails when the list is missing"     guard
printf '# applied\r\n\r\n  001_a.sql  \r\n002_b.sql # note\n' > "$G/sql/production-applied.txt"
is  "guard passes when every migration is listed (comments, blanks, CR, spaces)" guard
echo x > "$G/sql/migrations/003_c.sql"
rc=0; guard || rc=$?
if [ "$rc" = 1 ] && grep -q '003_c.sql' "$TMP/out"; then ok "guard fails and names an unlisted migration"; else bad "guard names an unlisted migration (exit $rc)"; fi
echo '003_c.sql.bak' >> "$G/sql/production-applied.txt"; echo 'x003_c.sql' >> "$G/sql/production-applied.txt"
not "guard is not satisfied by a near-name"    guard
rc=0; guard --warn || rc=$?
if [ "$rc" = 0 ] && grep -q '::warning::.*003_c.sql' "$TMP/out"; then ok "guard --warn reports without failing"; else bad "guard --warn (exit $rc)"; fi
rm "$G"/sql/migrations/*.sql
not "guard fails when no migration files are found" guard
is  "the real tree passes the guard"           bash "$ROOT/scripts/deploy-schema-guard.sh" "$ROOT"

echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
