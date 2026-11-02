# Responses to round 1

Record: `code-round-1.json` (local; approved, 0 critical, 0 high, 3 medium, 13 low).

| Finding | Response |
|---|---|
| medium: read path has no error fallback (2 auditors) | fixed: `donor.php` and `donorProfile.php` catch `mysqli_sql_exception`, log, and render "Could not load donors / this donor" with empty tiles and rows |
| medium: campaign names never asserted | fixed: Maria Lopez's Cash, Volunteer and Communications panels assert "Fall Food Drive 2026"; Grace Community Church's Cash tab asserts "Holiday Meals 2025" |
| low: read pages do not consult `can_view` | accepted: deferred to Manage Users as the ticket's Out section says; the Manage Users ticket must add the read gate when `can_view` becomes editable |
| low: raw exception on a read fault while `display_errors` is on | fixed by the fallback above; turning `display_errors` off in the container is a follow-up |
| low: `donors_tiles()` used unprepared `query()` | fixed: both tile queries are prepared, the anonymous id is bound |
| low: review-process commit bundled on this branch | accepted: the branch is stacked on the process branch; the skeptics-for-criticals commit is called out in the PR description |
| low: `donor_form_from_post()` repeats `fish_str()` | accepted with a comment: the data layer must not depend on the page layout |
| low: unreachable "Other" branch for preferred contact | fixed: the Other option applies to state only |
| low: changing the sort dropped the filter pill | fixed: `requestSubmit()` carries the current filter |
| low: AC 2/3 partially asserted (volunteer footer, organization create, flags) | fixed: fixture adds two volunteer shifts and asserts the average; an Organization donor with two flags is created and read back |
| low: fallback assertion could pass vacuously | fixed: literal `selected` option and active pill asserted |
| low: frequency only partly represented; Jira comments pending | the footers carry the frequency reading; Jira comments on UF2-26/27 and James's tickets are posted right before the PR, and a per-month figure is a follow-up |
| low: action links and tip point at pages that do not exist yet | tip reworded; links stay as documented in Out until the donation pages land; `autofocus` restored |
| low: unticketed `ReportGeneration.php` one-liner | kept: the widened PHP-error check exposed a real notice (`ob_flush()` with no buffer); named in the PR |
