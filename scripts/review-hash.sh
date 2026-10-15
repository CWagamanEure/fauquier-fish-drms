#!/usr/bin/env bash
# Content hash of a revision's tree with the .reviews/ records left out, so committing an approval
# record does not change the hash the record vouches for. Used by .githooks/pre-push and the
# /review-swarm skill.
set -euo pipefail
rev="${1:-HEAD}"
git ls-tree -r "$rev" | grep -v $'\t\\.reviews/' | if command -v sha256sum >/dev/null 2>&1; then sha256sum; else shasum -a 256; fi | cut -c1-64
