# Ticket: required two-gate review process for every change

Jira: none yet (process change requested by Cory on 2026-10-15).

## Problem

Two PRs merged this week had real defects (SQL injection on a GET delete, a migration default that
stamped every donor as new) that only surfaced when an independent review was run after the fact.
The team has no required step that catches this before implementation or before a push.

## Scope

In: a runnable four-auditor review swarm (`.claude/workflows/review-swarm.js`) and a skill that drives
it (`/review-swarm ticket|code`); committed records under `.reviews/<branch>/`; a pre-push git hook
that refuses a feature-branch push without a matching approval record; process doc, PR template,
CLAUDE.md rules, Makefile `hooks` target wired into `make dev`; README pointer.

Out: changing how `dev`/`main` are merged (still PRs); human review (still required); running the test
suite inside the swarm.

## Approach

Four specialist agents (security, data/schema, correctness/architecture, verification/product) review
in parallel and return severity-rated findings; two skeptic agents try to refute each critical/high
finding; approval = all four reported and zero confirmed criticals. The hook compares a content hash
of the pushed tree (excluding `.reviews/`) with the hash stored in `code-approved.json`.

## Acceptance criteria

1. `make hooks` activates the hook; `git push` of a feature branch without `code-approved.json` is refused with an instruction.
2. A push whose code changed after approval is refused.
3. `REVIEW_SWARM_SKIP=1` bypasses with a visible message; `dev`/`main` are exempt.
4. The workflow runs from `/review-swarm` and returns `approved` plus findings with severities and verification status.
5. The process is documented in `docs/process/change-review-process.md` and summarized in README and CLAUDE.md.

## Verification plan

- `make hooks`; `git push --dry-run origin <branch>` before and after writing the record.
- Run the workflow in code mode on this branch itself; commit the record; push.

## Risks

The hook is client-side and runs only where `make hooks`/`make dev` ran (and `--no-verify` skips it).
`.github/workflows/review-gate.yml` repeats the check on every PR; it blocks the merge because branch
protection on dev and main requires it (applied 2026-10-15), not on its own. The record is a plain file, so
the human reviewer must confirm it is genuine. Swarm cost per round is roughly 6 to 16 agent runs.
