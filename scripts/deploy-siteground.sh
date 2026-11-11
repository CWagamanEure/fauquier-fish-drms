#!/usr/bin/env bash
# Publish the tracked tree at HEAD to SiteGround's public_html with rsync (docs/deploy-siteground.md).
# Used by .github/workflows/deploy.yml, by tests/deploy/deploy_test.sh and for local dry runs.
#
# Environment:
#   DRY_RUN              anything but the exact word "false" is a dry run (the default)
#   RSYNC                rsync binary, default "rsync"; must be GNU rsync 3 (macOS openrsync is refused)
#   Remote mode:         SG_HOST SG_USER SG_PORT SG_PATH SG_KEY_FILE SG_KNOWN_HOSTS_FILE
#   Test seam:           SG_LOCAL_DEST=<dir ending in /public_html/> syncs to a local directory, no ssh.
#                        SG_FILTER_FILE replaces the filter and is accepted only with SG_LOCAL_DEST.
# A real run to a remote destination is refused unless GITHUB_ACTIONS=true. That stops accidental
# deploys from a laptop; it is not a security boundary.
# Exit codes: 2 refused before rsync ran, 3 the run listed a deletion of a protected path, else rsync's.
set -euo pipefail

die() { echo "deploy: $*" >&2; exit 2; }

HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$HERE/.." && pwd)"
DRY_RUN="${DRY_RUN:-true}"
RSYNC="${RSYNC:-rsync}"
LOCAL="${SG_LOCAL_DEST:-}"
FILTER="$HERE/deploy-siteground.filter"

if [ -n "${SG_FILTER_FILE:-}" ]; then
  [ -n "$LOCAL" ] || die "SG_FILTER_FILE is only accepted together with SG_LOCAL_DEST"
  FILTER="$SG_FILTER_FILE"
fi
[ -f "$FILTER" ] || die "filter file not found: $FILTER"

check_path() { # check_path <name> <value>: allowed characters, no "..", ends in /public_html/
  case "$2" in *[!A-Za-z0-9._/-]*) die "$1 has a character outside A-Za-z0-9._/-" ;; esac
  case "$2" in *..*) die "$1 must not contain '..'" ;; esac
  case "$2" in */public_html/) ;; *) die "$1 must end in /public_html/" ;; esac
}

if [ -n "$LOCAL" ]; then
  check_path SG_LOCAL_DEST "$LOCAL"
  [ -d "$LOCAL" ] || die "SG_LOCAL_DEST is not a directory"
  DEST="$LOCAL"
else
  for v in SG_HOST SG_USER SG_PORT SG_PATH SG_KEY_FILE SG_KNOWN_HOSTS_FILE; do
    [ -n "${!v:-}" ] || die "$v is empty"
  done
  case "$SG_PORT" in *[!0-9]*) die "SG_PORT must be digits" ;; esac
  case "$SG_HOST" in *[!A-Za-z0-9._-]*) die "SG_HOST has a character outside A-Za-z0-9._-" ;; esac
  case "$SG_USER" in *[!A-Za-z0-9._-]*) die "SG_USER has a character outside A-Za-z0-9._-" ;; esac
  check_path SG_PATH "$SG_PATH"
  for v in SG_KEY_FILE SG_KNOWN_HOSTS_FILE; do
    case "${!v}" in *[!A-Za-z0-9._/-]*) die "$v has a character outside A-Za-z0-9._/-" ;; esac
    [ -f "${!v}" ] || die "$v is not a file"
  done
  if [ "$DRY_RUN" = "false" ] && [ "${GITHUB_ACTIONS:-}" != "true" ]; then
    die "a real deploy to a remote destination is refused outside CI; run a dry run instead"
  fi
  DEST="$SG_USER@$SG_HOST:$SG_PATH"
fi

version="$("$RSYNC" --version 2>/dev/null | head -n 1 || true)"
case "$version" in
  "rsync  version 3."*|"rsync version 3."*) ;;
  *) die "GNU rsync 3 is required, found: ${version:-nothing} (set RSYNC, or use make test-deploy-docker)" ;;
esac

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
SRC="$WORK/src"
mkdir "$SRC"
# The source is the tracked tree at HEAD by construction: untracked and ignored files cannot be uploaded.
git -C "$ROOT" archive --format=tar HEAD | tar -x -C "$SRC"
[ -f "$SRC/login.php" ] || die "the tree at HEAD has no login.php; refusing to sync it with --delete"

args=(-rlz --checksum --delete --delete-after --itemize-changes "--filter=merge $FILTER")
if [ "$DRY_RUN" = "false" ]; then
  echo "deploy: REAL DEPLOY of $(git -C "$ROOT" rev-parse --short HEAD) ($version)"
else
  echo "deploy: DRY RUN of $(git -C "$ROOT" rev-parse --short HEAD), nothing will change ($version)"
  args+=(--dry-run)
fi
if [ -z "$LOCAL" ]; then
  args+=(-e "ssh -p $SG_PORT -i $SG_KEY_FILE -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile=$SG_KNOWN_HOSTS_FILE -o BatchMode=yes")
fi

OUT="$WORK/rsync.out"
rc=0
"$RSYNC" "${args[@]}" -- "$SRC/" "$DEST" >"$OUT" 2>&1 || rc=$?
cat "$OUT"
[ "$rc" -eq 0 ] || { echo "deploy: rsync failed with exit $rc" >&2; exit "$rc"; }

# Tripwire: the filter must never let these be deleted. In a dry run this fails before anything changed.
protected='^\*deleting +(\.htaccess$|(.*/)?\.api_env$|(.*/)?php_errorlog$|uploads(/|$)|reportGeneration/reports(/|$)|python/exports(/|$)|\.well-known(/|$))'
if grep -E "$protected" "$OUT" >"$WORK/hits"; then
  echo "deploy: the run lists deletion of protected paths:" >&2
  cat "$WORK/hits" >&2
  exit 3
fi
echo "deploy: finished, $(grep -c '^\*deleting' "$OUT" || true) deletion(s) listed, none protected"
