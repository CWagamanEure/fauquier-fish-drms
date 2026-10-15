#!/usr/bin/env bash
# Validate the review-swarm approval record for one revision and destination branch.
# Usage: scripts/review-check.sh <rev> <branch>   (exit 0 = allowed, 1 = refused, message on stderr)
# Shared by .githooks/pre-push and .github/workflows/review-gate.yml so the two cannot diverge.
# Rules: dev and main are exempt (PR merges only); the record .reviews/<branch>/code-approved.json must
# be committed at <rev>, say "approved": true and "mode": "code", and its "tree" must equal
# scripts/review-hash.sh <rev>, so nothing changed after the approval.
set -euo pipefail
rev="$1"; branch="$2"
case "$branch" in dev|main) echo "review-check: $branch is exempt (it only receives PR merges)."; exit 0 ;; esac
record=".reviews/${branch//\//-}/code-approved.json"
if ! git cat-file -e "$rev:$record" 2>/dev/null; then
  echo "review-check: $branch has no review-swarm approval ($record is not committed on $rev)." >&2
  echo "              Run /review-swarm code, commit the record, then push." >&2
  exit 1
fi
blob=$(git show "$rev:$record")
# here-strings, not pipes: a pipe into grep -q can die of SIGPIPE on large records under pipefail
if ! grep -Eq '"approved"[[:space:]]*:[[:space:]]*true' <<<"$blob" \
   || ! grep -Eq '"mode"[[:space:]]*:[[:space:]]*"code"' <<<"$blob"; then
  echo "review-check: $record is not an approving code-review record (needs \"approved\": true and \"mode\": \"code\")." >&2
  exit 1
fi
recorded=$(sed -n 's/.*"tree"[[:space:]]*:[[:space:]]*"\([0-9a-f]\{64\}\)".*/\1/p' <<<"$blob" | head -1)
if [ -z "$recorded" ]; then
  echo "review-check: $record has no \"tree\" hash; write the output of scripts/review-hash.sh into it." >&2
  exit 1
fi
actual=$("$(dirname "$0")/review-hash.sh" "$rev")
if [ "$recorded" != "$actual" ]; then
  echo "review-check: $branch changed after its review-swarm approval (recorded $recorded, now $actual)." >&2
  echo "              Merges and rebases count as changes. Run /review-swarm code again on the current code." >&2
  exit 1
fi
echo "review-check: $branch approval record matches $rev ($actual)."
