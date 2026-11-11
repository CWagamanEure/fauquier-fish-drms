#!/usr/bin/env bash
# Checks from outside that the site answers and that private paths are not served.
# Usage: deploy-probe.sh https://site.example
# login.php must answer 200. Files the deploy itself uploads into denied directories must answer exactly
# 403 (a 404 would prove nothing about the deny rule). Other private paths must answer 403 or 404.
# Redirects are not followed; 3xx, 000 (no connection) and everything else fail. The database is not checked.
set -euo pipefail

URL="${1:-}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
case "$URL" in https://*) ;; *) echo "probe: the site URL must start with https://" >&2; exit 2 ;; esac
URL="${URL%/}"
case "${URL#https://}" in *[!A-Za-z0-9._/-]*|"") echo "probe: the site URL has an unexpected character" >&2; exit 2 ;; esac

bad=0
probe() { # probe <path> <allowed codes, space separated>
  local code
  code="$(curl -s -o /dev/null -m 20 -w '%{http_code}' "$URL/$1" || true)"
  case " $2 " in
    *" $code "*) echo "ok   $code /$1" ;;
    *) echo "FAIL $code /$1 (wanted $2)"; bad=1 ;;
  esac
}

probe login.php "200"

# Tracked files inside denied directories. If one is removed from the repository, pick a new probe here.
for f in "uploads/CPSC326_Final_Review (1).pdf" "reportGeneration/reports/makeReport.py"; do
  [ -f "$ROOT/$f" ] || { echo "FAIL probe file is no longer in the repository: $f"; bad=1; }
done
probe "uploads/CPSC326_Final_Review%20(1).pdf" "403"
probe "reportGeneration/reports/makeReport.py" "403"

# python/exports/ tracks only its .htaccess, so this one is weak: a directory without an index is
# usually refused with or without the deny rule.
for p in .htaccess .api_env ai-email-gen/.api_env php_errorlog php.ini .env .git/HEAD sql/casadb.sql python/exports/; do
  probe "$p" "403 404"
done

[ "$bad" -eq 0 ] || { echo "probe: at least one check failed" >&2; exit 1; }
echo "probe: all checks passed"
