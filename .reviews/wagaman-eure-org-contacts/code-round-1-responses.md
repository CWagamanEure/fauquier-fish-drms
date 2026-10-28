# Responses to round 1 (code)

Round 1 approved with 5 low findings. After the push, CodeRabbit raised the first one again on PR #35
(two comments on 013), so it is fixed before merge while 013 can still change.

- Not-blank CHECK lets through tab/newline-only values (low, data): fixed. The CHECK and the backfill filter
  use `COALESCE(col, '') REGEXP '[^[:space:]]'`, and the backfill trims with
  `REGEXP_REPLACE(..., '^[[:space:]]+|[[:space:]]+$', '')`. Tests: an all-whitespace row (tab, newline, CR)
  is refused; the backfill turns "\t Pat Doe \n" into "Pat Doe" and skips " \t\n ".
- Seed keeps org_contact set next to explicit contacts (low, data): accepted. It is demo data, and AC3/AC8
  rely on the names matching; the NOT EXISTS guard keeps a replay from adding rows.
- contacts_backfill relies on the implicit DDL commit (low, correctness): accepted. The code comment says so
  and 013 is frozen once merged.
- multi_query errors caught only by strict mode (low, correctness and verification): accepted. PHP 8.2
  mysqli defaults to throwing, and dbinfo.php does not change it.
