#!/usr/bin/env bash
# Regression test for the review gate: builds a bare remote and a clone under a temp dir, installs the
# repo's hook and scripts there, and asserts every documented outcome (exit code and message) for the
# hook and for scripts/review-check.sh, which the GitHub Actions check also runs.   Run: make test-gate
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
pass=0; fail=0
check() { # check <name> <expected exit> [--msg <text>] <command...>
  local name="$1" want="$2" msg=""; shift 2
  if [ "${1:-}" = "--msg" ]; then msg="$2"; shift 2; fi
  if "$@" >"$TMP/out" 2>&1; then got=0; else got=$?; fi
  if [ "$got" = "$want" ] && { [ -z "$msg" ] || grep -q -- "$msg" "$TMP/out"; }; then
    pass=$((pass+1)); echo "ok   $name"
  else
    fail=$((fail+1)); echo "FAIL $name (exit $got, wanted $want${msg:+, message '$msg'})"; sed 's/^/     /' "$TMP/out"
  fi
}
git init -q --bare "$TMP/remote.git"
git init -q -b dev "$TMP/clone"; cd "$TMP/clone"
git config user.email t@example.org; git config user.name t; git config commit.gpgsign false
git remote add origin "$TMP/remote.git"
mkdir -p .githooks scripts
cp "$ROOT/.githooks/pre-push" .githooks/; cp "$ROOT/scripts/review-hash.sh" "$ROOT/scripts/review-check.sh" scripts/
chmod +x .githooks/pre-push scripts/*.sh
git config core.hooksPath .githooks
echo base > app.txt; git add -A; git commit -qm base
check "dev is exempt"                       0 git push -q origin dev
check "main is exempt"                      0 git push -q origin dev:main
git switch -qc feature/x
echo change > app.txt; git add -A; git commit -qm change
check "no record is refused"                1 --msg "Run /review-swarm code" git push -q origin feature/x
check "push HEAD without record refused"    1 git push -q origin HEAD
check "tag push is not gated"               0 bash -c 'git tag v0 && git push -q origin v0'
approve() { mkdir -p ".reviews/feature-x"; printf '{"mode":"code","approved":true,"tree":"%s"}\n' "$(scripts/review-hash.sh)" > .reviews/feature-x/code-approved.json; git add -A; git commit -qm record; }
approve
check "matching record allows branch push"  0 git push -q origin feature/x
check "matching record allows push HEAD"    0 git push -q origin HEAD
check "review-check accepts PR head"        0 --msg "matches" scripts/review-check.sh HEAD feature/x
check "record on another branch name"       1 git push -q origin HEAD:feature/y
echo more > app.txt; git add -A; git commit -qm drift
check "edit after approval is refused"      1 --msg "changed after its review-swarm approval" git push -q origin feature/x
check "review-check refuses drift"          1 --msg "changed after" scripts/review-check.sh HEAD feature/x
approve
check "re-approval after edit allows"       0 git push -q origin feature/x
python3 -c "import json;print(json.dumps({'mode':'code','approved':True,'tree':open('/dev/stdin').read().strip(),'padding':'x'*200000}))" <<<"$(scripts/review-hash.sh)" > .reviews/feature-x/code-approved.json; git add -A; git commit -qm bigrecord
check "large (200KB) record is accepted"    0 git push -q origin feature/x
printf '{"mode":"ticket","approved":true,"tree":"%s"}\n' "$(scripts/review-hash.sh)" > .reviews/feature-x/code-approved.json; git add -A; git commit -qm wrongmode
check "ticket-mode record is refused"       1 --msg "not an approving code-review record" git push -q origin feature/x
printf '{"mode":"code","approved":false,"tree":"%s"}\n' "$(scripts/review-hash.sh)" > .reviews/feature-x/code-approved.json; git add -A; git commit -qm notapproved
check "approved:false record is refused"    1 git push -q origin feature/x
printf '{"mode":"code","approved":true}\n' > .reviews/feature-x/code-approved.json; git add -A; git commit -qm notree
check "record without tree is refused"      1 --msg "no \"tree\" hash" git push -q origin feature/x
check "REVIEW_SWARM_SKIP bypasses"          0 --msg "skipping the review-swarm gate" env REVIEW_SWARM_SKIP=1 git push -q origin feature/x
check "deleting a remote branch passes"     0 git push -q origin :feature/x
echo "review gate: $pass passed, $fail failed"
[ "$fail" = 0 ]
