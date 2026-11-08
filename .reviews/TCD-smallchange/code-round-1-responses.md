# Responses to round 1

Round 1: four auditors reported, no critical findings; one high (security), three mediums and seven lows.

- Dashboard shows donation totals without checking donations view permission (high, security): fixed. The cash and non-cash cards query and display only when fish_can('donations','view'); the volunteer card only when fish_can('donors','view') (donor permissions govern volunteer records). Without the permission the card shows a dash and "No access to donations" / "No access to volunteer records". Checked by rendering the dashboard with a per-user override removing donations view.
- itemValues.php has no permission gate for data_import (low): accepted; front end only, forms post to `#`. The backend handler must gate on a permission and check the CSRF token.
- Non-cash card sums quantity while donations.php counts gifts (medium): accepted; the ticket asks for items donated this month, which is total quantity. Labelling can be revisited with the Item values backend.
- Cash card rounds to whole dollars (low): accepted; headline figure, matches the original mock-up.
- Unvalued non-cash items count as $0 (low): accepted; same as donations.php.
- NULL donation_date rows excluded (low): no change needed (auditor agrees).
- Last-admin demote kept other posted edits on screen (low): fixed. The whole form is reset to the stored values and the pop-up says nothing was saved.
- .chip nowrap applies on every page (low): accepted; chips are short labels everywhere.
- Dashboard error handling relies on mysqli exceptions (low): accepted; PHP 8.2 throws by default and the codebase relies on this elsewhere.
- No automated check of dashboard totals (medium) and of the pop-up markup (medium): accepted for this branch; manual SQL cross-check and screenshots listed in the PR. Follow-up test ticket suggested.
- donorProfile.php still links to logCommunication.php (low): accepted; out of this ticket's scope, follow-up.
- +Log Communication hidden without communications edit (low): intended, per ticket.
