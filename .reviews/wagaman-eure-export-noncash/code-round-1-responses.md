# Responses to round 1 (code)

Round 1 reviewed 2915dc7 and approved it: 0 critical, 0 high, 0 medium, 6 low. All six are accepted, and nothing in the code was changed after this approval, so no second round was run.

- **Unplanned second helper export_query() added (low):** accepted. It is the cash case's old inline query step, moved so both cases share it. Its behaviour is unchanged. It is noted in the PR description, and it can be widened when a year-based export needs a different binding.
- **Staff and viewer success check matches on a bare numeric id (low):** accepted. test_noncash_export_file checks the rows exactly for the manager. Follow-up: parse the CSV in the staff and viewer subtests.
- **Non-cash file test omits two checks the cash test makes (low):** accepted. nosniff and the method/token checks come from shared code that the cash tests already cover. Follow-up: assert nosniff and loop test_method_and_token over both exports.
- **The database-error path in export_query() is untested (low):** accepted. The cash export had the same gap before this change, and it was already listed as a follow-up from UF2-133.
- **README test paragraph wording and an over-long line (low):** accepted. Follow-up wording fix.
- **The plan's manual demo-month comparison is not recorded (low):** accepted. It was not done, and the PR says so.
