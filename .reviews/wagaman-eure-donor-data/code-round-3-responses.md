# Responses to round 3

Record: `code-round-3.json` (local; approved by all four auditors, 0 critical, 0 high, 0 medium, 8 low). Reviewed commit d50d214, tree `70c6d8ba…3c14`.

No code changed after this round, so the approval stub `code-approved.json` points at d50d214. The eight lows are answered below; the four that need code are follow-ups, listed in the PR body.

| Finding | Response |
|---|---|
| low (security, verification): `code-round-2-responses.md` says "accepted" for lows that d50d214 fixed, and miscounts the record | fixed: a dated correction section is appended to that file; the original text is left in place |
| low (verification): the UF2-88 Jira comment mentions an `updated=1` banner that does not exist | fixed: the comment now says the profile has one `saved=1` banner (after add, edit and reactivate) and that the list and profile show `.notice[role=alert]` on a load failure |
| low (data): `donor_set_active()` does not check the current `is_active`, so a repeated deactivate overwrites `deactivated_at`/`deactivated_by` | accepted, follow-up: add `AND is_active = 1` / `AND is_active = 0` to the two UPDATEs, return `affected_rows === 1`, and assert in the test that a second deactivate leaves the audit columns unchanged |
| low (data): database session time zone is UTC while PHP formats in America/New_York (`created_at` display, `deactivated_at` write) | accepted, follow-up for the whole stack (the donation pages will write `occurred_at` and `receipt_sent_at` too): set the session zone in `connect()` or the db service and note the rule in `sql/README.md` |
| low (correctness, verification): `donor.php?error=load` shows "Could not load donors" above a list that did load, and `pageUrl()` carries the flag through the pager | accepted, follow-up: name the failed action in the flag text and drop it from `pageUrl()`; one test for the flag |
| low (correctness): the read-only notice on the two form pages has no `.notice` rule because only `donor.css` defines it | accepted, follow-up: move `.notice` into `css/forms.css` or `css/fishnet.css` (Tristan owns the CSS; noted on UF2-88) |
